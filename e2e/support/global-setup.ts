/**
 * Global setup (runs once, after the webServer is up):
 *  1. fresh DB copy of tests/fixtures/symine-seeded.db + attachments dir, REST API enabled, an API key per user;
 *  2. starts the SMTP sink capturing e-mails (support/smtp-sink.ts);
 *  3. logs every seeded user in through the real login form and saves var/e2e-auth/symine/<login>.json;
 *  4. snapshots the DB as "pristine" (after login: Symine re-hashes legacy passwords on first login and the session
 *     keeps the hash, so the snapshot must contain the new hashes for the stored sessions to stay valid).
 * Against an external server (E2E_BASE_URL) only step 3 runs.
 */
import { chromium, type FullConfig } from '@playwright/test';
import { randomBytes } from 'crypto';
import * as fs from 'fs';
import { API_KEYS_FILE, BASE_URL, IS_EXTERNAL, PATHS, SMTP_PORT, USERS, USER_LOGINS, authFile } from './env';
import { prepareDatabase, snapshotDatabase, sql } from './db';
import { startSmtpSink } from './smtp-sink';

async function waitForServer(): Promise<void> {
  const deadline = Date.now() + 120_000;
  for (;;) {
    try {
      // first request in dev compiles the container: can take a while
      const res = await fetch(`${BASE_URL}/login`, { signal: AbortSignal.timeout(90_000) });
      if (res.ok) return;
    } catch {
      /* retry */
    }
    if (Date.now() > deadline) throw new Error(`Server ${BASE_URL} is not answering`);
    await new Promise((r) => setTimeout(r, 500));
  }
}

function seedDatabase(): Record<string, string> {
  sql("UPDATE settings SET value = '1' WHERE name = 'rest_api_enabled'");
  if (sql("SELECT id FROM settings WHERE name = 'rest_api_enabled'").length === 0) {
    sql("INSERT INTO settings (name, value, updated_on) VALUES ('rest_api_enabled', '1', datetime('now'))");
  }
  const keys: Record<string, string> = {};
  for (const login of USER_LOGINS) {
    const id = USERS[login].id;
    let key = sql("SELECT value FROM tokens WHERE user_id = ? AND action = 'api'", [id])[0]?.value as string | undefined;
    if (!key) {
      key = randomBytes(20).toString('hex');
      sql("INSERT INTO tokens (user_id, action, value, created_on, updated_on) VALUES (?, 'api', ?, datetime('now'), datetime('now'))", [id, key]);
    }
    keys[login] = key;
  }
  return keys;
}

async function loginAll(): Promise<void> {
  fs.mkdirSync(PATHS.auth, { recursive: true });
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    for (const login of USER_LOGINS) {
      const context = await browser.newContext({ baseURL: BASE_URL, locale: 'en-US' });
      const page = await context.newPage();
      fs.rmSync(authFile(login), { force: true });
      try {
        await page.goto('/login');
        await page.locator('#username').fill(USERS[login].login);
        await page.locator('#password').fill(USERS[login].password);
        await page.locator('#login-submit').click();
        await page.waitForLoadState();
        if (/\/(twofa|my\/password|account\/lost_password|login)/.test(new URL(page.url()).pathname)) {
          const err = (await page.locator('#flash_error').allTextContents()).join(' ').trim();
          throw new Error(`login ended on ${page.url()} ${err}`);
        }
        await page.locator('#account .user-login').waitFor({ state: 'attached', timeout: 15_000 });
        await context.storageState({ path: authFile(login) });
      } catch (e) {
        // The reference Redmine may have users in another state (2FA enabled, password changed...): only the
        // specs using that user fail (with a clear message from the `as` fixture). On Symine it is fatal.
        if (!IS_EXTERNAL) throw e;
        console.warn(`[e2e] could not log in as ${login} on ${BASE_URL}: ${(e as Error).message.split('\n')[0]}`);
      } finally {
        await context.close();
      }
    }
  } finally {
    await browser.close();
  }
}

export default async function globalSetup(_config: FullConfig): Promise<() => Promise<void>> {
  if (IS_EXTERNAL) {
    await waitForServer();
    await loginAll();
    return async () => undefined;
  }
  prepareDatabase();
  const keys = seedDatabase();
  fs.mkdirSync(PATHS.auth, { recursive: true });
  fs.writeFileSync(API_KEYS_FILE(), JSON.stringify(keys, null, 2));

  fs.rmSync(PATHS.mail, { recursive: true, force: true });
  const smtp = await startSmtpSink(SMTP_PORT, PATHS.mail);

  await waitForServer();
  await loginAll();
  snapshotDatabase();
  return async () => {
    await new Promise<void>((resolve) => smtp.close(() => resolve()));
  };
}
