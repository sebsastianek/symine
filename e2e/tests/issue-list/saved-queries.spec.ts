import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import { addColumns, applyQuery, expectIssues, headerColumns } from './_helpers';

const LIST = '/projects/ecommerce/issues?set_filter=1';

/** From the issue list: filter on Bug and click "Save custom query". */
async function startQueryFromList(page: import('@playwright/test').Page): Promise<void> {
  await page.goto(LIST);
  await page.locator('#add_filter_select').selectOption('tracker_id');
  await page.locator('#values_tracker_id_1').selectOption('1');
  await applyQuery(page);
  await page.locator('#query_form p.buttons a.icon-save').click();
  await expect(page.locator('#content h2')).toHaveText('New query');
}

test.describe('saved issue queries', () => {
  test.use({ as: 'jsmith' });

  test('create a private query with filters and columns, then it appears in the sidebar', async ({ page, loginAs }) => {
    const name = uid('My bugs ');
    await startQueryFromList(page);
    // filters from the list are carried over to the form
    await expect(page.locator('#cb_tracker_id')).toBeChecked();
    await expect(page.locator('#values_tracker_id_1')).toHaveValue('1');

    await page.locator('#query_name').fill(name);
    await expect(page.locator('#query_visibility_0')).toBeChecked(); // "to me only" by default
    // custom columns: uncheck "Default columns" enables the column selects
    await page.locator('#query_default_columns').uncheck();
    await expect(page.locator('#available_c')).toBeEnabled();
    await addColumns(page, ['estimated_hours']);
    await page.locator('input[name=commit]').click();

    await expect(page).toHaveURL(/query_id=\d+/);
    await expect(page.locator('#content h2')).toHaveText(name);
    await expectIssues(page, [13, 17]);
    expect(await headerColumns(page)).toContain('estimated_hours');
    const link = page.locator('#sidebar ul.queries a.query', { hasText: name });
    await expect(link).toHaveClass(/selected/);
    expect(sqlValue('SELECT visibility FROM queries WHERE name = ?', [name])).toBe(0);

    // private: another member does not see it
    const other = await loginAs('dlopper');
    await other.page.goto(LIST);
    await expect(other.page.locator('#sidebar ul.queries')).toBeVisible();
    await expect(other.page.locator('#sidebar ul.queries a', { hasText: name })).toHaveCount(0);

    // navigating away and back through the sidebar link reloads the query
    await page.goto(LIST);
    await expect(page.locator('#content h2')).toHaveText('Issues');
    await page.locator('#sidebar ul.queries a.query', { hasText: name }).click();
    await expect(page.locator('#content h2')).toHaveText(name);
    await expectIssues(page, [13, 17]);
  });

  test('create a public query grouped by status, visible to other users', async ({ page, loginAs }) => {
    const name = uid('Public bugs ');
    await startQueryFromList(page);
    await page.locator('#query_name').fill(name);
    await page.locator('#query_description').fill('Shared with everybody');
    await page.locator('#query_visibility_2').check();
    await page.locator('#query_group_by').selectOption('status');
    await page.locator('input[name=commit]').click();

    await expect(page.locator('#content h2')).toHaveText(name);
    await expect(page.locator('#content p.subtitle')).toHaveText('Shared with everybody');
    await expect(page.locator('table.list tr.group .name')).toHaveText(['New', 'In Progress']); // status position order, as Redmine

    const reporter = await loginAs('rhill');
    await reporter.page.goto(LIST);
    await reporter.page.locator('#sidebar ul.queries a', { hasText: name }).click();
    await expect(reporter.page.locator('#content h2')).toHaveText(name);
    // private #17 (checkout) is visible to rhill as its author
    await expectIssues(reporter.page, [13, 17]);
    // not the author and no manage_public_queries on ... -> no edit link
    await expect(reporter.page.locator('#content a.icon-edit', { hasText: 'Edit custom query' })).toHaveCount(0);
  });

  test('"to these roles only" enables the role checkboxes', async ({ page }) => {
    await startQueryFromList(page);
    const roles = page.locator('input[name="query[role_ids][]"][type=checkbox]');
    await expect(roles.first()).toBeDisabled();
    await page.locator('#query_visibility_1').check();
    await expect(roles.first()).toBeEnabled();
    const name = uid('Managers only ');
    await page.locator('#query_name').fill(name);
    await page.locator('input[name="query[role_ids][]"][value="3"]').check();
    await page.locator('input[name=commit]').click();
    await expect(page.locator('#content h2')).toHaveText(name);
    const id = sqlValue('SELECT id FROM queries WHERE name = ?', [name]);
    expect(sql('SELECT role_id FROM queries_roles WHERE query_id = ?', [id]).map((r) => r.role_id)).toEqual([3]);
  });

  test('edit a saved query: rename and change filters', async ({ page }) => {
    const name = uid('To edit ');
    await startQueryFromList(page);
    await page.locator('#query_name').fill(name);
    await page.locator('input[name=commit]').click();
    await expect(page.locator('#content h2')).toHaveText(name);

    await page.locator('#content a.icon-edit', { hasText: 'Edit custom query' }).click();
    await expect(page.locator('#content h2')).toHaveText('Custom query');
    await expect(page.locator('#query_name')).toHaveValue(name);
    const renamed = `${name} (renamed)`;
    await page.locator('#query_name').fill(renamed);
    await page.locator('#values_tracker_id_1').selectOption('2');
    await page.locator('input[name=commit]').click();

    await expect(page.locator('#content h2')).toHaveText(renamed);
    await expectIssues(page, [14, 15, 16]);
    await expect(page.locator('#sidebar ul.queries a.query', { hasText: renamed })).toBeVisible();
  });

  test('delete a saved query', async ({ page }) => {
    const name = uid('To delete ');
    await startQueryFromList(page);
    await page.locator('#query_name').fill(name);
    await page.locator('input[name=commit]').click();
    await expect(page.locator('#content h2')).toHaveText(name);

    await page.locator('#content a.icon-del', { hasText: 'Delete custom query' }).click(); // confirm() auto-accepted
    await expect(page.locator('#content h2')).toHaveText('Issues');
    await expect(page.locator('#sidebar ul.queries a', { hasText: name })).toHaveCount(0);
    expect(sql('SELECT id FROM queries WHERE name = ?', [name])).toEqual([]);
  });

  test('validation: a query needs a name', async ({ page }) => {
    await startQueryFromList(page);
    await page.locator('input[name=commit]').click();
    await expect(page.locator('#errorExplanation')).toContainText("Name cannot be blank");
  });
});
