import { request, type Page } from '@playwright/test';
import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import { BASE_URL } from '../../support/env';
import { anonymousPage, loginViaForm, setSetting } from './_helpers';

/*
 * The account of a newcomer from registration to lock-out, with several browsers ("devices") at once: what a change
 * made on one device, or by the administrator, does to the sessions and keys used elsewhere.
 * A user created by the story is used throughout, so the stored sessions of the seeded users are never touched.
 */

/** Status of an API call authenticated with an API key. */
async function apiStatus(key: string, path = '/users/current.json'): Promise<number> {
  const ctx = await request.newContext({ baseURL: BASE_URL, extraHTTPHeaders: { 'X-Redmine-API-Key': key, Accept: 'application/json' } });
  const res = await ctx.get(path);
  await ctx.dispose();
  return res.status();
}

const path = (url: string) => url.replace(/^https?:\/\/[^/]+/, '');
const loggedAs = (page: Page) => page.locator('#account .user-login');

test.describe('account and security', () => {
  test('register with e-mail activation, reset and change the password, rotate the API key, get locked', async ({ browser, loginAs, mailbox }) => {
    test.setTimeout(240_000);
    setSetting('self_registration', '1'); // account activation by e-mail
    const login = uid('newbie').toLowerCase();
    const mail = `${login}@example.net`;
    const passwords = { first: 'first-Passw0rd', reset: 'reset-Passw0rd', changed: 'changed-Passw0rd' };
    const laptop = await anonymousPage(browser);
    const phone = await anonymousPage(browser);
    const admin = await loginAs('admin');
    let userId = 0;

    await test.step('registration: the account stays unusable until the mailed link is followed', async () => {
      const { page } = laptop;
      await page.goto('/');
      await page.locator('#top-menu a.register').click();
      await page.locator('#user_login').fill(login);
      await page.locator('#user_password').fill(passwords.first);
      await page.locator('#user_password_confirmation').fill(passwords.first);
      await page.locator('#user_firstname').fill('New');
      await page.locator('#user_lastname').fill('Comer');
      await page.locator('#user_mail').fill(mail);
      await page.locator('#new_user input[type=submit]').click();
      await expect(page.locator('#flash_notice')).toContainText(`An email containing the instructions to activate your account was sent to ${mail}`);
      userId = Number(sqlValue('SELECT id FROM users WHERE login = ?', [login]));
      expect(sqlValue('SELECT status FROM users WHERE id = ?', [userId])).toBe(2); // registered

      await loginViaForm(page, login, passwords.first, { expectSuccess: false });
      await expect(page.locator('#flash_error')).toContainText("You haven't activated your account yet");
      // the administrator sees the pending account
      await admin.page.goto('/users?set_filter=1&f[]=status&op[status]==&v[status][]=2');
      await expect(admin.page.locator(`tr#user-${userId}`)).toHaveClass(/registered/);

      const activation = await mailbox.waitFor({ to: mail, subject: /account activation/ });
      const link = activation.links().find((l) => l.includes('/account/activate?token='));
      expect(link).toBeTruthy();
      await page.goto(path(link!));
      await expect(page.locator('#flash_notice')).toHaveText('Your account has been activated. You can now log in.');
      expect(sqlValue('SELECT status FROM users WHERE id = ?', [userId])).toBe(1);
      // the link works once
      await page.goto(path(link!));
      await expect(page.locator('#flash_notice')).toHaveCount(0);

      await loginViaForm(page, login, passwords.first);
      await page.goto('/my/account');
      await expect(page.locator('#user_mail')).toHaveValue(mail);
    });

    await test.step('lost password on the phone: the mailed token sets a new password and ends the session of the laptop', async () => {
      const { page } = phone;
      await page.goto('/login');
      await page.locator('a.lost_password').click();
      await page.locator('#mail').fill(mail);
      await page.locator('#content input[type=submit]').click();
      await expect(page.locator('#flash_notice')).toHaveText('An email with instructions to choose a new password has been sent to you.');
      const recovery = await mailbox.waitFor({ to: mail, subject: /password/i });
      const link = recovery.links().find((l) => l.includes('/account/lost_password?token='));
      expect(link).toContain(String(sqlValue("SELECT value FROM tokens WHERE user_id = ? AND action = 'recovery'", [userId])));

      // the laptop is still logged in at this point
      await laptop.page.goto('/my/page');
      await expect(loggedAs(laptop.page)).toHaveText(`@${login}`);

      await page.goto(path(link!));
      await page.locator('#new_password').fill(passwords.reset);
      await page.locator('#new_password_confirmation').fill(passwords.reset);
      await page.locator('#content input[type=submit]').click();
      await expect(page.locator('#flash_notice')).toHaveText('Password was successfully updated.');
      expect(sql("SELECT id FROM tokens WHERE user_id = ? AND action = 'recovery'", [userId])).toEqual([]);

      // the session opened with the old password is gone
      await laptop.page.goto('/my/page');
      await expect(laptop.page).toHaveURL(/\/login\?back_url=/);
      await loginViaForm(laptop.page, login, passwords.first, { expectSuccess: false });
      await expect(laptop.page.locator('#flash_error')).toHaveText('Invalid user or password');
      // new password: after the login he lands on the page he asked for
      await laptop.page.goto('/my/page');
      await loginViaForm(laptop.page, login, passwords.reset);
      await expect(laptop.page).toHaveURL(/\/my\/page$/);
      await loginViaForm(page, login, passwords.reset);
    });

    await test.step('password changed on the laptop: it stays logged in, the phone is logged out', async () => {
      const { page } = laptop;
      mailbox.clear();
      await page.goto('/my/account');
      await page.locator('#content .contextual a', { hasText: 'Change password' }).click();
      await page.locator('#password').fill(passwords.reset);
      await page.locator('#new_password').fill(passwords.changed);
      await page.locator('#new_password_confirmation').fill(passwords.changed);
      await page.locator('#content input[type=submit]').click();
      await expect(page.locator('#flash_notice')).toHaveText('Password was successfully updated.');
      await page.goto('/my/page');
      await expect(loggedAs(page)).toHaveText(`@${login}`);
      // a security notification tells him about the change
      const notice = await mailbox.waitFor({ to: mail });
      expect(notice.text + notice.html).toMatch(/password/i);

      await phone.page.goto('/my/page');
      await expect(phone.page).toHaveURL(/\/login\?back_url=/);
      await loginViaForm(phone.page, login, passwords.reset, { expectSuccess: false });
      await expect(phone.page.locator('#flash_error')).toHaveText('Invalid user or password');
      await loginViaForm(phone.page, login, passwords.changed);
    });

    let apiKey = '';
    await test.step('API key: shown on My account, a reset invalidates the old key at once', async () => {
      const { page } = laptop;
      await page.goto('/my/api_key');
      apiKey = (await page.locator('#content .box pre').innerText()).trim();
      expect(apiKey).toMatch(/^[0-9a-f]{40}$/);
      expect(await apiStatus(apiKey)).toBe(200);
      // a key that differs by its last character is refused
      expect(await apiStatus(apiKey.slice(0, 39) + (apiKey.endsWith('0') ? '1' : '0'))).toBe(401);

      await page.goto('/my/account');
      await page.locator('#sidebar a[href="/my/api_key"][data-method="post"]').click();
      await expect(page.locator('#flash_notice')).toHaveText('Your API access key was reset.');
      const fresh = String(sqlValue("SELECT value FROM tokens WHERE user_id = ? AND action = 'api'", [userId]));
      expect(fresh).not.toBe(apiKey);
      expect(await apiStatus(apiKey)).toBe(401);
      expect(await apiStatus(fresh)).toBe(200);
      apiKey = fresh;
      // the password changes above did not touch the key mechanism: sessions and keys are independent
      await phone.page.goto('/my/page');
      await expect(loggedAs(phone.page)).toHaveText(`@${login}`);
    });

    await test.step('"authentication required": anonymous visitors are sent to the login form, keys still work', async () => {
      const visitor = await anonymousPage(browser);
      await visitor.page.goto('/projects/ecommerce/issues');
      await expect(visitor.page.locator('table.list.issues')).toBeVisible();

      setSetting('login_required', '1');
      for (const p of ['/', '/projects', '/projects/ecommerce/issues', '/issues/13', '/projects/ecommerce/wiki']) {
        await visitor.page.goto(p);
        await expect(visitor.page, p).toHaveURL(/\/login(\?back_url=.*)?$/);
      }
      // the login and lost password pages stay reachable, the API asks for credentials
      await visitor.page.goto('/account/lost_password');
      await expect(visitor.page.locator('#mail')).toBeVisible();
      const anon = await request.newContext({ baseURL: BASE_URL, extraHTTPHeaders: { Accept: 'application/json' } });
      expect((await anon.get('/projects.json')).status()).toBe(401);
      expect((await anon.get('/issues/13.json')).status()).toBe(401);
      await anon.dispose();
      expect(await apiStatus(apiKey, '/issues/13.json')).toBe(200);

      // logging in brings the visitor to the page he wanted
      await visitor.page.goto('/issues/13');
      await loginViaForm(visitor.page, login, passwords.changed);
      await expect(visitor.page).toHaveURL(/\/issues\/13$/);
      await expect(visitor.page.locator('#content h2')).toContainText('#13');
      setSetting('login_required', '0');
      await visitor.context.close();
    });

    await test.step('the administrator locks the account: every session and the key stop working, until it is unlocked', async () => {
      const { page, ui } = admin;
      await laptop.page.goto('/projects/ecommerce/issues/new');
      await laptop.page.locator('#issue_subject').fill('Typed just before being locked');

      await page.goto('/users?set_filter=1&f[]=status&op[status]==&v[status][]=1'); // active users (the list remembers its last filter)
      await page.locator(`tr#user-${userId} td.login a`).click();
      await page.locator('#content > .contextual a.icon-lock').click();
      await ui.expectNotice('Successful update.');
      expect(sqlValue('SELECT status FROM users WHERE id = ?', [userId])).toBe(3);

      // the form he was filling cannot be submitted, both devices are out
      await laptop.page.locator('#issue-form input[name=commit]').click();
      await expect(laptop.page).toHaveURL(/\/login/);
      expect(Number(sqlValue("SELECT COUNT(*) FROM issues WHERE subject = 'Typed just before being locked'"))).toBe(0);
      await phone.page.goto('/my/page');
      await expect(phone.page).toHaveURL(/\/login\?back_url=/);
      await loginViaForm(phone.page, login, passwords.changed, { expectSuccess: false });
      await expect(phone.page.locator('#flash_error')).toHaveText('Your account is locked.');
      expect(await apiStatus(apiKey)).toBe(401);
      // no password recovery for a locked account
      await phone.page.goto('/account/lost_password');
      await phone.page.locator('#mail').fill(mail);
      await phone.page.locator('#content input[type=submit]').click();
      expect(sql("SELECT id FROM tokens WHERE user_id = ? AND action = 'recovery'", [userId])).toEqual([]);

      // locked users are filtered out of the default list
      await page.goto('/users?set_filter=1&f[]=status&op[status]==&v[status][]=1'); // active users (the list remembers its last filter)
      await expect(page.locator(`tr#user-${userId}`)).toHaveCount(0);
      await page.goto('/users?set_filter=1&f[]=status&op[status]==&v[status][]=3');
      await expect(page.locator(`tr#user-${userId}`)).toHaveClass(/locked/);
      await page.locator(`tr#user-${userId} td.login a`).click();
      await page.locator('#content > .contextual a.icon-unlock').click();
      await ui.expectNotice('Successful update.');

      await loginViaForm(phone.page, login, passwords.changed);
      expect(await apiStatus(apiKey)).toBe(200);
    });

    await laptop.context.close();
    await phone.context.close();
  });
});
