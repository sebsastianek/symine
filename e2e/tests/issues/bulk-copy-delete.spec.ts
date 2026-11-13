import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import { attribute, issueIdFromUrl } from './_helpers';

test.use({ as: 'jsmith' });

const LIST = '/projects/ecommerce/issues?set_filter=1&status_id=*&sort=id';

test.describe('context menu', () => {
  test('change the status of one issue from the context menu', async ({ page, ui }) => {
    await page.goto(LIST);
    const menu = await ui.openContextMenu(ui.issueRow(14));
    await expect(ui.issueRow(14)).toHaveClass(/context-menu-selection/);
    await expect(menu.locator('li.folder > a.submenu', { hasText: 'Status' })).toBeVisible();
    await ui.contextMenuPick('Status', 'In Progress');
    await expect(ui.flashNotice()).toHaveText('Successful update.');
    await expect(ui.issueRow(14).locator('td.status')).toHaveText('In Progress');
  });

  test('assign several selected issues to me', async ({ page, ui }) => {
    await page.goto(LIST);
    await ui.issueRow(15).locator('td.checkbox input').check();
    await ui.issueRow(16).locator('td.checkbox input').check();
    await expect(ui.issueRow(15)).toHaveClass(/context-menu-selection/);
    await ui.openContextMenu(ui.issueRow(16));
    await ui.contextMenuPick('Assignee', '<< me >>');
    await expect(ui.flashNotice()).toHaveText('Successful update.');
    expect(sql('SELECT id, assigned_to_id FROM issues WHERE id IN (15, 16) ORDER BY id')).toEqual([
      { id: 15, assigned_to_id: 5 },
      { id: 16, assigned_to_id: 5 },
    ]);
  });

  test('bulk edit selected issues with a note', async ({ page, ui }) => {
    await page.goto(LIST);
    // "toggle all" checkbox of the list header, then keep only #15 and #16
    // (#14 is their parent: priority is derived, so Priority is not bulk-editable when it is selected)
    await page.locator('table.list thead th.checkbox input, table.list thead th.checkbox a').first().click();
    await expect(ui.issueRow(13)).toHaveClass(/context-menu-selection/);
    for (const id of [13, 14, 17, 19]) await ui.issueRow(id).locator('td.checkbox input').uncheck();
    await ui.openContextMenu(ui.issueRow(15));
    await page.locator('#context-menu a.icon-edit', { hasText: 'Bulk Edit' }).click();

    await expect(page.locator('#content h2')).toHaveText('Bulk edit selected issues');
    await expect(page.locator('#bulk-selection li')).toHaveCount(2);
    await page.locator('#issue_priority_id').selectOption({ label: 'Urgent' });
    await page.locator('#issue_category_id').selectOption({ label: 'UI' });
    await page.locator('#notes').fill('Bulk updated by e2e');
    await page.locator('#bulk_edit_form input[type=submit][name=commit]').click();

    await expect(ui.flashNotice()).toHaveText('Successful update.');
    expect(sql('SELECT id, priority_id, category_id FROM issues WHERE id IN (15, 16) ORDER BY id')).toEqual([
      { id: 15, priority_id: 4, category_id: 1 },
      { id: 16, priority_id: 4, category_id: 1 },
    ]);
    await page.goto('/issues/15');
    await expect(page.locator('#history .journal').last()).toContainText('Bulk updated by e2e');
    await expect(page.locator('#history .journal').last()).toContainText(/Priority changed from \w+ to Urgent/);
  });

  test('bulk edit refreshes the available statuses when the tracker changes', async ({ page }) => {
    await page.goto('/issues/bulk_edit?ids[]=14&ids[]=15');
    await page.locator('#issue_tracker_id').selectOption({ label: 'Bug' });
    // custom fields of the new tracker appear after the ajax refresh (Platforms is Bug only)
    await expect(page.locator('#bulk_edit_form')).toContainText('Platforms');
    await expect(page.locator('#issue_tracker_id')).toHaveValue('1');
  });

  test('bulk copy to another project', async ({ page, ui }) => {
    const before = Number(sqlValue('SELECT COUNT(*) FROM issues WHERE project_id = 2'));
    await page.goto(LIST);
    await ui.issueRow(15).locator('td.checkbox input').check();
    await ui.issueRow(16).locator('td.checkbox input').check();
    await ui.openContextMenu(ui.issueRow(16));
    await page.locator('#context-menu a.icon-copy', { hasText: 'Copy' }).click();
    await expect(page.locator('#content h2')).toHaveText('Copy');
    await page.locator('#issue_project_id').selectOption('2'); // Internal
    await page.locator('#bulk_edit_form input[type=submit][name=commit]').click();
    await expect(ui.flashNotice()).toHaveText('Successful update.'); // Redmine uses the same notice for bulk copy
    expect(Number(sqlValue('SELECT COUNT(*) FROM issues WHERE project_id = 2'))).toBe(before + 2);
  });

  test('delete several issues from the context menu', async ({ page, ui, api }) => {
    const a = await (await api('jsmith')).createIssue({ project_id: 1, subject: uid('To delete A ') });
    const b = await (await api('jsmith')).createIssue({ project_id: 1, subject: uid('To delete B ') });
    await page.goto(LIST);
    await ui.issueRow(a.id).locator('td.checkbox input').check();
    await ui.issueRow(b.id).locator('td.checkbox input').check();
    await ui.openContextMenu(ui.issueRow(b.id));
    await page.locator('#context-menu a.icon-del').click(); // confirm accepted
    await expect(ui.issueRow(a.id)).toHaveCount(0);
    await expect(ui.issueRow(b.id)).toHaveCount(0);
    expect(Number(sqlValue('SELECT COUNT(*) FROM issues WHERE id IN (?, ?)', [a.id, b.id]))).toBe(0);
  });
});

test.describe('copy', () => {
  test('copy an issue with its attachments, linked to the original', async ({ page, ui }) => {
    await page.goto('/issues/13');
    await page.locator('#content > .contextual a.icon-copy').first().click();
    await expect(page.locator('#content h2')).toHaveText('New issue');
    await expect(page.locator('#issue_subject')).toHaveValue('Cart total is wrong with discounts');
    await expect(page.locator('#issue_tracker_id')).toHaveValue('1');
    await expect(page.locator('#link_copy')).toBeChecked();
    await expect(page.locator('#copy_attachments')).toBeChecked();
    await page.locator('#issue_subject').fill('Cart total copy');
    await page.locator('#issue-form input[name=commit]').click();

    await expect(ui.flashNotice()).toContainText('created');
    const id = issueIdFromUrl(page);
    expect(id).not.toBe(13);
    await expect(page.locator('#relations')).toContainText('Copied from');
    await expect(page.locator('#relations')).toContainText('Cart total is wrong with discounts');
    await expect(page.locator('.attachments a', { hasText: 'notes.txt' }).first()).toBeVisible();
    await expect(attribute(page, 'status')).toHaveText('New');
  });
});

test.describe('delete', () => {
  test('delete an issue from its page', async ({ page, ui, api }) => {
    const issue = await (await api('jsmith')).createIssue({ project_id: 1, subject: uid('Delete me ') });
    await page.goto(`/issues/${issue.id}`);
    await (await ui.openActionsMenu()).locator('a.icon-del').click(); // confirm accepted
    await expect(page).toHaveURL(/\/projects\/ecommerce\/issues/);
    expect(Number(sqlValue('SELECT COUNT(*) FROM issues WHERE id = ?', [issue.id]))).toBe(0);
    await page.goto(`/issues/${issue.id}`);
    await expect(page.locator('#content h2')).toHaveText('404');
  });

  test('deleting an issue with spent time asks what to do with the hours', async ({ page, ui }) => {
    await page.goto('/issues/13');
    await (await ui.openActionsMenu()).locator('a.icon-del').click();
    await expect(page.locator('#content h2')).toHaveText('Confirmation');
    await expect(page.locator('#content .box')).toContainText('2:30 hours were reported');
    await page.locator('#todo_reassign').check();
    await page.locator('#reassign_to_id').fill('14');
    await page.locator('#content input[type=submit]').click();
    await expect(page).toHaveURL(/\/projects\/ecommerce\/issues/);
    expect(sql('SELECT issue_id FROM time_entries WHERE id = 1')).toEqual([{ issue_id: 14 }]);
    expect(Number(sqlValue('SELECT COUNT(*) FROM issues WHERE id = 13'))).toBe(0);
  });
});
