/** Helpers local to the administration specs. */
import { expect, type Browser, type Locator, type Page } from '@playwright/test';
import { BASE_URL } from '../../support/env';

/** Logs in through the real login form in a fresh context (no stored session). Returns the page after submit. */
export async function formLogin(browser: Browser, login: string, password: string): Promise<{ page: Page; close: () => Promise<void> }> {
  // explicit empty storageState: inside a test, browser.newContext() inherits the `use` options (incl. the session)
  const context = await browser.newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] } });
  await context.route(/\/_wdt\//, (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '' }));
  const page = await context.newPage();
  await page.goto('/login');
  await page.locator('#username').fill(login);
  await page.locator('#password').fill(password);
  await page.locator('#login-submit').click();
  return { page, close: () => context.close() };
}

/** First submit input of the content area with that value ("Create", "Save", "Apply"...). */
export function submitButton(page: Page, value: string): Locator {
  return page.locator(`#content input[type=submit][value="${value}"]`).first();
}

/** Admin list table row containing an exact link text. */
export function rowWithLink(page: Page, text: string): Locator {
  return page.locator('table.list tbody tr').filter({ has: page.getByRole('link', { name: text, exact: true }) });
}

/** Opens a "tab" of a Redmine tabbed page (div.tabs a#tab-xxx) and waits for its content. */
export async function openTab(page: Page, name: string): Promise<Locator> {
  await page.locator(`#tab-${name}`).click();
  const content = page.locator(`#tab-content-${name}`);
  await expect(content).toBeVisible();
  return content;
}

/**
 * Sets a text filter of a Redmine query form. Text/string filters render a single input with id "values_<field>"
 * (no "_1" suffix), which RedmineUI.setFilter does not handle.
 */
export async function setTextFilter(page: Page, field: string, operator: string, value: string): Promise<void> {
  if (!(await page.locator(`#cb_${field}`).isChecked().catch(() => false))) {
    await page.locator('#add_filter_select').selectOption(field);
  }
  await page.locator(`#operators_${field}`).selectOption(operator);
  await page.locator(`#values_${field}`).fill(value);
}
