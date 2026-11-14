import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import type { Page } from '@playwright/test';
import { formLogin } from '../admin/_helpers';

/*
 * CSV import wizard (ImportsController): upload -> options -> fields mapping (with the file preview) -> run (ajax
 * progress) -> result page. Only the Manager role has import_issues / import_time_entries; users are imported by
 * administrators.
 */

function csv(lines: string[][], separator = ';'): Buffer {
  return Buffer.from(lines.map((cells) => cells.map((c) => (/[;,"\n]/.test(c) ? `"${c.replace(/"/g, '""')}"` : c)).join(separator)).join('\n') + '\n');
}

/** Step 1: selects the CSV file and goes to the options step. */
async function upload(page: Page, name: string, buffer: Buffer): Promise<void> {
  await page.locator('#file').setInputFiles({ name, mimeType: 'text/csv', buffer });
  await page.locator('#content input[type=submit]').click();
  await expect(page).toHaveURL(/\/imports\/[0-9a-f]+\/settings$/);
  await expect(page.locator('#import-form fieldset legend')).toHaveText('Options');
}

/** Step 2: separator / wrapper / date format, then the mapping step. */
async function options(page: Page, separator: ';' | ',', dateFormat = '%Y-%m-%d'): Promise<void> {
  await page.locator('#import_settings_separator').selectOption(separator);
  await page.locator('#import_settings_wrapper').selectOption('"');
  await page.locator('#import_settings_encoding').selectOption('UTF-8');
  await page.locator('#import_settings_date_format').selectOption(dateFormat);
  await page.locator('#import-form input[type=submit]').click();
  await expect(page).toHaveURL(/\/imports\/[0-9a-f]+\/mapping$/);
}

/** Maps a field to the CSV column with that header. */
async function map(page: Page, field: string, column: string): Promise<void> {
  await page.locator(`#import_mapping_${field}`).selectOption({ label: column });
}

/**
 * Maps the tracker. Its change posts the form to mapping.js and the response replaces the whole fields mapping:
 * wait until the old <select> node is gone, otherwise selections made in between are overwritten.
 */
async function mapTracker(page: Page, option: string): Promise<void> {
  const tracker = page.locator('#import_mapping_tracker');
  await tracker.evaluate((el) => el.setAttribute('data-e2e-stale', '1'));
  await tracker.selectOption({ label: option });
  await expect(page.locator('#import_mapping_tracker[data-e2e-stale]')).toHaveCount(0);
  await expect(tracker.locator('option:checked')).toHaveText(option);
}

/** Step 3 -> 4: starts the import and waits for the result page (the run page polls run.js until finished). */
async function run(page: Page): Promise<void> {
  await page.locator('#import-form input[type=submit]').click();
  await expect(page).toHaveURL(/\/imports\/[0-9a-f]+$/, { timeout: 30_000 });
}

test.describe('issues import', () => {
  test.use({ as: 'jsmith' });

  test('the "..." menu of the issue list offers "Import"', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues?set_filter=1');
    await page.locator('#content > .contextual .drdn-trigger').click();
    await page.locator('#content > .contextual .drdn-content a.icon-import').click();
    await expect(page).toHaveURL(/\/issues\/imports\/new\?project_id=ecommerce$/);
    await expect(page.locator('#content h2')).toHaveText('Import issues');
  });

  test('wizard: upload, options, mapping with preview, run, result with errors', async ({ page }) => {
    const tag = uid('Imp');
    const file = csv([
      ['Title', 'Kind', 'Prio', 'Area', 'Milestone', 'Begin', 'Owner', 'Estimate', 'Details'],
      [`${tag} first`, 'Bug', 'High', 'UI', '1.0', '2026-11-20', 'dlopper', '3.5', 'Line one; with a separator'],
      [`${tag} second`, 'Feature', 'Urgent', `${tag} area`, '', '2026-11-21', '', '', ''],
      ['', 'Bug', 'Low', '', '', '', '', '', 'no subject: rejected'],
    ]);

    await page.goto('/projects/ecommerce/issues/imports/new');
    await expect(page.locator('#content h2')).toHaveText('Import issues');

    await upload(page, 'issues.csv', file);
    await options(page, ';');

    // mapping step: project preselected, the file content is previewed
    await expect(page.locator('#import_mapping_project_id')).toHaveValue('1');
    const preview = page.locator('table.sample-data');
    await expect(preview.locator('tr').first().locator('td')).toHaveText(['Title', 'Kind', 'Prio', 'Area', 'Milestone', 'Begin', 'Owner', 'Estimate', 'Details']);
    await expect(preview.locator('tr').nth(1).locator('td').first()).toHaveText(`${tag} first`);
    await expect(preview.locator('tr').nth(1).locator('td').last()).toHaveText('Line one; with a separator');

    await mapTracker(page, 'Kind');
    await map(page, 'subject', 'Title');
    await map(page, 'description', 'Details');
    await map(page, 'priority', 'Prio');
    await map(page, 'category', 'Area');
    await page.locator('#import_settings_mapping_create_categories').check();
    await map(page, 'fixed_version', 'Milestone');
    await map(page, 'assigned_to', 'Owner');
    await map(page, 'start_date', 'Begin');
    await map(page, 'estimated_hours', 'Estimate');

    await run(page);

    // result page: two issues saved, the third row is reported with its position and the validation message
    await expect(page.locator('#content h2')).toHaveText('Import issues');
    await expect(page.locator('#content')).toContainText('2 items have been imported');
    const saved = page.locator('#saved-items li');
    await expect(saved).toHaveCount(2);
    await expect(saved.nth(0)).toContainText(`${tag} first`);
    await expect(saved.nth(1)).toContainText(`${tag} second`);
    await expect(page.locator('#content .flash.error')).toContainText('1 out of 3 items could not be imported');
    const unsaved = page.locator('#unsaved-items tbody tr');
    await expect(unsaved).toHaveCount(1);
    await expect(unsaved.locator('td').first()).toHaveText('3');
    await expect(unsaved.locator('td').nth(1)).toContainText('Subject cannot be blank');

    // saved values
    const first = sql('SELECT * FROM issues WHERE subject = ?', [`${tag} first`])[0];
    expect(first).toMatchObject({ project_id: 1, tracker_id: 1, priority_id: 3, category_id: 1, fixed_version_id: 1, assigned_to_id: 6, author_id: 5, start_date: '2026-11-20', estimated_hours: 3.5 });
    expect(first.description).toBe('Line one; with a separator');
    const second = sql('SELECT * FROM issues WHERE subject = ?', [`${tag} second`])[0];
    expect(second).toMatchObject({ tracker_id: 2, priority_id: 4, assigned_to_id: null, start_date: '2026-11-21' });
    // the missing category was created
    expect(sqlValue('SELECT name FROM issue_categories WHERE id = ?', [second.category_id as number])).toBe(`${tag} area`);
    expect(Number(sqlValue('SELECT COUNT(*) FROM issues WHERE description = ?', ['no subject: rejected']))).toBe(0);

    // "View all issues" lists exactly the imported ones
    await page.locator('#content').getByRole('link', { name: 'View all issues' }).click();
    await expect(page).toHaveURL(/\/issues\?/);
    await expect(page.locator('table.issues tbody tr.issue')).toHaveCount(2);
    await expect(page.locator('table.issues tbody')).toContainText(`${tag} first`);
    await expect(page.locator('table.issues tbody')).toContainText(`${tag} second`);

    // the imported issue is a normal issue
    await page.locator('table.issues td.subject a', { hasText: `${tag} first` }).click();
    await expect(page.locator('.issue.details .attributes .assigned-to .value')).toContainText('Dave Lopper');
    await expect(page.locator('.issue.details .attributes .category .value')).toHaveText('UI');
    await expect(page.locator('.issue.details .attributes .fixed-version .value')).toContainText('1.0');
  });

  test('a fixed tracker value and "Previous" back to the options', async ({ page }) => {
    const tag = uid('Fix');
    const file = csv([['Summary', 'When'], [`${tag} one`, '25/11/2026']], ',');
    await page.goto('/projects/ecommerce/issues/imports/new');
    await upload(page, 'short.csv', file);
    await options(page, ',', '%d/%m/%Y');

    // "Previous" returns to the options, which kept the chosen values
    await page.locator('#import-form button[name=previous]').click();
    await expect(page).toHaveURL(/\/settings$/);
    await expect(page.locator('#import_settings_separator')).toHaveValue(',');
    await expect(page.locator('#import_settings_date_format')).toHaveValue('%d/%m/%Y');
    await page.locator('#import-form input[type=submit]').click();
    await expect(page).toHaveURL(/\/mapping$/);

    // tracker as a fixed value for every row (options below the "--" separator of the select)
    await mapTracker(page, 'Support');
    await map(page, 'subject', 'Summary');
    await map(page, 'start_date', 'When');
    await run(page);

    await expect(page.locator('#content')).toContainText('1 items have been imported');
    await expect(page.locator('#unsaved-items')).toHaveCount(0);
    const issue = sql('SELECT tracker_id, start_date, status_id FROM issues WHERE subject = ?', [`${tag} one`])[0];
    expect(issue).toMatchObject({ tracker_id: 3, start_date: '2026-11-25', status_id: 1 });
  });

  test('a file that does not match the options is refused with an error', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/issues/imports/new');
    // no file selected: stays on the first step
    await page.locator('#content input[type=submit]').click();
    await expect(page.locator('#file')).toBeVisible();
    await expect(page).not.toHaveURL(/\/settings$/);

    // unbalanced quotes: not parsable as CSV with the double quote wrapper
    await page.goto('/projects/ecommerce/issues/imports/new');
    await upload(page, 'broken.csv', Buffer.from('Subject,Tracker\n"never closed,Bug\nother,"x" y\n'));
    await page.locator('#import_settings_separator').selectOption(',');
    await page.locator('#import_settings_wrapper').selectOption('"');
    await page.locator('#import-form input[type=submit]').click();
    await ui.expectError(/The file is not a CSV file or does not match the settings below/);
    await expect(page).toHaveURL(/\/settings$/);
  });

  test('a developer has no import link and gets 403', async ({ loginAs }) => {
    const { page, http } = await loginAs('dlopper');
    await page.goto('/projects/ecommerce/issues?set_filter=1');
    await expect(page.locator('#content > .contextual a.icon-import')).toHaveCount(0);
    await http.expectStatus('/projects/ecommerce/issues/imports/new', 403);
  });
});

test.describe('time entries import', () => {
  test.use({ as: 'jsmith' });

  test('imports spent time for several users with a fixed activity', async ({ page }) => {
    const tag = uid('TImp');
    const file = csv([
      ['Who', 'Day', 'Time', 'Ticket', 'Note'],
      ['dlopper', '2026-11-10', '1.5', '13', `${tag} a`],
      ['jsmith', '2026-11-11', '0:45', '', `${tag} b`],
      ['dlopper', '2026-11-12', 'abc', '13', `${tag} c`],
    ]);

    await page.goto('/projects/ecommerce/time_entries?set_filter=1');
    await page.locator('#content > .contextual .drdn-trigger').click();
    await page.locator('#content > .contextual .drdn-content a.icon-import').click();
    await expect(page.locator('#content h2')).toHaveText('Import time entries');

    await upload(page, 'time.csv', file);
    await options(page, ';');

    await page.locator('#import_mapping_activity').selectOption({ label: 'Development' });
    // the user defaults to the importing user; map it to the column instead
    await expect(page.locator('#import_mapping_user')).toHaveValue('value:5');
    await map(page, 'user', 'Who');
    await map(page, 'issue_id', 'Ticket');
    await map(page, 'spent_on', 'Day');
    await map(page, 'hours', 'Time');
    await map(page, 'comments', 'Note');
    await run(page);

    await expect(page.locator('#content')).toContainText('2 items have been imported');
    await expect(page.locator('#content .flash.error')).toContainText('1 out of 3 items could not be imported');
    await expect(page.locator('#unsaved-items tbody tr td').first()).toHaveText('3');
    const savedRows = page.locator('#saved-items tbody tr');
    await expect(savedRows).toHaveCount(2);
    await expect(savedRows.first().locator('td')).toContainText(['eCommerce', 'Dave Lopper', 'Development', '#13', '11/10/2026', '1:30', `${tag} a`]);

    expect(sql('SELECT user_id, hours, spent_on, issue_id, activity_id, project_id FROM time_entries WHERE comments = ?', [`${tag} a`])[0])
      .toEqual({ user_id: 6, hours: 1.5, spent_on: '2026-11-10', issue_id: 13, activity_id: 9, project_id: 1 });
    expect(sql('SELECT user_id, hours, issue_id FROM time_entries WHERE comments = ?', [`${tag} b`])[0]).toEqual({ user_id: 5, hours: 0.75, issue_id: null });

    // the entries show up in the spent time list
    await page.goto('/projects/ecommerce/time_entries?set_filter=1&spent_on=*');
    const row = page.locator('table.time-entries tr.time-entry').filter({ hasText: `${tag} a` });
    await expect(row.locator('td.user')).toHaveText('Dave Lopper');
    await expect(row.locator('td.activity')).toHaveText('Development');
    await expect(row.locator('td.hours')).toHaveText('1:30');
  });
});

test.describe('users import', () => {
  test.use({ as: 'admin' });

  test('administrator imports users who can then sign in', async ({ page, browser }) => {
    const login = uid('imp').toLowerCase();
    const file = csv(
      [
        ['Login', 'First', 'Last', 'Email', 'Secret', 'Admin'],
        [login, 'Imported', 'Person', `${login}@example.net`, 'importedPass123', 'no'],
        ['jsmith', 'Dup', 'Licate', `${login}-dup@example.net`, 'importedPass123', 'no'],
      ],
      ',',
    );

    await page.goto('/users');
    await page.locator('#content > .contextual .drdn-trigger').click();
    await page.locator('#content > .contextual .drdn-content a.icon-import').click();
    await expect(page.locator('#content h2')).toHaveText('Import users');

    await upload(page, 'users.csv', file);
    await options(page, ',');
    await map(page, 'login', 'Login');
    await map(page, 'firstname', 'First');
    await map(page, 'lastname', 'Last');
    await map(page, 'mail', 'Email');
    await map(page, 'password', 'Secret');
    await map(page, 'admin', 'Admin');
    await run(page);

    await expect(page.locator('#content')).toContainText('1 items have been imported');
    const saved = page.locator('#saved-items tbody tr');
    await expect(saved).toHaveCount(1);
    await expect(saved.locator('td')).toContainText([login, 'Imported', 'Person', `${login}@example.net`, '', 'active']);
    // the duplicate login is rejected
    await expect(page.locator('#unsaved-items tbody tr')).toHaveCount(1);
    await expect(page.locator('#unsaved-items tbody tr td').nth(1)).toContainText('Login has already been taken');
    expect(sql('SELECT firstname, lastname, admin, status FROM users WHERE login = ?', [login])[0]).toEqual({ firstname: 'Imported', lastname: 'Person', admin: 0, status: 1 });

    // listed among the users, and the account works
    await page.goto(`/users?set_filter=1&f[]=login&op[login]=~&v[login][]=${login}`);
    await expect(page.locator('table.users tbody tr')).toHaveCount(1);
    await expect(page.locator('table.users tbody tr td.login, table.users tbody tr td.username').first()).toContainText(login);

    const session = await formLogin(browser, login, 'importedPass123');
    await expect(session.page.locator('#account .user-login')).toHaveText(new RegExp(login));
    await session.close();
  });
});
