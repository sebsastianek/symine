/** Helpers shared by the account / cross-cutting specs. */
import { expect, type Page } from '@playwright/test';
import { sql } from '../../support/db';

/** Upserts a Redmine setting directly in the e2e DB (restored with the pristine DB before the next file). */
export function setSetting(name: string, value: string): void {
  if (sql('SELECT id FROM settings WHERE name = ?', [name]).length > 0) {
    sql('UPDATE settings SET value = ?, updated_on = datetime(\'now\') WHERE name = ?', [value, name]);
  } else {
    sql("INSERT INTO settings (name, value, updated_on) VALUES (?, ?, datetime('now'))", [name, value]);
  }
}

/** Logs in through the real login form (page must be in a context without a stored session). */
export async function loginViaForm(page: Page, login: string, password: string, opts: { remember?: boolean; expectSuccess?: boolean } = {}): Promise<void> {
  if (!/\/login/.test(page.url())) await page.goto('/login');
  await page.locator('#username').fill(login);
  await page.locator('#password').fill(password);
  if (opts.remember) await page.locator('#autologin').check();
  await page.locator('#login-submit').click();
  if (opts.expectSuccess !== false) await expect(page.locator('#account .user-login')).toHaveText(`@${login}`);
}

/**
 * New anonymous browser context (browser.newContext() inside a test inherits the `use` options, including the
 * storageState of `test.use({ as })`, so it must be reset explicitly). The web debug toolbar is stubbed out.
 */
export async function anonymousContext(browser: import('@playwright/test').Browser): Promise<import('@playwright/test').BrowserContext> {
  const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  await ctx.route(/\/_wdt\//, (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '' }));
  return ctx;
}

/**
 * Opens the account dropdown. It is a Stimulus controller loaded as an ES module, so a click right after the page
 * load can happen before the controller is connected: retry until the menu shows (ui.openAccountMenu() does not).
 */
export async function openAccountMenu(page: Page): Promise<import('@playwright/test').Locator> {
  const menu = page.locator('#account .dropdown-content');
  await expect(async () => {
    await page.locator('#account .dropdown-trigger').click();
    await expect(menu).toBeVisible({ timeout: 1000 });
  }).toPass();
  return menu;
}
