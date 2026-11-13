/**
 * OAuth2 provider end to end: the administrator registers an application (REST API enabled), jsmith goes
 * through the consent page of the authorization code flow with PKCE (S256), the client exchanges the code for
 * tokens, uses the Bearer token on the REST API (restricted to the granted scopes), refreshes it, and jsmith
 * finally revokes the application from My account.
 *
 * Symine only: application/token rows would accumulate on the reference Redmine.
 */
import { createHash, randomBytes } from 'crypto';
import { test, expect, ensureFileReset } from '../../support/fixtures';
import { sqlValue } from '../../support/db';
import { IS_EXTERNAL } from '../../support/env';

test.skip(IS_EXTERNAL, 'creates OAuth applications on the target server');
test.describe.configure({ mode: 'serial' });

const CALLBACK = 'http://localhost:8888/callback';
const verifier = randomBytes(32).toString('base64url');
const challenge = createHash('sha256').update(verifier).digest('base64url');
let clientId = '';
let clientSecret = '';
let accessToken = '';
let refreshToken = '';

test.beforeAll(async ({}, testInfo) => {
  await ensureFileReset(testInfo);
});

function authorizeUrl(state: string, scope = 'view_project view_issues'): string {
  const q = new URLSearchParams({ client_id: clientId, redirect_uri: CALLBACK, response_type: 'code', scope, state, code_challenge: challenge, code_challenge_method: 'S256' });
  return `/oauth/authorize?${q}`;
}

/** Captures the redirection to the client callback (nothing listens there). */
async function captureCallback(page: import('@playwright/test').Page): Promise<() => URL> {
  let url: URL | null = null;
  const reached = () => url;
  await page.route(`${CALLBACK}**`, async (route) => {
    url = new URL(route.request().url());
    await route.fulfill({ status: 200, contentType: 'text/html', body: '<p>callback</p>' });
  });
  const get = () => {
    const u = reached();
    if (!u) throw new Error('callback not reached');
    return u;
  };
  (get as any).maybe = reached;
  return get;
}

/** Waits until the callback was reached with the given state */
async function waitForState(callback: () => URL, state: string): Promise<URL> {
  await expect.poll(() => ((callback as any).maybe() as URL | null)?.searchParams.get('state') ?? null, { timeout: 10_000 }).toBe(state);
  return callback();
}

test.describe('administrator', () => {
  test.use({ as: 'admin' });

  test('registers an application', async ({ page, ui }) => {
    await page.goto('/admin');
    await ui.adminMenu('applications').click();
    await expect(page).toHaveURL(/\/oauth\/applications$/);
    await ui.contextual('New Application').click();
    await page.locator('#doorkeeper_application_name').fill('E2E client');
    await page.locator('#doorkeeper_application_redirect_uri').fill(CALLBACK);
    await expect(page.locator('#doorkeeper_application_scopes_view_project')).toBeChecked();
    await expect(page.locator('#doorkeeper_application_scopes_view_project')).toBeDisabled();
    await page.locator('#doorkeeper_application_scopes_view_issues').check();
    await page.locator('#doorkeeper_application_scopes_add_issues').check();
    await page.getByRole('button', { name: 'Create' }).click();

    await ui.expectNotice('Application created.');
    const box = page.locator('#content .box').first();
    await expect(box).toContainText('Copy the secret to a safe place now');
    const codes = await box.locator('code').allInnerTexts();
    clientId = codes[0].trim();
    clientSecret = codes[1].trim();
    expect(clientId).toMatch(/^[\w-]{43}$/);
    expect(clientSecret).toMatch(/^[\w-]{43}$/);
    // the secret is stored hashed and never shown again
    expect(String(sqlValue('SELECT secret FROM oauth_applications WHERE uid = ?', [clientId]))).toMatch(/^\$2[ay]\$12\$/);
    await page.reload();
    await expect(box).toContainText('Secret hashed');
  });
});

test.describe('resource owner', () => {
  test.use({ as: 'jsmith' });

  test('denies, then authorizes the application (consent page)', async ({ page }) => {
    const callback = await captureCallback(page);
    await page.goto(authorizeUrl('deny-state'));
    await expect(page.locator('#content h2')).toHaveText('Authorization required');
    await expect(page.locator('.oauth-permissions')).toContainText('View Issues');
    await page.getByRole('button', { name: 'Deny' }).click();
    expect((await waitForState(callback, 'deny-state')).searchParams.get('error')).toBe('access_denied');

    await page.goto(authorizeUrl('ok-state'));
    await page.getByRole('button', { name: 'Authorize' }).click();
    const code = (await waitForState(callback, 'ok-state')).searchParams.get('code')!;
    expect(code).toMatch(/^[\w-]{43}$/);

    // token exchange (client side): the PKCE verifier is required
    const missing = await page.request.post('/oauth/token', { form: { grant_type: 'authorization_code', code, redirect_uri: CALLBACK, client_id: clientId, client_secret: clientSecret } });
    expect(missing.status()).toBe(400);
    expect((await missing.json()).error_description).toBe('Missing required parameter: code_verifier.');

    const res = await page.request.post('/oauth/token', {
      form: { grant_type: 'authorization_code', code, redirect_uri: CALLBACK, code_verifier: verifier },
      headers: { Authorization: `Basic ${Buffer.from(`${clientId}:${clientSecret}`).toString('base64')}` },
    });
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body).toMatchObject({ token_type: 'Bearer', expires_in: 7200, scope: 'view_project view_issues' });
    accessToken = body.access_token;
    refreshToken = body.refresh_token;
  });

  test('the Bearer token gives scope-restricted API access', async ({ playwright }) => {
    const client = await playwright.request.newContext({ extraHTTPHeaders: { Authorization: `Bearer ${accessToken}`, Accept: 'application/json' } });
    const me = await client.get('/users/current.json');
    expect(me.status()).toBe(200);
    expect((await me.json()).user.login).toBe('jsmith');
    expect((await client.get('/issues.json')).status()).toBe(200);
    // view_time_entries was not granted
    expect((await client.get('/time_entries.json')).status()).toBe(403);
    const info = await (await client.get('/oauth/token/info')).json();
    expect(info.scope).toEqual(['view_project', 'view_issues']);
    expect(info.application.uid).toBe(clientId);
    await client.dispose();
  });

  test('refresh token rotation', async ({ playwright }) => {
    const anon = await playwright.request.newContext();
    const res = await anon.post('/oauth/token', { form: { grant_type: 'refresh_token', refresh_token: refreshToken, client_id: clientId, client_secret: clientSecret } });
    expect(res.status()).toBe(200);
    const fresh = await res.json();
    expect(fresh.access_token).not.toBe(accessToken);
    // first use of the new token revokes the previous one
    expect((await anon.get('/issues.json', { headers: { Authorization: `Bearer ${fresh.access_token}` } })).status()).toBe(200);
    const old = await anon.get('/my/account.json', { headers: { Authorization: `Bearer ${accessToken}` } });
    expect(old.status()).toBe(401);
    expect(old.headers()['www-authenticate']).toContain('The access token was revoked');
    accessToken = fresh.access_token;
    await anon.dispose();
  });

  test('revokes the application from My account', async ({ page, ui, playwright }) => {
    await page.goto('/my/account');
    await ui.contextual('Authorized applications').click();
    await expect(page).toHaveURL(/\/oauth\/authorized_applications$/);
    const row = page.locator('table.list tbody tr', { hasText: 'E2E client' });
    await expect(row).toBeVisible();
    await row.getByRole('link', { name: 'Revoke' }).click();
    await ui.expectNotice('Application revoked.');
    await expect(page.locator('p.nodata')).toBeVisible();

    const anon = await playwright.request.newContext();
    expect((await anon.get('/oauth/token/info', { headers: { Authorization: `Bearer ${accessToken}` } })).status()).toBe(401);
    await anon.dispose();
  });
});
