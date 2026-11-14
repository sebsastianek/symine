import { test, expect } from '../../support/fixtures';
import { sqlValue } from '../../support/db';
import type { Page } from '@playwright/test';

/** Themes: Administration > Themes, the Strata theme, personal appearance and the quick switcher. */

function setting(name: string): string {
  return String(sqlValue('SELECT value FROM settings WHERE name = ?', [name]) ?? '');
}

async function useTheme(page: Page, id: string): Promise<void> {
  await page.goto('/admin/themes');
  if (await page.locator(`#theme-${id}.theme-card-active`).count()) return;
  await page.locator(`#theme-${id} input[type=submit][value="Use this theme"]`).click();
  await expect(page.locator('#flash_notice')).toHaveText('Successful update.');
  await expect(page.locator(`#theme-${id}`)).toHaveClass(/theme-card-active/);
}

const rgb = (page: Page, selector: string, property: string) =>
  page.locator(selector).first().evaluate((el, prop) => getComputedStyle(el).getPropertyValue(prop), property);

/** Relative luminance (0 = black, 1 = white) of a computed "rgb(...)"/"color(...)" value */
function luminance(color: string): number {
  const n = (color.match(/[\d.]+/g) ?? []).map(Number);
  const [r, g, b] = color.startsWith('rgb') ? n.map((v) => v / 255) : n.slice(-3);
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

test.describe('themes', () => {
  test.use({ as: 'admin' });

  test('the default theme is Redmine\'s markup, untouched', async ({ page }) => {
    await page.goto('/admin/themes');
    await expect(page.locator('#theme-default')).toHaveClass(/theme-card-active/);
    for (const id of ['classic', 'alternate', 'strata']) {
      await expect(page.locator(`#theme-${id}`)).toBeVisible();
    }
    await expect(page.locator('html')).not.toHaveAttribute('data-theme', /.*/);
    await expect(page.locator('#appearance-switcher')).toHaveCount(0);
    expect(setting('ui_theme')).toBe('');
  });

  test('a Redmine theme replaces the core stylesheet', async ({ page }) => {
    await useTheme(page, 'classic');
    expect(setting('ui_theme')).toBe('classic');
    await expect(page.locator('body')).toHaveClass(/theme-Classic/);
    await expect(page.locator('link[rel=stylesheet][href^="/themes/classic/stylesheets/application.css"]')).toHaveCount(1);
    await expect(page.locator('link[rel=stylesheet][href="/assets/application.css"]')).toHaveCount(0);
    // the theme imports the core stylesheet and overrides it: the classic header colour
    expect(await rgb(page, '#header', 'background-color')).toBe('rgb(70, 122, 167)');
    await useTheme(page, 'default');
  });

  test('Strata: side rail, quick switcher, options that apply at once', async ({ page, browser }) => {
    await useTheme(page, 'strata');
    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto('/projects/ecommerce/issues');
    const html = page.locator('html');
    await expect(html).toHaveAttribute('data-theme', 'strata');
    await expect(html).toHaveAttribute('data-color-scheme', 'auto');
    await expect(html).toHaveAttribute('data-theme-navigation', 'rail');

    // the header is a rail beside the content, with the menu as a list
    const header = (await page.locator('#header').boundingBox())!;
    const content = (await page.locator('#content').boundingBox())!;
    expect(header.x + header.width).toBeLessThanOrEqual(content.x + 1);
    expect(header.height).toBeGreaterThan(400);
    const items = page.locator('#main-menu > ul > li > a');
    const first = (await items.nth(0).boundingBox())!;
    const second = (await items.nth(1).boundingBox())!;
    expect(second.y).toBeGreaterThan(first.y);
    await expect(page.locator('.st-brand')).toBeVisible();
    await expect(page.locator('#header h1 .current-project')).toHaveText('eCommerce');

    // "auto" follows the system
    expect(luminance(await rgb(page, 'body', 'background-color'))).toBeGreaterThan(0.9);
    await page.emulateMedia({ colorScheme: 'dark' });
    expect(luminance(await rgb(page, 'body', 'background-color'))).toBeLessThan(0.1);
    await page.emulateMedia({ colorScheme: 'light' });

    // quick switcher: applied without reload, kept after it
    await page.locator('#appearance-switcher .dropdown-trigger').click();
    const dark = page.locator('#appearance-switcher [data-appearance-option="color_scheme"] [data-appearance-value="dark"]');
    await expect(dark).toBeVisible();
    const saved = page.waitForResponse((r) => r.url().endsWith('/appearance') && r.request().method() === 'POST');
    await dark.click();
    await expect(html).toHaveAttribute('data-color-scheme', 'dark');
    await expect(dark).toHaveAttribute('aria-pressed', 'true');
    expect((await saved).status()).toBe(200);
    expect(luminance(await rgb(page, 'body', 'background-color'))).toBeLessThan(0.1);
    await page.reload();
    await expect(html).toHaveAttribute('data-color-scheme', 'dark');
    expect(luminance(await rgb(page, '#content', 'background-color'))).toBeLessThan(0.1);
    // text stays readable on the dark background
    expect(luminance(await rgb(page, '#content h2', 'color'))).toBeGreaterThan(0.6);

    // somebody else is not affected
    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] }, colorScheme: 'light' });
    const anon = await ctx.newPage();
    await anon.goto('/projects');
    await expect(anon.locator('html')).toHaveAttribute('data-color-scheme', 'auto');
    expect(luminance(await rgb(anon, 'body', 'background-color'))).toBeGreaterThan(0.9);
    await ctx.close();

    // personal appearance page: changes show immediately, are saved on submit
    await page.goto('/my/appearance');
    await page.locator('input[name="options[color_scheme]"][value="light"]').check({ force: true });
    await expect(html).toHaveAttribute('data-color-scheme', 'light');
    await page.locator('input[name="options[navigation]"][value="tabs"]').check({ force: true });
    await expect(html).toHaveAttribute('data-theme-navigation', 'tabs');
    const headerTabs = (await page.locator('#header').boundingBox())!;
    const contentTabs = (await page.locator('#content').boundingBox())!;
    expect(headerTabs.y + headerTabs.height).toBeLessThanOrEqual(contentTabs.y + 1);
    await page.locator('input[name="options[accent]"][value="azurite"]').check({ force: true });
    await expect(html).toHaveAttribute('data-theme-accent', 'azurite');
    expect((await rgb(page, 'html', '--st-accent-source')).trim()).toBe('#2456c9');
    await page.locator('#appearance-form input[type=submit]').click();
    await expect(page.locator('#flash_notice')).toHaveText('Successful update.');
    await expect(html).toHaveAttribute('data-theme-navigation', 'tabs');
    await expect(html).toHaveAttribute('data-theme-accent', 'azurite');
    expect((await rgb(page, 'html', '--st-accent-source')).trim()).toBe('#2456c9');
    await expect(page.locator('input[name="options[navigation]"][value="tabs"]')).toBeChecked();
  });

  test('Strata: site options are customised by administrators for everybody', async ({ page, browser }) => {
    await useTheme(page, 'strata');
    await page.goto('/admin/themes/strata');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'strata');
    await page.locator('input[name="options[density]"][value="compact"]').check({ force: true });
    await expect(page.locator('html')).toHaveAttribute('data-theme-density', 'compact');
    await page.locator('input[name="options[radius]"]').fill('0');
    await page.locator('input[name="options[brand_name]"]').fill('Acme Tracker');
    await page.locator('#custom_css').fill('#footer { letter-spacing: 3px; }');
    await page.locator('#theme-options-form input[name=commit]').click();
    await expect(page.locator('#flash_notice')).toHaveText('Successful update.');

    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const anon = await ctx.newPage();
    await anon.goto('/projects');
    await expect(anon.locator('html')).toHaveAttribute('data-theme-density', 'compact');
    await expect(anon.locator('.st-brand-name')).toHaveText('Acme Tracker');
    expect(await rgb(anon, '#footer', 'letter-spacing')).toBe('3px');
    expect(await rgb(anon, 'html', '--st-radius')).toBe('0');
    // visitors keep their own choice in a cookie
    await anon.locator('#appearance-switcher .dropdown-trigger').click();
    await anon.locator('#appearance-switcher [data-appearance-option="density"] [data-appearance-value="comfortable"]').click();
    await expect(anon.locator('html')).toHaveAttribute('data-theme-density', 'comfortable');
    await anon.waitForResponse((r) => r.url().endsWith('/appearance')).catch(() => undefined);
    await anon.reload();
    await expect(anon.locator('html')).toHaveAttribute('data-theme-density', 'comfortable');
    await ctx.close();

    // an invalid value is refused and explained
    await page.goto('/admin/themes/strata');
    await page.locator('input[name="options[radius]"]').evaluate((el: HTMLInputElement) => { el.removeAttribute('max'); el.value = '99'; });
    await page.locator('#theme-options-form input[name=commit]').click();
    await expect(page.locator('.appearance-field-error')).toContainText('cannot be used here');

    // back to the defaults (the test fixtures accept the confirmation dialog)
    await page.locator('#theme-options-form input[name=reset]').click();
    await expect(page.locator('#flash_notice')).toHaveText('Successful update.');
    await expect(page.locator('html')).toHaveAttribute('data-theme-density', 'comfortable');
    await expect(page.locator('input[name="options[radius]"]')).toHaveValue('6');
  });

  test('preview shows a theme to the administrator only', async ({ page, browser }) => {
    await useTheme(page, 'default');
    await page.goto('/admin/themes');
    await page.locator('#theme-strata input[type=submit][value="Preview"]').click();
    await expect(page.locator('#theme-preview-bar')).toContainText('You are previewing the theme Strata');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'strata');
    expect(setting('ui_theme')).toBe('');

    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const anon = await ctx.newPage();
    await anon.goto('/projects');
    await expect(anon.locator('html')).not.toHaveAttribute('data-theme', /.*/);
    await ctx.close();

    await page.locator('#theme-preview-bar button', { hasText: 'Stop preview' }).click();
    await expect(page.locator('#theme-preview-bar')).toHaveCount(0);
    await expect(page.locator('html')).not.toHaveAttribute('data-theme', /.*/);
  });
});
