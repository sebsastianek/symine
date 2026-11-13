import { test, expect } from '../../support/fixtures';

test.describe('activity', () => {
  test.use({ as: 'jsmith' });

  test('project activity with type filters, user filter and date navigation', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce');
    await ui.mainMenu('activity').click();
    await expect(page).toHaveURL(/\/projects\/ecommerce\/activity/);
    await expect(page.locator('#content h2')).toHaveText('Activity');
    const events = page.locator('#activity dl dt');
    await expect(page.locator('#activity dt.issue', { hasText: 'Cart total is wrong with discounts' }).first()).toBeVisible();
    await expect(page.locator('#activity dt.news', { hasText: 'Release 0.9' })).toBeVisible();
    // spent time is not displayed by default
    await expect(page.locator('#show_time_entries')).not.toBeChecked();
    await expect(page.locator('#activity dt.time-entry')).toHaveCount(0);

    // only news
    for (const cb of await page.locator('#activity_scope_form input[type=checkbox][id^=show_]').all()) await cb.uncheck();
    await page.locator('#show_news').check();
    await page.locator('#activity_scope_form input[type=submit]').click();
    await expect(page).toHaveURL(/show_news=1/);
    await expect(page.locator('#show_news')).toBeChecked();
    await expect(page.locator('#show_issues')).not.toBeChecked();
    await expect(events).toHaveCount(1);
    await expect(events.first()).toHaveClass(/news/);

    // add spent time
    await page.locator('#show_time_entries').check();
    await page.locator('#activity_scope_form input[type=submit]').click();
    await expect(page.locator('#activity dt.time-entry').first()).toBeVisible();
    await expect(page.locator('#activity dt.issue')).toHaveCount(0);

    // a type link in the sidebar shows only that type
    await page.locator('#sidebar label[for=show_issues] a').click();
    await expect(page.locator('#show_issues')).toBeChecked();
    await expect(page.locator('#show_news')).not.toBeChecked();
    await expect(page.locator('#activity dt.issue').first()).toBeVisible();
    await expect(page.locator('#activity dt.news')).toHaveCount(0);

    // user filter
    await page.locator('#user_id').selectOption({ label: 'Robert Hill' });
    await page.locator('#activity_scope_form input[type=submit]').click();
    await expect(page.locator('#content h2')).toContainText('Robert Hill');
    await expect(page.locator('#activity dt.issue', { hasText: 'Wishlist' }).first()).toBeVisible();
    await expect(page.locator('#activity dt', { hasText: 'Cart total is wrong with discounts' })).toHaveCount(0);

    // previous period: nothing older in the fixture
    await page.goto('/projects/ecommerce/activity');
    const subtitle = await page.locator('#content p.subtitle').textContent();
    await page.locator('.pagination li.previous a').click();
    await expect(page.locator('#content p.subtitle')).not.toHaveText(subtitle!);
    await expect(page.locator('#content p.nodata')).toBeVisible();
    await expect(page.locator('.pagination li.next a')).toBeVisible();
    await page.locator('.pagination li.next a').click();
    await expect(page.locator('#content p.subtitle')).toHaveText(subtitle!);
  });

  test('subprojects activity can be excluded', async ({ page }) => {
    await page.goto('/projects/ecommerce/activity');
    await expect(page.locator('#activity dt', { hasText: 'Payment fails on Safari' }).first()).toBeVisible();
    await page.locator('#with_subprojects').uncheck();
    await page.locator('#activity_scope_form input[type=submit]').click();
    await expect(page.locator('#activity dt.issue').first()).toBeVisible();
    await expect(page.locator('#activity dt', { hasText: 'Payment fails on Safari' })).toHaveCount(0);
  });

  test('global activity respects project visibility', async ({ page, loginAs }) => {
    await page.goto('/activity');
    await expect(page.locator('#activity dt', { hasText: 'Internal tool crash' }).first()).toBeVisible();
    const rhill = await loginAs('rhill');
    await rhill.page.goto('/activity');
    await expect(rhill.page.locator('#activity dt', { hasText: 'Cart total is wrong with discounts' }).first()).toBeVisible();
    await expect(rhill.page.locator('#activity dt', { hasText: 'Internal tool crash' })).toHaveCount(0);
  });
});

test.describe('search', () => {
  test.use({ as: 'jsmith' });

  test('"#13" in the quick search jumps to the issue', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce');
    await ui.quickSearch('#13');
    await expect(page).toHaveURL(/\/issues\/13$/);
    await expect(page.locator('#content h2')).toContainText('#13');
  });

  test('quick search inside a project searches that project and its subprojects', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/issues');
    await ui.quickSearch('wishlist');
    await expect(page).toHaveURL(/\/projects\/ecommerce\/search\?.*q=wishlist/);
    await expect(page.locator('#search-input')).toHaveValue('wishlist');
    const results = page.locator('#search-results dt');
    await expect(results).toHaveCount(3);
    await expect(page.locator('#search-results')).toContainText('Wishlist sharing');
    await expect(page.locator('#search-results-counts')).toContainText('Issues (3)');
  });

  test('result types, titles only, scope and private projects', async ({ page, loginAs }) => {
    await page.goto('/search?q=coupon');
    await expect(page.locator('#search-results dt', { hasText: 'Cart total is wrong with discounts' })).toBeVisible();

    // the word is only in the description: "titles only" finds nothing
    await page.locator('#titles_only').check();
    await page.locator('#search-form input[name=commit]').click();
    await expect(page).toHaveURL(/titles_only=1/);
    await expect(page.locator('#titles_only')).toBeChecked();
    await expect(page.locator('#search-results dt')).toHaveCount(0);

    // types: news + wiki only
    await page.goto('/search?q=checkout');
    await expect(page.locator('#search-results dt.issue').first()).toBeVisible();
    await page.locator('#search-types input#issues').uncheck();
    await page.locator('#search-form input[name=commit]').click();
    await expect(page.locator('#search-types input#issues')).not.toBeChecked();
    await expect(page.locator('#search-results dt.issue')).toHaveCount(0);
    await expect(page.locator('#search-results dt.wiki-page').first()).toBeVisible();

    // clicking a type label link checks only that type
    await page.goto('/search?q=hello');
    await page.locator('#search-types label', { hasText: 'Messages' }).locator('a').click();
    await expect(page.locator('#search-types input#messages')).toBeChecked();
    await expect(page.locator('#search-types input#issues')).not.toBeChecked();
    await expect(page.locator('#search-results dt.message').first()).toBeVisible();

    // scope: "My projects" vs "All projects"; private project issues need membership
    await page.goto('/search?q=crash');
    await expect(page.locator('#search-results')).toContainText('Internal tool crash');
    const rhill = await loginAs('rhill');
    await rhill.page.goto('/search?q=crash');
    await expect(rhill.page.locator('#search-results dt')).toHaveCount(0);
    // inside a project, the default scope is the project itself (the subproject's issue is not found)
    await rhill.page.goto('/projects/ecommerce/search?q=safari');
    await expect(rhill.page.locator('#scope option:checked')).toHaveText('eCommerce');
    await expect(rhill.page.locator('#search-results dt')).toHaveCount(0);
    await rhill.page.locator('#scope').selectOption('subprojects');
    await rhill.page.locator('#search-form input[name=commit]').click();
    await expect(rhill.page.locator('#search-results')).toContainText('Payment fails on Safari');
    await rhill.page.locator('#scope').selectOption({ label: 'eCommerce' });
    await rhill.page.locator('#search-form input[name=commit]').click();
    await expect(rhill.page.locator('#search-results dt')).toHaveCount(0);
  });

  test('search options: open issues only', async ({ page }) => {
    await page.goto('/search?q=login');
    await expect(page.locator('#search-results')).toContainText('Customer cannot login');
    await page.locator('#search-form legend, #search-form fieldset a', { hasText: 'Options' }).first().click();
    await expect(page.locator('#options-content')).toBeVisible();
    await page.locator('#open_issues').check();
    await page.locator('#search-form input[name=commit]').click();
    await expect(page.locator('#open_issues')).toBeChecked();
    await expect(page.locator('#search-results')).not.toContainText('Customer cannot login');
  });

  test('anonymous search does not show private content', async ({ browser }) => {
    // browser.newContext() inherits the `use` options (jsmith's storageState): reset it explicitly
    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const page = await ctx.newPage();
    await page.goto('/search?q=wishlist');
    await expect(page.locator('#search-results dt')).toHaveCount(3);
    await page.goto('/search?q=crash');
    await expect(page.locator('#search-results dt')).toHaveCount(0);
    await ctx.close();
  });
});
