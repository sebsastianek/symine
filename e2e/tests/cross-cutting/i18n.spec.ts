import { test, expect } from '../../support/fixtures';
import { sql } from '../../support/db';

test.describe('i18n: anna uses the German UI', () => {
  test.use({ as: 'anna' });

  test('menus, headings and pages are German', async ({ page, ui }) => {
    await page.goto('/my/page');
    await expect(page.locator('html')).toHaveAttribute('lang', 'de');
    await expect(ui.heading()).toHaveText('Meine Seite');
    await expect(ui.topMenu('projects')).toHaveText('Projekte');
    await expect(ui.topMenu('help')).toHaveText('Hilfe');
    await expect(page.locator('#block-issuesassignedtome h3')).toContainText('Mir zugewiesene Tickets');

    await page.goto('/projects/ecommerce/issues');
    await expect(ui.mainMenu('issues')).toHaveText('Tickets');
    await expect(ui.heading()).toHaveText('Tickets');
    await expect(page.locator('#query_form legend').first()).toHaveText('Filter');
    await expect(page.locator('table.list.issues th', { hasText: 'Thema' })).toBeVisible();
  });

  test('error pages and dates are localized', async ({ page }) => {
    await page.goto('/admin');
    await expect(page.locator('#errorExplanation')).toHaveText('Sie sind nicht berechtigt, auf diese Seite zuzugreifen.');
    // German relative times on the issue page
    await page.goto('/issues/13');
    const author = page.locator('#content div.issue p.author').first();
    await expect(author).toContainText(/Von John Smith vor .* hinzugefügt/);
    // German date format (dd.mm.yyyy) in the tooltip
    await expect(author.locator('span[title]').first()).toHaveAttribute('title', /^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$/);
  });

  test('forms are German (new issue)', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues/new');
    await expect(page.locator('#content h2')).toHaveText('Neues Ticket');
    await expect(page.locator('label[for=issue_subject]')).toContainText('Thema');
    await expect(page.locator('label[for=issue_start_date]')).toContainText('Beginn');
    await expect(page.locator('label[for=issue_due_date]')).toContainText('Abgabedatum');
    await expect(page.locator('#issue-form input[name=commit]')).toHaveValue('Anlegen');
  });
});

test.describe('i18n: browser language for anonymous users', () => {
  test.use({ locale: 'de-DE', extraHTTPHeaders: { 'Accept-Language': 'de-DE,de;q=0.9' } });

  test('anonymous visitor with a German browser gets the German UI', async ({ page, ui }) => {
    await page.goto('/');
    await expect(ui.topMenu('login')).toHaveText('Anmelden');
    await expect(ui.topMenu('home')).toHaveText('Hauptseite');
  });
});

test.describe('i18n: default language setting', () => {
  test('anonymous UI follows the default language when the browser sends none', async ({ page, ui }) => {
    sql("INSERT INTO settings (name, value, updated_on) VALUES ('default_language', 'fr', datetime('now'))");
    await page.route('**/*', (route) => route.continue({ headers: { ...route.request().headers(), 'accept-language': '' } }));
    await page.goto('/');
    await expect(ui.topMenu('login')).toHaveText('Connexion');
  });
});
