import { test, expect } from '../../support/fixtures';
import { sqlValue } from '../../support/db';

test.describe('e2e infrastructure', () => {
  test('anonymous home page renders with Redmine assets and JS', async ({ page, ui }) => {
    await page.goto('/');
    await expect(page.locator('#header h1')).toBeVisible();
    await expect(ui.topMenu('login')).toBeVisible();
    // jQuery + application.js loaded
    expect(await page.evaluate(() => typeof (window as any).jQuery)).toBe('function');
  });

  test('stored session logs in as jsmith', async ({ browser }) => {
    const { authFile } = await import('../../support/env');
    const ctx = await browser.newContext({ storageState: authFile('jsmith') });
    const page = await ctx.newPage();
    await page.goto('/my/page');
    await expect(page.locator('#account .user-login')).toHaveText('@jsmith');
    await ctx.close();
  });

  test.describe('as admin', () => {
    test.use({ as: 'admin' });
    test('admin menu is reachable', async ({ page, ui }) => {
      await page.goto('/admin');
      await expect(ui.adminMenu('users')).toBeVisible();
    });
  });

  test('api key works', async ({ api }) => {
    const jsmith = await api('jsmith');
    const me = await jsmith.get('/users/current.json');
    expect(me.user.login).toBe('jsmith');
  });

  test('db helper reads the e2e database', async () => {
    expect(Number(sqlValue('SELECT COUNT(*) FROM projects'))).toBeGreaterThanOrEqual(4);
  });
});

test('e-mails are captured by the SMTP sink', async ({ page, mailbox }) => {
  await page.goto('/account/lost_password');
  await page.locator('#mail').fill('jsmith@example.net');
  await page.locator('input[type=submit]').click();
  const mail = await mailbox.waitFor({ to: 'jsmith@example.net' });
  expect(mail.links().some((l) => l.includes('/account/lost_password?token='))).toBe(true);
});
