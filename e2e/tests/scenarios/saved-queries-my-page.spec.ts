import type { Page } from '@playwright/test';
import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import { anonymousPage, applyQuery, clickAndWait, listedIssueIds } from './_helpers';

/*
 * One custom query from the filter form to the places where other people meet it: the sidebar (private, then for
 * a role, then public), the default list of the project, a block of My page - and what is left when it is deleted.
 *
 * Open issues of eCommerce and its subproject: bugs #13 (8 h, High) and #17 (Checkout, private, Immediate);
 * features #14 (parent, Urgent), #15 (4 h, Low), #16 (2 h, Urgent).
 */
const LIST = '/projects/ecommerce/issues?set_filter=1';
const sidebarQuery = (page: Page, name: string) => page.locator('#sidebar ul.queries a.query', { hasText: name });
const groups = (page: Page) => page.locator('table.list.issues tr.group');
const totalEstimated = (page: Page) => page.locator('p.query-totals .total-for-estimated-hours .value');

test.describe('saved queries and My page', () => {
  test('build a query, share it step by step, make it the project default, show it on My page, delete it', async ({ loginAs, browser, api }) => {
    test.setTimeout(240_000);
    const manager = await loginAs('jsmith');
    const developer = await loginAs('dlopper');
    const reporter = await loginAs('rhill');
    const { page } = manager;
    const name = uid('Planning board ');
    let queryId = 0;

    await test.step('the manager filters, groups, totals and sorts the list, then saves it for himself', async () => {
      await page.goto(LIST);
      await manager.ui.setFilter('tracker_id', '=', ['1', '2']);
      await manager.ui.expandFieldset('Options');
      await page.locator('#available_c').selectOption(['estimated_hours']);
      await page.locator('#options button.move-right, #options input.move-right').first().click();
      await expect(page.locator('#selected_c option[value="estimated_hours"]')).toHaveCount(1);
      await page.locator('#group_by').selectOption('tracker');
      await page.locator('#options input[name="t[]"][value="estimated_hours"]').check();
      await applyQuery(page);

      await expect(groups(page).locator('.name')).toHaveText(['Bug', 'Feature']);
      await expect(groups(page).locator('.count')).toHaveText(['2', '3']);
      await expect(groups(page).nth(0).locator('.totals .value')).toHaveText('8:00');
      await expect(groups(page).nth(1).locator('.totals .value')).toHaveText('6:00');
      await expect(totalEstimated(page)).toHaveText('14:00');
      // sort by priority (first click: highest first), inside the groups
      await clickAndWait(page, page.locator('table.list.issues thead th.priority a'));
      let ids = await listedIssueIds(page);
      expect(ids.slice(0, 2)).toEqual([17, 13]);
      expect(ids.at(-1)).toBe(15);

      await page.locator('#query_form p.buttons a.icon-save').click();
      await expect(page.locator('#content h2')).toHaveText('New query');
      // everything set on the list came along
      await expect(page.locator('#cb_tracker_id')).toBeChecked();
      await expect(page.locator('select#query_group_by')).toHaveValue('tracker');
      await expect(page.locator('input[name="t[]"][value="estimated_hours"]')).toBeChecked();
      await expect(page.locator('#selected_c option[value="estimated_hours"]')).toHaveCount(1);
      await page.locator('#query_name').fill(name);
      await expect(page.locator('#query_visibility_0')).toBeChecked();
      await page.locator('input[name=commit]').click();

      await expect(page.locator('#content h2')).toHaveText(name);
      queryId = Number(sqlValue('SELECT id FROM queries WHERE name = ?', [name]));
      await expect(page).toHaveURL(new RegExp(`query_id=${queryId}`));
      await expect(groups(page).locator('.name')).toHaveText(['Bug', 'Feature']);
      await expect(totalEstimated(page)).toHaveText('14:00');
      ids = await listedIssueIds(page);
      expect(ids.slice(0, 2)).toEqual([17, 13]);
      expect(ids.at(-1)).toBe(15);
      await expect(sidebarQuery(page, name)).toHaveClass(/selected/);
      expect(sql('SELECT project_id, user_id, visibility, group_by FROM queries WHERE id = ?', [queryId])).toEqual([
        { project_id: 1, user_id: 5, visibility: 0, group_by: 'tracker' },
      ]);
    });

    await test.step('private: nobody else sees or opens it', async () => {
      for (const other of [developer, reporter]) {
        await other.page.goto(LIST);
        await expect(sidebarQuery(other.page, 'Open bugs')).toHaveCount(1); // seeded public query
        await expect(sidebarQuery(other.page, name)).toHaveCount(0);
        expect((await other.page.goto(`/projects/ecommerce/issues?query_id=${queryId}`))?.status()).toBe(403);
      }
      const list = (await (await api('dlopper')).get('/queries.json?limit=100')).queries as Array<{ id: number }>;
      expect(list.map((q) => q.id)).not.toContain(queryId);
    });

    await test.step('shared with the Manager and Developer roles: developers get it, reporters do not', async () => {
      await page.locator('#content a.icon-edit', { hasText: 'Edit custom query' }).click();
      await expect(page.locator('#query_name')).toHaveValue(name);
      await page.locator('#query_visibility_1').check();
      // (his own role too: a query restricted to roles is not shown to an owner who has none of them)
      await page.locator('input[name="query[role_ids][]"][value="3"]').check();
      await page.locator('input[name="query[role_ids][]"][value="4"]').check();
      await page.locator('input[name=commit]').click();
      await expect(page.locator('#content h2')).toHaveText(name);
      expect(sql('SELECT role_id FROM queries_roles WHERE query_id = ? ORDER BY role_id', [queryId])).toEqual([{ role_id: 3 }, { role_id: 4 }]);

      const dev = developer.page;
      await dev.goto(LIST);
      await sidebarQuery(dev, name).click();
      await expect(dev.locator('#content h2')).toHaveText(name);
      // same definition, his own visibility: the private bug #17 of Checkout is not in his list
      await expect(groups(dev).locator('.count')).toHaveText(['1', '3']);
      await expect(totalEstimated(dev)).toHaveText('14:00');
      // not his query and no "manage public queries": he cannot change it
      await expect(dev.locator('#content a.icon-edit', { hasText: 'Edit custom query' })).toHaveCount(0);
      expect((await developer.http.get(`/queries/${queryId}/edit`)).status()).toBe(403);

      await reporter.page.goto(LIST);
      await expect(sidebarQuery(reporter.page, name)).toHaveCount(0);
      expect((await reporter.page.goto(`/projects/ecommerce/issues?query_id=${queryId}`))?.status()).toBe(403);
    });

    await test.step('public, and chosen as the default query of the project', async () => {
      await page.goto(`/queries/${queryId}/edit`);
      await page.locator('#query_visibility_2').check();
      await page.locator('input[name=commit]').click();
      await expect(page.locator('#content h2')).toHaveText(name);
      await reporter.page.goto(LIST);
      await expect(sidebarQuery(reporter.page, name)).toHaveCount(1);

      await page.goto('/projects/ecommerce/settings/issues');
      const tab = page.locator('#tab-content-issues');
      await tab.locator('#project_default_issue_query_id').selectOption({ label: name });
      await tab.locator('input[name=commit]').click();
      await manager.ui.expectNotice('Successful update.');
      expect(sqlValue("SELECT default_issue_query_id FROM projects WHERE identifier = 'ecommerce'")).toBe(queryId);

      // somebody opening the issues of the project for the first time gets the query instead of the plain list
      const anna = await loginAs('anna');
      await anna.page.goto('/projects/ecommerce');
      await anna.ui.mainMenu('issues').click();
      await expect(anna.page.locator('#content h2')).toHaveText(name);
      await expect(sidebarQuery(anna.page, name)).toHaveClass(/selected/);
      await expect(groups(anna.page).locator('.count')).toHaveText(['1', '3']);
      await expect(anna.page.locator('table.list.issues thead th.estimated_hours')).toHaveCount(1);
      const visitor = await anonymousPage(browser);
      await visitor.page.goto('/projects/ecommerce/issues');
      await expect(visitor.page.locator('#content h2')).toHaveText(name);
      await expect(groups(visitor.page).locator('.name')).toHaveText(['Bug', 'Feature']);
      // "Clear" goes back to the plain list; other projects are not affected
      await visitor.page.goto(LIST);
      await expect(visitor.page.locator('#content h2')).toHaveText('Issues');
      await expect(groups(visitor.page)).toHaveCount(0);
      await visitor.page.goto('/projects/checkout/issues');
      await expect(visitor.page.locator('#content h2')).toHaveText('Issues');
      await visitor.context.close();
    });

    await test.step('the developer puts the query on his My page', async () => {
      const dev = developer.page;
      await dev.goto('/my/page');
      await dev.locator('#block-select').selectOption('issuequery');
      const block = dev.locator('#block-issuequery');
      await expect(block).toBeVisible();
      await block.locator('#settings_issuequery_query_id').selectOption({ label: name });
      await block.locator('input[type=submit]').click();
      await expect(block.locator('h3')).toContainText(name);
      await expect(block.locator('table.list.issues tr.issue')).toHaveCount(4);
      await expect(block.locator('table.list.issues')).toContainText('Wishlist sharing');
      const settings = () => JSON.parse(String(sqlValue('SELECT others FROM user_preferences WHERE user_id = 6'))).my_page_settings;
      await expect.poll(() => String(settings().issuequery?.query_id)).toBe(String(queryId));
      // still there after a reload, and the title links to the full list
      await dev.reload();
      await expect(block.locator('table.list.issues tr.issue')).toHaveCount(4);
      await block.locator('h3 a', { hasText: name }).click();
      await expect(dev.locator('#content h2')).toHaveText(name);
      await expect(dev).toHaveURL(new RegExp(`query_id=${queryId}`));
    });

    await test.step('deleted by its owner: sidebar, project default and My page block let go of it', async () => {
      await page.goto(`/projects/ecommerce/issues?query_id=${queryId}`);
      await page.locator('#content a.icon-del', { hasText: 'Delete custom query' }).click(); // confirm accepted
      await expect(page.locator('#content h2')).toHaveText('Issues');
      expect(sql('SELECT id FROM queries WHERE id = ?', [queryId])).toEqual([]);
      expect(sql('SELECT role_id FROM queries_roles WHERE query_id = ?', [queryId])).toEqual([]);
      expect(sqlValue("SELECT default_issue_query_id FROM projects WHERE identifier = 'ecommerce'")).toBeNull();

      const visitor = await anonymousPage(browser);
      await visitor.page.goto('/projects/ecommerce/issues');
      await expect(visitor.page.locator('#content h2')).toHaveText('Issues');
      await visitor.context.close();
      expect((await reporter.page.goto(`/projects/ecommerce/issues?query_id=${queryId}`))?.status()).toBe(404);

      const dev = developer.page;
      await dev.goto('/my/page');
      const block = dev.locator('#block-issuequery');
      await expect(block).toBeVisible();
      await expect(block).not.toContainText(name);
      await expect(block.locator('table.list.issues')).toHaveCount(0);
      await expect(block.locator('#settings_issuequery_query_id')).toBeVisible(); // asks for a query again
    });
  });
});
