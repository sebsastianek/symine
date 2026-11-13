import * as fs from 'fs';
import type { Page } from '@playwright/test';
import { test, expect, ensureFileReset } from '../../support/fixtures';
import { apiAs } from '../../support/api';
import { applyQuery, clickAndWait } from '../issue-list/_helpers';

/*
 * Data after beforeAll (ecommerce):
 *   #1 dlopper 2.5h Design      2026-11-06 issue #13   (seed)
 *   #2 jsmith  1h   Design      2026-11-07             (seed)
 *   +  jsmith  4h   Development 2026-10-20 issue #15
 *   +  dlopper 0.5h Development 2026-11-07 issue #14
 */
test.beforeAll(async ({}, testInfo) => {
  await ensureFileReset(testInfo);
  const jsmith = await apiAs('jsmith');
  await jsmith.logTime({ issue_id: 15, hours: 4, activity_id: 9, spent_on: '2026-10-20', comments: 'October work' });
  await jsmith.dispose();
  const dlopper = await apiAs('dlopper');
  await dlopper.logTime({ issue_id: 14, hours: 0.5, activity_id: 9, spent_on: '2026-11-07', comments: 'Small fix' });
  await dlopper.dispose();
});

const LIST = '/projects/ecommerce/time_entries?set_filter=1';
const total = (page: Page) => page.locator('p.query-totals .total-for-hours .value');
const rows = (page: Page) => page.locator('table.time-entries tbody tr.time-entry');

test.describe('time entries list', () => {
  test.use({ as: 'admin' });

  test('lists all entries with the hours total', async ({ page }) => {
    await page.goto(LIST);
    await expect(rows(page)).toHaveCount(4);
    await expect(total(page)).toHaveText('8:00');
    // default sort: date desc
    await expect(rows(page).locator('td.spent_on')).toHaveText(['11/07/2026', '11/07/2026', '11/06/2026', '10/20/2026']);
  });

  test('date filter "between"', async ({ page }) => {
    await page.goto(LIST);
    await page.locator('#operators_spent_on').selectOption('><');
    await page.locator('#values_spent_on_1').fill('2026-11-01');
    await page.locator('#values_spent_on_2').fill('2026-11-30');
    await applyQuery(page);
    await expect(rows(page)).toHaveCount(3);
    await expect(total(page)).toHaveText('4:00');
  });

  test('user filter and activity filter', async ({ page }) => {
    await page.goto(LIST);
    await page.locator('#add_filter_select').selectOption('user_id');
    await page.locator('#values_user_id_1').selectOption({ label: 'Dave Lopper' });
    await applyQuery(page);
    await expect(rows(page).locator('td.user')).toHaveText(['Dave Lopper', 'Dave Lopper']);
    await expect(total(page)).toHaveText('3:00');

    await page.locator('#cb_user_id').uncheck();
    await page.locator('#add_filter_select').selectOption('activity_id');
    await page.locator('#values_activity_id_1').selectOption({ label: 'Development' });
    await applyQuery(page);
    await expect(rows(page).locator('td.activity')).toHaveText(['Development', 'Development']);
    await expect(total(page)).toHaveText('4:30');
  });

  test('issue filter from the spent time link of an issue', async ({ page }) => {
    await page.goto('/issues/13');
    await page.locator('.attributes .spent-time .value a').click();
    await expect(rows(page)).toHaveCount(1);
    await expect(total(page)).toHaveText('2:30');
  });

  test('sort by hours and group by user with totals', async ({ page }) => {
    await page.goto(LIST);
    await clickAndWait(page, page.locator('table.time-entries thead th.hours a'));
    await expect(rows(page).locator('td.hours')).toHaveText(['0:30', '1:00', '2:30', '4:00']);

    await page.locator('fieldset#options > legend').click();
    await page.locator('#group_by').selectOption('user');
    await applyQuery(page);
    const groups = page.locator('table.time-entries tr.group');
    await expect(groups.locator('.name')).toHaveText(['Dave Lopper', 'John Smith'].sort());
    const smith = groups.filter({ hasText: 'John Smith' });
    await expect(smith.locator('.count')).toHaveText('2');
    await expect(smith.locator('.totals .value')).toHaveText('5:00');
  });

  test('CSV export of the list', async ({ page }) => {
    await page.goto(LIST);
    await page.locator('p.other-formats a.csv').click();
    await expect(page.locator('.ui-dialog #csv-export-options')).toBeVisible();
    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.locator('#csv-export-options input[type=submit]').click(),
    ]);
    const text = fs.readFileSync((await download.path())!, 'utf8').replace(/^﻿/, '');
    const lines = text.trim().split(/\r?\n/);
    expect(lines[0]).toBe('Date,User,Activity,Issue,Comment,Hours');
    expect(lines.length).toBeGreaterThanOrEqual(5); // header + 4 entries (+ optional totals line)
    expect(text).toContain('October work');
  });
});

test.describe('time report', () => {
  test.use({ as: 'admin' });
  const REPORT = '/projects/ecommerce/time_entries/report?set_filter=1';
  const report = (page: Page) => page.locator('table#time-report');

  test('add criteria, change period, check totals', async ({ page }) => {
    await page.goto(LIST);
    await page.locator('.tabs a', { hasText: 'Report' }).click();
    await expect(page).toHaveURL(/\/time_entries\/report/);

    // "Add" criteria select submits the form on change
    await Promise.all([page.waitForURL(/criteria/), page.locator('#criterias').selectOption('user')]);
    await expect(report(page).locator('thead th').first()).toHaveText('User');
    await expect(report(page).locator('tr.total td.hours').last()).toHaveText('8:00');
    // already used criteria disappear from the select
    await expect(page.locator('#criterias option[value="user"]')).toHaveCount(0);

    // default period is month: 2026-10 and 2026-11
    await expect(page.locator('#columns')).toHaveValue('month');
    await expect(report(page).locator('thead th.period')).toHaveText(['2026-10', '2026-11']);
    const smith = report(page).locator('tbody tr').filter({ hasText: 'John Smith' }).first();
    await expect(smith.locator('td.hours')).toHaveText(['4:00', '1:00', '5:00']);

    // second level criteria: activity
    await Promise.all([page.waitForResponse((r) => r.url().includes('/time_entries/report')), page.locator('#criterias').selectOption('activity')]);
    await expect(report(page).locator('thead th').nth(1)).toHaveText('Activity');
    await expect(report(page).locator('tr.last-level td.name', { hasText: 'Development' })).toHaveCount(2);

    // switch the period to year: a single period column
    await Promise.all([page.waitForURL(/columns=year/), page.locator('#columns').selectOption('year')]);
    await expect(report(page).locator('thead th.period')).toHaveText(['2026']);
    await expect(report(page).locator('tr.total td.hours')).toHaveText(['8:00', '8:00']);

    // weeks
    await Promise.all([page.waitForURL(/columns=week/), page.locator('#columns').selectOption('week')]);
    await expect(report(page).locator('thead th.period')).toHaveText(['2026-43', '2026-44', '2026-45']);

    // Clear removes all criteria
    await clickAndWait(page, page.locator('#query_form a.icon-reload', { hasText: 'Clear' }).last());
    await expect(report(page).locator('tbody tr.subtotal, tbody tr.last-level')).toHaveCount(0);
  });

  test('report by issue honours the filters', async ({ page }) => {
    await page.goto(`${REPORT}&columns=month&criteria[]=issue`);
    await expect(report(page).locator('tbody tr').filter({ hasText: '#15' }).locator('td.hours').last()).toHaveText('4:00');
    await page.locator('#add_filter_select').selectOption('activity_id');
    await page.locator('#values_activity_id_1').selectOption({ label: 'Design' });
    await applyQuery(page);
    await expect(report(page).locator('tbody tr').filter({ hasText: '#15' })).toHaveCount(0);
    await expect(report(page).locator('tr.total td.hours').last()).toHaveText('3:30');
  });

  test('CSV export of the report', async ({ page }) => {
    await page.goto(`${REPORT}&columns=month&criteria[]=user`);
    await page.locator('p.other-formats a.csv').click();
    await expect(page.locator('.ui-dialog #csv-export-options')).toBeVisible();
    await page.locator('.ui-dialog #csv-export-options select#encoding').selectOption('UTF-8');
    const [download] = await Promise.all([page.waitForEvent('download'), page.locator('#csv-export-button').click()]);
    const text = fs.readFileSync((await download.path())!, 'utf8').replace(/^﻿/, '');
    const lines = text.trim().split(/\r?\n/);
    expect(lines[0]).toBe('User,2026-10,2026-11,Total time');
    expect(lines.find((l) => l.startsWith('John Smith'))).toBe('John Smith,4.00,1.00,5.00');
    expect(lines.at(-1)).toBe('Total time,4.00,4.00,8.00');
  });
});
