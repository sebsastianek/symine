import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import { rowWithLink, submitButton } from './_helpers';

function permissionsOf(name: string): string[] {
  const raw = sqlValue('SELECT permissions FROM roles WHERE name = ?', [name]);
  return raw ? (JSON.parse(String(raw)) as string[]) : [];
}

test.describe('admin roles and permissions', () => {
  test.use({ as: 'admin' });

  test('create a role with permissions using the "check all" toggles', async ({ page, ui }) => {
    const name = uid('Role ');
    await page.goto('/roles');
    await ui.contextual('New role').click();
    await page.locator('#role_name').fill(name);

    // a new role starts with the Non member permissions (like Redmine)
    await expect(page.locator('#role_permissions_view_wiki_pages')).toBeChecked();
    await expect(page.locator('#role_permissions_edit_wiki_pages')).not.toBeChecked();

    // module toggle: checks every permission of the Wiki module (some are checked -> all checked)
    const wiki = page.locator('fieldset#module_wiki');
    const wikiBoxes = wiki.locator('input[type=checkbox]');
    const n = await wikiBoxes.count();
    expect(n).toBeGreaterThan(3);
    await wiki.locator('legend a.icon-checked').click();
    await expect(wiki.locator('input[type=checkbox]:checked')).toHaveCount(n);
    // toggling again unchecks all, then check them back
    await wiki.locator('legend a.icon-checked').click();
    await expect(wiki.locator('input[type=checkbox]:checked')).toHaveCount(0);
    await wiki.locator('legend a.icon-checked').click();
    await expect(wiki.locator('input[type=checkbox]:checked')).toHaveCount(n);

    // single permission
    await page.locator('#role_permissions_view_issues').check();
    await page.locator('#role_permissions_add_issues').check();
    await submitButton(page, 'Create').click();
    await ui.expectNotice('Successful creation.');

    await expect(rowWithLink(page, name)).toBeVisible();
    const perms = permissionsOf(name);
    expect(perms).toEqual(expect.arrayContaining(['view_issues', 'add_issues', 'view_wiki_pages', 'edit_wiki_pages']));
    expect(perms).not.toContain('manage_members');

    // edit: uncheck one permission
    await rowWithLink(page, name).getByRole('link', { name }).click();
    await expect(page.locator('#role_permissions_edit_wiki_pages')).toBeChecked();
    await page.locator('#role_permissions_edit_wiki_pages').uncheck();
    await submitButton(page, 'Save').click();
    await ui.expectNotice('Successful update.');
    expect(permissionsOf(name)).not.toContain('edit_wiki_pages');
    expect(permissionsOf(name)).toContain('view_wiki_pages');
  });

  test('copy a role (permissions and workflow) then delete it', async ({ page, ui }) => {
    await page.goto('/roles');
    await rowWithLink(page, 'Developer').getByRole('link', { name: 'Copy' }).click();
    await expect(page).toHaveURL(/\/roles\/new\?copy=4/);
    // permissions are pre-filled from Developer
    await expect(page.locator('#role_permissions_manage_versions')).toBeChecked();
    await expect(page.locator('#role_permissions_manage_members')).not.toBeChecked();
    const name = uid('Dev copy ');
    await page.locator('#role_name').fill(name);
    await expect(page.locator('#copy_workflow_from')).toHaveValue('4');
    await submitButton(page, 'Create').click();
    await ui.expectNotice('Successful creation.');

    const id = Number(sqlValue('SELECT id FROM roles WHERE name = ?', [name]));
    expect(permissionsOf(name).sort()).toEqual(permissionsOf('Developer').sort());
    const copied = Number(sqlValue('SELECT COUNT(*) FROM workflows WHERE role_id = ?', [id]));
    expect(copied).toBe(Number(sqlValue('SELECT COUNT(*) FROM workflows WHERE role_id = 4')));

    // like Redmine, deleting a role redirects to the list without a flash message
    await rowWithLink(page, name).getByRole('link', { name: 'Delete' }).click();
    await expect(rowWithLink(page, name)).toHaveCount(0);
    await expect(page).toHaveURL(/\/roles$/);
    expect(sql('SELECT id FROM roles WHERE id = ?', [id])).toHaveLength(0);
  });

  test('a role in use cannot be deleted', async ({ page, ui }) => {
    await page.goto('/roles');
    await rowWithLink(page, 'Reporter').getByRole('link', { name: 'Delete' }).click();
    await ui.expectError(/This role is in use and cannot be deleted/);
    await expect(rowWithLink(page, 'Reporter')).toBeVisible();
  });

  test('permissions report: toggle a column and a row, save', async ({ page, ui }) => {
    await page.goto('/roles/permissions');
    await expect(page.locator('#content h2')).toContainText('Permissions report');
    const form = page.locator('#permissions_form');

    // Reporter (role 5) gets "Manage versions" through its checkbox
    const reporterManageVersions = form.locator('tr.permission-manage_versions input.role-5');
    await expect(reporterManageVersions).not.toBeChecked();
    await reporterManageVersions.check();

    // row toggle: "Delete issues" for every role of the row
    const deleteIssuesRow = form.locator('tr.permission-delete_issues');
    await deleteIssuesRow.locator('a.icon-checked').click();
    const enabled = deleteIssuesRow.locator('input[type=checkbox]:enabled');
    const count = await enabled.count();
    await expect(deleteIssuesRow.locator('input[type=checkbox]:enabled:checked')).toHaveCount(count);

    await form.locator('input[type=submit][value="Save"]').click();
    await ui.expectNotice('Successful update.');
    expect(permissionsOf('Reporter')).toEqual(expect.arrayContaining(['manage_versions', 'delete_issues']));
    expect(permissionsOf('Developer')).toContain('delete_issues');

    // reload shows the saved state
    await page.goto('/roles/permissions');
    await expect(page.locator('#permissions_form tr.permission-manage_versions input.role-5')).toBeChecked();
  });

  test('a new permission is effective for the member: Reporter can edit issues after granting edit_issues', async ({ page, ui, loginAs }) => {
    const rhill = await loginAs('rhill');
    await rhill.page.goto('/issues/14');
    await expect(rhill.page.locator('#content .contextual a.icon-edit').first()).toBeVisible(); // add notes
    await rhill.page.goto('/issues/14/edit');
    await expect(rhill.page.locator('#issue_subject')).toHaveCount(0);

    await page.goto('/roles/5/edit');
    await page.locator('#role_permissions_edit_issues').check();
    await submitButton(page, 'Save').click();
    await ui.expectNotice('Successful update.');

    await rhill.page.goto('/issues/14/edit');
    await expect(rhill.page.locator('#issue_subject')).toBeVisible();
  });
});
