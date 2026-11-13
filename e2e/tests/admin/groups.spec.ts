import { test, expect, uid } from '../../support/fixtures';
import { sqlValue } from '../../support/db';
import { openTab, rowWithLink, submitButton } from './_helpers';

test.describe('admin groups', () => {
  test.use({ as: 'admin' });

  test('create a group, add users through search + checkboxes, remove a user, delete the group', async ({ page, ui }) => {
    const name = uid('E2E group ');
    await page.goto('/groups');
    await ui.contextual('New group').click();
    await submitButton(page, 'Create').click();
    await expect(ui.errors()).toContainText('Name cannot be blank');

    await page.locator('#group_name').fill(name);
    await submitButton(page, 'Create').click();
    await ui.expectNotice('Successful creation.');
    await expect(page).toHaveURL(/\/groups(\?|$)/);
    await rowWithLink(page, name).getByRole('link', { name }).click();
    const id = Number(page.url().match(/\/groups\/(\d+)\/edit/)![1]);

    // users tab -> "New user" (Redmine: modal, Symine: page or modal)
    const users = await openTab(page, 'users');
    await expect(users.locator('p.nodata')).toBeVisible();
    await users.getByRole('link', { name: 'New user' }).click();
    const scope = page.locator('#ajax-modal:visible, #content').first();
    const search = scope.locator('#user_search');
    await expect(search).toBeVisible();
    await expect(scope.locator('#principals label')).toHaveCount(5); // admin, jsmith, dlopper, rhill, anna

    // live search filters the checkbox list (observeSearchfield)
    await search.pressSequentially('robe');
    await expect(scope.locator('#principals label')).toHaveCount(1);
    await expect(scope.locator('#principals label')).toHaveText(/Robert Hill/);
    await scope.locator('#principals label', { hasText: 'Robert Hill' }).locator('input').check();

    await search.fill('');
    await search.pressSequentially('anna');
    await expect(scope.locator('#principals label')).toHaveCount(1);
    await scope.locator('#principals label', { hasText: 'Anna' }).locator('input').check();
    await scope.locator('input[type=submit][value="Add"]').click();

    const usersTab = page.locator('#tab-content-users');
    await expect(usersTab).toBeVisible();
    await expect(usersTab.locator('table.users tbody tr')).toHaveCount(2);
    await expect(usersTab.locator('tr#user-7')).toContainText('Robert Hill');
    await expect(usersTab.locator('tr#user-9')).toContainText('Anna Müller');
    expect(Number(sqlValue('SELECT COUNT(*) FROM groups_users WHERE group_id = ?', [id]))).toBe(2);

    // remove one member
    await usersTab.locator('tr#user-7').getByRole('link', { name: 'Remove' }).click();
    // Redmine 6+ asks to confirm by typing "Yes"
    await expect(page.locator('#content h2')).toHaveText('Confirmation');
    await expect(page.locator('#content')).toContainText('Robert Hill');
    await page.locator('#confirm').fill('Yes');
    await submitButton(page, 'Delete').click();
    await expect(page.locator('#tab-content-users tr#user-7')).toHaveCount(0);
    await expect(page.locator('#tab-content-users tr#user-9')).toBeVisible();
    expect(Number(sqlValue('SELECT COUNT(*) FROM groups_users WHERE group_id = ?', [id]))).toBe(1);

    // rename
    await openTab(page, 'general');
    await page.locator('#group_name').fill(`${name} renamed`);
    await submitButton(page, 'Save').click();
    await ui.expectNotice('Successful update.');

    // delete from the list
    await page.goto('/groups');
    // like Redmine: back to the referer without a flash message
    await rowWithLink(page, `${name} renamed`).getByRole('link', { name: 'Delete' }).click();
    await expect(rowWithLink(page, `${name} renamed`)).toHaveCount(0);
    await expect(page).toHaveURL(/\/groups$/);
    expect(Number(sqlValue('SELECT COUNT(*) FROM users WHERE id = ?', [id]))).toBe(0);
  });

  test('group project membership is inherited by its users', async ({ page, ui }) => {
    // Developers group (id 10) is a member of ecommerce; add it to Internal Tools as Reporter
    await page.goto('/groups/10/edit');
    const tab = await openTab(page, 'memberships');
    await tab.getByRole('link', { name: 'Add projects' }).click();
    const scope = page.locator('#ajax-modal:visible, #content').first();
    await scope.locator('label', { hasText: 'Internal Tools' }).locator('input[type=checkbox]').check();
    await scope.locator('label', { hasText: 'Reporter' }).locator('input[type=checkbox]').check();
    await scope.locator('input[type=submit][value="Add"]').click();
    await expect(page.locator('#tab-content-memberships table.memberships tbody tr').filter({ hasText: 'Internal Tools' })).toBeVisible();

    // add anna to the group, she becomes (inherited) member of Internal Tools
    await page.goto('/groups/10/edit?tab=users');
    await page.locator('#tab-content-users').getByRole('link', { name: 'New user' }).click();
    const s2 = page.locator('#ajax-modal:visible, #content').first();
    await s2.locator('#principals label', { hasText: 'Anna' }).locator('input').check();
    await s2.locator('input[type=submit][value="Add"]').click();
    await expect(page.locator('#tab-content-users tr#user-9')).toBeVisible();

    await page.goto('/projects/internal/settings/members');
    await expect(page.locator('#tab-content-members table.members')).toContainText('Anna Müller');
    await expect(page.locator('#tab-content-members table.members')).toContainText('Developers');
  });
});
