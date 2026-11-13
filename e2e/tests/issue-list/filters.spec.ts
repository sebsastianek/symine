import { test, expect } from '../../support/fixtures';
import { applyQuery, expectIssues } from './_helpers';

// set_filter=1 resets the query kept in the session (Redmine remembers the last query per project)
const LIST = '/projects/ecommerce/issues?set_filter=1';

/*
 * Seeded issues of ecommerce (+ subproject checkout):
 *  13 Bug  In Progress  start 11-02 due 11-12  Database=PostgreSQL  assigned to group Developers
 *  14 Feature New       start 11-07 due 12-01  (parent of 15, 16)
 *  15 Feature New       start 11-07 due 11-17
 *  16 Feature New       start 11-19 due 12-01
 *  17 Bug  New (checkout, private) assigned to jsmith
 *  19 Support Closed
 */
test.describe('issue list filters', () => {
  test.use({ as: 'admin', dbReset: 'none' });

  test.beforeEach(async ({ page }) => {
    await page.goto(LIST);
    await expect(page.locator('#tr_status_id')).toBeVisible();
  });

  test('default query shows open issues with the status filter row', async ({ page }) => {
    await expect(page.locator('#cb_status_id')).toBeChecked();
    await expect(page.locator('#operators_status_id')).toHaveValue('o');
    await expectIssues(page, [13, 14, 15, 16, 17]);
    await expect(page.locator('.pagination .items')).toHaveText('(1-5/5)');
  });

  test('status operators: closed, any, is', async ({ page }) => {
    await page.locator('#operators_status_id').selectOption('c');
    // operator without values hides the values field
    await expect(page.locator('#tr_status_id .values')).toBeHidden();
    await applyQuery(page);
    await expectIssues(page, [19]);
    await expect(page.locator('#operators_status_id')).toHaveValue('c');

    await page.locator('#operators_status_id').selectOption('*');
    await applyQuery(page);
    await expectIssues(page, [13, 14, 15, 16, 17, 19]);

    await page.locator('#operators_status_id').selectOption('=');
    await expect(page.locator('#values_status_id_1')).toBeVisible();
    await page.locator('#values_status_id_1').selectOption('2');
    await applyQuery(page);
    await expectIssues(page, [13]);
  });

  test('status "is" with several values via the multi-select toggle', async ({ page }) => {
    await page.locator('#operators_status_id').selectOption('=');
    await page.locator('#tr_status_id .toggle-multiselect').click();
    await expect(page.locator('#values_status_id_1')).toHaveAttribute('multiple', /.*/);
    await page.locator('#values_status_id_1').selectOption(['2', '5']);
    await applyQuery(page);
    await expectIssues(page, [13, 19]);
  });

  test('tracker is / is not added from the "Add filter" dropdown', async ({ page }) => {
    await page.locator('#add_filter_select').selectOption('tracker_id');
    await expect(page.locator('#tr_tracker_id')).toBeVisible();
    await expect(page.locator('#cb_tracker_id')).toBeChecked();
    // the option is disabled once the filter is in the table
    await expect(page.locator('#add_filter_select option[value="tracker_id"]')).toBeDisabled();
    await page.locator('#values_tracker_id_1').selectOption('1');
    await applyQuery(page);
    await expectIssues(page, [13, 17]);
    expect(page.url()).toContain('f%5B%5D=tracker_id');

    await page.locator('#operators_tracker_id').selectOption('!');
    await applyQuery(page);
    await expectIssues(page, [14, 15, 16]);
  });

  test('unchecking a filter disables it', async ({ page }) => {
    await page.locator('#add_filter_select').selectOption('tracker_id');
    await page.locator('#values_tracker_id_1').selectOption('2');
    await page.locator('#cb_status_id').uncheck();
    await expect(page.locator('#operators_status_id')).toBeDisabled();
    await applyQuery(page);
    await expectIssues(page, [14, 15, 16]);
    await expect(page.locator('#cb_status_id')).toHaveCount(0);
  });

  test('subject contains / doesn\'t contain', async ({ page }) => {
    await page.locator('#add_filter_select').selectOption('subject');
    await expect(page.locator('#operators_subject')).toHaveValue('~');
    await page.locator('#values_subject').fill('Wishlist');
    await applyQuery(page);
    await expectIssues(page, [14, 15, 16]);
    await expect(page.locator('#values_subject')).toHaveValue('Wishlist');

    await page.locator('#operators_subject').selectOption('!~');
    await applyQuery(page);
    await expectIssues(page, [13, 17]);
  });

  test('pressing Enter in a text filter submits the form', async ({ page }) => {
    await page.locator('#add_filter_select').selectOption('subject');
    await page.locator('#values_subject').fill('Cart');
    const nav = page.waitForURL(/values?%5Bsubject%5D|v%5Bsubject%5D/);
    await page.locator('#values_subject').press('Enter');
    await nav;
    await expectIssues(page, [13]);
  });

  test('date operators on due date and start date', async ({ page }) => {
    await page.locator('#add_filter_select').selectOption('due_date');
    await page.locator('#operators_due_date').selectOption('<=');
    await page.locator('#values_due_date_1').fill('2026-11-17');
    await applyQuery(page);
    await expectIssues(page, [13, 15]);

    await page.locator('#operators_due_date').selectOption('><');
    await expect(page.locator('#values_due_date_2')).toBeVisible();
    await page.locator('#values_due_date_1').fill('2026-11-13');
    await page.locator('#values_due_date_2').fill('2026-12-31');
    await applyQuery(page);
    await expectIssues(page, [14, 15, 16]);

    await page.locator('#operators_due_date').selectOption('!*');
    await applyQuery(page);
    await expectIssues(page, [17]);
  });

  test('custom field filter (Database list)', async ({ page }) => {
    await page.locator('#add_filter_select').selectOption('cf_1');
    // remote values are loaded by XHR
    await page.locator('#values_cf_1_1').selectOption('PostgreSQL');
    await applyQuery(page);
    await expectIssues(page, [13]);

    await page.locator('#operators_cf_1').selectOption('!*');
    await applyQuery(page);
    await expectIssues(page, [14, 15, 16, 17]);
  });

  test('numeric filter: estimated time >=', async ({ page }) => {
    await page.locator('#add_filter_select').selectOption('estimated_hours');
    await page.locator('#operators_estimated_hours').selectOption('>=');
    await page.locator('#values_estimated_hours_1').fill('3');
    await applyQuery(page);
    await expectIssues(page, [13, 15]);
  });

  test('Clear resets the filters to the default query', async ({ page }) => {
    await page.locator('#operators_status_id').selectOption('*');
    await applyQuery(page);
    await expectIssues(page, [13, 14, 15, 16, 17, 19]);
    await page.locator('#query_form p.buttons a.icon-reload').click();
    await expect(page.locator('#operators_status_id')).toHaveValue('o');
    await expectIssues(page, [13, 14, 15, 16, 17]);
  });
});

test.describe('assignee "<< me >>"', () => {
  test.use({ as: 'jsmith', dbReset: 'none' });

  test('lists the issues assigned to the current user', async ({ page }) => {
    await page.goto(LIST);
    await page.locator('#add_filter_select').selectOption('assigned_to_id');
    const values = page.locator('#values_assigned_to_id_1');
    await expect(values.locator('option[value="me"]')).toHaveText('<< me >>');
    await values.selectOption('me');
    await applyQuery(page);
    await expectIssues(page, [17]);
    await expect(page.locator('#values_assigned_to_id_1')).toHaveValue('me');
  });

  test('assignee "none" lists unassigned issues', async ({ page }) => {
    await page.goto(LIST);
    await page.locator('#add_filter_select').selectOption('assigned_to_id');
    await page.locator('#operators_assigned_to_id').selectOption('!*');
    await applyQuery(page);
    await expectIssues(page, [14, 15, 16]);
  });
});
