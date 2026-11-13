import { test, expect } from '../../support/fixtures';
import { USERS } from '../../support/env';
import { anonymousContext, loginViaForm, openAccountMenu, setSetting } from './_helpers';

// Every test starts anonymous (no stored session): login/logout go through the real form.
test.describe('login / logout', () => {
  test('sign in from the top menu lands on My page', async ({ page, ui }) => {
    await page.goto('/');
    await ui.topMenu('login').click();
    await expect(page).toHaveURL(/\/login/);
    await loginViaForm(page, 'jsmith', USERS.jsmith.password);
    await expect(page).toHaveURL(/\/my\/page$/);
    await expect(ui.heading()).toHaveText('My page');
    await expect(ui.topMenu('login')).toHaveCount(0);
  });

  test('wrong password shows an error and keeps the user anonymous', async ({ page, ui }) => {
    await page.goto('/login');
    await page.locator('#username').fill('jsmith');
    await page.locator('#password').fill('wrong-password');
    await page.locator('#login-submit').click();
    await expect(ui.flashError()).toHaveText('Invalid user or password');
    await expect(page.locator('#username')).toHaveValue('jsmith');
    await expect(page.locator('#account .user-login')).toHaveCount(0);
  });

  test('locked account cannot sign in', async ({ page, ui }) => {
    await page.goto('/login');
    await page.locator('#username').fill('locked');
    await page.locator('#password').fill('password123');
    await page.locator('#login-submit').click();
    await expect(ui.flashError()).toContainText(/locked|Invalid user or password/);
    await expect(page.locator('#account .user-login')).toHaveCount(0);
  });

  test('protected page redirects to login and back after sign in', async ({ page }) => {
    await page.goto('/my/account');
    await expect(page).toHaveURL(/\/login\?back_url=/);
    await loginViaForm(page, 'dlopper', USERS.dlopper.password);
    await expect(page).toHaveURL(/\/my\/account$/);
    await expect(page.locator('#user_firstname')).toHaveValue('Dave');
  });

  test('sign out through the account menu', async ({ page, ui }) => {
    await loginViaForm(page, 'rhill', USERS.rhill.password);
    const menu = await openAccountMenu(page);
    await expect(menu.locator('.user-name')).toHaveText('Robert Hill');
    await menu.locator('a.logout').click();
    await expect(ui.topMenu('login')).toBeVisible();
    await expect(page.locator('#account .user-login')).toHaveCount(0);
    // the session is really gone
    await page.goto('/my/page');
    await expect(page).toHaveURL(/\/login\?back_url=/);
  });

  test('"Stay logged in" is only offered when autologin is enabled', async ({ page }) => {
    await page.goto('/login');
    await expect(page.locator('#autologin')).toHaveCount(0);
    setSetting('autologin', '7');
    await page.reload();
    await expect(page.locator('#autologin')).toBeVisible();
  });

  test('"Stay logged in" sets an autologin cookie that logs in a new browser session', async ({ page, browser }) => {
    setSetting('autologin', '7');
    await loginViaForm(page, 'jsmith', USERS.jsmith.password, { remember: true });
    const cookies = await page.context().cookies();
    const autologin = cookies.find((c) => c.name === 'autologin');
    expect(autologin, 'autologin cookie').toBeTruthy();
    expect(autologin!.expires).toBeGreaterThan(Date.now() / 1000 + 86400);

    // a brand-new browser session that only carries the autologin cookie
    const ctx = await anonymousContext(browser);
    try {
      await ctx.addCookies([autologin!]);
      const p2 = await ctx.newPage();
      await p2.goto('/my/page');
      await expect(p2).toHaveURL(/\/my\/page$/);
      await expect(p2.locator('#account .user-login')).toHaveText('@jsmith');
    } finally {
      await ctx.close();
    }
  });

  test('without "Stay logged in" no autologin cookie is set', async ({ page }) => {
    await loginViaForm(page, 'dlopper', USERS.dlopper.password);
    const cookies = await page.context().cookies();
    expect(cookies.find((c) => c.name === 'autologin')).toBeUndefined();
  });
});
