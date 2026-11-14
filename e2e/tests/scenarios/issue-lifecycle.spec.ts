import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import { attribute, issueIdFromUrl, journal, openEdit, selectStatus, statusOptions, submitIssue } from './_helpers';

/*
 * One bug, from the report to the close, seen by the three roles of eCommerce:
 *   rhill   Reporter  (files it, watches it, comments; no private notes, no "edit issues": notes only)
 *   jsmith  Manager   (triages; every transition)
 *   dlopper Developer (works on it; never "-> Rejected"; due date required for Bug / In Progress)
 * Every seeded user has the notification option "only for things I watch or I am assigned to", does not get
 * mails about his own changes, and auto-watches the issues he creates or contributes to when his role may add
 * watchers (Redmine defaults).
 */
test.describe('issue lifecycle across roles', () => {
  test('reporter files a bug, manager triages, developer resolves, reporter confirms, manager closes', async ({ loginAs, mailbox, api }) => {
    test.setTimeout(180_000);
    const subject = uid('Checkout crashes ');
    const privateNote = uid('VIP customer, keep it quiet ');
    const fixNote = uid('Fixed the null total ');
    const confirmNote = uid('Works for me now ');
    const closeNote = uid('Released, closing ');
    let id = 0;

    const reporter = await loginAs('rhill');
    const manager = await loginAs('jsmith');
    const developer = await loginAs('dlopper');

    await test.step('the reporter files the bug with an attachment and a mention', async () => {
      const { page, ui } = reporter;
      await page.goto('/projects/ecommerce/issues/new');
      await expect(page.locator('#issue_tracker_id')).toHaveValue('1');
      // a reporter can neither make the issue private nor pick watchers
      await expect(page.locator('#issue_is_private')).toHaveCount(0);
      await page.locator('#issue_subject').fill(subject);
      await page.locator('#issue_description').fill('The checkout page crashes with an empty cart.\n\n@jsmith could you triage this?');
      await ui.attachFile({ name: 'stacktrace.log', buffer: 'TypeError: total is null\n' }, page.locator('#issue-form'));
      await submitIssue(page);

      await expect(ui.flashNotice()).toContainText(/Issue #\d+ created\./);
      id = issueIdFromUrl(page);
      await expect(page.locator('#content h2')).toHaveText(`Bug #${id}`);
      await expect(attribute(page, 'status')).toHaveText('New');
      await expect(attribute(page, 'priority')).toHaveText('Normal');
      await expect(attribute(page, 'assigned-to')).toHaveText('-');
      await expect(page.locator('.issue.details p.author')).toContainText('Added by Robert Hill');
      await expect(page.locator('.attachments a', { hasText: 'stacktrace.log' }).first()).toBeVisible();
      // the mention is a link to the user
      const mention = page.locator('#issue_description_wiki a.user-mention');
      await expect(mention).toHaveText('@John Smith');
      await expect(mention).toHaveAttribute('href', '/users/5');

      // the mentioned manager is notified although he only gets "assigned / watched" things;
      // the developer (neither assigned nor watching) is not
      const mail = await mailbox.waitFor({ to: 'jsmith@example.net', subject: new RegExp(`Bug #${id}`) });
      expect(mail.subject).toBe(`[eCommerce - Bug #${id}] (New) ${subject}`);
      expect(mail.text).toContain('The checkout page crashes with an empty cart.');
      expect(mailbox.find({ to: 'dlopper@example.net', subject: new RegExp(`#${id}\\]`) })).toHaveLength(0);

      // a reporter may not manage watchers ("add watchers" permission), so he is not added automatically as the
      // author either (Issue#add_auto_watcher): he follows his issue with the "Watch" link
      const watch = page.locator(`#content > .contextual a.issue-${id}-watcher`).first();
      await expect(watch).toHaveText(/^\s*Watch\s*$/);
      await watch.click();
      await expect(watch).toHaveText(/Unwatch/);
      await expect.poll(() => sql("SELECT user_id FROM watchers WHERE watchable_type = 'Issue' AND watchable_id = ?", [id])).toEqual([{ user_id: 7 }]);
    });

    await test.step('the manager triages it: assignee, version, category, priority and a private note', async () => {
      const { page, ui } = manager;
      await page.goto('/projects/ecommerce/issues?set_filter=1&status_id=o&sort=id:desc');
      await ui.issueRow(id).locator('td.subject a').click();
      await expect(page.locator('#content h2')).toHaveText(`Bug #${id}`);
      await openEdit(page);
      expect(await statusOptions(page)).toEqual(['New', 'In Progress', 'Resolved', 'Feedback', 'Closed', 'Rejected']);
      await page.locator('#issue_assigned_to_id').selectOption('6'); // Dave Lopper
      await page.locator('#issue_fixed_version_id').selectOption({ label: '1.0' });
      await page.locator('#issue_category_id').selectOption({ label: 'Backend' });
      await page.locator('#issue_priority_id').selectOption({ label: 'Urgent' });
      await page.locator('#issue_notes').fill(privateNote);
      await page.locator('#issue_private_notes').check();
      await submitIssue(page);

      await expect(ui.flashNotice()).toHaveText('Successful update.');
      await expect(attribute(page, 'assigned-to')).toContainText('Dave Lopper');
      await expect(attribute(page, 'fixed-version')).toHaveText('1.0');
      await expect(attribute(page, 'category')).toHaveText('Backend');
      await expect(attribute(page, 'priority')).toHaveText('Urgent');

      // like Redmine (Journal#split_private_notes): the property changes are public, the note is a private journal
      const changes = journal(page, 'Assignee set to Dave Lopper');
      await expect(changes).toHaveCount(1);
      await expect(changes).not.toHaveClass(/private-notes/);
      await expect(changes.locator('.journal-details li')).toHaveCount(4);
      for (const detail of ['Priority changed from Normal to Urgent', 'Assignee set to Dave Lopper', 'Category set to Backend', 'Target version set to 1.0']) {
        await expect(changes.locator('.journal-details li', { hasText: detail })).toHaveCount(1);
      }
      const secret = journal(page, privateNote);
      await expect(secret).toHaveClass(/private-notes/);
      await expect(secret.locator('.badge-private')).toBeVisible();
      await expect(secret.locator('.journal-details')).toHaveCount(0);
      expect(
        sql('SELECT private_notes, (SELECT COUNT(*) FROM journal_details d WHERE d.journal_id = j.id) AS details FROM journals j WHERE journalized_id = ? ORDER BY id', [id]),
      ).toEqual([
        { private_notes: 0, details: 4 },
        { private_notes: 1, details: 0 },
      ]);

      // new assignee: gets the changes and (Developer: "view private notes") the private note
      const toAssignee = await mailbox.waitFor({ to: 'dlopper@example.net', body: 'Assignee set to Dave Lopper' });
      expect(toAssignee.subject).toBe(`[eCommerce - Bug #${id}] ${subject}`);
      await mailbox.waitFor({ to: 'dlopper@example.net', body: privateNote });
      // watcher (the reporter): the changes only
      const toWatcher = await mailbox.waitFor({ to: 'rhill@example.net', body: 'Assignee set to Dave Lopper' });
      expect(toWatcher.text).toContain('Priority changed from Normal to Urgent');
      expect(toWatcher.text + toWatcher.html).not.toContain(privateNote);
    });

    await test.step('the developer finds it on My page and starts working (workflow rules apply)', async () => {
      const { page, ui, http } = developer;
      await page.goto('/my/page');
      await page.locator('#block-issuesassignedtome td.subject a', { hasText: subject }).click();
      await expect(page.locator('#content h2')).toHaveText(`Bug #${id}`);
      // Developer: the private note is visible
      await expect(journal(page, privateNote)).toHaveClass(/private-notes/);

      await openEdit(page);
      // never "Rejected" for a developer
      expect(await statusOptions(page)).toEqual(['New', 'In Progress', 'Resolved', 'Feedback', 'Closed']);
      await selectStatus(page, 'In Progress');
      // Developer / Bug / In Progress: the due date is required
      await expect(page.locator('label[for=issue_due_date] .required')).toBeVisible();
      await page.locator('#issue_notes').fill('Reproduced, working on it');
      await submitIssue(page);
      await expect(ui.errors()).toContainText('Due date cannot be blank');
      expect(sqlValue('SELECT status_id FROM issues WHERE id = ?', [id])).toBe(1);
      // the form keeps what was typed
      await expect(page.locator('#issue_notes')).toHaveValue('Reproduced, working on it');
      await expect(page.locator('#issue_status_id option:checked')).toHaveText('In Progress');

      await page.locator('#issue_start_date').fill('2026-11-16');
      await page.locator('#issue_due_date').fill('2026-11-20');
      await page.locator('#time_entry_hours').fill('1:30');
      await page.locator('#time_entry_activity_id').selectOption({ label: 'Development' });
      await page.locator('#time_entry_comments').fill('Reproducing the crash');
      await submitIssue(page);

      await expect(ui.flashNotice()).toHaveText('Successful update.');
      await expect(attribute(page, 'status')).toHaveText('In Progress');
      await expect(attribute(page, 'due-date')).toContainText('11/20/2026');
      await expect(attribute(page, 'spent-time')).toContainText('1:30 h');
      await expect(journal(page, 'Reproduced, working on it').locator('.journal-details')).toContainText('Status changed from New to In Progress');
      expect(sql('SELECT user_id, hours, activity_id, project_id FROM time_entries WHERE issue_id = ?', [id])).toEqual([
        { user_id: 6, hours: 1.5, activity_id: 9, project_id: 1 },
      ]);

      // a forged request for a transition the workflow forbids (-> Rejected) does not change the status
      // (like Redmine: the attribute is silently ignored)
      const forged = await http.post(`/issues/${id}`, { 'issue[status_id]': '6' }, 'patch');
      expect(forged.status()).toBeLessThan(500);
      expect(sqlValue('SELECT status_id FROM issues WHERE id = ?', [id])).toBe(2);
      expect(Number(sqlValue('SELECT COUNT(*) FROM journals WHERE journalized_id = ?', [id]))).toBe(3);

      // done: resolved with a public note
      await page.goto(`/issues/${id}`);
      await openEdit(page);
      expect(await statusOptions(page)).toEqual(['In Progress', 'Resolved', 'Feedback', 'Closed']);
      await selectStatus(page, 'Resolved');
      await expect(page.locator('label[for=issue_due_date] .required')).toHaveCount(0);
      await page.locator('#issue_done_ratio').selectOption('100');
      await page.locator('#issue_notes').fill(fixNote);
      await submitIssue(page);
      await expect(ui.flashNotice()).toHaveText('Successful update.');
      await expect(attribute(page, 'status')).toHaveText('Resolved');
      await expect(attribute(page, 'progress')).toContainText('100%');

      // the watching reporter is told about the status changes, with the status in the subject
      const resolved = await mailbox.waitFor({ to: 'rhill@example.net', body: fixNote });
      expect(resolved.subject).toBe(`[eCommerce - Bug #${id}] (Resolved) ${subject}`);
      expect(resolved.text).toContain('Status changed from In Progress to Resolved');
    });

    await test.step('the reporter sees the public history only and confirms the fix', async () => {
      const { page, ui } = reporter;
      await page.reload();
      await expect(attribute(page, 'status')).toHaveText('Resolved');
      await expect(attribute(page, 'assigned-to')).toContainText('Dave Lopper');
      await expect(journal(page, fixNote)).toBeVisible();
      await expect(journal(page, 'Assignee set to Dave Lopper')).toBeVisible();
      // Reporter: no "view private notes"
      await expect(page.locator('#history')).not.toContainText(privateNote);
      await expect(page.locator('#history .journal.private-notes')).toHaveCount(0);

      // same through the REST API, the Atom feed of the issue and the project activity
      const asReporter = await (await api('rhill')).issue(id, 'journals');
      const notes = (asReporter.journals as Array<{ notes: string }>).map((j) => j.notes);
      expect(notes).toContain(fixNote);
      expect(notes).not.toContain(privateNote);
      const asDeveloper = await (await api('dlopper')).issue(id, 'journals');
      expect((asDeveloper.journals as Array<{ notes: string; private_notes: boolean }>).find((j) => j.notes === privateNote)?.private_notes).toBe(true);
      const atom = await (await page.request.get(`/issues/${id}.atom`)).text();
      expect(atom).toContain(fixNote);
      expect(atom).not.toContain(privateNote);
      await page.goto('/projects/ecommerce/activity');
      await expect(page.locator('#activity dt', { hasText: subject }).first()).toBeVisible();
      await expect(page.locator('#activity')).toContainText(fixNote);
      await expect(page.locator('#activity')).not.toContainText(privateNote);

      // Reporter: "add notes" without "edit issues" -> the update form only offers the notes (like Redmine)
      await page.goto(`/issues/${id}`);
      await page.locator('#content > .contextual a.icon-edit').first().click();
      await expect(page.locator('#issue_notes')).toBeVisible();
      await expect(page.locator('#issue-form #all_attributes')).toHaveCount(0);
      await expect(page.locator('#issue_status_id')).toHaveCount(0);
      await expect(page.locator('#issue_private_notes')).toHaveCount(0);
      await page.locator('#issue_notes').fill(confirmNote);
      await submitIssue(page);
      await expect(ui.flashNotice()).toHaveText('Successful update.');
      await expect(journal(page, confirmNote)).toContainText('Updated by Robert Hill');
      // a forged close is ignored: the status is not an attribute he may change
      const forged = await reporter.http.post(`/issues/${id}`, { 'issue[status_id]': '5' }, 'patch');
      expect(forged.status()).toBeLessThan(500);
      expect(sqlValue('SELECT status_id FROM issues WHERE id = ?', [id])).toBe(3);

      // the assignee and the watching manager are told; no status change, so no status in the subject
      const toAssignee = await mailbox.waitFor({ to: 'dlopper@example.net', body: confirmNote });
      expect(toAssignee.subject).toBe(`[eCommerce - Bug #${id}] ${subject}`);
      await mailbox.waitFor({ to: 'jsmith@example.net', body: confirmNote });
    });

    await test.step('the manager closes it; lists, history and the mails each actor received', async () => {
      const { page, ui } = manager;
      await page.goto(`/issues/${id}`);
      await openEdit(page);
      await selectStatus(page, 'Closed');
      await page.locator('#issue_notes').fill(closeNote);
      await submitIssue(page);
      await expect(ui.flashNotice()).toHaveText('Successful update.');
      await expect(attribute(page, 'status')).toHaveText('Closed');
      await expect(page.locator('#content .badge-status-closed')).toBeVisible();
      expect(sqlValue('SELECT closed_on IS NOT NULL FROM issues WHERE id = ?', [id])).toBe(1);

      await expect(page.locator('#history .journal')).toHaveCount(6);
      await expect(page.locator('#history .journal .journal-link')).toHaveText(['#1', '#2', '#3', '#4', '#5', '#6']);
      // the manager was added to the watchers by his triage (preference "auto watch: issues I contributed to",
      // needs the "add watchers" permission, which the developer does not have)
      await expect(page.locator('#watchers h3')).toContainText('Watchers (2)');
      await expect(page.locator('#watchers ul.watchers li')).toHaveText([/John Smith/, /Robert Hill/]); // sorted by name
      // "Spent time" tab of the history lists the developer's entry
      await page.locator('#tab-time_entries').click();
      await expect(page.locator('#tab-content-time_entries')).toContainText('Reproducing the crash');

      // closed: gone from the default (open) list, listed with the closed ones
      await page.goto('/projects/ecommerce/issues?set_filter=1&status_id=o');
      await expect(ui.issueRow(13)).toBeVisible();
      await expect(ui.issueRow(id)).toHaveCount(0);
      await page.goto('/projects/ecommerce/issues?set_filter=1&status_id=c');
      await expect(ui.issueRow(id).locator('td.status')).toHaveText('Closed');

      // reporter (watcher) and developer (assignee) are told about the close
      const closed = await mailbox.waitFor({ to: 'rhill@example.net', body: closeNote });
      expect(closed.subject).toBe(`[eCommerce - Bug #${id}] (Closed) ${subject}`);
      await mailbox.waitFor({ to: 'dlopper@example.net', body: closeNote });

      // nothing the reporter received ever contained the private note
      const toReporter = mailbox.find({ to: 'rhill@example.net', subject: new RegExp(`#${id}\\]`) });
      expect(toReporter.map((m) => m.subject)).toEqual([
        `[eCommerce - Bug #${id}] ${subject}`, // triage (no status change)
        `[eCommerce - Bug #${id}] (In Progress) ${subject}`,
        `[eCommerce - Bug #${id}] (Resolved) ${subject}`,
        `[eCommerce - Bug #${id}] (Closed) ${subject}`,
      ]);
      for (const m of toReporter) expect(m.text + m.html, m.subject).not.toContain(privateNote);
      // the manager: the creation (mention), then - watching since his triage - the others' updates, never his own
      const toManager = mailbox.find({ to: 'jsmith@example.net', subject: new RegExp(`#${id}\\]`) });
      expect(toManager.map((m) => m.subject)).toEqual([
        `[eCommerce - Bug #${id}] (New) ${subject}`,
        `[eCommerce - Bug #${id}] (In Progress) ${subject}`,
        `[eCommerce - Bug #${id}] (Resolved) ${subject}`,
        `[eCommerce - Bug #${id}] ${subject}`,
      ]);
    });
  });
  // Redmine does not touch the record (no dirty attribute, an empty journal is not saved): nobody who has the edit
  // form open gets an "updated by an other user" conflict for a change that does not exist.
  test('an update that changes nothing leaves the issue untouched and causes no edit conflict', async ({ loginAs }) => {
    const before = sql('SELECT lock_version, updated_on FROM issues WHERE id = 16');
    const manager = await loginAs('jsmith');
    await manager.page.goto('/issues/16');
    await openEdit(manager.page);
    await manager.page.locator('#issue_notes').fill('My real change');

    // meanwhile somebody opens the form and submits it without touching anything
    const developer = await loginAs('dlopper');
    await developer.page.goto('/issues/16');
    await openEdit(developer.page);
    await submitIssue(developer.page);
    await expect(developer.page.locator('#content h2')).toHaveText('Feature #16');
    await expect(developer.page.locator('#history .journal')).toHaveCount(0);
    expect(sql('SELECT lock_version, updated_on FROM issues WHERE id = 16')).toEqual(before);

    await submitIssue(manager.page);
    await expect(manager.page.locator('#issue-form .conflict')).toHaveCount(0);
    await expect(manager.ui.flashNotice()).toHaveText('Successful update.');
  });
});
