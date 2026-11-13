import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import { loginViaForm, setSetting } from './_helpers';

async function fillRegistration(page: import('@playwright/test').Page, login: string, password = 'regist3r-pass', confirmation = password) {
  await page.locator('#user_login').fill(login);
  await page.locator('#user_password').fill(password);
  await page.locator('#user_password_confirmation').fill(confirmation);
  await page.locator('#user_firstname').fill('Reg');
  await page.locator('#user_lastname').fill('Istered');
  await page.locator('#user_mail').fill(`${login}@example.net`);
  await page.locator('#new_user input[type=submit]').click();
}

test.describe('registration', () => {
  test('disabled self-registration hides the link and the page', async ({ page, ui }) => {
    setSetting('self_registration', '0');
    await page.goto('/');
    await expect(ui.topMenu('register')).toHaveCount(0);
    await page.goto('/account/register');
    await expect(page.locator('#new_user')).toHaveCount(0);
  });

  test('validation errors are shown on the form', async ({ page, ui }) => {
    setSetting('self_registration', '2');
    await page.goto('/');
    await ui.topMenu('register').click();
    await expect(ui.heading()).toHaveText('Register');
    await fillRegistration(page, 'jsmith', 'abc', 'abd');
    await expect(ui.errors()).toContainText('Login has already been taken');
    await expect(ui.errors()).toContainText("Password doesn't match confirmation");
    await expect(ui.errors()).toContainText('Password is too short');
  });

  test('manual activation (default): account pending, administrators notified', async ({ page, ui, mailbox }) => {
    setSetting('self_registration', '2');
    const login = uid('man');
    await page.goto('/account/register');
    await fillRegistration(page, login);
    await expect(page).toHaveURL(/\/login$/);
    await expect(ui.flashError()).toHaveText('Your account was created and is now pending administrator approval.');
    expect(Number(sqlValue('SELECT status FROM users WHERE login = ?', [login]))).toBe(2); // registered
    const mail = await mailbox.waitFor({ to: 'admin@dummy.invalid', subject: /account activation request/ });
    expect(mail.text + mail.html).toContain(login);

    // cannot log in yet
    await loginViaForm(page, login, 'regist3r-pass', { expectSuccess: false });
    await expect(ui.flashError()).toContainText('pending administrator approval');
  });

  test('activation by e-mail: follow the link, then sign in', async ({ page, ui, mailbox }) => {
    setSetting('self_registration', '1');
    const login = uid('mail');
    await page.goto('/account/register');
    await fillRegistration(page, login);
    await expect(ui.flashNotice()).toContainText(`An email containing the instructions to activate your account was sent to ${login}@example.net`);
    const userId = sqlValue('SELECT id FROM users WHERE login = ?', [login]);
    expect(sql("SELECT value FROM tokens WHERE user_id = ? AND action = 'register'", [userId])).toHaveLength(1);

    // signing in before the activation explains why
    await loginViaForm(page, login, 'regist3r-pass', { expectSuccess: false });
    await expect(ui.flashError()).toContainText("You haven't activated your account yet");
    await expect(ui.flashError()).not.toContainText('%{url}');

    const mail = await mailbox.waitFor({ to: `${login}@example.net`, subject: /account activation/ });
    const link = mail.links().find((l) => l.includes('/account/activate?token='));
    expect(link).toBeTruthy();
    await page.goto(link!.replace(/^https?:\/\/[^/]+/, ''));
    await expect(page).toHaveURL(/\/login$/);
    await expect(ui.flashNotice()).toHaveText('Your account has been activated. You can now log in.');
    await loginViaForm(page, login, 'regist3r-pass');
  });

  test('automatic activation logs the new user in', async ({ page, ui }) => {
    setSetting('self_registration', '3');
    const login = uid('auto');
    await page.goto('/account/register');
    await fillRegistration(page, login);
    await expect(page).toHaveURL(/\/my\/account$/);
    await expect(ui.flashNotice()).toHaveText('Your account has been activated. You can now log in.');
    await expect(ui.loggedAs()).toHaveText(`@${login}`);
  });
});
