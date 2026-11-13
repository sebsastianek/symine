import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';

test.describe('project settings', () => {
  test.describe('as admin', () => {
    test.use({ as: 'admin' });

    test('tabs switch client-side and update the URL', async ({ page }) => {
      await page.goto('/projects/ecommerce/settings');
      const tabs = ['info', 'members', 'issues', 'versions', 'categories', 'repositories', 'boards', 'activities'];
      for (const name of tabs) await expect(page.locator(`#tab-${name}`)).toBeVisible();
      await expect(page.locator('#tab-info')).toHaveClass(/selected/);
      await expect(page.locator('#tab-content-info')).toBeVisible();

      for (const name of tabs.slice(1)) {
        const requests: string[] = [];
        const onRequest = (r: { url(): string; resourceType(): string }) => {
          if (r.resourceType() === 'document') requests.push(r.url());
        };
        page.on('request', onRequest);
        await page.locator(`#tab-${name}`).click();
        await expect(page.locator(`#tab-content-${name}`)).toBeVisible();
        await expect(page.locator(`#tab-${name}`)).toHaveClass(/selected/);
        await expect(page.locator('#tab-content-info')).toBeHidden();
        await expect(page).toHaveURL(new RegExp(`/projects/ecommerce/settings/${name}`));
        page.off('request', onRequest);
        expect(requests, `switching to ${name} must not reload the page`).toEqual([]);
      }

      // a tab URL opens that tab directly
      await page.goto('/projects/ecommerce/settings/categories');
      await expect(page.locator('#tab-categories')).toHaveClass(/selected/);
      await expect(page.locator('#tab-content-categories')).toBeVisible();
      await expect(page.locator('#tab-content-info')).toBeHidden();
    });

    test('information tab saves name, description and homepage', async ({ page, ui }) => {
      await page.goto('/projects/ecommerce/settings/info');
      // identifier cannot be changed once created
      await expect(page.locator('#project_identifier')).toBeDisabled();
      const description = uid('Shop description ');
      await page.locator('#project_description').fill(description);
      await page.locator('#project_homepage').fill('https://shop.example.org');
      await page.locator('#tab-content-info input[name=commit]').click();
      await ui.expectNotice('Successful update.');
      await expect(page).toHaveURL(/\/projects\/ecommerce\/settings(\/info)?$/);
      await expect(page.locator('#project_description')).toHaveValue(description);

      await page.goto('/projects/ecommerce');
      await expect(page.locator('#content .wiki')).toContainText(description);
      await expect(page.locator('#content')).toContainText('https://shop.example.org');
    });

    test('information tab shows validation errors', async ({ page, ui }) => {
      await page.goto('/projects/ecommerce/settings/info');
      await page.locator('#project_name').fill('');
      await page.locator('#tab-content-info input[name=commit]').click();
      await expect(ui.errors()).toContainText('Name cannot be blank');
      await expect(page.locator('#tab-info')).toHaveClass(/selected/);
      expect(sqlValue("SELECT name FROM projects WHERE identifier = 'ecommerce'")).toBe('eCommerce');
    });

    test('disabling a module removes its menu tab, enabling it brings it back', async ({ page, ui }) => {
      await page.goto('/projects/ecommerce/settings/info');
      await expect(ui.mainMenu('news')).toBeVisible();
      await page.locator('#project_enabled_module_names_news').uncheck();
      await page.locator('#tab-content-info input[name=commit]').click();
      await ui.expectNotice('Successful update.');
      await expect(ui.mainMenu('news')).toHaveCount(0);
      await expect(page.locator('#project_enabled_module_names_news')).not.toBeChecked();
      // the module's pages are gone too
      const res = await page.request.get('/projects/ecommerce/news');
      expect(res.status()).toBe(403);

      await page.locator('#project_enabled_module_names_news').check();
      await page.locator('#tab-content-info input[name=commit]').click();
      await ui.expectNotice('Successful update.');
      await expect(ui.mainMenu('news')).toBeVisible();
    });

    test('disabling issue tracking removes the issue tracking tab', async ({ page, ui }) => {
      await page.goto('/projects/checkout/settings/info');
      await expect(page.locator('#tab-issues')).toBeVisible();
      await page.locator('#project_enabled_module_names_issue_tracking').uncheck();
      await page.locator('#tab-content-info input[name=commit]').click();
      await ui.expectNotice('Successful update.');
      await expect(page.locator('#tab-issues')).toHaveCount(0);
      await expect(ui.mainMenu('issues')).toHaveCount(0);
    });

    test('issue tracking tab: trackers and default assignee', async ({ page, ui }) => {
      await page.goto('/projects/ecommerce/settings/issues');
      const support = page.locator('#project_trackers label', { hasText: 'Support' }).locator('input');
      await expect(support).toBeChecked();
      await support.uncheck();
      await page.locator('#project_default_assigned_to_id').selectOption({ label: 'Dave Lopper' });
      await page.locator('#project_issue_tracking input[type=submit]').click();
      await ui.expectNotice('Successful update.');
      await expect(page.locator('#tab-issues')).toHaveClass(/selected/);
      await expect(page.locator('#project_trackers label', { hasText: 'Support' }).locator('input')).not.toBeChecked();
      await expect(page.locator('#project_default_assigned_to_id option:checked')).toHaveText('Dave Lopper');

      // the new issue form only offers the remaining trackers
      await page.goto('/projects/ecommerce/issues/new');
      await expect(page.locator('#issue_tracker_id option')).toHaveText(['Bug', 'Feature']);
      expect(sqlValue("SELECT default_assigned_to_id FROM projects WHERE identifier = 'ecommerce'")).toBe(6);
    });

    test('time tracking tab: deactivate an activity for the project, then reset', async ({ page, ui }) => {
      await page.goto('/projects/ecommerce/settings/activities');
      const row = page.locator('#tab-content-activities tr', { hasText: 'Design' });
      await row.locator('input.enumerations_active').uncheck();
      await page.locator('#tab-content-activities input[name=commit]').click();
      await ui.expectNotice('Successful update.');
      await expect(page.locator('#tab-activities')).toHaveClass(/selected/);
      await expect(page.locator('#tab-content-activities tr', { hasText: 'Design' }).locator('input.enumerations_active')).not.toBeChecked();
      // a project-specific override row exists
      expect(
        sql("SELECT active FROM enumerations WHERE type = 'TimeEntryActivity' AND project_id = 1 AND parent_id = 8"),
      ).toEqual([{ active: 0 }]);

      // the activity is not offered when logging time on the project
      await page.goto('/projects/ecommerce/time_entries/new');
      await expect(page.locator('#time_entry_activity_id')).not.toContainText('Design');

      await page.goto('/projects/ecommerce/settings/activities');
      await page.locator('#tab-content-activities .contextual a.icon-del').click();
      await expect(page.locator('#tab-content-activities tr', { hasText: 'Design' }).locator('input.enumerations_active')).toBeChecked();
      expect(Number(sqlValue("SELECT COUNT(*) FROM enumerations WHERE project_id = 1"))).toBe(0);
    });
  });

  test.describe('as a developer', () => {
    test.use({ as: 'dlopper' });

    test('only sees the tabs allowed by the role', async ({ page, http }) => {
      await page.goto('/projects/ecommerce/settings');
      await expect(page.locator('#tab-versions')).toBeVisible();
      await expect(page.locator('#tab-categories')).toBeVisible();
      for (const hidden of ['info', 'members', 'issues', 'activities']) {
        await expect(page.locator(`#tab-${hidden}`)).toHaveCount(0);
      }
      // the settings tab is in the project menu, but editing the project is forbidden
      await expect(page.locator('#main-menu a.settings')).toBeVisible();
      const res = await http.post('/projects/ecommerce', { 'project[name]': 'Hacked' }, 'patch');
      expect(res.status()).toBe(403);
    });
  });

  test.describe('as a reporter', () => {
    test.use({ as: 'rhill' });

    test('has no settings menu and gets 403', async ({ page, http }) => {
      await page.goto('/projects/ecommerce');
      await expect(page.locator('#main-menu a.settings')).toHaveCount(0);
      await http.expectStatus('/projects/ecommerce/settings', 403);
    });
  });
});
