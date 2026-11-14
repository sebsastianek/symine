import type { Page } from '@playwright/test';
import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import type { RedmineUI } from '../../support/ui';
import { journal, openEdit, selectAndRefresh } from './_helpers';

/*
 * Bulk edit / move / copy from the issue list: what is offered for a mixed selection, what happens to each issue
 * (journals, mapped attributes, subtasks, time entries, attachments) and what is refused.
 */

const LIST = '/projects/ecommerce/issues?set_filter=1&status_id=o&sort=id:desc&per_page=100';

/** Checks the given rows of the list and opens "Bulk Edit" (or "Copy") from the context menu. */
async function bulk(page: Page, ui: RedmineUI, ids: number[], action: 'edit' | 'copy'): Promise<void> {
  await page.goto(LIST);
  for (const id of ids) await ui.issueRow(id).locator('td.checkbox input').check();
  for (const id of ids) await expect(ui.issueRow(id)).toHaveClass(/context-menu-selection/);
  await ui.openContextMenu(ui.issueRow(ids[0]));
  await page.locator(action === 'edit' ? '#context-menu a.icon-edit' : '#context-menu a.icon-copy', { hasText: action === 'edit' ? 'Bulk Edit' : 'Copy' }).click();
  await expect(page.locator('#content h2')).toHaveText(action === 'edit' ? 'Bulk edit selected issues' : 'Copy');
  await expect(page.locator('#bulk-selection li')).toHaveCount(ids.length);
}

/** Option labels of a select (indentation of subprojects stripped); polled, since the ajax refresh replaces the form. */
async function expectOptions(page: Page, selector: string, expected: string[]): Promise<void> {
  await expect
    .poll(async () => (await page.locator(`${selector} option`).allTextContents()).map((o) => o.replace(/[\s\xa0»]+/g, ' ').trim()), { message: selector })
    .toEqual(expected);
}

test.describe('bulk edit', () => {
  test('a developer updates a mixed selection: workflow statuses, one issue refused, then fixed', async ({ loginAs, api }) => {
    test.setTimeout(120_000);
    const { page, ui } = await loginAs('dlopper');
    const jsmith = await api('jsmith');
    const prefix = uid('Sprint ');
    const bug = await jsmith.createIssue({ project_id: 1, tracker_id: 1, subject: `${prefix} bug` });
    const feature = await jsmith.createIssue({ project_id: 1, tracker_id: 2, subject: `${prefix} feature` });
    const support = await jsmith.createIssue({ project_id: 1, tracker_id: 3, subject: `${prefix} support`, assigned_to_id: 5 });
    const note = `${prefix} planning`;

    await bulk(page, ui, [support.id, feature.id, bug.id], 'edit');
    // statuses common to the three workflows of a developer: never "Rejected"
    await expectOptions(page, '#issue_status_id', ['(No change)', 'New', 'In Progress', 'Resolved', 'Feedback', 'Closed']);
    // seeded #19 is closed (no transition for a developer): with it in the selection no status can be chosen
    await page.goto(`/issues/bulk_edit?ids[]=${bug.id}&ids[]=19`);
    await expect(page.locator('#bulk-selection li')).toHaveCount(2);
    await expect(page.locator('#issue_priority_id')).toBeVisible();
    await expect(page.locator('#issue_status_id')).toHaveCount(0);

    await bulk(page, ui, [support.id, feature.id, bug.id], 'edit');
    await selectAndRefresh(page, '#issue_status_id', '2'); // In Progress
    await page.locator('#issue_assigned_to_id').selectOption('6'); // << me >>
    await page.locator('#issue_fixed_version_id').selectOption({ label: '1.0' });
    await page.locator('#notes').fill(note);
    await page.locator('#bulk_edit_form input[type=submit][name=commit]').click();

    // Developer / Bug / In Progress requires a due date: the bug is refused, the two others are saved
    await expect(page.locator('#errorExplanation')).toContainText(`Failed to save 1 issue(s) on 3 selected: #${bug.id}.`);
    await expect(page.locator('#errorExplanation li')).toHaveText([`Due date cannot be blank: #${bug.id}`]);
    await expect(page.locator('#bulk-selection li')).toHaveCount(1);
    await expect(page.locator('#bulk-selection li')).toContainText(`#${bug.id}`);
    expect(sql('SELECT id, status_id, assigned_to_id, fixed_version_id FROM issues WHERE id IN (?, ?, ?) ORDER BY id', [bug.id, feature.id, support.id])).toEqual([
      { id: bug.id, status_id: 1, assigned_to_id: null, fixed_version_id: null },
      { id: feature.id, status_id: 2, assigned_to_id: 6, fixed_version_id: 1 },
      { id: support.id, status_id: 2, assigned_to_id: 6, fixed_version_id: 1 },
    ]);
    expect(Number(sqlValue('SELECT COUNT(*) FROM journals WHERE journalized_id = ?', [bug.id]))).toBe(0);

    // the form came back for the refused issue with what was chosen: add the due date and submit again
    await expect(page.locator('#issue_status_id')).toHaveValue('2');
    await expect(page.locator('#notes')).toHaveValue(note);
    await expect(page.locator('#issue_assigned_to_id')).toHaveValue('6');
    await expect(page.locator('#issue_fixed_version_id option:checked')).toHaveText('1.0');
    await page.locator('#issue_due_date').fill('2026-12-18');
    await page.locator('#bulk_edit_form input[type=submit][name=commit]').click();
    await expect(ui.flashNotice()).toHaveText('Successful update.');
    expect(sql('SELECT status_id, assigned_to_id, fixed_version_id, due_date FROM issues WHERE id = ?', [bug.id])).toEqual([
      { status_id: 2, assigned_to_id: 6, fixed_version_id: 1, due_date: '2026-12-18' },
    ]);

    // back on the list: the three rows carry the new values
    await page.goto(LIST);
    for (const id of [bug.id, feature.id, support.id]) {
      await expect(ui.issueRow(id).locator('td.status')).toHaveText('In Progress');
      await expect(ui.issueRow(id).locator('td.assigned_to')).toHaveText('Dave Lopper');
    }
    // each issue has one journal with the note and its own changes
    await page.goto(`/issues/${support.id}`);
    const j = journal(page, note);
    await expect(j).toHaveCount(1);
    await expect(j).toContainText('Updated by Dave Lopper');
    await expect(j.locator('.journal-details')).toContainText('Status changed from New to In Progress');
    await expect(j.locator('.journal-details')).toContainText('Assignee changed from John Smith to Dave Lopper');
    await expect(j.locator('.journal-details')).toContainText('Target version set to 1.0');
    await page.goto(`/issues/${bug.id}`);
    await expect(journal(page, note).locator('.journal-details')).toContainText('Due date set to 12/18/2026');
    await expect(page.locator('#history .journal')).toHaveCount(1);
  });

  // Redmine: principals_options_for_select(@assignables, @issue_params[:assigned_to_id]) and the like: what was chosen
  // survives a refresh of the form (project / tracker / status change) and a failed update.
  test('the bulk edit form keeps the chosen assignee, category and version when it is refreshed', async ({ loginAs }) => {
    const { page } = await loginAs('jsmith');
    await page.goto('/issues/bulk_edit?ids[]=15&ids[]=16');
    await page.locator('#issue_assigned_to_id').selectOption('6');
    await page.locator('#issue_category_id').selectOption('1');
    await page.locator('#issue_fixed_version_id').selectOption('1');
    await page.locator('#issue_priority_id').selectOption('4');
    await selectAndRefresh(page, '#issue_status_id', '2');
    await expect(page.locator('#issue_priority_id')).toHaveValue('4'); // kept (works)
    await expect(page.locator('#issue_assigned_to_id')).toHaveValue('6');
    await expect(page.locator('#issue_category_id')).toHaveValue('1');
    await expect(page.locator('#issue_fixed_version_id')).toHaveValue('1');
  });
});

test.describe('bulk move', () => {
  test('moving issues to the subproject maps tracker, category and version, takes subtasks and spent time along', async ({ loginAs, api }) => {
    test.setTimeout(120_000);
    const manager = await loginAs('jsmith');
    const developer = await loginAs('dlopper');
    const { page, ui } = manager;
    const jsmith = await api('jsmith');
    const prefix = uid('Move ');
    // target project "Checkout" (public subproject, trackers Bug + Feature, no category): give it a category "UI"
    const { issue_category: targetUi } = await jsmith.post('/projects/checkout/issue_categories.json', { issue_category: { name: 'UI' } });
    const parent = await jsmith.createIssue({ project_id: 1, tracker_id: 2, subject: `${prefix} parent`, category_id: 2, fixed_version_id: 1, assigned_to_id: 6 });
    const child = await jsmith.createIssue({ project_id: 1, tracker_id: 2, subject: `${prefix} child`, parent_issue_id: parent.id });
    const support = await jsmith.createIssue({ project_id: 1, tracker_id: 3, subject: `${prefix} support`, category_id: 1, fixed_version_id: 1 });
    await (await api('dlopper')).logTime({ issue_id: child.id, hours: 2, activity_id: 9, comments: `${prefix} work` });

    await test.step('before: the developer has the full edit form in eCommerce', async () => {
      await developer.page.goto(`/issues/${support.id}`);
      await openEdit(developer.page);
      await expect(developer.page.locator('#issue-form #all_attributes')).toBeVisible();
    });

    await test.step('the manager moves the parent and the support request to Checkout', async () => {
      await bulk(page, ui, [support.id, parent.id], 'edit');
      // only the projects where he may add issues (not the archived one)
      await expectOptions(page, '#issue_project_id', ['(No change)', 'Internal Tools', 'eCommerce', 'Checkout']);
      await expectOptions(page, '#issue_tracker_id', ['(No change)', 'Bug', 'Feature', 'Support']);
      await selectAndRefresh(page, '#issue_project_id', '3');
      // the form now offers what the target project has
      await expectOptions(page, '#issue_tracker_id', ['(No change)', 'Bug', 'Feature']);
      await expectOptions(page, '#issue_category_id', ['(No change)', 'none', 'UI']);
      await expectOptions(page, '#issue_fixed_version_id', ['(No change)', 'none', '1.0']);
      await page.locator('#notes').fill(`${prefix} belongs to checkout`);
      await page.locator('#bulk_edit_form input[type=submit][name=commit]').click();
      await expect(ui.flashNotice()).toHaveText('Successful update.');
    });

    await test.step('each issue was adapted to the target project', async () => {
      expect(
        sql('SELECT id, project_id, tracker_id, category_id, fixed_version_id, parent_id FROM issues WHERE id IN (?, ?, ?) ORDER BY id', [parent.id, child.id, support.id]),
      ).toEqual([
        // Backend does not exist in Checkout: category dropped; version 1.0 is shared with subprojects: kept
        { id: parent.id, project_id: 3, tracker_id: 2, category_id: null, fixed_version_id: 1, parent_id: null },
        // the subtask followed its parent
        { id: child.id, project_id: 3, tracker_id: 2, category_id: null, fixed_version_id: null, parent_id: parent.id },
        // Support is not a tracker of Checkout: first tracker of the project; the category of the same name is used
        { id: support.id, project_id: 3, tracker_id: 1, category_id: targetUi.id, fixed_version_id: 1, parent_id: null },
      ]);
      // spent time follows the issue
      expect(sql('SELECT project_id, issue_id, hours FROM time_entries WHERE comments = ?', [`${prefix} work`])).toEqual([
        { project_id: 3, issue_id: child.id, hours: 2 },
      ]);

      await page.goto(`/issues/${support.id}`);
      await expect(page.locator('#header h1')).toContainText('Checkout');
      await expect(page.locator('#content h2')).toHaveText(`Bug #${support.id}`);
      const moved = journal(page, `${prefix} belongs to checkout`);
      await expect(moved.locator('.journal-details')).toContainText('Project changed from eCommerce to Checkout');
      await expect(moved.locator('.journal-details')).toContainText('Tracker changed from Support to Bug');
      await page.goto(`/issues/${parent.id}`);
      await expect(journal(page, `${prefix} belongs to checkout`).locator('.journal-details')).toContainText('Category deleted (Backend)');
      await expect(page.locator(`#issue_tree tr#issue-${child.id}`)).toBeVisible();

      // listed in Checkout only; the eCommerce list still shows them as issues of the subproject
      await page.goto('/projects/checkout/issues?set_filter=1&status_id=o&sort=id:desc');
      for (const id of [parent.id, child.id, support.id]) await expect(ui.issueRow(id)).toBeVisible();
      await page.goto('/projects/ecommerce/issues?set_filter=1&status_id=o&subproject_id=!*&sort=id:desc');
      for (const id of [parent.id, child.id, support.id]) await expect(ui.issueRow(id)).toHaveCount(0);
      await expect(ui.issueRow(14)).toBeVisible();
      // the time report of Checkout counts the hours
      await page.goto('/projects/checkout/time_entries?set_filter=1');
      await expect(page.locator('table.time-entries tr.time-entry', { hasText: `${prefix} work` })).toBeVisible();
    });

    await test.step('after: the developer is not a member of Checkout and can only comment there', async () => {
      await developer.page.reload();
      await expect(developer.page.locator('#header h1')).toContainText('Checkout');
      await developer.page.locator('#content > .contextual a.icon-edit').first().click();
      await expect(developer.page.locator('#issue_notes')).toBeVisible();
      await expect(developer.page.locator('#issue-form #all_attributes')).toHaveCount(0);
      // and he cannot move it back: he has no bulk edit on it
      const res = await developer.http.post('/issues/bulk_update', { 'ids[]': support.id, 'issue[project_id]': 1 });
      expect(res.status()).toBe(403);
      expect(sqlValue('SELECT project_id FROM issues WHERE id = ?', [support.id])).toBe(3);
    });
  });
});

test.describe('bulk copy', () => {
  test('copy to another project with subtasks, attachments and links; then a bare copy', async ({ loginAs }) => {
    test.setTimeout(120_000);
    const { page, ui } = await loginAs('jsmith');
    const internalIssues = () => sql('SELECT id, subject, tracker_id, status_id, parent_id, author_id, assigned_to_id, fixed_version_id, category_id FROM issues WHERE project_id = 2 AND id > 19 ORDER BY id');
    const attachmentsOf = (id: number) => sql("SELECT filename, filesize FROM attachments WHERE container_type = 'Issue' AND container_id = ?", [id]);

    await test.step('copy #13 (attachment, group assignee, version) and #14 (two subtasks) to Internal Tools', async () => {
      await bulk(page, ui, [14, 13], 'copy');
      for (const box of ['#link_copy', '#copy_attachments', '#copy_subtasks']) await expect(page.locator(box)).toBeChecked();
      await selectAndRefresh(page, '#issue_project_id', '2');
      await page.locator('#notes').fill('Copied for the internal rollout');
      await page.locator('#bulk_edit_form input[type=submit][name=commit]').click();
      await expect(ui.flashNotice()).toHaveText('Successful update.');

      const copies = internalIssues();
      expect(copies.map((c) => c.subject).sort()).toEqual(['Cart total is wrong with discounts', 'Wishlist', 'Wishlist emails', 'Wishlist sharing']);
      const by = (subject: string) => copies.find((c) => c.subject === subject)!;
      const cart = by('Cart total is wrong with discounts');
      const wishlist = by('Wishlist');
      // copies start in the default status, are authored by the copier and lose what the target project does not have
      // (the Developers group is not a member of Internal Tools, version 1.0 and category UI are not available there)
      expect(cart).toMatchObject({ tracker_id: 1, status_id: 1, author_id: 5, assigned_to_id: null, fixed_version_id: null, category_id: null, parent_id: null });
      expect(by('Wishlist sharing').parent_id).toBe(wishlist.id);
      expect(by('Wishlist emails').parent_id).toBe(wishlist.id);
      // the originals are untouched
      expect(sql('SELECT project_id, status_id, assigned_to_id, parent_id FROM issues WHERE id IN (13, 15) ORDER BY id')).toEqual([
        { project_id: 1, status_id: 2, assigned_to_id: 10, parent_id: null },
        { project_id: 1, status_id: 1, assigned_to_id: null, parent_id: 14 },
      ]);
      // the attachment was copied (same file, new record)
      expect(attachmentsOf(Number(cart.id))).toEqual(attachmentsOf(13));
      expect(attachmentsOf(13)).toHaveLength(1);
      // custom field values came along
      expect(sqlValue("SELECT value FROM custom_values WHERE customized_type = 'Issue' AND customized_id = ? AND custom_field_id = 1", [cart.id])).toBe('PostgreSQL');

      await page.goto(`/issues/${cart.id}`);
      await expect(page.locator('#header h1')).toContainText('Internal Tools');
      await expect(page.locator('#relations table.list tr.issue', { hasText: '#13' })).toContainText('Copied from');
      await expect(page.locator('.attachments a', { hasText: 'notes.txt' }).first()).toBeVisible();
      const res = await page.request.get(await page.locator('.attachments a.icon-download').first().getAttribute('href') as string);
      expect(res.status()).toBe(200);
      await page.goto(`/issues/${wishlist.id}`);
      await expect(page.locator('#issue_tree table.list tr.issue')).toHaveCount(2);
      await expect(page.locator('#relations table.list tr.issue', { hasText: '#14' })).toContainText('Copied from');
      // the original links to its copy
      await page.goto('/issues/13');
      await expect(page.locator('#relations table.list tr.issue', { hasText: `#${cart.id}` })).toContainText('Copied to');
    });

    await test.step('a second copy without subtasks, attachments and link creates bare issues', async () => {
      const before = internalIssues().length;
      await bulk(page, ui, [14, 13], 'copy');
      await selectAndRefresh(page, '#issue_project_id', '2');
      await selectAndRefresh(page, '#issue_tracker_id', '1'); // everything becomes a Bug
      await expectOptions(page, '#issue_tracker_id', ['(No change)', 'Bug', 'Feature']);
      await expect(page.locator('#issue_project_id')).toHaveValue('2');
      // (unchecked last: the ajax refreshes of the form re-render these boxes)
      await page.locator('#link_copy').uncheck();
      await page.locator('#copy_attachments').uncheck();
      await page.locator('#copy_subtasks').uncheck();
      await page.locator('#bulk_edit_form input[type=submit][name=commit]').click();
      await expect(ui.flashNotice()).toHaveText('Successful update.');

      const created = internalIssues().slice(before);
      expect(created.map((c) => c.subject).sort()).toEqual(['Cart total is wrong with discounts', 'Wishlist']);
      for (const c of created) {
        expect(c.tracker_id).toBe(1);
        expect(attachmentsOf(Number(c.id))).toEqual([]);
        expect(Number(sqlValue('SELECT COUNT(*) FROM issue_relations WHERE issue_from_id = ? OR issue_to_id = ?', [c.id, c.id]))).toBe(0);
        expect(Number(sqlValue('SELECT COUNT(*) FROM issues WHERE parent_id = ?', [c.id]))).toBe(0);
      }
    });

    await test.step('a reporter has no copy permission: no "Copy" in the context menu, forged request refused', async () => {
      const reporter = await loginAs('rhill');
      await reporter.page.goto(LIST);
      await reporter.ui.openContextMenu(reporter.ui.issueRow(14));
      await expect(reporter.page.locator('#context-menu a.icon-copy:not(.disabled)')).toHaveCount(0);
      const before = Number(sqlValue('SELECT COUNT(*) FROM issues'));
      const res = await reporter.http.post('/issues/bulk_update', { 'ids[]': 14, copy: 1, 'issue[project_id]': 1 });
      expect(res.status()).toBe(403);
      expect(Number(sqlValue('SELECT COUNT(*) FROM issues'))).toBe(before);
    });
  });
});
