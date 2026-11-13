import { test, expect, uid } from '../../support/fixtures';
import { sqlValue } from '../../support/db';
import { formLogin, openTab, setTextFilter, submitButton } from './_helpers';

test.describe('admin users', () => {
  test.use({ as: 'admin' });

  test('list shows active users and can be filtered by login', async ({ page, ui }) => {
    await page.goto('/users');
    await expect(ui.heading()).toHaveText('Users');
    // default filter: status = active -> the locked user is hidden
    await expect(page.locator('tr#user-6 td.login')).toHaveText('dlopper');
    await expect(page.locator('tr#user-8')).toHaveCount(0);

    await setTextFilter(page, 'login', '~', 'dlop');
    await ui.applyFilters();
    await expect(ui.listRows()).toHaveCount(1);
    await expect(ui.listRows().first().locator('td.login')).toHaveText('dlopper');

    // status filter "locked" shows the locked account
    await page.goto('/users?set_filter=1&f[]=status&op[status]==&v[status][]=3');
    await expect(page.locator('tr#user-8 td.login')).toHaveText('locked');
  });

  test('create, edit, lock, unlock and delete a user', async ({ page, ui, browser }) => {
    const login = uid('e2euser');
    await page.goto('/users');
    await ui.contextual('New user').click();
    await expect(page).toHaveURL(/\/users\/new/);

    // validation: empty form
    await submitButton(page, 'Create').click();
    await expect(ui.errors()).toContainText("Login cannot be blank");

    await page.locator('#user_login').fill(login);
    await page.locator('#user_firstname').fill('Eve');
    await page.locator('#user_lastname').fill('Tester');
    await page.locator('#user_mail').fill(`${login}@example.net`);
    await page.locator('#user_language').selectOption('en');
    await page.locator('#user_password').fill('secret-pass-123');
    await page.locator('#user_password_confirmation').fill('secret-pass-123');
    await submitButton(page, 'Create').click();
    await ui.expectNotice(`User ${login} created.`);
    await expect(page).toHaveURL(/\/users\/\d+\/edit/);
    const id = Number(page.url().match(/\/users\/(\d+)\/edit/)![1]);

    // the new account can log in through the form
    let session = await formLogin(browser, login, 'secret-pass-123');
    await expect(session.page.locator('#account .user-login')).toHaveText(`@${login}`);
    await session.close();

    // edit
    await page.locator('#user_lastname').fill('Edited');
    await page.locator('#user_admin').check();
    await submitButton(page, 'Save').click();
    await ui.expectNotice('Successful update.');
    await expect(page.locator('#user_lastname')).toHaveValue('Edited');
    await expect(page.locator('#user_admin')).toBeChecked();
    await page.goto(`/users?set_filter=1&f[]=login&op[login]=%3D&v[login][]=${login}`);
    await expect(page.locator(`tr#user-${id} td.lastname`)).toHaveText('Edited');
    await expect(page.locator(`tr#user-${id} td.admin`)).toHaveText('Yes');

    // lock: the account can no longer log in
    await page.goto(`/users/${id}/edit`);
    await ui.contextual('Lock').click();
    await ui.expectNotice('Successful update.');
    await expect(ui.contextual('Unlock')).toBeVisible();
    expect(Number(sqlValue('SELECT status FROM users WHERE id = ?', [id]))).toBe(3);

    session = await formLogin(browser, login, 'secret-pass-123');
    await expect(session.page.locator('#flash_error')).toBeVisible();
    await expect(session.page.locator('#account .user-login')).toHaveCount(0);
    await session.close();

    // unlock
    await ui.contextual('Unlock').click();
    await ui.expectNotice('Successful update.');
    await expect(ui.contextual('Lock')).toBeVisible();
    session = await formLogin(browser, login, 'secret-pass-123');
    await expect(session.page.locator('#account .user-login')).toHaveText(`@${login}`);
    await session.close();

    // delete (confirmation page asks to type the login)
    await ui.contextual('Delete').click();
    await expect(page.locator('#content h2')).toHaveText('Confirmation');
    await page.locator('#confirm').fill(login);
    await submitButton(page, 'Delete').click();
    await ui.expectNotice('Successful deletion.');
    expect(Number(sqlValue('SELECT COUNT(*) FROM users WHERE id = ?', [id]))).toBe(0);
  });

  test('lock and unlock from the list context menu', async ({ page, ui }) => {
    // explicit filter: the users query is kept in the session between requests
    await page.goto('/users?set_filter=1&f[]=status&op[status]==&v[status][]=1');
    const menu = await ui.openContextMenu(page.locator('tr#user-7'));
    await menu.getByRole('link', { name: 'Lock' }).click();
    await ui.expectNotice('Successful update.');
    expect(Number(sqlValue('SELECT status FROM users WHERE id = 7'))).toBe(3);
    // locked users disappear from the default (active) list
    await expect(page.locator('tr#user-7')).toHaveCount(0);

    await page.goto('/users?set_filter=1&f[]=status&op[status]==&v[status][]=3');
    const menu2 = await ui.openContextMenu(page.locator('tr#user-7'));
    await menu2.getByRole('link', { name: 'Unlock' }).click();
    await ui.expectNotice('Successful update.');
    expect(Number(sqlValue('SELECT status FROM users WHERE id = 7'))).toBe(1);
  });

  test('user memberships tab: add, edit and remove a project membership', async ({ page, ui }) => {
    await page.goto('/users/9/edit');
    const tab = await openTab(page, 'memberships');
    await expect(tab.locator('p.nodata')).toBeVisible();

    await tab.getByRole('link', { name: 'Add projects' }).click();
    // Redmine opens a modal; a full page is accepted too
    const scope = page.locator('#ajax-modal:visible, #content').first();
    await scope.locator('label', { hasText: 'Internal Tools' }).locator('input[type=checkbox]').check();
    await scope.locator('label', { hasText: 'Reporter' }).locator('input[type=checkbox]').check();
    await scope.locator('input[type=submit][value="Add"]').click();

    const row = page.locator('#tab-content-memberships table.memberships tbody tr').filter({ hasText: 'Internal Tools' });
    await expect(row.locator('td.roles')).toContainText('Reporter');
    expect(Number(sqlValue("SELECT COUNT(*) FROM members WHERE user_id = 9 AND project_id = 2"))).toBe(1);

    // edit roles
    await row.getByRole('link', { name: 'Edit' }).click();
    const editScope = page.locator('#ajax-modal:visible, #content').first();
    await editScope.locator('label', { hasText: 'Developer' }).locator('input[type=checkbox]').check();
    await editScope.locator('label', { hasText: 'Reporter' }).locator('input[type=checkbox]').uncheck();
    await editScope.locator('input[type=submit][value="Save"]').click();
    const row2 = page.locator('#tab-content-memberships table.memberships tbody tr').filter({ hasText: 'Internal Tools' });
    await expect(row2.locator('td.roles')).toHaveText(/^\s*Developer\s*$/);

    // remove
    await row2.getByRole('link', { name: 'Delete' }).click();
    await expect(page.locator('#tab-content-memberships table.memberships tbody tr').filter({ hasText: 'Internal Tools' })).toHaveCount(0);
    expect(Number(sqlValue("SELECT COUNT(*) FROM members WHERE user_id = 9 AND project_id = 2"))).toBe(0);
  });
});
