import type { Page } from '@playwright/test';
import { test, expect, uid, ensureFileReset } from '../../support/fixtures';
import { apiAs } from '../../support/api';
import { sql, sqlValue } from '../../support/db';
import { applyQuery, openOptions } from '../issue-list/_helpers';

/*
 * Bulk operations on the spent time list (row selection + context menu + bulk edit form) and saved time entry queries.
 * Data after beforeAll (ecommerce):
 *   #1 dlopper 2.5h Design      2026-11-06 issue #13   (seed)
 *   #2 jsmith  1h   Design      2026-11-07             (seed)
 *   +  jsmith  4h   Development 2026-10-20 issue #15   "October work"
 *   +  dlopper 0.5h Development 2026-11-07 issue #14   "Small fix"
 *   +  jsmith  2h   Design      2026-11-03             "Workshop"
 * jsmith (Manager) may edit every entry; dlopper (Developer) may only log time.
 */
const ids: Record<string, number> = {};

test.beforeAll(async ({}, testInfo) => {
  await ensureFileReset(testInfo);
  const jsmith = await apiAs('jsmith');
  ids.october = (await jsmith.logTime({ issue_id: 15, hours: 4, activity_id: 9, spent_on: '2026-10-20', comments: 'October work' })).id;
  ids.workshop = (await jsmith.logTime({ project_id: 1, hours: 2, activity_id: 8, spent_on: '2026-11-03', comments: 'Workshop' })).id;
  await jsmith.dispose();
  const dlopper = await apiAs('dlopper');
  ids.fix = (await dlopper.logTime({ issue_id: 14, hours: 0.5, activity_id: 9, spent_on: '2026-11-07', comments: 'Small fix' })).id;
  await dlopper.dispose();
});

const LIST = '/projects/ecommerce/time_entries?set_filter=1&spent_on=*';
const row = (page: Page, id: number) => page.locator(`tr#time-entry-${id}`);
const total = (page: Page) => page.locator('p.query-totals .total-for-hours .value');

/** Selects rows with their checkboxes and opens the context menu on the last one. */
async function contextMenuFor(page: Page, entryIds: number[]) {
  for (const id of entryIds) await row(page, id).locator('td.checkbox input').check();
  for (const id of entryIds) await expect(row(page, id)).toHaveClass(/context-menu-selection/);
  await row(page, entryIds[entryIds.length - 1]).locator('td.hours').click({ button: 'right' });
  const menu = page.locator('#context-menu');
  await expect(menu).toBeVisible();
  return menu;
}

test.describe('bulk edit time entries', () => {
  test.use({ as: 'jsmith' });

  test('select rows, bulk edit activity and comment through the form', async ({ page, ui }) => {
    await page.goto(LIST);
    await expect(total(page)).toHaveText('10:00');
    const menu = await contextMenuFor(page, [1, 2]);
    // several entries selected: "Bulk edit" instead of "Edit"
    await menu.locator('a.icon-edit', { hasText: 'Bulk edit' }).click();

    await expect(page.locator('#content h2')).toHaveText('Bulk edit selected time entries');
    await expect(page.locator('#bulk-selection li')).toHaveCount(2);
    await expect(page.locator('#bulk-selection')).toContainText('11/06/2026 - eCommerce: 2:30 hours (Dave Lopper)');
    await expect(page.locator('#bulk-selection')).toContainText('11/07/2026 - eCommerce: 1:00 hours (John Smith)');
    // every field starts on "(No change)"
    await expect(page.locator('#time_entry_activity_id option:checked')).toHaveText('(No change)');
    await expect(page.locator('#time_entry_hours')).toHaveValue('');

    // "Clear" disables the issue field
    const clear = page.locator('input[type=checkbox][name="time_entry[issue_id]"]');
    await clear.check();
    await expect(page.locator('#time_entry_issue_id')).toBeDisabled();
    await clear.uncheck();
    await expect(page.locator('#time_entry_issue_id')).toBeEnabled();

    await page.locator('#time_entry_activity_id').selectOption({ label: 'Development' });
    await page.locator('#time_entry_comments').fill('Bulk edited');
    await page.locator('#bulk_edit_form input[name=commit]').click();
    await ui.expectNotice('Successful update.');

    await page.goto(LIST);
    for (const id of [1, 2]) {
      await expect(row(page, id).locator('td.activity')).toHaveText('Development');
      await expect(row(page, id).locator('td.comments')).toHaveText('Bulk edited');
    }
    // untouched attributes and entries
    await expect(row(page, 1).locator('td.hours')).toHaveText('2:30');
    await expect(row(page, 1).locator('td.issue')).toContainText('#13');
    await expect(row(page, ids.workshop).locator('td.comments')).toHaveText('Workshop');
    expect(sql('SELECT activity_id, issue_id, hours FROM time_entries WHERE id IN (1, 2) ORDER BY id')).toEqual([
      { activity_id: 9, issue_id: 13, hours: 2.5 },
      { activity_id: 9, issue_id: null, hours: 1 },
    ]);
  });

  test('"check all" selects every row; clearing the issue of the selection', async ({ page, ui }) => {
    await page.goto(`${LIST}&f[]=issue_id&op[issue_id]=*`);
    await expect(page.locator('table.time-entries tr.time-entry')).toHaveCount(3);
    await page.locator('#check_all').click();
    await expect(page.locator('table.time-entries tr.time-entry.context-menu-selection')).toHaveCount(3);
    await page.locator('#check_all').click();
    await expect(page.locator('table.time-entries tr.time-entry.context-menu-selection')).toHaveCount(0);

    const menu = await contextMenuFor(page, [ids.october, ids.fix]);
    await menu.locator('a.icon-edit').click();
    await expect(page.locator('#bulk-selection li')).toHaveCount(2);
    await page.locator('input[type=checkbox][name="time_entry[issue_id]"]').check();
    await page.locator('#bulk_edit_form input[name=commit]').click();
    await ui.expectNotice('Successful update.');
    expect(sql('SELECT issue_id, project_id FROM time_entries WHERE id IN (?, ?)', [ids.october, ids.fix])).toEqual([
      { issue_id: null, project_id: 1 },
      { issue_id: null, project_id: 1 },
    ]);
    // the issue has no spent time anymore
    await page.goto('/issues/15');
    await expect(page.locator('.issue.details .subject h3')).toHaveText('Wishlist sharing');
    await expect(page.locator('.issue.details .attributes .spent-time')).toHaveCount(0);
  });

  test('moving entries to another project refreshes the form, then moves them', async ({ page, ui }) => {
    // disable "Design" on Checkout: the activities offered must follow the selected project
    sql("INSERT INTO enumerations (name, position, is_default, type, active, project_id, parent_id) VALUES ('Design', 1, 0, 'TimeEntryActivity', 0, 3, 8)");
    await page.goto(LIST);
    const menu = await contextMenuFor(page, [ids.workshop, ids.october]);
    await menu.locator('a.icon-edit').click();
    await expect(page.locator('#time_entry_activity_id option')).toHaveText(['(No change)', 'Design', 'Development']);

    await Promise.all([
      page.waitForResponse((r) => r.url().includes('/time_entries/bulk_edit') && r.request().method() === 'POST'),
      page.locator('#time_entry_project_id').selectOption({ label: '» Checkout' }),
    ]);
    await expect(page.locator('#time_entry_project_id')).toHaveValue('3');
    await expect(page.locator('#time_entry_activity_id option')).toHaveText(['(No change)', 'Development']);
    await expect(page.locator('#bulk-selection li')).toHaveCount(2);
    await page.locator('#time_entry_activity_id').selectOption({ label: 'Development' });
    await page.locator('#bulk_edit_form input[name=commit]').click();
    await ui.expectNotice('Successful update.');

    expect(sql('SELECT project_id, activity_id FROM time_entries WHERE id IN (?, ?)', [ids.workshop, ids.october])).toEqual([
      { project_id: 3, activity_id: 9 },
      { project_id: 3, activity_id: 9 },
    ]);
    await page.goto('/projects/checkout/time_entries?set_filter=1&spent_on=*');
    await expect(row(page, ids.workshop)).toBeVisible();
    await expect(row(page, ids.october)).toBeVisible();
    await expect(total(page)).toHaveText('6:00');
  });

  test('delete the selected entries from the context menu', async ({ page, ui }) => {
    await page.goto(LIST);
    const before = Number(sqlValue('SELECT COUNT(*) FROM time_entries'));
    const menu = await contextMenuFor(page, [1, ids.fix]);
    await menu.locator('a.icon-del').click(); // confirm() accepted by the fixtures
    await ui.expectNotice('Successful deletion.');
    await expect(row(page, 1)).toHaveCount(0);
    await expect(row(page, ids.fix)).toHaveCount(0);
    await expect(row(page, 2)).toBeVisible();
    expect(Number(sqlValue('SELECT COUNT(*) FROM time_entries'))).toBe(before - 2);
  });

  test('a developer gets a disabled context menu on entries he cannot edit', async ({ loginAs }) => {
    const { page } = await loginAs('dlopper');
    await page.goto(LIST);
    await row(page, 2).locator('td.hours').click({ button: 'right' });
    const menu = page.locator('#context-menu');
    await expect(menu).toBeVisible();
    await expect(menu.locator('a.icon-edit')).toHaveClass(/disabled/);
    await expect(menu.locator('a.icon-del')).toHaveClass(/disabled/);
    // clicking a disabled entry does nothing
    await menu.locator('a.icon-del').click();
    await expect(page).toHaveURL(/\/projects\/ecommerce\/time_entries/);
    expect(Number(sqlValue('SELECT COUNT(*) FROM time_entries WHERE id = 2'))).toBe(1);
  });
});

test.describe('saved time entry queries', () => {
  test.use({ as: 'jsmith' });

  test('save a filtered, grouped query; it is listed in the sidebar, then edit and delete it', async ({ page, ui, loginAs }) => {
    const name = uid('Development hours ');
    await page.goto(LIST);
    await ui.setFilter('activity_id', '=', ['9']);
    await openOptions(page);
    await page.locator('#group_by').selectOption('user');
    await applyQuery(page);
    await expect(page.locator('table.time-entries tr.group')).not.toHaveCount(0);

    await page.locator('#query_form p.buttons a.icon-save').click();
    await expect(page.locator('#content h2')).toHaveText('New query');
    // filters and grouping of the list are carried over
    await expect(page.locator('#cb_activity_id')).toBeChecked();
    await expect(page.locator('#values_activity_id_1')).toHaveValue('9');
    await expect(page.locator('#query_group_by')).toHaveValue('user');
    await page.locator('#query_name').fill(name);
    await page.locator('#query_visibility_2').check(); // to any users
    await page.locator('input[name=commit]').click();

    // back on the spent time list (not the issue list) with the query applied
    await expect(page).toHaveURL(/\/projects\/ecommerce\/time_entries\?query_id=\d+/);
    await expect(page.locator('#content h2')).toHaveText(name);
    const queryId = Number(new URL(page.url()).searchParams.get('query_id'));
    expect(sql('SELECT type, project_id, user_id, visibility, group_by FROM queries WHERE id = ?', [queryId])[0]).toEqual({
      type: 'TimeEntryQuery',
      project_id: 1,
      user_id: 5,
      visibility: 2,
      group_by: 'user',
    });
    const activities = await page.locator('table.time-entries tr.time-entry td.activity').allTextContents();
    expect(activities.length).toBeGreaterThan(0);
    expect(new Set(activities.map((a) => a.trim()))).toEqual(new Set(['Development']));
    await expect(page.locator('#sidebar a.query.selected')).toHaveText(name);

    // other members get it in their sidebar; it is not offered on the issue list
    const other = await loginAs('dlopper');
    await other.page.goto('/projects/ecommerce/time_entries?set_filter=1');
    await other.page.locator('#sidebar a.query', { hasText: name }).click();
    await expect(other.page.locator('#content h2')).toHaveText(name);
    await expect(other.page.locator('table.time-entries tr.group')).not.toHaveCount(0);
    await other.page.goto('/projects/ecommerce/issues?set_filter=1');
    await expect(other.page.locator('#sidebar a.query', { hasText: name })).toHaveCount(0);

    // edit: rename and drop the grouping
    await page.locator('#query_form p.buttons a.icon-edit').click();
    await expect(page.locator('#query_name')).toHaveValue(name);
    await page.locator('#query_name').fill(`${name} v2`);
    await page.locator('#query_group_by').selectOption('');
    await page.locator('input[name=commit]').click();
    await expect(page.locator('#content h2')).toHaveText(`${name} v2`);
    await expect(page.locator('table.time-entries tr.group')).toHaveCount(0);

    // delete
    await page.locator('#query_form p.buttons a.icon-del').click();
    await expect(page).toHaveURL(/\/projects\/ecommerce\/time_entries/);
    await expect(page.locator('#sidebar a.query', { hasText: name })).toHaveCount(0);
    expect(Number(sqlValue('SELECT COUNT(*) FROM queries WHERE id = ?', [queryId]))).toBe(0);
  });
});
