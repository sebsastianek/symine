import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import type { Page } from '@playwright/test';

async function openProjectActions(page: Page) {
  await page.locator('#content > .contextual .icon-actions.dropdown-trigger').click();
  const menu = page.locator('#content > .contextual .dropdown-content');
  await expect(menu).toBeVisible();
  return menu;
}

test.describe('project lifecycle', () => {
  test.describe('as admin', () => {
    test.use({ as: 'admin' });

    test('archive and unarchive from the admin projects list context menu', async ({ page, ui, api, http }) => {
      const admin = await api('admin');
      const identifier = uid('arch-');
      const project = await admin.createProject({ name: `Archive me ${identifier}`, identifier });

      await page.goto('/admin/projects');
      await expect(ui.adminMenu('projects')).toHaveClass(/selected/);
      const row = page.locator(`tr#project-${project.id}`);
      await expect(row).toBeVisible();

      const menu = await ui.openContextMenu(row);
      await menu.locator('a.icon-lock', { hasText: 'Archive' }).click();
      await expect(page).toHaveURL(/\/admin\/projects/);
      // the default admin list only shows active projects
      await expect(page.locator(`tr#project-${project.id}`)).toHaveCount(0);
      expect(sqlValue('SELECT status FROM projects WHERE id = ?', [project.id])).toBe(9);

      // archived projects are not accessible anymore, even for admins (403 with an explanation)
      const res = await http.get(`/projects/${identifier}`);
      expect(res.status()).toBe(403);
      await page.goto(`/projects/${identifier}`);
      await expect(page.locator('#content')).toContainText("The project you're trying to access has been archived.");
      // and they are not listed in the projects list
      await page.goto('/projects?display_type=list');
      await expect(page.locator('table.projects')).not.toContainText(`Archive me ${identifier}`);

      // archived projects are listed with the status filter; the "..." button opens the same context menu
      await page.goto('/admin/projects');
      await ui.setFilter('status', '=', ['9']);
      await ui.applyFilters();
      await expect(page.locator(`tr#project-${project.id}`)).toHaveClass(/archived/);
      await page.locator(`tr#project-${project.id} td.buttons a.js-contextmenu`).click();
      await expect(page.locator('#context-menu')).toBeVisible();
      await page.locator('#context-menu a.icon-unlock', { hasText: 'Unarchive' }).click();
      await expect(page).toHaveURL(/\/admin\/projects/);
      await expect.poll(() => sqlValue('SELECT status FROM projects WHERE id = ?', [project.id])).toBe(1);
      await page.goto('/admin/projects');
      await expect(page.locator(`tr#project-${project.id}`)).not.toHaveClass(/archived/);
      await page.goto(`/projects/${identifier}`);
      await expect(page.locator('#content h2')).toHaveText('Overview');
    });

    test('admin projects list filters by status', async ({ page, ui }) => {
      await page.goto('/admin/projects');
      await expect(page.locator('tr#project-1')).toBeVisible();
      await expect(page.locator('tr#project-4')).toHaveCount(0); // legacy (archived)
      await ui.setFilter('status', '=', ['9']);
      await ui.applyFilters();
      await expect(page.locator('tr#project-4 td.name')).toContainText('Legacy');
      await expect(page.locator('tr#project-4')).toHaveClass(/archived/);
      await expect(page.locator('tr#project-1')).toHaveCount(0);
    });

    test('delete a project with the identifier confirmation page', async ({ page, ui, api }) => {
      const admin = await api('admin');
      const identifier = uid('del-');
      const project = await admin.createProject({ name: `Delete me ${identifier}`, identifier });
      await admin.createProject({ name: `Child ${identifier}`, identifier: `${identifier}-child`, parent_id: project.id });

      await page.goto(`/projects/${identifier}`);
      const menu = await openProjectActions(page);
      await menu.locator('a.icon-del', { hasText: 'Delete' }).click();
      await expect(page.locator('#content h2')).toHaveText('Confirmation');
      await expect(page.locator('#content .warning')).toContainText(`Delete me ${identifier}`);
      // the subproject is announced
      await expect(page.locator('#content .warning')).toContainText(`Child ${identifier}`);

      // wrong identifier: nothing deleted, still on the confirmation page
      await page.locator('#confirm').fill('nope');
      await page.locator('input[name=commit]').click();
      await expect(page.locator('#content h2')).toHaveText('Confirmation');
      expect(sql('SELECT id FROM projects WHERE identifier = ?', [identifier])).toHaveLength(1);

      await page.locator('#confirm').fill(identifier);
      await page.locator('input[name=commit]').click();
      await ui.expectNotice('Successful deletion.');
      await expect(page).toHaveURL(/\/admin\/projects/);
      await expect(page.locator(`tr#project-${project.id}`)).toHaveCount(0);
      expect(sql('SELECT id FROM projects WHERE identifier LIKE ?', [`${identifier}%`])).toEqual([]);

      // cancel goes back to the list
      await page.goto('/projects/checkout');
      await (await openProjectActions(page)).locator('a.icon-del').click();
      await page.getByRole('link', { name: 'Cancel' }).click();
      await expect(page).toHaveURL(/\/admin\/projects/);
    });
  });

  test.describe('as manager', () => {
    test.use({ as: 'jsmith' });

    test('close and reopen a project (read-only banner)', async ({ page, ui }) => {
      await page.goto('/projects/checkout');
      // "New issue" lives in the "+" menu of the project menu
      await expect(ui.mainMenu('new-issue-sub')).toHaveCount(1);
      let menu = await openProjectActions(page);
      await menu.locator('a.icon-lock', { hasText: 'Close' }).click();

      await expect(page).toHaveURL(/\/projects\/checkout$/);
      const banner = page.locator('#content p.warning, p.warning', { hasText: 'This project is closed and read-only.' });
      await expect(banner.first()).toBeVisible();
      expect(sqlValue("SELECT status FROM projects WHERE identifier = 'checkout'")).toBe(5);
      // write actions disappear
      await expect(ui.mainMenu('new-issue-sub')).toHaveCount(0);
      await page.goto('/issues/17');
      await expect(page.locator('#content .contextual a.icon-edit')).toHaveCount(0);
      const res = await page.request.get('/projects/checkout/issues/new');
      expect(res.status()).toBe(403);

      await page.goto('/projects/checkout');
      menu = await openProjectActions(page);
      await expect(menu.locator('a.icon-lock')).toHaveCount(0);
      await menu.locator('a.icon-unlock', { hasText: 'Reopen' }).click();
      await expect(page.locator('p.warning', { hasText: 'closed and read-only' })).toHaveCount(0);
      await expect(ui.mainMenu('new-issue-sub')).toHaveCount(1);
      expect(sqlValue("SELECT status FROM projects WHERE identifier = 'checkout'")).toBe(1);
    });

    test('closed projects are listed only with the "closed" filter', async ({ page }) => {
      await page.goto('/projects/checkout');
      await (await openProjectActions(page)).locator('a.icon-lock').click();
      await expect(page.locator('p.warning', { hasText: 'closed and read-only' }).first()).toBeVisible();
      await page.goto('/projects?display_type=list');
      await expect(page.locator('table.projects')).not.toContainText('Checkout');
      await page.goto('/projects?display_type=list&set_filter=1&f[]=status&op[status]=%3D&v[status][]=5');
      await expect(page.locator('table.projects')).toContainText('Checkout');
    });
  });

  test.describe('as reporter', () => {
    test.use({ as: 'rhill' });

    test('cannot close, delete or archive', async ({ page, http }) => {
      await page.goto('/projects/ecommerce');
      await expect(page.locator('#content > .contextual a.icon-lock')).toHaveCount(0);
      await expect(page.locator('#content > .contextual a.icon-del')).toHaveCount(0);
      expect((await http.post('/projects/ecommerce/close')).status()).toBe(403);
      expect((await http.post('/projects/ecommerce/archive')).status()).toBe(403);
      expect((await http.post('/projects/ecommerce', {}, 'delete')).status()).toBe(403);
      expect(sqlValue("SELECT status FROM projects WHERE identifier = 'ecommerce'")).toBe(1);
    });
  });
});
