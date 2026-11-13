import { test, expect } from '../../support/fixtures';
import { sqlValue } from '../../support/db';

test.describe('project jump box', () => {
  test.use({ as: 'jsmith' });

  test('lists my projects and filters them while typing', async ({ page }) => {
    await page.goto('/my/page');
    const jump = page.locator('#project-jump');
    await expect(jump.locator('.drdn-trigger')).toHaveText('Jump to a project...');
    await jump.locator('.drdn-trigger').click();
    await expect(jump).toHaveClass(/expanded/);
    const items = jump.locator('.drdn-items.projects');
    await expect(items).toContainText('eCommerce');
    await expect(items).toContainText('Internal');

    await jump.locator('#projects-quick-search').pressSequentially('check');
    await expect(items.locator('a')).toHaveText([/Checkout/]);
    await items.locator('a', { hasText: 'Checkout' }).click();
    await expect(page).toHaveURL(/\/projects\/checkout$/);
    await expect(page.locator('#project-jump .drdn-trigger')).toHaveText('Checkout');
  });

  test('keeps the current module when jumping from a project page', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/issues');
    await ui.jumpToProject('Internal');
    await expect(page).toHaveURL(/\/projects\/internal\/issues/);
    await expect(ui.mainMenu('issues')).toHaveClass(/selected/);
  });

  test('"All Projects" opens the project list', async ({ page }) => {
    await page.goto('/my/page');
    await page.locator('#project-jump .drdn-trigger').click();
    await page.locator('#project-jump .all-projects a').click();
    await expect(page).toHaveURL(/\/projects(\?|$)/);
    await expect(page.locator('#content h2')).toHaveText('Projects');
  });

  test('is not shown to anonymous users', async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const p = await ctx.newPage();
    await p.goto('/projects/ecommerce');
    await expect(p.locator('#project-jump')).toHaveCount(0);
    await expect(p.locator('#quick-search #q')).toBeVisible();
    await ctx.close();
  });
});

test.describe('quick search', () => {
  test.use({ as: 'jsmith' });

  test('"#13" and "13" jump straight to the issue', async ({ page, ui }) => {
    await page.goto('/my/page');
    await ui.quickSearch('#13');
    await expect(page).toHaveURL(/\/issues\/13$/);
    await expect(ui.heading()).toContainText('Bug #13');
    await ui.quickSearch('15');
    await expect(page).toHaveURL(/\/issues\/15$/);
  });

  test('a word shows the search results with type filters', async ({ page, ui }) => {
    await page.goto('/my/page');
    await ui.quickSearch('Wishlist');
    await expect(page).toHaveURL(/\/search\?.*q=Wishlist/);
    await expect(page.locator('#search-results dt', { hasText: 'Wishlist sharing' })).toBeVisible();
    await expect(page.locator('#search-results-counts')).toContainText('Issues (3)');
    await expect(page.locator('#search-input')).toHaveValue('Wishlist');
    // clicking a result opens it
    await page.locator('#search-results dt a', { hasText: 'Wishlist emails' }).click();
    await expect(page).toHaveURL(/\/issues\/16$/);
  });

  test('searching from a project page is scoped to the project', async ({ page, ui }) => {
    await page.goto('/projects/internal');
    await ui.quickSearch('crash');
    await expect(page).toHaveURL(/\/projects\/internal\/search\?/);
    await expect(page.locator('#search-results')).toContainText('Internal tool crash');
  });

  test('the issue autocomplete of the search box suggests issues', async ({ page }) => {
    await page.goto('/my/page');
    // inline auto complete (tribute): "#" + text suggests issues, picking one inserts "#id"
    await page.locator('#q').pressSequentially('#Wishl');
    const suggestion = page.locator('.tribute-container li', { hasText: 'Wishlist emails' }).first();
    await expect(suggestion).toBeVisible();
    await suggestion.click();
    await expect(page.locator('#q')).toHaveValue(/#16/);
  });
});

test.describe('issue list context menu', () => {
  test.use({ as: 'jsmith' });

  test('right click opens the menu with the allowed actions and updates the issue', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/issues');
    const row = ui.issueRow(16);
    const menu = await ui.openContextMenu(row);
    await expect(row).toHaveClass(/context-menu-selection/);
    await expect(menu.locator('a.icon-edit').first()).toBeVisible();
    await expect(menu.locator('a.icon-copy:not(.disabled)')).toBeVisible();
    await expect(menu.locator('a.icon-del:not(.disabled)')).toBeVisible();

    // close it with a click elsewhere
    await page.locator('#content h2').click();
    await expect(menu).toBeHidden();

    await ui.openContextMenu(row);
    await ui.contextMenuPick('Priority', 'High');
    await ui.expectNotice('Successful update.');
    await expect(ui.issueRow(16).locator('td.priority')).toHaveText('High');
    expect(Number(sqlValue('SELECT priority_id FROM issues WHERE id = 16'))).toBe(3);
  });

  test('reporter gets a reduced menu', async ({ loginAs }) => {
    const { page, ui } = await loginAs('rhill');
    await page.goto('/projects/ecommerce/issues');
    await expect(ui.loggedAs()).toHaveText('@rhill');
    const menu = await ui.openContextMenu(ui.issueRow(14));
    // like Redmine, forbidden actions are rendered disabled
    await expect(menu.locator('a.icon-del')).toHaveClass(/disabled/);
    await expect(menu.locator('a.icon-copy')).toHaveClass(/disabled/);
    // no edit_issues: bulk edit is disabled too (only notes are allowed)
    await expect(menu.locator('a.icon-edit').first()).toHaveClass(/disabled/);
  });
});

test.describe('collapsible sidebar', () => {
  test.use({ as: 'jsmith' });

  test('hides the sidebar and remembers it for the page type', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues');
    const main = page.locator('#main');
    const button = page.locator('#sidebar-switch-button');
    await expect(main).toHaveClass(/collapsiblesidebar/);
    await expect(page.locator('#sidebar')).toBeVisible();
    await expect(main).not.toHaveClass(/collapsedsidebar/);

    await button.click();
    await expect(main).toHaveClass(/collapsedsidebar/);
    await page.reload();
    await expect(page.locator('#main')).toHaveClass(/collapsedsidebar/);
    // another page type keeps its own state
    await page.goto('/projects/ecommerce/activity');
    await expect(page.locator('#main')).not.toHaveClass(/collapsedsidebar/);

    await page.goto('/projects/ecommerce/issues');
    await page.locator('#sidebar-switch-button').click();
    await expect(page.locator('#main')).not.toHaveClass(/collapsedsidebar/);
    await page.reload();
    await expect(page.locator('#main')).not.toHaveClass(/collapsedsidebar/);
  });
});

test.describe('flash messages', () => {
  test.use({ as: 'jsmith' });

  test('notice after a successful action, error after a failed one, gone on the next page', async ({ page, ui }) => {
    await page.goto('/issues/14');
    // AJAX watch toggles every watch link of the page
    await page.locator('#content > .contextual a.issue-14-watcher').first().click();
    await expect(page.locator('a.issue-14-watcher').first()).toHaveText('Unwatch');
    await expect(page.locator('a.issue-14-watcher').last()).toHaveText('Unwatch');
    // notice from a regular POST/redirect
    await page.goto('/my/account');
    await page.locator('#my_account_form p.mobile-hide input[type=submit]').click();
    await expect(ui.flashNotice()).toHaveText('Account was successfully updated.');
    await page.reload();
    await expect(ui.flashNotice()).toHaveCount(0);
    // error flash
    await page.goto('/projects/ecommerce/issues/new');
    await page.locator('#issue-form input[name=commit]').click();
    await expect(ui.errors()).toContainText('Subject cannot be blank');
  });
});
