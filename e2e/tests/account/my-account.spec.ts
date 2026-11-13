import { test, expect } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import { anonymousContext, loginViaForm, openAccountMenu } from './_helpers';

const save = (page: import('@playwright/test').Page) => page.locator('#my_account_form p.mobile-hide input[type=submit]').click();

test.describe('My account', () => {
  test.describe('as dlopper', () => {
    test.use({ as: 'dlopper' });

    test('reach My account from the account menu', async ({ page, ui }) => {
      await page.goto('/');
      const menu = await openAccountMenu(page);
      await menu.locator('a.my-account').click();
      await expect(page).toHaveURL(/\/my\/account$/);
      await expect(ui.heading()).toHaveText('My account');
      await expect(page.locator('#sidebar')).toContainText('dlopper');
    });

    test('update name, time zone, comments order and hide e-mail', async ({ page, ui }) => {
      await page.goto('/my/account');
      await page.locator('#user_firstname').fill('David');
      await page.locator('#pref_time_zone').selectOption('Tokyo');
      await page.locator('#pref_comments_sorting').selectOption('desc');
      await page.locator('#pref_hide_mail').uncheck();
      await save(page);
      await expect(page).toHaveURL(/\/my\/account$/);
      await expect(ui.flashNotice()).toHaveText('Account was successfully updated.');

      // the form shows the saved values
      await expect(page.locator('#user_firstname')).toHaveValue('David');
      await expect(page.locator('#pref_time_zone')).toHaveValue('Tokyo');
      await expect(page.locator('#pref_comments_sorting')).toHaveValue('desc');
      await expect(page.locator('#pref_hide_mail')).not.toBeChecked();
      const pref = sql('SELECT time_zone, hide_mail, others FROM user_preferences WHERE user_id = 6')[0];
      expect(pref.time_zone).toBe('Tokyo');
      expect(Number(pref.hide_mail)).toBe(0);
      expect(String(pref.others)).toContain('"comments_sorting":"desc"');
      expect(sqlValue('SELECT firstname FROM users WHERE id = 6')).toBe('David');
    });

    test('validation error keeps the form', async ({ page, ui }) => {
      await page.goto('/my/account');
      await page.locator('#user_mail').fill('not-an-email');
      await save(page);
      await expect(ui.errors()).toContainText('Email is invalid');
      await expect(page.locator('#user_mail')).toHaveValue('not-an-email');
    });

    test('notification option shows the project list for "selected projects"', async ({ page }) => {
      await page.goto('/my/account');
      await expect(page.locator('#notified-projects')).toBeHidden();
      await page.locator('#user_mail_notification').selectOption('selected');
      await expect(page.locator('#notified-projects')).toBeVisible();
      await expect(page.locator('#notified-projects')).toContainText('eCommerce');
    });

    test('switching the language to German translates the UI', async ({ page, ui }) => {
      await page.goto('/my/account');
      await page.locator('#user_language').selectOption('de');
      await save(page);
      // like Redmine, the notice is still built in the language of the request, the next page is German
      await expect(ui.flashNotice()).toHaveText('Account was successfully updated.');
      await expect(ui.heading()).toHaveText('Mein Konto');
      await expect(page.locator('html')).toHaveAttribute('lang', 'de');
      await expect(page.locator('label[for=user_firstname]')).toContainText('Vorname');
      await expect(ui.topMenu('my-page')).toHaveText('Meine Seite');
      // back to English
      await page.locator('#user_language').selectOption('en');
      await save(page);
      await expect(ui.flashNotice()).toHaveText('Konto wurde erfolgreich aktualisiert.');
      await expect(ui.heading()).toHaveText('My account');
    });
  });

  test.describe('API access key (jsmith)', () => {
    test.use({ as: 'jsmith' });

    test('show reveals the key, reset generates a new one', async ({ page, ui }) => {
      const before = String(sqlValue("SELECT value FROM tokens WHERE user_id = 5 AND action = 'api'"));
      await page.goto('/my/account');
      const key = page.locator('#api-access-key');
      await expect(key).toBeHidden();
      await page.locator('#sidebar .api-key-actions a', { hasText: 'Show' }).click();
      await expect(key).toBeVisible();
      await expect(key).toHaveText(before);
      await expect(page.locator('#sidebar .copy-api-key-link')).toBeVisible();
      // toggles back
      await page.locator('#sidebar .api-key-actions a', { hasText: 'Show' }).click();
      await expect(key).toBeHidden();

      await page.locator('#sidebar a[href="/my/api_key"][data-method="post"]').click();
      await expect(ui.flashNotice()).toHaveText('Your API access key was reset.');
      const after = String(sqlValue("SELECT value FROM tokens WHERE user_id = 5 AND action = 'api'"));
      expect(after).not.toBe(before);
      expect(after).toMatch(/^[0-9a-f]{40}$/);
      await page.locator('#sidebar .api-key-actions a', { hasText: 'Show' }).click();
      await expect(key).toHaveText(after);
    });

    test('the API key page (non-JS fallback) shows the key', async ({ page }) => {
      const value = String(sqlValue("SELECT value FROM tokens WHERE user_id = 5 AND action = 'api'"));
      await page.goto('/my/api_key');
      await expect(page.locator('#content .box pre')).toHaveText(value);
    });

    test('Atom key reset', async ({ page, ui }) => {
      await page.goto('/my/account');
      await page.locator('#sidebar a[href="/my/atom_key"]').click();
      await expect(ui.flashNotice()).toHaveText('Your Atom access key was reset.');
      expect(sql("SELECT id FROM tokens WHERE user_id = 5 AND action = 'feeds'")).toHaveLength(1);
    });
  });

  // Changing the password re-authenticates (new session id, the old session is destroyed): never do it with a
  // stored session (test.use({ as })), that would log the user out for every later spec file of the run.
  test.describe('change password (rhill, fresh login)', () => {
    test('wrong current password, then a successful change', async ({ page, ui, browser, mailbox }) => {
      await loginViaForm(page, 'rhill', 'password123');
      await page.goto('/my/account');
      await ui.contextual('Change password').click();
      await expect(ui.heading()).toHaveText('Change password');
      await page.locator('#password').fill('wrong');
      await page.locator('#new_password').fill('rhill-new-pass');
      await page.locator('#new_password_confirmation').fill('rhill-new-pass');
      await page.locator('#content input[type=submit]').click();
      await expect(ui.flashError()).toHaveText('Wrong password');

      await page.locator('#password').fill('password123');
      await page.locator('#new_password').fill('rhill-new-pass');
      await page.locator('#new_password_confirmation').fill('rhill-new-pass');
      await page.locator('#content input[type=submit]').click();
      await expect(page).toHaveURL(/\/my\/account$/);
      await expect(ui.flashNotice()).toHaveText('Password was successfully updated.');
      // still logged in after the change
      await expect(ui.loggedAs()).toHaveText('@rhill');
      await mailbox.waitFor({ to: 'rhill@example.net' });

      // the new password works in a fresh session
      const ctx = await anonymousContext(browser);
      try {
        const p2 = await ctx.newPage();
        await loginViaForm(p2, 'rhill', 'rhill-new-pass');
      } finally {
        await ctx.close();
      }
    });
  });
});
