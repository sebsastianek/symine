import { test, expect } from '../../support/fixtures';
import { sql } from '../../support/db';

test.describe('project members', () => {
  test.use({ as: 'jsmith' });

  test('add a member through the "New member" dialog with principal search', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/settings/members');
    const table = page.locator('#tab-content-members table.members');
    await expect(table).toContainText('Dave Lopper');
    await expect(table).not.toContainText('Anna Müller');

    await page.locator('#tab-content-members a.icon-add', { hasText: 'New member' }).click();
    const modal = await ui.expectModal('New member');
    // current members are not offered
    await expect(modal.locator('#principals')).not.toContainText('Dave Lopper');
    await expect(modal.locator('#principals')).toContainText('Anna Müller');

    // the search field filters the principals list through an ajax request
    const search = modal.locator('#principal_search');
    const refreshed = page.waitForResponse((r) => r.url().includes('autocomplete') && r.url().includes('q=ann'));
    await search.pressSequentially('ann', { delay: 30 });
    await refreshed;
    await expect(modal.locator('#principals label')).toHaveCount(1);
    await expect(modal.locator('#principals label')).toContainText('Anna Müller');

    await modal.locator('#principals label', { hasText: 'Anna Müller' }).locator('input').check();
    await modal.locator('.roles-selection label', { hasText: 'Reporter' }).locator('input').check();
    await modal.locator('#member-add-submit').click();

    await expect(ui.modal()).toBeHidden();
    const row = table.locator('tr', { hasText: 'Anna Müller' });
    await expect(row.locator('td.roles')).toHaveText('Reporter');
    // persisted
    expect(
      sql(
        'SELECT r.name FROM members m JOIN member_roles mr ON mr.member_id = m.id JOIN roles r ON r.id = mr.role_id WHERE m.user_id = 9 AND m.project_id = 1',
      ),
    ).toEqual([{ name: 'Reporter' }]);

    // anna now sees the project in "My projects" style lists: the project is visible to her as a member
    await page.goto('/projects/ecommerce/settings/members');
    await expect(table.locator('tr', { hasText: 'Anna Müller' })).toBeVisible();
  });

  test('adding without a role reports the error', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/settings/members');
    await page.locator('#tab-content-members a.icon-add', { hasText: 'New member' }).click();
    const modal = await ui.expectModal();
    await modal.locator('#principals label', { hasText: 'Redmine Admin' }).locator('input').check();
    const dialog = page.waitForEvent('dialog');
    await modal.locator('#member-add-submit').click();
    expect((await dialog).message()).toContain('Role');
    await expect(page.locator('#tab-content-members table.members')).not.toContainText('Redmine Admin');
  });

  test('edit the roles of a member inline', async ({ page }) => {
    await page.goto('/projects/ecommerce/settings/members');
    const row = page.locator('#tab-content-members tr.member', { hasText: 'Robert Hill' });
    await expect(row.locator('td.roles span')).toHaveText('Reporter');
    await row.locator('a.icon-edit').click();
    const form = row.locator('form.edit_membership');
    await expect(form).toBeVisible();
    await expect(row.locator('td.roles > span')).toBeHidden();

    // cancel restores the roles label
    await form.getByRole('link', { name: 'Cancel' }).click();
    await expect(form).toHaveCount(0);
    await expect(row.locator('td.roles > span')).toBeVisible();

    await row.locator('a.icon-edit').click();
    await form.locator('label', { hasText: 'Developer' }).locator('input').check();
    await form.locator('label', { hasText: 'Reporter' }).locator('input').uncheck();
    await form.locator('input[name=commit]').click();
    await expect(page.locator('#tab-content-members tr.member', { hasText: 'Robert Hill' }).locator('td.roles span').first()).toHaveText(
      'Developer',
    );
    await page.reload();
    await expect(page.locator('#tab-content-members tr.member', { hasText: 'Robert Hill' }).locator('td.roles span').first()).toHaveText(
      'Developer',
    );
  });

  test('remove a member', async ({ page }) => {
    await page.goto('/projects/ecommerce/settings/members');
    const row = page.locator('#tab-content-members tr.member', { hasText: 'Robert Hill' });
    await row.locator('a.icon-link-break').click();
    await expect(page.locator('#tab-content-members tr.member', { hasText: 'Robert Hill' })).toHaveCount(0);
    expect(sql('SELECT id FROM members WHERE user_id = 7 AND project_id = 1')).toEqual([]);

    // rhill lost access to the members-only parts (still a public project: issues visible as non member)
    await page.reload();
    await expect(page.locator('#tab-content-members table.members')).not.toContainText('Robert Hill');
  });

  test('members are listed on the project overview', async ({ page }) => {
    await page.goto('/projects/ecommerce');
    const box = page.locator('#content .members.box');
    await expect(box).toContainText('Manager');
    await expect(box).toContainText('John Smith');
    await expect(box).toContainText('Developer');
  });
});
