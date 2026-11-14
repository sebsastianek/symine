import type { Page } from '@playwright/test';
import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import { attribute, journal, openEdit, selectStatus, setSetting, statusOptions, submitIssue } from './_helpers';

/*
 * Issue trees and relations (settings of the seed: parent dates / priority / % done are derived from the subtasks,
 * cross-project relations allowed, Saturday and Sunday are non-working days, duplicates are closed with the original).
 */

const CLOSED = ['Closed', 'Rejected'];

/** Adds a relation through the "Related issues" form of the issue page currently shown. */
async function addRelation(page: Page, type: string, toId: number, delay?: string): Promise<void> {
  const form = page.locator('#new-relation-form');
  if (!(await form.isVisible())) await page.locator('#relations .contextual a.icon-link-add').click();
  await expect(form).toBeVisible();
  await form.locator('#relation_relation_type').selectOption(type);
  await form.locator('#relation_issue_to_id').fill(String(toId));
  if (delay !== undefined) {
    await expect(form.locator('#predecessor_fields')).toBeVisible();
    await form.locator('#relation_delay').fill(delay);
  }
  await form.locator('input[type=submit]').click();
  await expect(page.locator('#relations table.list tr.issue', { hasText: `#${toId}` })).toBeVisible();
}

function dates(id: number) {
  return sql('SELECT start_date, due_date FROM issues WHERE id = ?', [id])[0];
}

test.describe('subtasks', () => {
  test('a parent derives dates, priority, estimate and progress from its subtasks and cannot be closed before them', async ({
    loginAs,
    api,
  }) => {
    test.setTimeout(180_000);
    const manager = await loginAs('jsmith');
    const developer = await loginAs('dlopper');
    const { page, ui } = manager;
    const parentSubject = uid('Gift cards ');
    const parent = await (await api('jsmith')).createIssue({ project_id: 1, tracker_id: 2, subject: parentSubject, priority_id: 1 });
    let design = 0;
    let build = 0;

    await test.step('the manager breaks the feature down into two subtasks', async () => {
      await page.goto(`/issues/${parent.id}`);
      await expect(attribute(page, 'priority')).toHaveText('Low');
      for (const sub of [
        { subject: `${parentSubject}: design`, priority: 'High', start: '2026-12-01', due: '2026-12-04', hours: '4', assignee: '6' },
        { subject: `${parentSubject}: build`, priority: 'Urgent', start: '2026-12-03', due: '2026-12-10', hours: '4', assignee: '6' },
      ]) {
        await page.locator('#issue_tree .contextual a.icon-add').click();
        await expect(page.locator('#issue_parent_issue_id')).toHaveValue(String(parent.id));
        await expect(page.locator('#issue_tracker_id')).toHaveValue('2'); // same tracker as the parent
        await page.locator('#issue_subject').fill(sub.subject);
        await page.locator('#issue_priority_id').selectOption({ label: sub.priority });
        await page.locator('#issue_assigned_to_id').selectOption(sub.assignee);
        await page.locator('#issue_start_date').fill(sub.start);
        await page.locator('#issue_due_date').fill(sub.due);
        await page.locator('#issue_estimated_hours').fill(sub.hours);
        await submitIssue(page);
        await expect(ui.flashNotice()).toContainText('created');
        await expect(page).toHaveURL(new RegExp(`/issues/${parent.id}$`)); // back to the parent
      }
      design = Number(sqlValue('SELECT id FROM issues WHERE subject = ?', [`${parentSubject}: design`]));
      build = Number(sqlValue('SELECT id FROM issues WHERE subject = ?', [`${parentSubject}: build`]));
      expect(sql('SELECT parent_id, root_id FROM issues WHERE id IN (?, ?)', [design, build])).toEqual([
        { parent_id: parent.id, root_id: parent.id },
        { parent_id: parent.id, root_id: parent.id },
      ]);

      // the tree lists both, the parent takes the earliest start, the latest due date, the highest priority
      await expect(page.locator('#issue_tree table.list tr.issue')).toHaveCount(2);
      await expect(page.locator('#issue_tree .issues-stat')).toContainText('2 open');
      await expect(attribute(page, 'start-date')).toHaveText('12/01/2026');
      await expect(attribute(page, 'due-date')).toContainText('12/10/2026');
      await expect(attribute(page, 'priority')).toHaveText('Urgent');
      await expect(attribute(page, 'estimated-hours')).toContainText('Total: 8:00 h');
      await expect(attribute(page, 'progress')).toContainText('0%');

      // derived attributes are not editable on the parent, and it cannot be closed while subtasks are open
      await openEdit(page);
      for (const field of ['#issue_priority_id', '#issue_start_date', '#issue_due_date', '#issue_done_ratio']) {
        await expect(page.locator(field), field).toHaveCount(0);
      }
      expect(await statusOptions(page)).toEqual(['New', 'In Progress', 'Resolved', 'Feedback']);
      await manager.http.post(`/issues/${parent.id}`, { 'issue[status_id]': '5', 'issue[priority_id]': '1', 'issue[due_date]': '2027-01-01' }, 'patch');
      expect(sql('SELECT status_id, priority_id, due_date FROM issues WHERE id = ?', [parent.id])).toEqual([
        { status_id: 1, priority_id: 4, due_date: '2026-12-10' },
      ]);
    });

    await test.step('progress and dates of the subtasks roll up while the developer works', async () => {
      const dev = developer.page;
      await dev.goto(`/issues/${design}`);
      // the subject block shows the ancestor
      await expect(dev.locator('.issue.details div.subject')).toContainText(parentSubject);
      await openEdit(dev);
      await selectStatus(dev, 'In Progress');
      await dev.locator('#issue_done_ratio').selectOption('50');
      await dev.locator('#issue_due_date').fill('2026-12-15'); // slips after the other subtask
      await dev.locator('#issue_notes').fill('Half way');
      await submitIssue(dev);
      await expect(developer.ui.flashNotice()).toHaveText('Successful update.');

      await page.goto(`/issues/${parent.id}`);
      await expect(attribute(page, 'progress')).toContainText('25%'); // (4h * 50% + 4h * 0%) / 8h
      await expect(attribute(page, 'due-date')).toContainText('12/15/2026');
      await expect(page.locator(`#issue_tree tr#issue-${design} td.status`)).toHaveText('In Progress');
      await expect(page.locator(`#issue_tree tr#issue-${design} td.done_ratio`)).toContainText('50%');
      expect(sql('SELECT done_ratio, start_date, due_date FROM issues WHERE id = ?', [parent.id])).toEqual([
        { done_ratio: 25, start_date: '2026-12-01', due_date: '2026-12-15' },
      ]);
    });

    await test.step('closing the urgent subtask: 75 %, the priority falls back to the remaining open subtask', async () => {
      // closed from the context menu of the subtasks tree of the parent
      await page.locator(`#issue_tree tr#issue-${build} td.status`).click({ button: 'right' });
      await expect(page.locator('#context-menu')).toBeVisible();
      await ui.contextMenuPick('Status', 'Closed');
      await expect(ui.flashNotice()).toHaveText('Successful update.');
      await page.goto(`/issues/${parent.id}`);
      await expect(page.locator('#issue_tree .issues-stat')).toContainText('1 open');
      await expect(page.locator('#issue_tree .issues-stat')).toContainText('1 closed');
      await expect(attribute(page, 'progress')).toContainText('75%'); // a closed subtask counts for 100 %
      await expect(attribute(page, 'priority')).toHaveText('High');
      // still one open subtask: no closed status offered
      await openEdit(page);
      expect((await statusOptions(page)).filter((s) => CLOSED.includes(s))).toEqual([]);
    });

    await test.step('once every subtask is closed the parent can be closed; a subtask of a closed parent cannot be reopened', async () => {
      const dev = developer.page;
      await dev.goto(`/issues/${design}`);
      await openEdit(dev);
      await selectStatus(dev, 'Closed');
      await submitIssue(dev);
      await expect(developer.ui.flashNotice()).toHaveText('Successful update.');

      await page.goto(`/issues/${parent.id}`);
      await expect(attribute(page, 'progress')).toContainText('100%');
      await expect(attribute(page, 'priority')).toHaveText('Normal'); // no open subtask left: default priority
      await openEdit(page);
      expect(await statusOptions(page)).toEqual(['New', 'In Progress', 'Resolved', 'Feedback', 'Closed', 'Rejected']);
      await selectStatus(page, 'Closed');
      await submitIssue(page);
      await expect(ui.flashNotice()).toHaveText('Successful update.');
      await expect(page.locator('#content .badge-status-closed')).toBeVisible();

      await page.goto(`/issues/${design}`);
      await openEdit(page);
      expect(await statusOptions(page)).toEqual(['Closed', 'Rejected']);
      await manager.http.post(`/issues/${design}`, { 'issue[status_id]': '1' }, 'patch');
      expect(sqlValue('SELECT status_id FROM issues WHERE id = ?', [design])).toBe(5);

      // reopening the parent first makes it possible again
      await page.goto(`/issues/${parent.id}`);
      await openEdit(page);
      await selectStatus(page, 'Feedback');
      await submitIssue(page);
      await expect(ui.flashNotice()).toHaveText('Successful update.');
      await page.goto(`/issues/${design}`);
      await openEdit(page);
      expect(await statusOptions(page)).toContain('In Progress');
    });
  });
});

test.describe('relations', () => {
  test('"precedes" reschedules the follower when the predecessor moves and keeps it from starting earlier', async ({ loginAs, api }) => {
    test.setTimeout(120_000);
    const { page, ui } = await loginAs('jsmith');
    const jsmith = await api('jsmith');
    const prefix = uid('Rollout ');
    // 2026-12-01 is a Tuesday
    const first = await jsmith.createIssue({ project_id: 1, tracker_id: 2, subject: `${prefix} prepare`, start_date: '2026-12-01', due_date: '2026-12-04' });
    const second = await jsmith.createIssue({ project_id: 1, tracker_id: 2, subject: `${prefix} ship`, start_date: '2026-12-01', due_date: '2026-12-02' });

    await test.step('adding the relation moves the follower after the predecessor, skipping the week-end', async () => {
      await page.goto(`/issues/${first.id}`);
      await addRelation(page, 'precedes', second.id, '');
      const row = page.locator('#relations table.list tr.issue', { hasText: `${prefix} ship` });
      await expect(row).toContainText('Precedes');
      // predecessor due Friday 12/04 -> follower starts Monday 12/07 and keeps its duration (1 working day)
      expect(dates(second.id)).toEqual({ start_date: '2026-12-07', due_date: '2026-12-08' });
      await expect(row.locator('td.start_date')).toHaveText('12/07/2026');

      await page.goto(`/issues/${second.id}`);
      await expect(page.locator('#relations table.list tr.issue', { hasText: `${prefix} prepare` })).toContainText('Follows');
      await expect(attribute(page, 'start-date')).toHaveText('12/07/2026');
      await expect(attribute(page, 'due-date')).toContainText('12/08/2026');
    });

    await test.step('the predecessor slips: the follower is pushed and its history says so', async () => {
      await page.goto(`/issues/${first.id}`);
      await openEdit(page);
      await page.locator('#issue_due_date').fill('2026-12-09'); // Wednesday
      await page.locator('#issue_notes').fill('Vendor is late');
      await submitIssue(page);
      await expect(ui.flashNotice()).toHaveText('Successful update.');
      expect(dates(second.id)).toEqual({ start_date: '2026-12-10', due_date: '2026-12-11' });

      await page.goto(`/issues/${second.id}`);
      await expect(attribute(page, 'start-date')).toHaveText('12/10/2026');
      const moved = journal(page, 'Start date changed from 12/07/2026 to 12/10/2026');
      await expect(moved).toContainText('Due date changed from 12/08/2026 to 12/11/2026');
      await expect(moved).toContainText('Updated by John Smith');
    });

    await test.step('the follower cannot be planned before the end of its predecessor, later is fine', async () => {
      await openEdit(page);
      await page.locator('#issue_start_date').fill('2026-12-08');
      await page.locator('#issue_due_date').fill('2026-12-09');
      await submitIssue(page);
      await expect(ui.errors()).toContainText('Start date cannot be earlier than 12/10/2026 because of preceding issues');
      expect(dates(second.id)).toEqual({ start_date: '2026-12-10', due_date: '2026-12-11' });

      await page.locator('#issue_start_date').fill('2026-12-14');
      await page.locator('#issue_due_date').fill('2026-12-15');
      await submitIssue(page);
      await expect(ui.flashNotice()).toHaveText('Successful update.');
      expect(dates(second.id)).toEqual({ start_date: '2026-12-14', due_date: '2026-12-15' });

      // a delay of 2 working days on a new "follows" relation, created from the follower's side
      const third = await jsmith.createIssue({ project_id: 1, tracker_id: 2, subject: `${prefix} announce`, start_date: '2026-12-01', due_date: '2026-12-01' });
      await page.goto(`/issues/${third.id}`);
      await addRelation(page, 'follows', second.id, '2');
      // predecessor due Tuesday 12/15 + 1 + 2 working days -> Friday 12/18
      expect(dates(third.id)).toEqual({ start_date: '2026-12-18', due_date: '2026-12-18' });
      const follows = page.locator('#relations table.list tr.issue', { hasText: `${prefix} ship` });
      await expect(follows.locator('td.subject')).toContainText(`Follows (2 days) eCommerce - Feature #${second.id}: ${prefix} ship`);
      // a circular dependency is refused
      await page.goto(`/issues/${third.id}`);
      await page.locator('#relations .contextual a.icon-link-add').click();
      await page.locator('#relation_relation_type').selectOption('precedes');
      await page.locator('#relation_issue_to_id').fill(String(first.id));
      await page.locator('#new-relation-form input[type=submit]').click();
      await expect(page.locator('#new-relation-form #errorExplanation')).toContainText('circular dependency');
      expect(Number(sqlValue('SELECT COUNT(*) FROM issue_relations WHERE issue_from_id = ? AND issue_to_id = ?', [third.id, first.id]))).toBe(0);
    });
  });

  // IssueRelation#to_s: the delay comes right after the relation label, before the issue link
  test('the delay of a "follows" relation is printed like Redmine does: "Follows (2 days) <issue>"', async ({ loginAs, api }) => {
    const { page } = await loginAs('jsmith');
    const jsmith = await api('jsmith');
    const a = await jsmith.createIssue({ project_id: 1, tracker_id: 2, subject: uid('Delay a '), start_date: '2026-12-01', due_date: '2026-12-02' });
    const b = await jsmith.createIssue({ project_id: 1, tracker_id: 2, subject: uid('Delay b ') });
    await page.goto(`/issues/${b.id}`);
    await addRelation(page, 'follows', a.id, '2');
    await expect(page.locator('#relations table.list tr.issue td.subject')).toHaveText(new RegExp(`^\\s*Follows \\(2 days\\) eCommerce - Feature #${a.id}: `));
  });

  test('"blocks" prevents closing the blocked issue until the blocker is closed', async ({ loginAs, api }) => {
    const { page, ui, http } = await loginAs('jsmith');
    const developer = await loginAs('dlopper');
    const jsmith = await api('jsmith');
    const prefix = uid('Migration ');
    const blocker = await jsmith.createIssue({ project_id: 1, tracker_id: 1, subject: `${prefix} backup`, assigned_to_id: 6 });
    const blocked = await jsmith.createIssue({ project_id: 1, tracker_id: 1, subject: `${prefix} switch over` });

    await page.goto(`/issues/${blocked.id}`);
    await addRelation(page, 'blocked', blocker.id);
    await expect(page.locator('#relations table.list tr.issue', { hasText: `${prefix} backup` })).toContainText('Blocked by');
    expect(sql('SELECT issue_from_id, issue_to_id, relation_type FROM issue_relations WHERE issue_to_id = ?', [blocked.id])).toEqual([
      { issue_from_id: blocker.id, issue_to_id: blocked.id, relation_type: 'blocks' },
    ]);

    // the blocked issue can progress but not be closed (the page must be reloaded to get the new statuses)
    await page.reload();
    await openEdit(page);
    expect(await statusOptions(page)).toEqual(['New', 'In Progress', 'Resolved', 'Feedback']);
    await http.post(`/issues/${blocked.id}`, { 'issue[status_id]': '5' }, 'patch'); // forged: ignored
    expect(sqlValue('SELECT status_id FROM issues WHERE id = ?', [blocked.id])).toBe(1);
    // neither from the list context menu: the closed statuses are disabled
    await page.goto('/projects/ecommerce/issues?set_filter=1&status_id=o&sort=id:desc');
    await ui.openContextMenu(ui.issueRow(blocked.id));
    const statusFolder = page.locator('#context-menu li.folder').filter({ has: page.locator('> a', { hasText: 'Status' }) });
    await statusFolder.hover();
    await expect(statusFolder.locator('ul a', { hasText: 'In Progress' })).not.toHaveClass(/disabled/);
    await expect(statusFolder.locator('ul a:not(.disabled)', { hasText: /^Closed$/ })).toHaveCount(0);

    // the developer closes the blocker
    await developer.page.goto(`/issues/${blocker.id}`);
    await expect(developer.page.locator('#relations table.list tr.issue', { hasText: `${prefix} switch over` })).toContainText('Blocks');
    await openEdit(developer.page);
    await selectStatus(developer.page, 'Closed');
    await submitIssue(developer.page);
    await expect(developer.ui.flashNotice()).toHaveText('Successful update.');

    await page.goto(`/issues/${blocked.id}`);
    // the relation row shows the blocker as closed
    await expect(page.locator('#relations table.list tr.issue', { hasText: `${prefix} backup` })).toHaveClass(/closed/);
    await openEdit(page);
    expect(await statusOptions(page)).toEqual(['New', 'In Progress', 'Resolved', 'Feedback', 'Closed', 'Rejected']);
    await selectStatus(page, 'Closed');
    await submitIssue(page);
    await expect(ui.flashNotice()).toHaveText('Successful update.');
    await expect(attribute(page, 'status')).toHaveText('Closed');
  });

  test('duplicates are closed with the original when the setting says so, and left alone otherwise', async ({ loginAs, api, mailbox }) => {
    const { page, ui } = await loginAs('jsmith');
    const jsmith = await api('jsmith');
    const prefix = uid('Login loop ');
    const original = await jsmith.createIssue({ project_id: 1, tracker_id: 1, subject: `${prefix} original` });
    // reported again by the reporter (assigned to the developer, who is notified of the automatic close)
    const duplicate = await (await api('rhill')).createIssue({ project_id: 1, tracker_id: 1, subject: `${prefix} again`, assigned_to_id: 6 });

    await test.step('"Close duplicate issues" on (default)', async () => {
      await page.goto(`/issues/${duplicate.id}`);
      await addRelation(page, 'duplicates', original.id);
      await expect(page.locator('#relations table.list tr.issue', { hasText: `${prefix} original` })).toContainText('Is duplicate of');
      await page.goto(`/issues/${original.id}`);
      await expect(page.locator('#relations table.list tr.issue', { hasText: `${prefix} again` })).toContainText('Has duplicate');

      await openEdit(page);
      await selectStatus(page, 'Closed');
      await page.locator('#issue_notes').fill('Fixed by the session patch');
      await submitIssue(page);
      await expect(ui.flashNotice()).toHaveText('Successful update.');
      expect(sql('SELECT id, status_id FROM issues WHERE id IN (?, ?) ORDER BY id', [original.id, duplicate.id])).toEqual([
        { id: original.id, status_id: 5 },
        { id: duplicate.id, status_id: 5 },
      ]);
      // the duplicate got its own journal, by the user who closed the original
      await page.goto(`/issues/${duplicate.id}`);
      await expect(attribute(page, 'status')).toHaveText('Closed');
      const closing = journal(page, 'Status changed from New to Closed');
      await expect(closing).toContainText('Updated by John Smith');
      await expect(closing).toContainText('Fixed by the session patch'); // same notes as on the original
      const mail = await mailbox.waitFor({ to: 'dlopper@example.net', subject: new RegExp(`#${duplicate.id}\\]`), body: 'Status changed from New to Closed' });
      expect(mail.subject).toBe(`[eCommerce - Bug #${duplicate.id}] (Closed) ${prefix} again`);

      // the other way round nothing propagates: reopening the duplicate leaves the original closed
      await openEdit(page);
      await selectStatus(page, 'New');
      await submitIssue(page);
      await expect(ui.flashNotice()).toHaveText('Successful update.');
      expect(sqlValue('SELECT status_id FROM issues WHERE id = ?', [original.id])).toBe(5);
    });

    await test.step('"Close duplicate issues" off', async () => {
      setSetting('close_duplicate_issues', '0');
      const original2 = await jsmith.createIssue({ project_id: 1, tracker_id: 1, subject: `${prefix} original 2` });
      const duplicate2 = await jsmith.createIssue({ project_id: 1, tracker_id: 1, subject: `${prefix} again 2` });
      await page.goto(`/issues/${original2.id}`);
      await addRelation(page, 'duplicated', duplicate2.id); // "Has duplicate", from the original's side
      expect(sql('SELECT issue_from_id, issue_to_id, relation_type FROM issue_relations WHERE issue_to_id = ?', [original2.id])).toEqual([
        { issue_from_id: duplicate2.id, issue_to_id: original2.id, relation_type: 'duplicates' },
      ]);
      await page.reload();
      await openEdit(page);
      await selectStatus(page, 'Closed');
      await submitIssue(page);
      await expect(ui.flashNotice()).toHaveText('Successful update.');
      expect(sql('SELECT id, status_id FROM issues WHERE id IN (?, ?) ORDER BY id', [original2.id, duplicate2.id])).toEqual([
        { id: original2.id, status_id: 5 },
        { id: duplicate2.id, status_id: 1 },
      ]);
    });
  });
});
