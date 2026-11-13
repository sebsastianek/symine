import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import { attribute, openEdit, submitEdit } from './_helpers';

test.describe('issue update with workflow', () => {
  test('a developer only gets the statuses allowed by the workflow and the required due date', async ({ loginAs }) => {
    const { page, ui } = await loginAs('dlopper');
    await page.goto('/issues/13'); // Bug, In Progress
    await openEdit(page);

    // Developer / Bug / In Progress -> Resolved, Feedback, Closed (not New, not Rejected)
    const options = await page.locator('#issue_status_id option').allTextContents();
    expect(options.map((o) => o.trim())).toEqual(['In Progress', 'Resolved', 'Feedback', 'Closed']);
    // due date is required for Developer / Bug / In Progress (field permission)
    await expect(page.locator('label[for=issue_due_date] .required')).toBeVisible();

    await page.locator('#issue_due_date').fill('');
    await page.locator('#issue_notes').fill('Fixed the rounding');
    await submitEdit(page);
    await expect(ui.errors()).toContainText('Due date cannot be blank');
    await expect(page.locator('#issue_notes')).toHaveValue('Fixed the rounding');

    // status change refreshes the form (updateIssueFrom): the rule of the new status applies (not required)
    await page.locator('#issue_status_id').selectOption({ label: 'Resolved' });
    await expect(page.locator('label[for=issue_due_date] .required')).toHaveCount(0);
    await page.locator('#issue_due_date').fill('2026-12-01');
    await page.locator('#time_entry_hours').fill('1:30');
    await page.locator('#time_entry_activity_id').selectOption({ label: 'Development' });
    await page.locator('#time_entry_comments').fill('Debugging');
    await submitEdit(page);

    await expect(ui.flashNotice()).toHaveText('Successful update.');
    await expect(attribute(page, 'status')).toHaveText('Resolved');
    await expect(attribute(page, 'spent-time')).toContainText('4:00 h'); // 2:30 seeded + 1:30
    const lastJournal = page.locator('#history .journal').last();
    await expect(lastJournal.locator('.journal-details')).toContainText('Status changed from In Progress to Resolved');
    await expect(lastJournal.locator('.journal-details')).toContainText('Due date changed from');
    // the untouched multi-line description must not be reported as changed (CRLF round trip)
    await expect(lastJournal.locator('.journal-details')).not.toContainText('Description');
    await expect(lastJournal.locator('.wiki')).toContainText('Fixed the rounding');
    expect(sql("SELECT hours, comments, activity_id FROM time_entries WHERE issue_id = 13 AND comments = 'Debugging'")).toEqual([
      { hours: 1.5, comments: 'Debugging', activity_id: 9 },
    ]);
  });

  test('read-only fields of the workflow are not editable', async ({ loginAs }) => {
    // Reporter / Bug / Resolved: subject is read-only; issue #13 is Resolved after the previous test
    sql('UPDATE issues SET status_id = 3 WHERE id = 13');
    const { page } = await loginAs('rhill');
    await page.goto('/issues/13');
    await page.locator('#content > .contextual a.icon-edit').first().click();
    await expect(page.locator('#issue_notes')).toBeVisible();
    await expect(page.locator('#issue_subject')).toHaveCount(0);
  });

  test('a manager can change to any status (Rejected included)', async ({ loginAs }) => {
    const { page, ui } = await loginAs('jsmith');
    await page.goto('/issues/17'); // checkout, Bug, New; jsmith is Manager
    await openEdit(page);
    const options = (await page.locator('#issue_status_id option').allTextContents()).map((o) => o.trim());
    expect(options).toEqual(['New', 'In Progress', 'Resolved', 'Feedback', 'Closed', 'Rejected']);
    await page.locator('#issue_status_id').selectOption({ label: 'Rejected' });
    await page.locator('#issue_done_ratio').selectOption('100');
    await submitEdit(page);
    await expect(ui.flashNotice()).toHaveText('Successful update.');
    await expect(attribute(page, 'status')).toHaveText('Rejected');
    await expect(page.locator('#content .badge-status-closed')).toBeVisible();
  });
});

test.describe('notes', () => {
  test.use({ as: 'jsmith' });

  test('private notes are only visible to roles allowed to view them', async ({ page, ui, loginAs }) => {
    const text = uid('Secret note ');
    await page.goto('/issues/14');
    await openEdit(page);
    await page.locator('#issue_notes').fill(text);
    await page.locator('#issue_private_notes').check();
    await submitEdit(page);
    await expect(ui.flashNotice()).toHaveText('Successful update.');
    const journal = page.locator('#history .journal', { hasText: text });
    await expect(journal).toHaveClass(/private-notes/);
    await expect(journal.locator('.badge-private')).toBeVisible();

    const reporter = await loginAs('rhill'); // Reporter: no view_private_notes
    await reporter.page.goto('/issues/14');
    await expect(reporter.page.locator('#content h2')).toHaveText('Feature #14');
    await expect(reporter.page.locator('#history')).not.toContainText(text);

    const developer = await loginAs('dlopper'); // Developer: view_private_notes
    await developer.page.goto('/issues/14');
    await expect(developer.page.locator('#history')).toContainText(text);
  });

  test('quote the description and a note into the reply', async ({ page }) => {
    await page.goto('/issues/13');
    await page.locator('.description a.icon-quote').click();
    await expect(page.locator('#update')).toBeVisible();
    await expect(page.locator('#issue_notes')).toHaveValue(/wrote:\n> /);

    await page.locator('#issue_notes').fill('');
    const note = page.locator('#history .journal.has-notes').filter({ hasText: 'Started working on it' });
    await note.locator('a.icon-quote').click();
    await expect(page.locator('#issue_notes')).toHaveValue(/Dave Lopper wrote in #note-\d+:\n> Started working on it/);
  });

  test('edit and delete own notes in place', async ({ page, ui }) => {
    const text = uid('Editable note ');
    await page.goto('/issues/16');
    await openEdit(page);
    await page.locator('#issue_notes').fill(text);
    await submitEdit(page);
    const journal = page.locator('#history .journal', { hasText: text });
    await expect(journal).toBeVisible();

    // created "a while ago" so that the edit time differs (the "Edited" marker compares both timestamps)
    sql("UPDATE journals SET created_on = '2026-01-01 10:00:00', updated_on = '2026-01-01 10:00:00' WHERE notes = ?", [text]);
    await journal.locator('.journal-actions a.icon-edit').click();
    const textarea = journal.locator('textarea[id$=_notes]');
    await expect(textarea).toHaveValue(text);
    await textarea.fill(`${text} (corrected)`);
    await journal.locator('input[type=submit][name=commit]').click();
    await expect(journal.locator('div.journal-note')).toHaveText(`${text} (corrected)`);
    await expect(journal.locator('textarea')).toHaveCount(0);

    // after a reload the note keeps the new text and shows the "Edited" info
    await page.reload();
    const reloaded = page.locator('#history .journal', { hasText: `${text} (corrected)` });
    await expect(reloaded).toBeVisible();
    await expect(reloaded.locator('.update-info')).toBeVisible();

    // delete through the actions dropdown (confirm accepted by the fixture)
    const actions = await ui.openActionsMenu(reloaded.locator('.journal-actions'));
    await actions.locator('a.icon-del').click();
    await expect(page.locator('#history')).not.toContainText(`${text} (corrected)`);
    expect(Number(sqlValue("SELECT COUNT(*) FROM journals WHERE notes LIKE ?", [`${text}%`]))).toBe(0);
  });

  test('preview of the notes renders the text formatting', async ({ page }) => {
    await page.goto('/issues/16');
    await openEdit(page);
    await page.locator('#issue_notes').fill('**bold preview** and #13');
    await page.locator('#add_notes .tab-preview, #add_notes a[data-action*=preview], #add_notes .jstTabs a', { hasText: 'Preview' }).first().click();
    const preview = page.locator('#add_notes .wiki-preview');
    await expect(preview.locator('strong')).toHaveText('bold preview');
    await expect(preview.locator('a.issue')).toContainText('#13');
  });
});

test.describe('lock conflicts', () => {
  test.use({ as: 'jsmith' });

  test('concurrent update shows the conflict and lets the user add his notes', async ({ page, ui, loginAs }) => {
    await page.goto('/issues/14');
    await openEdit(page);
    await page.locator('#issue_subject').fill('Wishlist (edited by jsmith)');
    await page.locator('#issue_notes').fill('jsmith comment');

    // meanwhile dlopper updates the same issue
    const other = await loginAs('dlopper');
    await other.page.goto('/issues/14');
    await openEdit(other.page);
    await other.page.locator('#issue_notes').fill('dlopper was faster');
    await submitEdit(other.page);
    await expect(other.ui.flashNotice()).toHaveText('Successful update.');

    await submitEdit(page);
    await expect(page.locator('#issue-form .conflict')).toContainText('updated by an other user while you were editing it');
    await expect(page.locator('.conflict-journal')).toContainText('dlopper was faster');
    await page.locator('#conflict_resolution_add_notes').check();
    await page.locator('.conflict ~ p input[type=submit], #issue-form p input[type=submit][name=commit]').first().click();

    await expect(ui.flashNotice()).toHaveText('Successful update.');
    // only the notes were added, the subject change was dropped
    await expect(page.locator('.issue.details .subject h3')).toHaveText('Wishlist');
    await expect(page.locator('#history .journal').last()).toContainText('jsmith comment');
  });

  test('conflict resolution "overwrite" applies the changes', async ({ page, ui, api }) => {
    await page.goto('/issues/15');
    await openEdit(page);
    await page.locator('#issue_subject').fill('Wishlist sharing (overwritten)');

    await (await api('dlopper')).updateIssue(15, { notes: 'API change in between' });

    await submitEdit(page);
    await expect(page.locator('#issue-form .conflict')).toBeVisible();
    await page.locator('#conflict_resolution_overwrite').check();
    await page.locator('#issue-form p input[type=submit][name=commit]').first().click();
    await expect(ui.flashNotice()).toHaveText('Successful update.');
    await expect(page.locator('.issue.details .subject h3')).toHaveText('Wishlist sharing (overwritten)');
  });
});
