import { test, expect, uid } from '../../support/fixtures';
import { sqlValue } from '../../support/db';
import { rowWithLink, submitButton } from './_helpers';

test.describe('admin LDAP authentication', () => {
  test.use({ as: 'admin' });

  test('form validation, create, test connection (unreachable host), edit, delete', async ({ page, ui }) => {
    await page.goto('/auth_sources');
    await ui.contextual('New authentication mode').click();
    await expect(page).toHaveURL(/\/auth_sources\/new/);

    // missing required fields
    await page.locator('#auth_source_port').fill('');
    await submitButton(page, 'Create').click();
    const errors = ui.errors();
    await expect(errors).toContainText('Name cannot be blank');
    await expect(errors).toContainText('Host cannot be blank');
    await expect(errors).toContainText('Port cannot be blank');
    await expect(errors).toContainText('Login attribute cannot be blank');

    // invalid port
    await page.locator('#auth_source_port').fill('abc');
    await submitButton(page, 'Create').click();
    await expect(ui.errors()).toContainText('Port is not a number');

    const name = uid('LDAP ');
    await page.locator('#auth_source_name').fill(name);
    await page.locator('#auth_source_host').fill('127.0.0.1');
    await page.locator('#auth_source_port').fill('1');
    await page.locator('#auth_source_base_dn').fill('dc=example,dc=org');
    await page.locator('#auth_source_attr_login').fill('uid');
    await page.locator('#auth_source_attr_mail').fill('mail');
    await page.locator('#auth_source_timeout').fill('2');
    await submitButton(page, 'Create').click();
    await ui.expectNotice('Successful creation.');
    await expect(page).toHaveURL(/\/auth_sources$/);
    const row = rowWithLink(page, name);
    await expect(row).toBeVisible();
    const id = Number(sqlValue('SELECT id FROM auth_sources WHERE name = ?', [name]));

    // "Test" against an unreachable server shows an error flash
    await row.getByRole('link', { name: 'Test' }).click();
    await expect(ui.flashError()).toContainText('Unable to connect');

    // edit (the password field is not prefilled)
    await page.goto(`/auth_sources/${id}/edit`);
    await expect(page.locator('#auth_source_host')).toHaveValue('127.0.0.1');
    await page.locator('#auth_source_host').fill('ldap.invalid');
    await submitButton(page, 'Save').click();
    await ui.expectNotice('Successful update.');
    expect(String(sqlValue('SELECT host FROM auth_sources WHERE id = ?', [id]))).toBe('ldap.invalid');

    // the auth source can be chosen for a user
    await page.goto('/users/7/edit');
    await expect(page.locator('#user_auth_source_id option', { hasText: name })).toHaveCount(1);

    // delete
    await page.goto('/auth_sources');
    await rowWithLink(page, name).getByRole('link', { name: 'Delete' }).click();
    await ui.expectNotice('Successful deletion.');
    await expect(rowWithLink(page, name)).toHaveCount(0);
  });
});
