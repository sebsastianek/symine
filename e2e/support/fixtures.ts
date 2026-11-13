/**
 * The `test` object every spec imports:  import { test, expect } from '../../support/fixtures';
 *
 * Options (test.use):
 *   as:        'admin' | 'jsmith' | 'dlopper' | 'rhill' | 'anna' | null   (default null = anonymous)
 *              -> the page starts logged in (storageState saved by global setup through the real login form)
 *   dbReset:   'file' (default) | 'test' | 'none'
 *              -> DB restored from the pristine snapshot before the first test of each spec file / before each test
 *   allowJsErrors / allowServerErrors: false by default; uncaught page errors and HTTP 5xx responses fail the test.
 *
 * Fixtures:
 *   ui        RedmineUI helpers for `page` (menus, flash, context menu, filters, modal, autocomplete)
 *   http      SessionHttp: CSRF-aware requests sharing the page cookies
 *   api       (login) => RedmineApi authenticated with the user's API key (disposed after the test)
 *   loginAs   (login) => { page, ui, http } in a separate browser context logged in as `login`
 *   mailbox   Mailbox of the e-mails received during the test (SMTP sink)
 */
import { test as base, expect, type Page, type TestInfo } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { apiAs, type RedmineApi } from './api';
import { resetDatabase } from './db';
import { IS_EXTERNAL, PATHS, authFile, type UserLogin } from './env';
import { SessionHttp } from './http';
import { Mailbox } from './mail';
import { RedmineUI } from './ui';

export { expect };

const RESET_MARKER = path.join(PATHS.results, '.last-db-reset');

/**
 * Restores the pristine DB once per spec file (idempotent). Called automatically before each test; call it first
 * in a beforeAll hook that creates data, since beforeAll runs before the per-test fixtures.
 */
export async function ensureFileReset(testInfo: TestInfo): Promise<void> {
  if (IS_EXTERNAL) return;
  const key = `${process.env.E2E_RUN_ID || ''}:${testInfo.file}`;
  const last = fs.existsSync(RESET_MARKER) ? fs.readFileSync(RESET_MARKER, 'utf8') : '';
  if (last === key) return;
  await resetDatabase();
  fs.mkdirSync(path.dirname(RESET_MARKER), { recursive: true });
  fs.writeFileSync(RESET_MARKER, key);
}

/** Unique suffix for names created by tests. */
export function uid(prefix = ''): string {
  return `${prefix}${Date.now().toString(36)}${Math.floor(Math.random() * 1296).toString(36)}`;
}

/** Prepares a page: hides the Symfony web debug toolbar, accepts confirm() dialogs. */
async function preparePage(page: Page): Promise<void> {
  await page.context().route(/\/_wdt\//, (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '' }));
  page.on('dialog', (d) => void d.accept().catch(() => undefined));
}

type LoggedIn = { page: Page; ui: RedmineUI; http: SessionHttp };

type Options = {
  as: UserLogin | null;
  dbReset: 'file' | 'test' | 'none';
  allowJsErrors: boolean;
  allowServerErrors: boolean;
};

type Fixtures = {
  ui: RedmineUI;
  http: SessionHttp;
  api: (login: UserLogin) => Promise<RedmineApi>;
  loginAs: (login: UserLogin) => Promise<LoggedIn>;
  mailbox: Mailbox;
  _autoReset: void;
  _pageGuards: void;
};

function watchErrors(page: Page, jsErrors: string[], serverErrors: string[]): void {
  page.on('pageerror', (err) => jsErrors.push(`${page.url()}: ${err.message}`));
  page.on('response', (res) => {
    if (res.status() >= 500) serverErrors.push(`${res.status()} ${res.request().method()} ${res.url()}`);
  });
}

export const test = base.extend<Fixtures & Options>({
  as: [null, { option: true }],
  dbReset: ['file', { option: true }],
  allowJsErrors: [false, { option: true }],
  allowServerErrors: [false, { option: true }],

  storageState: async ({ as }, use) => {
    if (as && !fs.existsSync(authFile(as))) {
      throw new Error(`No stored session for ${as} (${authFile(as)}): global setup could not log in as this user`);
    }
    await use(as ? authFile(as) : { cookies: [], origins: [] });
  },

  _autoReset: [
    async ({ dbReset }, use, testInfo) => {
      if (!IS_EXTERNAL && dbReset === 'test') await resetDatabase();
      else if (dbReset === 'file') await ensureFileReset(testInfo);
      await use();
    },
    { auto: true },
  ],

  page: async ({ page }, use) => {
    await preparePage(page);
    await use(page);
  },

  _pageGuards: [
    async ({ page, allowJsErrors, allowServerErrors }, use) => {
      const jsErrors: string[] = [];
      const serverErrors: string[] = [];
      watchErrors(page, jsErrors, serverErrors);
      await use();
      if (!allowServerErrors) expect(serverErrors, 'HTTP 5xx responses during the test').toEqual([]);
      if (!allowJsErrors) expect(jsErrors, 'uncaught JavaScript errors during the test').toEqual([]);
    },
    { auto: true },
  ],

  ui: async ({ page }, use) => {
    await use(new RedmineUI(page));
  },

  http: async ({ page }, use) => {
    await use(new SessionHttp(page));
  },

  api: async ({}, use) => {
    const created: RedmineApi[] = [];
    await use(async (login) => {
      const api = await apiAs(login);
      created.push(api);
      return api;
    });
    for (const a of created) await a.dispose();
  },

  loginAs: async ({ browser, allowJsErrors, allowServerErrors }, use) => {
    const contexts: Awaited<ReturnType<typeof browser.newContext>>[] = [];
    const jsErrors: string[] = [];
    const serverErrors: string[] = [];
    await use(async (login) => {
      if (!fs.existsSync(authFile(login))) throw new Error(`No stored session for ${login}: global setup could not log in`);
      const context = await browser.newContext({ storageState: authFile(login) });
      contexts.push(context);
      const page = await context.newPage();
      await preparePage(page);
      watchErrors(page, jsErrors, serverErrors);
      return { page, ui: new RedmineUI(page), http: new SessionHttp(page) };
    });
    for (const c of contexts) await c.close();
    if (!allowServerErrors) expect(serverErrors, 'HTTP 5xx responses (loginAs pages)').toEqual([]);
    if (!allowJsErrors) expect(jsErrors, 'uncaught JavaScript errors (loginAs pages)').toEqual([]);
  },

  mailbox: async ({}, use) => {
    await use(new Mailbox(Date.now() - 1));
  },
});
