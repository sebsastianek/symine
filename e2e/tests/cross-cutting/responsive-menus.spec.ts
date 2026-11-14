import { test, expect, uid } from '../../support/fixtures';
import type { Page } from '@playwright/test';

/*
 * Layout behaviour driven by JavaScript: the mobile "flyout" menu (responsive.js moves the project menu, the general
 * menu, the sidebar and the account menu into it below 900px), the "+" new-object menu of the project menu and the
 * left/right buttons shown when the project tabs do not fit.
 */
const MOBILE = { width: 414, height: 800 };

async function openFlyout(page: Page): Promise<void> {
  await page.locator('#header .js-flyout-menu-toggle-button').click();
  await expect(page.locator('html')).toHaveClass(/flyout-is-active/);
  await expect(page.locator('.js-flyout-menu')).toBeInViewport();
}

test.describe('mobile layout', () => {
  test.use({ as: 'jsmith', viewport: MOBILE });

  test('the flyout menu holds the project menu, the general menu, the sidebar and the account links', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues?set_filter=1');
    // desktop navigation is not shown; the toggle button is
    await expect(page.locator('#header .js-flyout-menu-toggle-button')).toBeVisible();
    await expect(page.locator('#top-menu')).toBeHidden();
    await expect(page.locator('#main-menu')).toBeHidden();
    await expect(page.locator('#sidebar')).toBeHidden();
    await expect(page.locator('html')).not.toHaveClass(/flyout-is-active/);
    // the content is usable without horizontal scrolling of the whole page
    await expect(page.locator('table.issues tr#issue-13')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

    await openFlyout(page);
    const flyout = page.locator('.js-flyout-menu');
    await expect(flyout.locator('.flyout-menu__avatar')).toContainText('John Smith');
    // project menu, with the current tab selected
    await expect(flyout.locator('.js-project-menu a.issues')).toHaveClass(/selected/);
    await expect(flyout.locator('.js-project-menu a.overview')).toBeVisible();
    await expect(flyout.locator('.js-project-menu a.wiki')).toBeVisible();
    // general menu
    await expect(flyout.locator('.js-general-menu a.my-page')).toBeVisible();
    await expect(flyout.locator('.js-general-menu a.projects')).toBeVisible();
    // the sidebar of the issue list (saved queries) moved into the flyout
    await expect(flyout.locator('.js-sidebar')).toContainText('Open bugs');
    // account links
    await expect(flyout.locator('.js-profile-menu a.my-account')).toBeVisible();
    await expect(flyout.locator('.js-profile-menu a.logout')).toBeVisible();

    // clicking the page closes the flyout without following the click
    await page.locator('#main').click({ position: { x: MOBILE.width - 20, y: 300 } });
    await expect(page.locator('html')).not.toHaveClass(/flyout-is-active/);
    await expect(page).toHaveURL(/\/projects\/ecommerce\/issues/);

    // navigate with the project menu of the flyout
    await openFlyout(page);
    await flyout.locator('.js-project-menu a.roadmap').click();
    await expect(page).toHaveURL(/\/projects\/ecommerce\/roadmap$/);
    await expect(page.locator('#content h2').first()).toHaveText('Roadmap');
    await expect(page.locator('html')).not.toHaveClass(/flyout-is-active/);

    // a saved query from the moved sidebar
    await page.goto('/projects/ecommerce/issues?set_filter=1');
    await openFlyout(page);
    await flyout.locator('.js-sidebar a.query', { hasText: 'Open bugs' }).click();
    await expect(page).toHaveURL(/query_id=8/);
    await expect(page.locator('#content h2').first()).toHaveText('Open bugs');
  });

  test('search from the flyout and the toggle button closes it again', async ({ page }) => {
    await page.goto('/projects/ecommerce');
    await openFlyout(page);
    await page.locator('#header .js-flyout-menu-toggle-button').click({ force: true });
    await expect(page.locator('html')).not.toHaveClass(/flyout-is-active/);

    await openFlyout(page);
    await page.locator('#flyout-search').fill('Wishlist');
    await page.locator('#flyout-search').press('Enter');
    await expect(page).toHaveURL(/\/search\?q=Wishlist/);
    await expect(page.locator('#search-results')).toContainText('Wishlist');
  });

  test('forms stay usable: create an issue on a phone-sized screen', async ({ page, ui }) => {
    const subject = uid('Mobile issue ');
    await page.goto('/projects/ecommerce/issues/new');
    await page.locator('#issue_subject').fill(subject);
    await page.locator('#issue_description').fill('typed on a small screen');
    await page.locator('#issue_priority_id').selectOption({ label: 'High' });
    await page.locator('#issue-form input[name=commit]').click();
    await ui.expectNotice(/Issue #\d+ created/);
    await expect(page.locator('.issue.details .subject h3')).toHaveText(subject);
    await expect(page.locator('.issue.details .attributes .priority .value')).toHaveText('High');
  });

  test('growing the window restores the desktop menus', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues?set_filter=1');
    await expect(page.locator('.js-flyout-menu .js-project-menu a.issues')).toHaveCount(1);
    await page.setViewportSize({ width: 1366, height: 900 });
    // the resize handler moves everything back
    await expect(page.locator('#main-menu a.issues')).toBeVisible();
    await expect(page.locator('#top-menu a.my-page')).toBeVisible();
    await expect(page.locator('#sidebar')).toContainText('Open bugs');
    await expect(page.locator('.js-flyout-menu .js-project-menu a')).toHaveCount(0);
    await expect(page.locator('#header .js-flyout-menu-toggle-button')).toBeHidden();
    // and back to mobile
    await page.setViewportSize(MOBILE);
    await expect(page.locator('.js-flyout-menu .js-project-menu a.issues')).toHaveCount(1);
    await expect(page.locator('#main-menu')).toBeHidden();
  });
});

test.describe('mobile layout (anonymous)', () => {
  test.use({ viewport: MOBILE });

  test('the flyout offers "Sign in" and the login form works', async ({ page }) => {
    await page.goto('/projects/ecommerce');
    await openFlyout(page);
    const flyout = page.locator('.js-flyout-menu');
    await expect(flyout.locator('.flyout-menu__avatar')).toHaveCount(0);
    await expect(flyout.locator('.js-profile-menu a.my-account')).toHaveCount(0);
    await flyout.locator('.js-profile-menu a.login').click();
    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator('#login-form')).toBeVisible();
    await expect(page.locator('#username')).toBeVisible();
    await expect(page.locator('#login-submit')).toBeInViewport();
  });
});

test.describe('project menu', () => {
  test.describe('as a manager', () => {
    test.use({ as: 'jsmith' });

    test('"+" opens the new-object menu with the objects the user may create', async ({ page }) => {
      await page.goto('/projects/ecommerce');
      const dropdown = page.locator('#main-menu #new-object + ul.menu-children');
      await expect(dropdown).toBeHidden();
      await page.locator('#main-menu #new-object').click();
      await expect(dropdown).toHaveClass(/visible/);
      await expect(dropdown).toBeVisible();
      await expect(dropdown.locator('a')).toContainText(['New issue', 'New category', 'New version', 'Log time', 'Add news', 'New document', 'New wiki page', 'New file']);
      // a second click closes it
      await page.locator('#main-menu #new-object').click();
      await expect(dropdown).not.toHaveClass(/visible/);

      await page.locator('#main-menu #new-object').click();
      await dropdown.locator('a.new-version').click();
      await expect(page).toHaveURL(/\/projects\/ecommerce\/versions\/new/);
      await expect(page.locator('#content h2')).toHaveText('New version');
    });

    test('tabs that do not fit get left / right buttons', async ({ page }) => {
      await page.setViewportSize({ width: 1366, height: 900 });
      await page.goto('/projects/ecommerce');
      const buttons = page.locator('#main-menu .tabs-buttons');
      await expect(buttons).toBeHidden();

      // narrower than the tabs but still the desktop layout (>= 900px)
      await page.setViewportSize({ width: 905, height: 900 });
      await expect(buttons).toBeVisible();
      const tabs = page.locator('#main-menu > ul > li');
      await expect(tabs.first()).toBeVisible();
      // "right" hides the first tabs so that the following ones move into view; "left" brings them back
      await buttons.locator('.tab-right').click();
      await expect(tabs.first()).toBeHidden();
      await expect(buttons.locator('.tab-left')).not.toHaveClass(/disabled/);
      await buttons.locator('.tab-right').click();
      await expect(tabs.nth(1)).toBeHidden();
      await buttons.locator('.tab-left').click();
      await expect(tabs.nth(1)).toBeVisible();
      await expect(tabs.first()).toBeHidden();
      await buttons.locator('.tab-left').click();
      await expect(tabs.first()).toBeVisible();

      await page.setViewportSize({ width: 1366, height: 900 });
      await expect(buttons).toBeHidden();
    });
  });

  test.describe('as a reporter', () => {
    test.use({ as: 'rhill' });

    test('the new-object menu only lists what a reporter may create', async ({ page }) => {
      await page.goto('/projects/ecommerce');
      await page.locator('#main-menu #new-object').click();
      const dropdown = page.locator('#main-menu #new-object + ul.menu-children');
      await expect(dropdown).toBeVisible();
      await expect(dropdown.locator('a.new-issue-sub')).toBeVisible();
      await expect(dropdown.locator('a.new-version')).toHaveCount(0);
      await expect(dropdown.locator('a.new-issue-category')).toHaveCount(0);
      await dropdown.locator('a.new-issue-sub').click();
      await expect(page).toHaveURL(/\/projects\/ecommerce\/issues\/new$/);
    });
  });
});
