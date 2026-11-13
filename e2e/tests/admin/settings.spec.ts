import { test, expect, uid } from '../../support/fixtures';
import { sqlValue } from '../../support/db';
import type { Page } from '@playwright/test';
import { openTab } from './_helpers';

async function saveTab(page: Page, tab: string): Promise<void> {
  await page.locator(`#tab-content-${tab} input[type=submit][value="Save"]`).click();
  await expect(page.locator('#flash_notice')).toHaveText('Successful update.');
  // after saving, the same tab is displayed again
  await expect(page.locator(`#tab-content-${tab}`)).toBeVisible();
}

function setting(name: string): string {
  return String(sqlValue('SELECT value FROM settings WHERE name = ?', [name]) ?? '');
}

test.describe('admin settings', () => {
  test.use({ as: 'admin' });

  test('General: application title and welcome text', async ({ page, browser }) => {
    const title = uid('Symine E2E ');
    await page.goto('/settings');
    await expect(page.locator('#tab-general')).toHaveClass(/selected/);
    await page.locator('#settings_app_title').fill(title);
    await page.locator('#settings_welcome_text').fill('Hello **e2e** world');
    await saveTab(page, 'general');
    await expect(page.locator('#settings_app_title')).toHaveValue(title);
    expect(setting('app_title')).toBe(title);

    await page.goto('/');
    await expect(page.locator('#header h1')).toHaveText(title);
    await expect(page).toHaveTitle(new RegExp(title));
    await expect(page.locator('#content .wiki strong')).toHaveText('e2e');

    // also for anonymous visitors
    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const anon = await ctx.newPage();
    await anon.goto('/');
    await expect(anon.locator('#header h1')).toHaveText(title);
    await ctx.close();
  });

  test('Display: date format applies to dates', async ({ page }) => {
    await page.goto('/settings?tab=display');
    await expect(page.locator('#tab-content-display')).toBeVisible();
    await page.locator('#settings_date_format').selectOption('%Y-%m-%d');
    await saveTab(page, 'display');
    await expect(page.locator('#settings_date_format')).toHaveValue('%Y-%m-%d');
    await page.goto('/issues/13');
    await expect(page.locator('.issue .start-date .value')).toHaveText('2026-11-02');
  });

  test('Authentication: login required redirects anonymous users, self-registration toggles the register link', async ({ page, browser }) => {
    await page.goto('/settings');
    await openTab(page, 'authentication');
    await page.locator('#settings_login_required').selectOption('1');
    await page.locator('#settings_self_registration').selectOption('0');
    await saveTab(page, 'authentication');
    expect(setting('login_required')).toBe('1');
    expect(setting('self_registration')).toBe('0');

    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const anon = await ctx.newPage();
    await anon.goto('/projects/ecommerce');
    await expect(anon).toHaveURL(/\/login\?back_url=/);
    await expect(anon.locator('#top-menu a.register')).toHaveCount(0);

    // back to anonymous access + registration
    await page.locator('#settings_login_required').selectOption('0');
    await page.locator('#settings_self_registration').selectOption('3');
    await saveTab(page, 'authentication');
    await anon.goto('/projects/ecommerce');
    await expect(anon).toHaveURL(/\/projects\/ecommerce$/);
    await expect(anon.locator('#top-menu a.register')).toBeVisible();
    await ctx.close();
  });

  test('API: disabling the REST API ignores API keys', async ({ page, api }) => {
    const jsmith = await api('jsmith');
    expect((await jsmith.ctx.get('/users/current.json')).status()).toBe(200);

    await page.goto('/settings?tab=integrations');
    await expect(page.locator('#settings_rest_api_enabled')).toBeChecked();
    await page.locator('#settings_rest_api_enabled').uncheck();
    await saveTab(page, 'integrations');
    expect(setting('rest_api_enabled')).toBe('0');
    // like Redmine: the key is ignored, the request is anonymous -> 403 where a login is needed
    expect((await jsmith.ctx.get('/users/current.json')).status()).toBe(403);
    // public data stays readable anonymously, private project issues (#18 in "internal") are not
    const issues = await (await jsmith.ctx.get('/issues.json?status_id=*')).json();
    expect(issues.issues.map((i: { id: number }) => i.id)).not.toContain(18);

    await page.locator('#settings_rest_api_enabled').check();
    await saveTab(page, 'integrations');
    expect((await jsmith.ctx.get('/users/current.json')).status()).toBe(200);
  });

  test('Issue tracking: settings are persisted and used', async ({ page }) => {
    await page.goto('/settings?tab=issues');
    await expect(page.locator('#settings_display_subprojects_issues')).toBeChecked();
    await page.locator('#settings_display_subprojects_issues').uncheck();
    await page.locator('#settings_issues_export_limit').fill('123');
    await saveTab(page, 'issues');
    await expect(page.locator('#settings_issues_export_limit')).toHaveValue('123');
    expect(setting('display_subprojects_issues')).toBe('0');
    expect(setting('issues_export_limit')).toBe('123');

    // #17 belongs to the subproject checkout: no longer listed on the parent by default
    await page.goto('/projects/ecommerce/issues');
    await expect(page.locator('tr#issue-13')).toBeVisible();
    await expect(page.locator('tr#issue-17')).toHaveCount(0);
  });

  test('tabs switch without reload and remember the selected tab in the URL', async ({ page }) => {
    await page.goto('/settings');
    for (const tab of ['display', 'authentication', 'integrations', 'projects', 'users', 'issues', 'timelog', 'attachments', 'notifications']) {
      await page.locator(`#tab-${tab}`).click();
      await expect(page.locator(`#tab-content-${tab}`)).toBeVisible();
      await expect(page.locator('#tab-content-general')).toBeHidden();
      await expect(page).toHaveURL(new RegExp(`tab=${tab}`));
    }
  });
});
