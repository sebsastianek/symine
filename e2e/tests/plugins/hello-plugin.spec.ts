/**
 * Plugin API, end to end, with the example plugin plugins/symine-hello (enabled in the dev environment):
 * admin enables the plugin's project module, grants its permissions to a role and changes its setting;
 * a member uses the project tab, sees the view hook on the issue page and the activity provider.
 *
 * The plugin table is created by the plugin migration (bin/console symine:plugins:migrate) on the e2e DB.
 * Symine only: skipped against an external server (the reference Redmine has no such plugin).
 */
import { execFileSync } from 'child_process';
import { test, expect, ensureFileReset, uid } from '../../support/fixtures';
import { sqlValue } from '../../support/db';
import { IS_EXTERNAL, PATHS, PHP, ROOT } from '../../support/env';

test.skip(IS_EXTERNAL, 'Symine plugin (not available on the reference Redmine)');
test.describe.configure({ mode: 'serial' });

const message = uid('Greeting ');

test.beforeAll(async ({}, testInfo) => {
  await ensureFileReset(testInfo);
  execFileSync(PHP, ['bin/console', 'symine:plugins:migrate', 'symine_hello', '--no-interaction'], {
    cwd: ROOT,
    env: { ...process.env, APP_ENV: 'dev', APP_DEBUG: '1', APP_CACHE_DIR: PATHS.cache, DATABASE_URL: `sqlite:///${PATHS.db}`, SYMINE_FILES_PATH: PATHS.files },
    stdio: 'pipe',
  });
  expect(Number(sqlValue("SELECT COUNT(*) FROM sqlite_master WHERE name = 'hello_greetings'"))).toBe(1);
});

test.describe('as admin', () => {
  test.use({ as: 'admin' });

  test('the plugin is listed on Administration » Plugins and its settings can be changed', async ({ page, ui }) => {
    await page.goto('/admin');
    await ui.adminMenu('plugins').click();
    const row = page.locator('table.plugins tr#plugin-symine_hello');
    await expect(row.locator('td.name span.name')).toHaveText('Symine Hello');
    await expect(row.locator('td.version')).toHaveText('1.0.0');
    await row.getByRole('link', { name: 'Configure' }).click();

    await expect(page).toHaveURL(/\/settings\/plugin\/symine_hello$/);
    await expect(ui.heading()).toContainText('Symine Hello');
    await expect(page.locator('#settings_greeting')).toHaveValue('Hello');
    await page.locator('#settings_greeting').fill('Ahoj');
    await expect(page.locator('#settings_show_on_issues')).toBeChecked();
    await page.getByRole('button', { name: 'Apply' }).click();
    await expect(ui.flashNotice()).toContainText('Successful update');
    await expect(page.locator('#settings_greeting')).toHaveValue('Ahoj');
  });

  test('the plugin module is enabled in the project settings', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/settings');
    const module = page.locator('#project_enabled_module_names_hello');
    await expect(module).not.toBeChecked();
    await module.check();
    await page.locator('#project_modules').locator('xpath=ancestor::form').locator('input[type=submit][name=commit]').click();
    await expect(ui.flashNotice()).toContainText('Successful update');
    await expect(page.locator('#project_enabled_module_names_hello')).toBeChecked();
  });

  test('the plugin permissions are granted to the Manager role', async ({ page, ui }) => {
    await page.goto('/roles');
    await page.getByRole('link', { name: 'Manager', exact: true }).click();
    const fieldset = page.locator('fieldset#module_hello');
    await expect(fieldset.locator('legend')).toContainText('Greetings');
    await page.locator('#role_permissions_view_hello_greetings').check();
    await page.locator('#role_permissions_add_hello_greetings').check();
    await page.getByRole('button', { name: 'Save' }).first().click();
    await expect(ui.flashNotice()).toContainText('Successful update');
  });
});

test.describe('as jsmith (Manager of eCommerce)', () => {
  test.use({ as: 'jsmith' });

  test('the project tab lists and creates greetings', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce');
    // menu item pushed after "Activity"
    const tabs = page.locator('#main-menu > ul > li > a');
    const classes = await tabs.evaluateAll((links) => links.map((a) => a.className.split(' ')[0]));
    expect(classes.indexOf('hello')).toBe(classes.indexOf('activity') + 1);
    // view_projects_show_right hook
    await expect(page.locator('.splitcontentright div.hello.box')).toBeVisible();

    await ui.openMainMenu('hello');
    await expect(ui.heading()).toHaveText('Greetings');
    await page.locator('a.new-greeting').click();
    await page.locator('#greeting_message').fill(message);
    await page.getByRole('button', { name: 'Create' }).click();
    await expect(ui.flashNotice()).toContainText('Successful creation');
    await expect(page.locator('#hello-greetings li.hello-greeting').first()).toContainText(`Ahoj! ${message}`);
  });

  test('the view hook shows the configured greeting on the issue page', async ({ page }) => {
    const issueId = sqlValue('SELECT id FROM issues WHERE project_id = 1 ORDER BY id LIMIT 1');
    await page.goto(`/issues/${issueId}`);
    const hook = page.locator('div.issue div.hello-issue-greeting');
    await expect(hook).toContainText('Ahoj!');
    await expect(hook).toContainText('This project has 1 greeting.');
    // stylesheet added by the view_layouts_base_html_head hook and served from plugin_assets
    const color = await hook.evaluate((el) => getComputedStyle(el).color);
    expect(color).toBe('rgb(62, 91, 118)');
    await hook.getByRole('link', { name: 'Greetings' }).click();
    await expect(page).toHaveURL(/\/projects\/ecommerce\/hello$/);
  });

  test.describe(() => {
    // core issue, not plugin related: activities/index.html.twig uses `datepickerOptions` without defining it
    test.use({ allowJsErrors: true });

    test('the activity provider lists the greeting', async ({ page }) => {
    await page.goto('/projects/ecommerce/activity');
    const checkbox = page.locator('#sidebar input[name="show_hello_greetings"]');
    await expect(checkbox).not.toBeChecked();
    await checkbox.check();
    await page.locator('#sidebar').getByRole('button', { name: 'Apply' }).click();
    await expect(page.locator('#content dt.hello-greeting')).toContainText(message);
    });
  });

  test('the search provider finds the greeting', async ({ page }) => {
    await page.goto(`/search?q=${encodeURIComponent(message)}`);
    await expect(page.locator('#search-results')).toContainText(message);
  });
});

test.describe('as dlopper (Developer, no plugin permission)', () => {
  test.use({ as: 'dlopper' });

  test('neither the tab nor the issue hook are visible', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce');
    await expect(ui.mainMenu('issues')).toBeVisible();
    await expect(ui.mainMenu('hello')).toHaveCount(0);
    const issueId = sqlValue('SELECT id FROM issues WHERE project_id = 1 ORDER BY id LIMIT 1');
    await page.goto(`/issues/${issueId}`);
    await expect(page.locator('div.hello-issue-greeting')).toHaveCount(0);
    const response = await page.goto('/projects/ecommerce/hello');
    expect(response?.status()).toBe(403);
  });
});
