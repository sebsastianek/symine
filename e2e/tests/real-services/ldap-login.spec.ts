/**
 * LDAP authentication in the browser against a real OpenLDAP server seeded with
 * tests/Integration/RealServices/fixtures/ldap-users.ldif. Env-gated: SYMINE_TEST_LDAP_URL (e.g. ldap://localhost:13890).
 * Run by `bin/test-real-services --e2e` (containers + server); also works with the default bin/test-e2e harness.
 */
import { test, expect } from '../../support/fixtures';

const LDAP_URL = process.env.SYMINE_TEST_LDAP_URL || '';

async function login(page: import('@playwright/test').Page, user: string, password: string): Promise<void> {
  await page.goto('/login');
  await page.locator('#username').fill(user);
  await page.locator('#password').fill(password);
  await page.locator('#login-submit').click();
}

test.describe('LDAP authentication (real directory)', () => {
  test.skip(!LDAP_URL, 'SYMINE_TEST_LDAP_URL is not set');

  test.describe('administration', () => {
    test.use({ as: 'admin' });

    test('the administrator creates the LDAP auth source and tests the connection', async ({ page, ui }) => {
      const url = new URL(LDAP_URL);
      await page.goto('/auth_sources/new?type=AuthSourceLdap');
      await page.locator('#auth_source_name').fill('E2E LDAP');
      await page.locator('#auth_source_host').fill(url.hostname);
      await page.locator('#auth_source_port').fill(url.port || '389');
      await page.locator('#auth_source_account').fill('cn=admin,dc=symine,dc=test');
      await page.locator('#auth_source_account_password').fill('adminpw');
      await page.locator('#auth_source_base_dn').fill('ou=people,dc=symine,dc=test');
      await page.locator('#auth_source_timeout').fill('5');
      await page.locator('#auth_source_onthefly_register').check();
      await page.locator('#auth_source_attr_login').fill('uid');
      await page.locator('#auth_source_attr_firstname').fill('givenName');
      await page.locator('#auth_source_attr_lastname').fill('sn');
      await page.locator('#auth_source_attr_mail').fill('mail');
      await page.locator('#auth_source_form input[name=commit]').click();
      await ui.expectNotice('Successful creation.');

      const row = page.locator('table.list tr', { hasText: 'E2E LDAP' });
      await row.locator('a.icon-test').click();
      await ui.expectNotice('Successful connection.');
    });
  });

  test.describe('login form', () => {
    test.use({ dbReset: 'none' });

    test('a wrong LDAP password is rejected', async ({ page, ui }) => {
      await login(page, 'ldapuser', 'not-the-password');
      await ui.expectError('Invalid user or password');
      await expect(page).toHaveURL(/\/login/);
    });

    test('an LDAP user logs in and is created on the fly with the mapped attributes', async ({ page, ui }) => {
      await login(page, 'ldapuser', 'ldappass1');
      await expect(page).toHaveURL(/\/my\/page$/);
      await expect(ui.loggedAs()).toContainText('ldapuser');
      await page.goto('/my/account');
      await expect(page.locator('#user_firstname')).toHaveValue('Lisa');
      await expect(page.locator('#user_lastname')).toHaveValue('Dapper');
      await expect(page.locator('#user_mail')).toHaveValue('lisa.dapper@ldap.symine.test');
    });

    test('missing LDAP attributes lead to the registration form', async ({ page, ui }) => {
      await login(page, 'nomail', 'nomailpass');
      await expect(page).toHaveURL(/\/account\/register$/);
      await expect(page.locator('#errorExplanation')).toContainText('Email cannot be blank');
      await expect(page.locator('#user_firstname')).toHaveValue('No');
      await expect(page.locator('#user_login')).toHaveCount(0);
      await page.locator('#user_mail').fill('nomail@ldap.symine.test');
      await page.locator('#new_user input[type=submit]').click();
      await expect(page).toHaveURL(/\/my\/account$/);
      await ui.expectNotice('Your account has been activated.');
      await expect(ui.loggedAs()).toContainText('nomail');
    });
  });
});
