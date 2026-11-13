import { test, expect } from '../../support/fixtures';
import { addColumns, applyQuery, clickAndWait, expectIssues, headerColumns, listedIssueIds, openOptions, removeColumns } from './_helpers';

const LIST = '/projects/ecommerce/issues?set_filter=1';

test.describe('issue list options: columns, grouping, totals, sorting', () => {
  test.use({ as: 'admin', dbReset: 'none' });

  test.beforeEach(async ({ page }) => {
    await page.goto(LIST);
    await expect(page.locator('#tr_status_id')).toBeVisible();
  });

  test('Options fieldset is collapsed by default and expands on click', async ({ page }) => {
    await expect(page.locator('fieldset#options')).toHaveClass(/collapsed/);
    await expect(page.locator('#available_c')).toBeHidden();
    await openOptions(page);
    await expect(page.locator('#available_c')).toBeVisible();
    await expect(page.locator('#selected_c option')).toHaveText(['Tracker', 'Status', 'Priority', 'Subject', 'Assignee', 'Updated']);
  });

  test('add and remove columns with the arrow buttons', async ({ page }) => {
    await openOptions(page);
    await addColumns(page, ['estimated_hours', 'cf_1']);
    await removeColumns(page, ['assigned_to']);
    await expect(page.locator('#selected_c option[value="assigned_to"]')).toHaveCount(0);
    await applyQuery(page);

    const cols = await headerColumns(page);
    expect(cols).toEqual(['id', 'tracker', 'status', 'priority', 'subject', 'updated_on', 'estimated_hours', 'cf_1']);
    const row = page.locator('tr#issue-13');
    await expect(row.locator('td.cf_1')).toHaveText('PostgreSQL');
    await expect(row.locator('td.estimated_hours')).toHaveText('8:00');
    await expect(page.locator('tr#issue-14 td.cf_1')).toHaveText('');
    expect(page.url()).toContain('c%5B%5D=cf_1');
  });

  test('double-click moves a column; reorder with the "top" button', async ({ page }) => {
    await openOptions(page);
    await page.locator('#available_c option[value="author"]').dblclick();
    await expect(page.locator('#selected_c option[value="author"]')).toHaveCount(1);
    await page.locator('#selected_c').selectOption('subject');
    await page.locator('button[onclick^="moveOptionTop"]').click();
    await expect(page.locator('#selected_c option').first()).toHaveText('Subject');
    await applyQuery(page);
    expect(await headerColumns(page)).toEqual(['id', 'subject', 'tracker', 'status', 'priority', 'assigned_to', 'updated_on', 'author']);
    await expect(page.locator('tr#issue-17 td.author')).toHaveText('Robert Hill');
  });

  test('group results by tracker, collapse and expand groups', async ({ page }) => {
    await openOptions(page);
    await page.locator('#group_by').selectOption('tracker');
    await applyQuery(page);

    const groups = page.locator('table.list tr.group');
    await expect(groups).toHaveCount(2);
    await expect(groups.locator('.name')).toHaveText(['Bug', 'Feature']);
    await expect(groups.locator('.count')).toHaveText(['2', '3']);

    // collapse the "Bug" group
    await groups.first().locator('.expander').click();
    await expect(groups.first()).not.toHaveClass(/open/);
    await expect(page.locator('tr#issue-13')).toBeHidden();
    await expect(page.locator('tr#issue-14')).toBeVisible();
    await groups.first().locator('.expander').click();
    await expect(page.locator('tr#issue-13')).toBeVisible();

    // collapse all / expand all
    await groups.first().locator('a.toggle-all').click();
    await expect(page.locator('tr.issue:visible')).toHaveCount(0);
    await groups.first().locator('a.toggle-all').click();
    await expect(page.locator('tr.issue:visible')).toHaveCount(5);
  });

  test('totals for estimated and spent time, overall and per group', async ({ page }) => {
    await openOptions(page);
    await page.locator('#options input[name="t[]"][value="estimated_hours"]').check();
    await page.locator('#options input[name="t[]"][value="spent_hours"]').check();
    await page.locator('#group_by').selectOption('tracker');
    await applyQuery(page);

    const totals = page.locator('p.query-totals');
    await expect(totals.locator('.total-for-estimated-hours .value')).toHaveText('14:00');
    await expect(totals.locator('.total-for-spent-hours .value')).toHaveText('2:30');
    const bug = page.locator('tr.group').filter({ hasText: 'Bug' });
    await expect(bug.locator('.totals .total-for-estimated-hours .value')).toHaveText('8:00');
    await expect(bug.locator('.totals .total-for-spent-hours .value')).toHaveText('2:30');
    const feature = page.locator('tr.group').filter({ hasText: 'Feature' });
    await expect(feature.locator('.totals .total-for-estimated-hours .value')).toHaveText('6:00');

    // totals checkboxes stay checked after reload
    await openOptions(page);
    await expect(page.locator('#options input[name="t[]"][value="estimated_hours"]')).toBeChecked();
  });

  test('"Show description" adds description rows', async ({ page }) => {
    await openOptions(page);
    await page.locator('#options input[name="c[]"][value="description"]').check();
    await applyQuery(page);
    await expect(page.locator('table.list tr.issue td.description, table.list td.description').first()).toBeAttached();
  });

  test('sort by clicking column headers (asc, then desc)', async ({ page }) => {
    // default: id desc
    await expect(page.locator('table.list.issues')).toHaveClass(/sort-by-id/);
    expect(await listedIssueIds(page)).toEqual([17, 16, 15, 14, 13]);

    await clickAndWait(page, page.locator('table.list thead th.subject a'));
    await expect(page.locator('table.list thead th.subject a')).toHaveClass(/sort asc/);
    await expectIssues(page, [13, 17, 14, 16, 15], { ordered: true });

    await clickAndWait(page, page.locator('table.list thead th.subject a'));
    await expect(page.locator('table.list thead th.subject a')).toHaveClass(/sort desc/);
    await expectIssues(page, [15, 16, 14, 17, 13], { ordered: true });

    // priority sorts by position (default direction: desc = highest first)
    await clickAndWait(page, page.locator('table.list thead th.priority a'));
    const prio = await page.locator('table.list tbody tr.issue td.priority').allTextContents();
    expect(prio).toEqual(['Immediate', 'Urgent', 'Urgent', 'High', 'Low']);
  });

  test('sort is kept when filters are applied', async ({ page }) => {
    await clickAndWait(page, page.locator('table.list thead th.subject a'));
    await page.locator('#add_filter_select').selectOption('tracker_id');
    await page.locator('#values_tracker_id_1').selectOption('2');
    await applyQuery(page);
    await expectIssues(page, [14, 16, 15], { ordered: true });
  });
});
