/**
 * Two-factor authentication (TOTP) end to end: rhill pairs an authenticator app from My account, generates
 * backup codes, then logs in through the login form + the second step with a TOTP code computed by the test,
 * and with a backup code (single use). Finally the administrator disables rhill's 2FA.
 *
 * Symine only: pairing a device permanently changes the user on the reference Redmine.
 */
import { test, expect, ensureFileReset } from '../../support/fixtures';
import { sqlValue } from '../../support/db';
import { IS_EXTERNAL, USERS } from '../../support/env';
import { nextTimeStep, totp } from './totp';

test.skip(IS_EXTERNAL, 'mutates users irreversibly on the target server');
test.describe.configure({ mode: 'serial' });

let key = '';
let backupCodes: string[] = [];

test.beforeAll(async ({}, testInfo) => {
  await ensureFileReset(testInfo);
});

async function loginWithPassword(page: import('@playwright/test').Page, login: keyof typeof USERS): Promise<void> {
  await page.goto('/login');
  await page.locator('#username').fill(USERS[login].login);
  await page.locator('#password').fill(USERS[login].password);
  await page.locator('#login-submit').click();
}

test.describe('pairing', () => {
  test.use({ as: 'rhill' });

  test('rhill enables the authenticator app from My account', async ({ page, ui }) => {
    await page.goto('/my/account');
    await page.getByRole('link', { name: 'Enable authenticator app' }).click();
    await expect(page).toHaveURL(/\/my\/twofa\/totp\/activate\/confirm$/);
    await expect(ui.heading()).toHaveText('Enable two-factor authentication');
    await expect(page.locator('img#twofa_code')).toBeVisible();
    key = (await page.locator('#twofa_form code').innerText()).replace(/\s+/g, '');
    expect(key).toMatch(/^[A-Z2-7]{32}$/);

    // a wrong code is rejected
    await page.locator('input#twofa_code').fill('000000');
    await page.getByRole('button', { name: 'Activate' }).click();
    await ui.expectError('Code is invalid or outdated.');

    await page.locator('input#twofa_code').fill(totp(key));
    await page.getByRole('button', { name: 'Activate' }).click();
    await expect(page).toHaveURL(/\/my\/account$/);
    await ui.expectNotice(/Two-factor authentication successfully enabled/);
    await expect(page.locator('#content')).toContainText('Currently active: Authenticator app');
    expect(sqlValue("SELECT twofa_scheme FROM users WHERE login = 'rhill'")).toBe('totp');
  });

  test('rhill generates backup codes (shown once)', async ({ page, ui }) => {
    test.setTimeout(90_000);
    await page.goto('/my/account');
    await page.getByRole('link', { name: 'Generate backup codes' }).click();
    await expect(page).toHaveURL(/\/my\/twofa\/backup_codes\/confirm$/);
    await nextTimeStep(); // the activation consumed the current time step
    await page.locator('input#twofa_code').fill(totp(key));
    await page.getByRole('button', { name: 'Submit' }).click();
    await expect(page).toHaveURL(/\/my\/twofa\/backup_codes$/);
    await ui.expectNotice('Your backup codes have been generated.');
    backupCodes = (await page.locator('ul.twofa_backup_codes code').allInnerTexts()).map((c) => c.replace(/\s+/g, ''));
    expect(backupCodes).toHaveLength(10);
    await page.goto('/my/twofa/backup_codes');
    await expect(page).toHaveURL(/\/my\/account$/);
    await expect(page.locator('.flash.warning')).toContainText('Backup codes cannot be shown again');
  });
});

test.describe('login', () => {
  test('password + TOTP code', async ({ page, ui }) => {
    test.setTimeout(90_000);
    await loginWithPassword(page, 'rhill');
    await expect(page).toHaveURL(/\/account\/twofa\/confirm$/);
    await expect(page.locator('#login-form h3')).toHaveText('Two-factor authentication');
    // not logged in yet
    await page.goto('/my/page');
    await expect(page).toHaveURL(/\/login\?back_url=/);

    await loginWithPassword(page, 'rhill');
    await page.locator('#twofa_code').fill('123456');
    await page.locator('#login-submit').click();
    await ui.expectError('Code is invalid or outdated.');

    await nextTimeStep();
    await page.locator('#twofa_code').fill(totp(key));
    await page.locator('#login-submit').click();
    await expect(page).toHaveURL(/\/my\/page$/);
    await expect(ui.loggedAs()).toHaveText('@rhill');
  });

  test('password + backup code (single use)', async ({ page, ui }) => {
    await loginWithPassword(page, 'rhill');
    await page.locator('#twofa_code').fill(backupCodes[0].toUpperCase().replace(/(.{4})/g, '$1 '));
    await page.locator('#login-submit').click();
    await expect(page).toHaveURL(/\/my\/page$/);
    await expect(ui.loggedAs()).toHaveText('@rhill');
    expect(Number(sqlValue("SELECT COUNT(*) FROM tokens WHERE action = 'twofa_backup_code' AND value = ?", [backupCodes[0]]))).toBe(0);

    await page.context().clearCookies();
    await loginWithPassword(page, 'rhill');
    await page.locator('#twofa_code').fill(backupCodes[0]);
    await page.locator('#login-submit').click();
    await ui.expectError('Code is invalid or outdated.');
  });

  test('three wrong codes end the login attempt', async ({ page, ui }) => {
    await loginWithPassword(page, 'rhill');
    for (let i = 0; i < 2; i++) {
      await page.locator('#twofa_code').fill('000000');
      await page.locator('#login-submit').click();
      await ui.expectError('Code is invalid or outdated.');
    }
    await page.locator('#twofa_code').fill('000000');
    await page.locator('#login-submit').click();
    await ui.expectError('Too many tries.');
  });
});

test.describe('administration', () => {
  test.use({ as: 'admin' });

  test('the administrator disables rhill\'s two-factor authentication', async ({ page, ui }) => {
    await page.goto(`/users/${USERS.rhill.id}/edit`);
    await expect(page.locator('#content')).toContainText('Currently active: Authenticator app');
    await page.locator(`a[href="/users/${USERS.rhill.id}/twofa/deactivate"]`).click();
    await ui.expectNotice('Two-factor authentication disabled.');
    await expect(page.locator('#content')).toContainText('Not activated');
    expect(sqlValue("SELECT twofa_scheme FROM users WHERE login = 'rhill'")).toBeNull();
  });
});
