import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';

test.describe('new project', () => {
  test.describe('as admin', () => {
    test.use({ as: 'admin' });

    test('identifier is generated from the name, modules and parent are saved', async ({ page, ui }) => {
      await page.goto('/projects');
      await ui.contextual(/New project/).click();
      // project_identifier.js binds its keyup handlers on document ready: wait for the load event before typing
      await page.waitForURL(/\/projects\/new$/, { waitUntil: 'load' });
      await expect(ui.heading()).toHaveText('New project');

      const suffix = uid();
      const name = `Crème Brûlée ${suffix}`;
      await page.locator('#project_name').pressSequentially(name, { delay: 10 });
      // project_identifier.js: diacritics stripped, non-alphanumerics -> '-', lowercase
      await expect(page.locator('#project_identifier')).toHaveValue(`creme-brulee-${suffix.toLowerCase()}`);

      // once the identifier is edited by hand, it is not regenerated anymore
      // (the script listens to keyup events: type like a user)
      const identifier = `cb-${suffix.toLowerCase()}`;
      await page.locator('#project_identifier').fill('');
      await page.locator('#project_identifier').pressSequentially(identifier);
      // caret at the end (the End key does not move the caret on macOS)
      await page.locator('#project_name').evaluate((el: HTMLInputElement) => {
        el.focus();
        el.setSelectionRange(el.value.length, el.value.length);
      });
      await page.keyboard.type('X');
      await expect(page.locator('#project_identifier')).toHaveValue(identifier);

      await page.locator('#project_description').fill('Dessert project');
      await page.locator('#project_parent_id').selectOption({ label: 'eCommerce' });
      // modules: the legend toggle checks all (some are unchecked by default), a second click unchecks all
      const modules = page.locator('#project_modules input[type=checkbox]');
      await page.locator('#project_modules legend a.icon-checked').click();
      for (const cb of await modules.all()) await expect(cb).toBeChecked();
      await page.locator('#project_modules legend a.icon-checked').click();
      await expect(page.locator('#project_enabled_module_names_issue_tracking')).not.toBeChecked();
      await page.locator('#project_enabled_module_names_issue_tracking').check();
      await page.locator('#project_enabled_module_names_wiki').check();
      await page.locator('#new_project input[name=commit]').click();

      await ui.expectNotice('Successful creation.');
      await expect(page).toHaveURL(new RegExp(`/projects/${identifier}/settings$`));
      // breadcrumb shows the parent
      await expect(page.locator('#header h1')).toContainText('eCommerce');
      await expect(page.locator('#header h1 .current-project')).toHaveText(`${name}X`);
      // only the enabled modules are in the project menu
      await expect(ui.mainMenu('issues')).toBeVisible();
      await expect(ui.mainMenu('wiki')).toBeVisible();
      await expect(ui.mainMenu('news')).toHaveCount(0);
      await expect(ui.mainMenu('time-entries')).toHaveCount(0);

      const enabled = sql(
        'SELECT m.name FROM enabled_modules m JOIN projects p ON p.id = m.project_id WHERE p.identifier = ? ORDER BY m.name',
        [identifier],
      ).map((r) => r.name);
      expect(enabled).toEqual(['issue_tracking', 'wiki']);
      expect(sqlValue('SELECT parent_id FROM projects WHERE identifier = ?', [identifier])).toBe(1);

      // the subproject is listed on the parent overview
      await page.goto('/projects/ecommerce');
      await expect(page.locator('#content')).toContainText(`${name}X`);
    });

    test('"Create and continue" returns to an empty form', async ({ page, ui }) => {
      await page.goto('/projects/new');
      const name = uid('Continue ');
      await page.locator('#project_name').pressSequentially(name);
      await expect(page.locator('#project_identifier')).toHaveValue(name.toLowerCase().replace(' ', '-'));
      await page.locator('#new_project input[name=continue]').click();
      await ui.expectNotice('Successful creation.');
      await expect(page).toHaveURL(/\/projects\/new/);
      await expect(page.locator('#project_name')).toHaveValue('');
      expect(Number(sqlValue('SELECT COUNT(*) FROM projects WHERE name = ?', [name]))).toBe(1);
    });

    test('validation errors are displayed and the form keeps the values', async ({ page, ui }) => {
      await page.goto('/projects/new');
      await page.locator('#project_name').fill('Duplicate identifier');
      await page.locator('#project_identifier').fill('ecommerce');
      await page.locator('#new_project input[name=commit]').click();
      await expect(ui.errors()).toContainText('Identifier has already been taken');
      await expect(page.locator('#project_name')).toHaveValue('Duplicate identifier');

      await page.locator('#project_name').fill('');
      await page.locator('#project_identifier').fill('Bad Identifier!');
      await page.locator('#new_project input[name=commit]').click();
      await expect(ui.errors()).toContainText("Name cannot be blank");
      await expect(ui.errors()).toContainText('Identifier is invalid');
    });
  });

  test.describe('as a manager (add_project permission)', () => {
    test.use({ as: 'jsmith' });

    test('creates a project and becomes its member', async ({ page, ui }) => {
      await page.goto('/projects');
      await ui.contextual(/New project/).click();
      await page.waitForURL(/\/projects\/new$/, { waitUntil: 'load' });
      const name = uid('Smith project ');
      await page.locator('#project_name').pressSequentially(name);
      const identifier = name.toLowerCase().replace(/ /g, '-');
      await expect(page.locator('#project_identifier')).toHaveValue(identifier);
      await page.locator('#new_project input[name=commit]').click();
      await ui.expectNotice('Successful creation.');
      await expect(page).toHaveURL(new RegExp(`/projects/${identifier}/settings`));
      // the creator is a member of the new project
      await page.locator('#tab-members').click();
      await expect(page.locator('#tab-content-members table.members')).toContainText('John Smith');
    });
  });

  test.describe('as a reporter', () => {
    test.use({ as: 'rhill' });

    test('has no "New project" link and gets 403 on the form', async ({ page, ui, http }) => {
      await page.goto('/projects');
      await expect(page.locator('#content .contextual a', { hasText: 'New project' })).toHaveCount(0);
      await http.expectStatus('/projects/new', 403);
    });
  });
});
