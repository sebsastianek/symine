import { test, expect } from '../../support/fixtures';
import { sql } from '../../support/db';
import { loginViaForm, setSetting } from './_helpers';

test.describe('lost password', () => {
  test('full recovery flow: e-mail link, new password, sign in with it', async ({ page, ui, mailbox }) => {
    await page.goto('/login');
    await page.locator('a.lost_password').click();
    await expect(ui.heading()).toHaveText('Lost password');
    await page.locator('#mail').fill('dlopper@example.net');
    await page.locator('input[type=submit]').click();
    await expect(page).toHaveURL(/\/login$/);
    await expect(ui.flashNotice()).toHaveText('An email with instructions to choose a new password has been sent to you.');

    // a recovery token was stored and mailed
    const tokens = sql("SELECT value FROM tokens WHERE user_id = 6 AND action = 'recovery'");
    expect(tokens).toHaveLength(1);
    const mail = await mailbox.waitFor({ to: 'dlopper@example.net', subject: /password/i });
    const link = mail.links().find((l) => l.includes('/account/lost_password?token='));
    expect(link).toContain(String(tokens[0].value));

    // the token is moved from the URL to the session
    await page.goto(link!.replace(/^https?:\/\/[^/]+/, ''));
    await expect(page).toHaveURL(/\/account\/lost_password$/);
    await expect(page.locator('#new_password')).toBeVisible();

    // validation: confirmation mismatch
    await page.locator('#new_password').fill('n3w-secret-pass');
    await page.locator('#new_password_confirmation').fill('something-else');
    await page.locator('input[type=submit]').click();
    await expect(ui.errors()).toContainText("Password doesn't match confirmation");

    await page.locator('#new_password').fill('n3w-secret-pass');
    await page.locator('#new_password_confirmation').fill('n3w-secret-pass');
    await page.locator('input[type=submit]').click();
    await expect(page).toHaveURL(/\/login$/);
    await expect(ui.flashNotice()).toHaveText('Password was successfully updated.');
    expect(sql("SELECT id FROM tokens WHERE user_id = 6 AND action = 'recovery'")).toHaveLength(0);

    // old password is refused, the new one works
    await loginViaForm(page, 'dlopper', 'password123', { expectSuccess: false });
    await expect(ui.flashError()).toHaveText('Invalid user or password');
    await loginViaForm(page, 'dlopper', 'n3w-secret-pass');
  });

  test('used or unknown token is rejected', async ({ page }) => {
    await page.goto('/account/lost_password?token=doesnotexist0000000000000000000000000000');
    await expect(page).not.toHaveURL(/lost_password/);
    await expect(page.locator('#new_password')).toHaveCount(0);
  });

  test('unknown e-mail address does not reveal anything and sends no mail', async ({ page, ui, mailbox }) => {
    await page.goto('/account/lost_password');
    await page.locator('#mail').fill('nobody@example.net');
    await page.locator('input[type=submit]').click();
    await expect(ui.flashNotice()).toHaveText('An email with instructions to choose a new password has been sent to you.');
    // a known address afterwards: only that one gets a mail
    await page.goto('/account/lost_password');
    await page.locator('#mail').fill('rhill@example.net');
    await page.locator('input[type=submit]').click();
    await mailbox.waitFor({ to: 'rhill@example.net' });
    expect(mailbox.find({ to: 'nobody@example.net' })).toHaveLength(0);
  });

  test('link is hidden when lost password is disabled', async ({ page }) => {
    setSetting('lost_password', '0');
    await page.goto('/login');
    await expect(page.locator('a.lost_password')).toHaveCount(0);
    await page.goto('/account/lost_password');
    await expect(page).not.toHaveURL(/lost_password/);
  });
});
