import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';

test.describe('versions and roadmap', () => {
  test.use({ as: 'jsmith' });

  test('create a version from the settings tab, then see it on the roadmap', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/settings/versions');
    await page.locator('#tab-content-versions a.icon-add', { hasText: 'New version' }).click();
    await expect(ui.heading()).toHaveText('New version');
    const name = uid('2.0-');
    await page.locator('#version_name').fill(name);
    await page.locator('#version_description').fill('Second release');
    await page.locator('#version_effective_date').fill('2027-03-01');
    await page.locator('#version_sharing').selectOption('none');
    await page.locator('#new_version input[name=commit]').click();

    await ui.expectNotice('Successful creation.');
    await expect(page).toHaveURL(/\/projects\/ecommerce\/settings\/versions/);
    const row = page.locator('#tab-content-versions table.versions tr.version', { hasText: name });
    await expect(row.locator('td.date')).toHaveText('03/01/2027');
    await expect(row.locator('td.description')).toHaveText('Second release');
    await expect(row.locator('td.sharing')).toHaveText('Not shared');

    await ui.mainMenu('roadmap').click();
    await expect(page.locator('#roadmap article', { hasText: name })).toBeVisible();
    await expect(page.locator('#sidebar a', { hasText: name })).toBeVisible();
    await expect(page.locator('#roadmap article', { hasText: name })).toContainText('No issues for this version');
  });

  test('version validation errors', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/versions/new');
    await page.locator('#version_name').fill('1.0');
    await page.locator('#new_version input[name=commit]').click();
    await expect(ui.errors()).toContainText('Name has already been taken');
  });

  test('roadmap lists issues by version and filters by tracker', async ({ page }) => {
    await page.goto('/projects/ecommerce/roadmap');
    // only trackers flagged "displayed in roadmap" (Feature) are checked by default
    const bug = page.locator('#sidebar label', { hasText: 'Bug' }).locator('input');
    await expect(bug).not.toBeChecked();
    await expect(page.locator('#sidebar label', { hasText: 'Feature' }).locator('input')).toBeChecked();
    await expect(page.locator('#roadmap')).not.toContainText('Cart total is wrong with discounts');
    // 0.9 is closed: hidden, listed under "Completed versions" in the sidebar (collapsed)
    await expect(page.locator('#roadmap h3', { hasText: '0.9' })).toHaveCount(0);
    await expect(page.locator('#completed-versions')).toBeHidden();
    await page.locator('#toggle-completed-versions').click();
    await expect(page.locator('#completed-versions')).toBeVisible();
    await expect(page.locator('#completed-versions')).toContainText('0.9');

    // tracker checkboxes in the sidebar
    await bug.check();
    await page.locator('#sidebar input[type=submit][value=Apply]').click();
    await expect(page).toHaveURL(/tracker_ids/);
    await expect(page.locator('#sidebar label', { hasText: 'Bug' }).locator('input')).toBeChecked();
    const v1 = page.locator('#roadmap article', { has: page.locator('h3', { hasText: '1.0' }) });
    await expect(v1.locator('table.related-issues')).toContainText('Cart total is wrong with discounts');
    await expect(v1.locator('.progress-info, p.progress-info').first()).toBeVisible();

    // show completed versions
    await page.locator('#completed').check();
    await page.locator('#sidebar input[type=submit][value=Apply]').click();
    await expect(page.locator('#roadmap h3', { hasText: '0.9' })).toBeVisible();
  });

  test('version page shows progress, issues and links', async ({ page }) => {
    await page.goto('/projects/ecommerce/roadmap');
    await page.locator('#roadmap h3 a', { hasText: '1.0' }).click();
    await expect(page).toHaveURL(/\/versions\/1$/);
    await expect(page.locator('#roadmap h2')).toHaveText('1.0');
    await expect(page.locator('#roadmap .badge-status-open')).toHaveText('open');
    await expect(page.locator('#content')).toContainText('First release');
    await expect(page.locator('#roadmap table.related-issues')).toContainText('Cart total is wrong with discounts');
    await expect(page.locator('#status_by')).toBeVisible();
    // "New issue" pre-fills the target version
    await page.locator('#content .contextual a.icon-add', { hasText: 'New issue' }).click();
    await expect(page.locator('#issue_fixed_version_id')).toHaveValue('1');
  });

  test('edit a version: close it, it leaves the roadmap', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/versions/new');
    const name = uid('closing-');
    await page.locator('#version_name').fill(name);
    await page.locator('#new_version input[name=commit]').click();
    await ui.expectNotice('Successful creation.');

    await page.goto('/projects/ecommerce/roadmap');
    const article = page.locator('#roadmap article', { has: page.locator('h3', { hasText: name }) });
    await article.locator('a.icon-edit').click();
    await expect(page.locator('#version_name')).toHaveValue(name);
    await page.locator('#version_status').selectOption('closed');
    await page.locator('#version_description').fill('done');
    await page.locator('input[name=commit]').click();
    await ui.expectNotice('Successful update.');
    expect(sqlValue('SELECT status FROM versions WHERE name = ?', [name])).toBe('closed');

    await page.goto('/projects/ecommerce/roadmap');
    await expect(page.locator('#roadmap h3', { hasText: name })).toHaveCount(0);
    await page.goto('/projects/ecommerce/settings/versions?version_status=closed');
    await expect(page.locator('#tab-content-versions table.versions')).toContainText(name);
    await expect(page.locator('#tab-content-versions table.versions')).not.toContainText('1.0');
  });

  test('version sharing controls availability in subprojects', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/versions/new');
    const name = uid('shared-');
    await page.locator('#version_name').fill(name);
    await page.locator('#version_sharing').selectOption('none');
    // system-wide sharing is reserved to administrators
    await expect(page.locator('#version_sharing option[value=system]')).toHaveCount(0);
    await page.locator('#new_version input[name=commit]').click();
    await ui.expectNotice('Successful creation.');

    await page.goto('/projects/checkout/issues/new');
    await expect(page.locator('#issue_fixed_version_id')).not.toContainText(name);

    const id = sqlValue('SELECT id FROM versions WHERE name = ?', [name]);
    await page.goto(`/versions/${id}/edit`);
    await page.locator('#version_sharing').selectOption('descendants');
    await page.locator('input[name=commit]').click();
    await ui.expectNotice('Successful update.');

    await page.goto('/projects/checkout/issues/new');
    await expect(page.locator('#issue_fixed_version_id')).toContainText(name);
    // shared versions are shown on the subproject's settings with the link icon
    await page.goto('/projects/checkout/settings/versions');
    await expect(page.locator('#tab-content-versions tr.version.shared', { hasText: name })).toBeVisible();
  });

  test('new version from the issue form modal is selected in the form', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/issues/new');
    await page.locator('#issue_fixed_version_id + a.icon-add, a.icon-add[href*="/versions/new"]').first().click();
    const modal = await ui.expectModal();
    const name = uid('modal-');
    await modal.locator('#version_name').fill(name);
    await modal.locator('input[type=submit]').click();
    await expect(ui.modal()).toBeHidden();
    await expect(page.locator('#issue_fixed_version_id option:checked')).toHaveText(name);
    expect(sql('SELECT project_id FROM versions WHERE name = ?', [name])).toEqual([{ project_id: 1 }]);
  });

  test('close completed versions and delete a version', async ({ page, ui }) => {
    // a version past its date without open issues is "completed"
    await page.goto('/projects/ecommerce/versions/new');
    const name = uid('old-');
    await page.locator('#version_name').fill(name);
    await page.locator('#version_effective_date').fill('2020-01-01');
    await page.locator('#new_version input[name=commit]').click();
    await ui.expectNotice('Successful creation.');

    await page.locator('#tab-content-versions a.icon-locked', { hasText: 'Close completed versions' }).click();
    await expect(page).toHaveURL(/settings\/versions/);
    expect(sqlValue('SELECT status FROM versions WHERE name = ?', [name])).toBe('closed');
    expect(sqlValue("SELECT status FROM versions WHERE name = '1.0'")).toBe('open');

    const id = sqlValue('SELECT id FROM versions WHERE name = ?', [name]);
    await page.goto(`/versions/${id}`);
    await page.locator('#content .contextual a.icon-del').click();
    await expect(page).toHaveURL(/\/projects\/ecommerce\/roadmap/);
    expect(sql('SELECT id FROM versions WHERE name = ?', [name])).toEqual([]);

    // a version used by issues cannot be deleted
    await page.goto('/versions/1');
    await page.locator('#content .contextual a.icon-del').click();
    await ui.expectError('Unable to delete version');
    expect(sql('SELECT id FROM versions WHERE id = 1')).toHaveLength(1);
  });
});

test.describe('issue categories', () => {
  test.use({ as: 'jsmith' });

  test('create, edit and delete a category', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/settings/categories');
    await page.locator('#tab-content-categories a.icon-add', { hasText: 'New category' }).click();
    const name = uid('Payments ');
    await page.locator('#issue_category_name').fill(name);
    await page.locator('#issue_category_assigned_to_id').selectOption({ label: 'Dave Lopper' });
    await page.locator('#new_issue_category input[name=commit]').click();
    await ui.expectNotice('Successful creation.');
    await expect(page).toHaveURL(/settings\/categories/);
    const row = page.locator('#tab-content-categories tr', { hasText: name });
    await expect(row).toContainText('Dave Lopper');

    await row.locator('a.icon-edit').click();
    await page.locator('#issue_category_name').fill(`${name} edited`);
    await page.locator('#issue_category_assigned_to_id').selectOption('');
    await page.locator('input[name=commit]').click();
    await ui.expectNotice('Successful update.');
    const edited = page.locator('#tab-content-categories tr', { hasText: `${name} edited` });
    await expect(edited).not.toContainText('Dave Lopper');

    // the category is offered on the issue form
    await page.goto('/projects/ecommerce/issues/new');
    await expect(page.locator('#issue_category_id')).toContainText(`${name} edited`);

    // unused: deleted right away (after confirm)
    await page.goto('/projects/ecommerce/settings/categories');
    await page.locator('#tab-content-categories tr', { hasText: `${name} edited` }).locator('a.icon-del').click();
    await expect(page).toHaveURL(/settings\/categories/);
    await expect(page.locator('#tab-content-categories')).not.toContainText(`${name} edited`);
  });

  test('deleting a used category asks what to do with its issues', async ({ page }) => {
    expect(sqlValue('SELECT category_id FROM issues WHERE id = 13')).toBe(1);
    await page.goto('/projects/ecommerce/settings/categories');
    await page.locator('#tab-content-categories tr', { hasText: 'UI' }).locator('a.icon-del').click();
    await expect(page.locator('#content h2')).toHaveText('Issue category: UI');
    await expect(page.locator('#content .box')).toContainText('Some issues (1) are assigned to this category');
    await page.locator('#todo_reassign').check();
    await page.locator('#reassign_to_id').selectOption({ label: 'Backend' });
    await page.locator('input[name=commit]').click();
    await expect(page).toHaveURL(/settings\/categories/);
    await expect(page.locator('#tab-content-categories td.name')).not.toHaveText(['UI']);
    expect(sqlValue('SELECT category_id FROM issues WHERE id = 13')).toBe(2);

    await page.goto('/issues/13');
    await expect(page.locator('.issue .attributes .category')).toContainText('Backend');
  });

  test('new category from the issue form modal', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/issues/new');
    await page.locator('a.icon-add[href*="/issue_categories/new"]').click();
    const modal = await ui.expectModal();
    const name = uid('Cat ');
    await modal.locator('#issue_category_name').fill(name);
    await modal.locator('input[type=submit]').click();
    await expect(ui.modal()).toBeHidden();
    await expect(page.locator('#issue_category_id option:checked')).toHaveText(name);
  });
});
