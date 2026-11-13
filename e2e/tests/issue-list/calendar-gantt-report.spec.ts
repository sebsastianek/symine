import { test, expect } from '../../support/fixtures';
import { applyQuery, clickAndWait, expectIssues, openOptions } from './_helpers';

// Seeded dates are in November 2026: always open that month explicitly (independent of today's date).
test.describe('calendar', () => {
  test.use({ as: 'admin', dbReset: 'none' });

  test('shows issues starting and ending on their days, navigates months', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues/calendar?set_filter=1&month=11&year=2026');
    await expect(page.locator('#content h2')).toHaveText('Calendar');
    const day = (n: number) =>
      page.locator('ul.cal li.calbody.this-month').filter({ has: page.locator('.day-value', { hasText: new RegExp(`^${n}$`) }) });

    await expect(day(2).locator('div.issue.starting > a.issue')).toHaveText('Bug #13');
    await expect(day(12).locator('div.issue.ending > a.issue')).toHaveText('Bug #13');
    await expect(day(7).locator('div.issue.starting > a.issue')).toHaveText(['Feature #15', 'Feature #14']);
    await expect(day(17).locator('div.issue.ending > a.issue')).toHaveText('Feature #15');

    // tooltip shows the details on hover
    const issue = day(2).locator('div.issue.starting').first();
    await issue.hover();
    await expect(issue.locator('span.tip')).toBeVisible();
    await expect(issue.locator('span.tip')).toContainText('Due date');

    // next month: December -> #14 and #16 end on 12/01
    await clickAndWait(page, page.locator('.pagination li.next a'));
    await expect(page.locator('#month')).toHaveValue('12');
    await expect(day(1).locator('div.issue.ending > a.issue')).toHaveText(['Feature #16', 'Feature #14']);
    await clickAndWait(page, page.locator('.pagination li.previous a'));
    await clickAndWait(page, page.locator('.pagination li.previous a'));
    await expect(page.locator('#month')).toHaveValue('10');
    await expect(page.locator('ul.cal div.issue')).toHaveCount(0);

    // month/year selects + Apply
    await page.locator('#month').selectOption('11');
    await page.locator('#year').selectOption('2026');
    await applyQuery(page);
    await expect(day(2).locator('div.issue.starting')).toHaveCount(1);
  });

  test('calendar honours the filters', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues/calendar?set_filter=1&month=11&year=2026');
    await page.locator('#add_filter_select').selectOption('tracker_id');
    await page.locator('#values_tracker_id_1').selectOption('1');
    await applyQuery(page);
    await expect(page.locator('ul.cal div.issue > a.issue')).toHaveText(['Bug #13', 'Bug #13']);
  });

  test('context menu on a calendar issue', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues/calendar?set_filter=1&month=11&year=2026');
    await page.locator('ul.cal div.issue.hascontextmenu').first().click({ button: 'right', position: { x: 2, y: 2 } });
    await expect(page.locator('#context-menu')).toBeVisible();
    await expect(page.locator('#context-menu a.icon-edit').first()).toBeVisible();
  });
});

test.describe('gantt', () => {
  test.use({ as: 'admin', dbReset: 'none' });
  const GANTT = '/projects/ecommerce/issues/gantt?set_filter=1&month=11&year=2026&months=2';

  test('subjects and bars of the project, versions and issues', async ({ page }) => {
    await page.goto(GANTT);
    await expect(page.locator('#content h2')).toHaveText('Gantt');
    const subjects = page.locator('[data-gantt-column="subjects"]');
    await expect(subjects.locator('[data-gantt-row-key="project-1"]')).toContainText('eCommerce');
    await expect(subjects.locator('[data-gantt-row-key="issue-13"]')).toContainText('Cart total is wrong with discounts');
    await expect(subjects.locator('[data-gantt-row-key="issue-15"]')).toContainText('Wishlist sharing');
    await expect(subjects.locator('[data-gantt-row-key^="version-1"]')).toContainText('1.0');

    // bars: todo + done + label
    await expect(page.locator('.gantt-task.gantt-task-todo[data-gantt-issue-id="13"]')).toBeVisible();
    await expect(page.locator('.gantt-row[data-gantt-row-key="issue-13"] .gantt-task-label').last()).toHaveText('In Progress 30%');
    // month headers
    await expect(page.locator('.gantt-chart header a[title="November 2026"], .gantt-chart a[title="November 2026"]').first()).toBeVisible();
  });

  test('collapse a parent row hides its subtasks', async ({ page }) => {
    await page.goto(GANTT);
    const subjects = page.locator('[data-gantt-column="subjects"]');
    await expect(subjects.locator('[data-gantt-row-key="issue-15"]')).toBeVisible();
    await subjects.locator('[data-gantt-row-key="issue-14"] .expander').click();
    await expect(subjects.locator('[data-gantt-row-key="issue-15"]')).toBeHidden();
    await expect(subjects.locator('[data-gantt-row-key="issue-16"]')).toBeHidden();
    await subjects.locator('[data-gantt-row-key="issue-14"] .expander').click();
    await expect(subjects.locator('[data-gantt-row-key="issue-15"]')).toBeVisible();
  });

  test('navigation: previous/next and zoom', async ({ page }) => {
    await page.goto(GANTT);
    await clickAndWait(page, page.locator('.pagination li.next a'));
    await expect(page).toHaveURL(/month=1&year=2027|year=2027.*month=1\b/);
    await expect(page.locator('#month')).toHaveValue('1');
    await expect(page.locator('#year')).toHaveValue('2027');
    await clickAndWait(page, page.locator('.pagination li.previous a'));
    await expect(page.locator('#month')).toHaveValue('11');

    await expect(page.locator('#zoom')).toHaveValue('2');
    await clickAndWait(page, page.locator('a.icon-zoom-in'));
    await expect(page.locator('#zoom')).toHaveValue('3');
    await clickAndWait(page, page.locator('a.icon-zoom-out'));
    await clickAndWait(page, page.locator('a.icon-zoom-out'));
    await expect(page.locator('#zoom')).toHaveValue('1');

    // number of months + Apply
    await page.locator('#months').fill('4');
    await applyQuery(page);
    await expect(page.locator('#months')).toHaveValue('4');
    await expect(page.locator('.gantt-chart a[title="February 2027"]').first()).toBeAttached();
  });

  test('"Selected columns" option shows the query columns', async ({ page }) => {
    await page.goto(GANTT);
    const status = page.locator('.gantt-selected-column[data-gantt-column="status"]');
    await expect(status).toBeHidden();
    await openOptions(page);
    await page.locator('#draw_selected_columns').check();
    await expect(status).toBeVisible();
    await expect(status.locator('[data-gantt-row-key="issue-13"]')).toHaveText('In Progress');
  });
});

test.describe('issues summary report', () => {
  test.use({ as: 'admin', dbReset: 'none' });

  test('counts by tracker/priority and details page', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues?set_filter=1');
    // "Summary" lives in the actions dropdown of the issue list
    await page.locator('#content .contextual .drdn-trigger').click();
    await page.locator('#content .contextual a.icon-stats').click();
    await expect(page.locator('#content h2')).toHaveText('Reports');

    const tracker = page.locator('table.issue-report').first();
    const bug = tracker.locator('tr').filter({ has: page.locator('td.name', { hasText: 'Bug' }) });
    await expect(bug.locator('td')).toHaveText(['Bug', '2', '-', '2']);
    const support = tracker.locator('tr').filter({ has: page.locator('td.name', { hasText: 'Support' }) });
    await expect(support.locator('td')).toHaveText(['Support', '-', '1', '1']);
    await expect(tracker.locator('tr.total td')).toHaveText(['Total', '5', '1', '6']);

    // details for trackers: one column per status
    await page.locator('h3', { hasText: 'Tracker' }).locator('a.icon-zoom-in').click();
    await expect(page).toHaveURL(/\/issues\/report\/tracker$/);
    const detailed = page.locator('table.issue-report-detailed');
    await expect(detailed.locator('thead th')).toContainText(['New', 'In Progress', 'Closed']);
    const bugRow = detailed.locator('tr').filter({ has: page.locator('td.name', { hasText: 'Bug' }) });
    await expect(bugRow.locator('td').nth(1)).toHaveText('1'); // New
    await expect(bugRow.locator('td').nth(2)).toHaveText('1'); // In Progress

    // a count links to the filtered issue list
    await bugRow.locator('td').nth(2).locator('a').click();
    await expect(page.locator('#operators_status_id')).toHaveValue('=');
    await expectIssues(page, [13]);
  });
});
