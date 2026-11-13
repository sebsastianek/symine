/**
 * Dual-run UI parity: read-only flows with selectors shared by Symine and Redmine. Run against both:
 *   bin/test-e2e --grep @parity
 *   E2E_BASE_URL=http://localhost:3300 bin/test-e2e --grep @parity
 * Assertions avoid data that may differ between the two instances (counts, dates, names created by other runs).
 */
import { test, expect } from '../../support/fixtures';

test.describe('UI parity @parity', () => {
  test('login page and failed login', async ({ page }) => {
    await page.goto('/login');
    await expect(page.locator('#login-form #username')).toBeVisible();
    await expect(page.locator('#login-form #password')).toBeVisible();
    await expect(page.locator('#login-form a[href$="/account/lost_password"]')).toBeVisible();
    await page.locator('#username').fill('jsmith');
    await page.locator('#password').fill('wrong-password');
    await page.locator('#login-submit').click();
    await expect(page.locator('#flash_error')).toHaveText('Invalid user or password');
  });

  test('anonymous: public project visible, private project needs login', async ({ page }) => {
    await page.goto('/projects');
    await expect(page.locator('#projects-index a[href="/projects/ecommerce"]').first()).toBeVisible();
    await expect(page.locator('#projects-index')).not.toContainText('Internal Tools');
    await page.goto('/projects/internal');
    await expect(page).toHaveURL(/\/login\?back_url=/);
  });

  test.describe('as jsmith', () => {
    test.use({ as: 'jsmith' });

    test('issue page structure', async ({ page }) => {
      await page.goto('/issues/13');
      await expect(page.locator('#content h2')).toHaveText('Bug #13');
      await expect(page.locator('.issue.details div.subject h3')).toHaveText('Cart total is wrong with discounts');
      for (const css of ['status', 'priority', 'assigned-to', 'category', 'fixed-version', 'start-date', 'due-date', 'progress']) {
        await expect(page.locator(`.issue.details .attributes .${css}.attribute .label`)).toBeVisible();
      }
      await expect(page.locator('#relations')).toContainText('Related issues');
      await expect(page.locator('#issue_tree')).toContainText('Subtasks');
      await expect(page.locator('#content > .contextual a.icon-edit').first()).toBeVisible();
      await expect(page.locator('#watchers')).toBeVisible();
      await expect(page.locator('#history .journal').first()).toBeVisible();
    });

    test('issue edit form toggles with JS and refreshes on tracker change', async ({ page }) => {
      await page.goto('/issues/14');
      await expect(page.locator('#update')).toBeHidden();
      await page.locator('#content > .contextual a.icon-edit').first().click();
      await expect(page.locator('#update')).toBeVisible();
      await expect(page.locator('#issue_notes')).toBeFocused();
      await page.goto('/projects/ecommerce/issues/new');
      await expect(page.locator('#issue_custom_field_values_2')).toBeVisible();
      await page.locator('#issue_tracker_id').selectOption('2');
      await expect(page.locator('#issue_custom_field_values_3')).toBeVisible();
      await expect(page.locator('#issue_custom_field_values_2')).toHaveCount(0);
    });

    test('issue list: add filter, context menu folders', async ({ page, ui }) => {
      await page.goto('/projects/ecommerce/issues');
      await expect(page.locator('#add_filter_select option[value=tracker_id]')).toHaveCount(1);
      await ui.addFilter('tracker_id');
      await expect(page.locator('#operators_tracker_id')).toHaveValue('=');
      await page.locator('#values_tracker_id_1').selectOption('1');
      await ui.applyFilters();
      await expect(page.locator('table.list.issues tbody tr td.tracker').first()).toHaveText('Bug');
      const trackers = await page.locator('table.list.issues tbody td.tracker').allTextContents();
      expect(new Set(trackers)).toEqual(new Set(['Bug']));

      const menu = await ui.openContextMenu(ui.listRows().first());
      for (const folder of ['Status', 'Tracker', 'Priority', 'Assignee', '% Done']) {
        await expect(menu.locator('li.folder > a.submenu', { hasText: folder }).first()).toBeVisible();
      }
      await expect(menu.locator('a.icon-edit').first()).toBeVisible();
      await page.keyboard.press('Escape');
    });

    test('quick search #id jumps to the issue, project jump box', async ({ page, ui }) => {
      await page.goto('/projects/ecommerce');
      await ui.quickSearch('#13');
      await expect(page).toHaveURL(/\/issues\/13$/);
      await page.goto('/my/page');
      await page.locator('#project-jump .drdn-trigger').click();
      await expect(page.locator('#project-jump .drdn-content')).toBeVisible();
      await page.locator('#project-jump .drdn-content input').fill('Checkout');
      await page.locator('#project-jump .drdn-items a', { hasText: 'Checkout' }).first().click();
      await expect(page).toHaveURL(/\/projects\/checkout/);
    });

    test('collapsible sidebar on the issue list', async ({ page }) => {
      await page.goto('/projects/ecommerce/issues');
      await expect(page.locator('#sidebar')).toBeVisible();
      await page.locator('#sidebar-switch-button').click();
      await expect(page.locator('#main')).toHaveClass(/collapsedsidebar/);
      await page.locator('#sidebar-switch-button').click();
      await expect(page.locator('#main')).not.toHaveClass(/collapsedsidebar/);
    });

    test('watchers modal and relation form open with JS', async ({ page, ui }) => {
      await page.goto('/issues/16');
      await page.locator('#watchers .contextual a').click();
      const modal = await ui.expectModal();
      await expect(modal.locator('#user_search')).toBeVisible();
      await modal.locator('p.buttons a').click();
      await expect(modal).toBeHidden();
      await page.locator('#relations .contextual a.icon-link-add').click();
      await expect(page.locator('#new-relation-form')).toBeVisible();
      await page.locator('#relation_relation_type').selectOption('precedes');
      await expect(page.locator('#predecessor_fields')).toBeVisible();
    });
  });
});
