import { test, expect } from '../../support/fixtures';
import type { UserLogin } from '../../support/env';

// Read-only, but keeps the default per-file reset: earlier files may have changed users (e.g. a password change
// invalidates the stored session of that user until the DB is restored).

type Row = { as: UserLogin | null; newIssue: boolean; settings: boolean; edit: boolean; copy: boolean; del: boolean; watch: boolean };

// Roles on ecommerce: jsmith Manager, dlopper Developer, rhill Reporter, anna non-member, anonymous.
const matrix: Row[] = [
  { as: null, newIssue: false, settings: false, edit: false, copy: false, del: false, watch: false },
  { as: 'anna', newIssue: true, settings: false, edit: true, copy: false, del: false, watch: true },
  { as: 'rhill', newIssue: true, settings: false, edit: true, copy: false, del: false, watch: true },
  { as: 'dlopper', newIssue: true, settings: true, edit: true, copy: false, del: false, watch: true },
  { as: 'jsmith', newIssue: true, settings: true, edit: true, copy: true, del: true, watch: true },
];

for (const row of matrix) {
  test.describe(`UI elements as ${row.as ?? 'anonymous'}`, () => {
    test.use({ as: row.as });

    test('project menu and issue actions follow the permissions', async ({ page, ui }) => {
      await page.goto('/projects/ecommerce');
      if (row.as) await expect(ui.loggedAs()).toHaveText(`@${row.as}`);
      else await expect(ui.topMenu('login')).toBeVisible();
      await expect(ui.mainMenu('overview')).toHaveClass(/selected/);
      await expect(ui.mainMenu('issues')).toBeVisible();
      await expect(ui.mainMenu('new-issue-sub')).toHaveCount(row.newIssue ? 1 : 0);
      await expect(ui.mainMenu('settings')).toHaveCount(row.settings ? 1 : 0);
      await expect(ui.topMenu('administration')).toHaveCount(row.as === 'admin' ? 1 : 0);

      await page.goto('/issues/13');
      await expect(ui.heading()).toContainText('Bug #13');
      const actions = page.locator('#content > .contextual').first();
      await expect(actions.locator('a.icon-edit')).toHaveCount(row.edit ? 1 : 0);
      await expect(actions.locator('a.icon-copy')).toHaveCount(row.copy ? 1 : 0);
      await expect(actions.locator('a.icon-del')).toHaveCount(row.del ? 1 : 0);
      await expect(actions.locator('a.issue-13-watcher')).toHaveCount(row.watch ? 1 : 0);
      // the edit form is only rendered for users allowed to update the issue
      await expect(page.locator('#issue-form')).toHaveCount(row.edit ? 1 : 0);
    });
  });
}

test.describe('anonymous', () => {
  test('private project, admin and My page redirect to the login form', async ({ page }) => {
    for (const path of ['/projects/internal', '/admin', '/my/page', '/issues/18']) {
      await page.goto(path);
      await expect(page, path).toHaveURL(/\/login\?back_url=/);
      await expect(page.locator('#login-form')).toBeVisible();
    }
  });

  test('private project is not listed', async ({ page }) => {
    await page.goto('/projects');
    await expect(page.locator('#content')).toContainText('eCommerce');
    await expect(page.locator('#content a[href="/projects/internal"]')).toHaveCount(0);
  });

  test('archived project shows a 403 page', async ({ page, ui }) => {
    const res = await page.goto('/projects/legacy');
    expect(res?.status()).toBe(403);
    await expect(ui.heading()).toHaveText('403');
    await expect(page.locator('#errorExplanation')).toHaveText("The project you're trying to access has been archived.");
  });
});

test.describe('logged-in non-member / reporter', () => {
  test.use({ as: 'rhill' });

  test('forbidden pages answer 403 with the Redmine error page', async ({ page, ui }) => {
    await page.goto('/my/page');
    await expect(ui.loggedAs()).toHaveText('@rhill');
    for (const path of ['/projects/internal', '/admin', '/users', '/projects/ecommerce/settings', '/issues/18']) {
      const res = await page.goto(path);
      expect(res?.status(), path).toBe(403);
      await expect(ui.heading()).toHaveText('403');
      await expect(page.locator('#errorExplanation')).toHaveText('You are not authorized to access this page.');
    }
  });

  test('unknown records answer 404', async ({ page, ui }) => {
    const res = await page.goto('/issues/99999');
    expect(res?.status()).toBe(404);
    await expect(ui.heading()).toHaveText('404');
    await expect(page.locator('#errorExplanation')).toHaveText("The page you were trying to access doesn't exist or has been removed.");
    expect((await page.goto('/projects/no-such-project'))?.status()).toBe(404);
  });

  test('reporter cannot delete an issue even with a forged request', async ({ http }) => {
    const res = await http.post('/issues/13', {}, 'delete');
    expect(res.status()).toBe(403);
  });
});

test.describe('manager', () => {
  test.use({ as: 'jsmith' });

  test('sees the private project and its issues but not the admin area', async ({ page, ui }) => {
    await page.goto('/projects/internal');
    await expect(page.locator('#header h1')).toContainText('Internal');
    await page.goto('/issues/18');
    await expect(ui.heading()).toContainText('#18');
    expect((await page.goto('/admin'))?.status()).toBe(403);
  });

  test('private issue of another project member is visible to the manager only', async ({ page, loginAs }) => {
    await page.goto('/issues/17');
    await expect(page.locator('#content div.issue.details')).toHaveClass(/\bprivate\b/);
    await expect(page.locator('#content span.badge-private')).toBeVisible();
    const anna = await loginAs('anna');
    expect((await anna.page.goto('/issues/17'))?.status()).toBe(403);
  });
});

test.describe('admin', () => {
  test.use({ as: 'admin' });

  test('administration menu and archived project are reachable', async ({ page, ui }) => {
    await page.goto('/');
    await ui.topMenu('administration').click();
    await expect(page).toHaveURL(/\/admin$/);
    await expect(ui.adminMenu('projects')).toBeVisible();
    await page.goto('/admin/projects');
    await expect(page.locator('table.list')).not.toContainText('Legacy');
    await ui.setFilter('status', '=', ['9']);
    await ui.applyFilters();
    await expect(page.locator('table.list')).toContainText('Legacy');
  });
});
