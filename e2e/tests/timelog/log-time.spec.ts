import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';

/*
 * Seeded time entries (ecommerce): #1 dlopper 2.5h Design 2026-11-06 on issue #13, #2 jsmith 1h Design 2026-11-07.
 * Roles: Manager (jsmith) may edit all entries, Developer (dlopper)/Reporter (rhill) may only log time.
 */
test.describe('log, edit and delete time entries', () => {
  test.use({ as: 'jsmith' });

  test('log time from the project with the issue autocomplete', async ({ page, ui }) => {
    const comment = uid('Pairing ');
    await page.goto('/projects/ecommerce/time_entries');
    await page.locator('#content .contextual a.icon-time-add').click();
    await expect(page.locator('#content h2')).toHaveText('Spent time');

    await page.locator('#time_entry_spent_on').fill('2026-11-05');
    await page.locator('#time_entry_hours').fill('1.5');
    await page.locator('#time_entry_comments').fill(comment);
    await page.locator('#time_entry_activity_id').selectOption({ label: 'Development' });

    // issue autocomplete: type, pick the suggestion; new.js refreshes the issue link and the activities
    // (keeping the selected one, so the issue is picked last to avoid racing the refresh)
    await ui.autocomplete(page.locator('#time_entry_issue_id'), 'Cart', /#13/);
    await expect(page.locator('#time_entry_issue_id')).toHaveValue('13');
    await expect(page.locator('#time_entry_issue')).toContainText('Cart total is wrong with discounts');
    await expect(page.locator('#time_entry_activity_id')).toHaveValue('9');
    await page.locator('input[name=commit]').click();

    await ui.expectNotice('Successful creation.');
    await expect(page).toHaveURL(/\/projects\/ecommerce\/time_entries/);
    const row = page.locator('table.time-entries tr.time-entry').filter({ hasText: comment });
    await expect(row.locator('td.spent_on')).toHaveText('11/05/2026');
    await expect(row.locator('td.user')).toHaveText('John Smith');
    await expect(row.locator('td.activity')).toHaveText('Development');
    await expect(row.locator('td.issue')).toContainText('Bug #13');
    await expect(row.locator('td.hours')).toHaveText('1:30');
    await expect(page.locator('p.query-totals .total-for-hours .value')).toHaveText('5:00');
  });

  test('"Create and add another" keeps the form open', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/time_entries/new');
    await page.locator('#time_entry_hours').fill('0.25');
    await page.locator('#time_entry_activity_id').selectOption({ label: 'Design' });
    await page.locator('#time_entry_comments').fill(uid('Quick '));
    await page.locator('input[name=continue]').click();
    await ui.expectNotice('Successful creation.');
    await expect(page.locator('#time_entry_hours')).toHaveValue('');
    await expect(page.locator('#time_entry_activity_id')).toBeVisible();
  });

  test('validation errors are displayed', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/time_entries/new');
    await page.locator('#time_entry_hours').fill('');
    await page.locator('input[name=commit]').click();
    await ui.expectError(/Hours cannot be blank/);
    await expect(page.locator('#errorExplanation')).toContainText('Activity cannot be blank');

    await page.locator('#time_entry_hours').fill('abc');
    await page.locator('#time_entry_activity_id').selectOption({ label: 'Design' });
    await page.locator('input[name=commit]').click();
    await expect(page.locator('#errorExplanation')).toContainText('Hours is invalid');
  });

  test('log time from an issue: issue prefilled, spent time shown on the issue', async ({ page, ui }) => {
    const comment = uid('From issue ');
    await page.goto('/issues/15');
    await page.locator('#content > .contextual a.icon-time-add').first().click();
    await expect(page).toHaveURL(/\/issues\/15\/time_entries\/new/);
    await expect(page.locator('#time_entry_issue_id')).toHaveValue('15');
    await page.locator('#time_entry_hours').fill('2:15'); // hh:mm accepted
    await page.locator('#time_entry_activity_id').selectOption({ label: 'Development' });
    await page.locator('#time_entry_comments').fill(comment);
    await page.locator('input[name=commit]').click();
    await ui.expectNotice('Successful creation.');
    expect(Number(sqlValue('SELECT hours FROM time_entries WHERE comments = ?', [comment]))).toBe(2.25);

    await page.goto('/issues/15');
    await expect(page.locator('.attributes .spent-time .value')).toContainText('2:15 h');
    // the spent time links to the issue's time entries
    await page.locator('.attributes .spent-time .value a').click();
    await expect(page.locator('table.time-entries tr.time-entry')).toHaveCount(1);
    await expect(page.locator('table.time-entries td.comments')).toHaveText(comment);
  });

  test('log time while updating an issue', async ({ page, ui }) => {
    const comment = uid('With update ');
    await page.goto('/issues/16');
    await page.locator('#content > .contextual a.icon-edit').first().click();
    await expect(page.locator('#log_time')).toBeVisible();
    await page.locator('#log_time #time_entry_hours').fill('0.5');
    await page.locator('#log_time #time_entry_activity_id').selectOption({ label: 'Design' });
    await page.locator('#log_time #time_entry_comments').fill(comment);
    await page.locator('#issue-form input[name=commit]').click();
    // like Redmine: no "Successful update." flash when only time was logged (the journal is empty)
    await expect(page.locator('.attributes .spent-time .value')).toContainText('0:30 h');
    await expect(ui.flashNotice()).toHaveCount(0);
    await expect(page.locator('#history')).toContainText(comment);
    expect(sql('SELECT issue_id, hours FROM time_entries WHERE comments = ?', [comment])).toEqual([{ issue_id: 16, hours: 0.5 }]);
  });

  test('edit a time entry from the list', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/time_entries?set_filter=1');
    const row = page.locator('tr#time-entry-1');
    await row.locator('a.icon-edit').click();
    await expect(page.locator('#time_entry_hours')).toHaveValue(/2[.:]5|2:30/);
    await page.locator('#time_entry_hours').fill('3');
    await page.locator('#time_entry_comments').fill('Investigation (updated)');
    await page.locator('input[name=commit]').click();
    await ui.expectNotice('Successful update.');
    await expect(page.locator('tr#time-entry-1 td.hours')).toHaveText('3:00');
    await expect(page.locator('tr#time-entry-1 td.comments')).toHaveText('Investigation (updated)');
  });

  test('change the activity of an entry through the context menu', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/time_entries?set_filter=1');
    // right-click a cell without link (Redmine ignores right-clicks on links; ui.openContextMenu clicks the 3rd cell)
    await page.locator('tr#time-entry-2 td.hours').click({ button: 'right' });
    await expect(page.locator('#context-menu')).toBeVisible();
    await ui.contextMenuPick('Activity', 'Development');
    await ui.expectNotice('Successful update.');
    await expect(page.locator('tr#time-entry-2 td.activity')).toHaveText('Development');
  });

  test('delete a time entry', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/time_entries?set_filter=1');
    await expect(page.locator('tr#time-entry-2')).toBeVisible();
    await page.locator('tr#time-entry-2 a.icon-del').click(); // confirm() auto-accepted
    await ui.expectNotice('Successful deletion.');
    await expect(page.locator('tr#time-entry-2')).toHaveCount(0);
    expect(sql('SELECT id FROM time_entries WHERE id = 2')).toEqual([]);
  });
});

test.describe('time logging permissions', () => {
  test.use({ as: 'rhill' });

  test('a reporter logs time for himself only and cannot edit it', async ({ page, ui }) => {
    const comment = uid('Reporter ');
    await page.goto('/projects/ecommerce/time_entries/new');
    // no "log time for other users" permission: no user select
    await expect(page.locator('#time_entry_user_id')).toHaveCount(0);
    await page.locator('#time_entry_hours').fill('1');
    await page.locator('#time_entry_activity_id').selectOption({ label: 'Design' });
    await page.locator('#time_entry_comments').fill(comment);
    await page.locator('input[name=commit]').click();
    await ui.expectNotice('Successful creation.');
    const row = page.locator('table.time-entries tr.time-entry').filter({ hasText: comment });
    await expect(row.locator('td.user')).toHaveText('Robert Hill');
    // no edit_own_time_entries: no edit/delete links
    await expect(row.locator('a.icon-edit')).toHaveCount(0);
    await expect(row.locator('a.icon-del')).toHaveCount(0);
  });

  test('anonymous users are asked to log in to log time', async ({ browser }) => {
    // explicit empty storage state: browser.newContext() inherits the `as` session otherwise
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();
    await page.goto('/projects/ecommerce/time_entries/new');
    await expect(page).toHaveURL(/\/login\?back_url=/);
    await context.close();
  });
});
