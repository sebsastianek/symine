import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';

/*
 * Project copy (administrators, from the admin projects list), bookmarks (ajax toggle, jump box, projects list)
 * and the projects list itself (board / list display, columns, filters).
 * Seeded eCommerce: 4 members (3 users + 1 group), versions 1.0 / 0.9, categories UI / Backend, 5 issues
 * (#14 "Wishlist" is the parent of #15 and #16), 2 wiki pages, 1 document, 1 forum, 1 saved query.
 */

function count(table: string, projectId: number): number {
  return Number(sqlValue(`SELECT COUNT(*) FROM ${table} WHERE project_id = ?`, [projectId]));
}

test.describe('project copy', () => {
  test.use({ as: 'admin' });

  test('copy from the admin list: selected data is duplicated, the source is untouched', async ({ page, ui }) => {
    const suffix = uid().toLowerCase();
    const name = `Shop copy ${suffix}`;

    await page.goto('/admin/projects');
    const menu = await ui.openContextMenu(page.locator('tr#project-1'));
    await menu.locator('a.icon-copy').click();
    await page.waitForURL(/\/projects\/ecommerce\/copy$/, { waitUntil: 'load' });
    await expect(ui.heading()).toHaveText('New project');

    // name and identifier start empty, everything else comes from the source
    await expect(page.locator('#project_name')).toHaveValue('');
    await expect(page.locator('#project_identifier')).toHaveValue('');
    await expect(page.locator('#project_description')).toHaveValue('Online **shop** project');
    await expect(page.locator('#project_enabled_module_names_boards')).toBeChecked();
    // the identifier follows the typed name (project_identifier.js)
    await page.locator('#project_name').pressSequentially(name, { delay: 10 });
    await expect(page.locator('#project_identifier')).toHaveValue(`shop-copy-${suffix}`);

    // "Copy" box: counts of the source, all checked; the legend toggles all
    const only = (value: string) => page.locator(`input[name="only[]"][value="${value}"]`);
    await expect(page.locator('#content fieldset', { hasText: 'Members (4)' })).toContainText('Issues (5)');
    await expect(page.locator('input[name="only[]"][type=checkbox]:checked')).toHaveCount(8);
    await page.locator('#content fieldset legend a.icon-checked').last().click();
    await expect(page.locator('input[name="only[]"][type=checkbox]:checked')).toHaveCount(0);
    for (const v of ['members', 'versions', 'issue_categories', 'issues', 'wiki']) await only(v).check();

    await page.locator('#new_project input[name=commit]').click();
    await ui.expectNotice('Successful creation.');
    await expect(page).toHaveURL(new RegExp(`/projects/shop-copy-${suffix}/settings$`));
    const id = Number(sqlValue('SELECT id FROM projects WHERE identifier = ?', [`shop-copy-${suffix}`]));

    // copied: members, versions, categories, issues, wiki; not copied: documents, forums, queries
    expect(count('members', id)).toBe(4);
    expect(count('versions', id)).toBe(2);
    expect(count('issue_categories', id)).toBe(2);
    expect(count('issues', id)).toBe(5);
    expect(count('documents', id)).toBe(0);
    expect(count('boards', id)).toBe(0);
    expect(count('queries', id)).toBe(0);
    expect(Number(sqlValue('SELECT COUNT(*) FROM wiki_pages p JOIN wikis w ON w.id = p.wiki_id WHERE w.project_id = ?', [id]))).toBe(2);
    // the source keeps its data
    expect(count('issues', 1)).toBe(5);
    expect(count('versions', 1)).toBe(2);

    // members tab of the new project
    await page.goto(`/projects/shop-copy-${suffix}/settings/members`);
    const members = page.locator('#tab-content-members table.members');
    await expect(members.locator('tr', { hasText: 'John Smith' })).toContainText('Manager');
    await expect(members.locator('tr', { hasText: 'Developers' })).toContainText('Developer');

    // issues keep their attributes and their hierarchy, pointing at the copies (not at the source issues)
    await page.goto(`/projects/shop-copy-${suffix}/issues?set_filter=1&status_id=*&sort=id`);
    await expect(page.locator('table.issues tbody tr.issue')).toHaveCount(5);
    await expect(page.locator('table.issues tbody tr.issue td.id a', { hasText: /^1[3-9]$/ })).toHaveCount(0);
    await page.locator('table.issues td.subject a', { hasText: 'Wishlist sharing' }).click();
    await expect(page.locator('#header h1')).toContainText(name);
    const parentId = Number(sqlValue("SELECT id FROM issues WHERE project_id = ? AND subject = 'Wishlist'", [id]));
    await expect(page.locator('.issue.details .subject a', { hasText: `#${parentId}` })).toBeVisible();
    // the copied bug keeps its version and category (the copies of the project)
    const cart = sql("SELECT fixed_version_id, category_id, assigned_to_id FROM issues WHERE project_id = ? AND subject LIKE 'Cart total%'", [id])[0];
    expect(sqlValue('SELECT project_id FROM versions WHERE id = ?', [cart.fixed_version_id as number])).toBe(id);
    expect(cart.assigned_to_id).toBe(10);

    // roadmap and wiki of the copy
    await page.goto(`/projects/shop-copy-${suffix}/roadmap`);
    await expect(page.locator('#roadmap h3.version', { hasText: '1.0' })).toBeVisible();
    await page.goto(`/projects/shop-copy-${suffix}/wiki/Checkout_flow`);
    await expect(page.locator('#content .wiki-page, #content .wiki').first()).toBeVisible();
    await expect(page.locator('#content p.nodata')).toHaveCount(0);
    // no forum was copied although the module is enabled
    await page.goto(`/projects/shop-copy-${suffix}/boards`);
    await expect(page.locator('#content')).not.toContainText('General');
  });

  test('copy form validation keeps the form; non-administrators cannot copy', async ({ page, ui, loginAs }) => {
    await page.goto('/projects/ecommerce/copy');
    await page.locator('#project_name').fill('Duplicate identifier');
    await page.locator('#project_identifier').fill('internal');
    await page.locator('#new_project input[name=commit]').click();
    await ui.expectError(/Identifier has already been taken/);
    await expect(ui.heading()).toHaveText('New project');
    await expect(page.locator('#project_name')).toHaveValue('Duplicate identifier');
    await expect(page.locator('#new_project input[name=commit]')).toHaveValue('Copy');
    await expect(page.locator('#project_identifier')).toHaveValue('internal');
    await expect(page.locator('input[name="only[]"][type=checkbox]')).toHaveCount(8);
    expect(Number(sqlValue("SELECT COUNT(*) FROM projects WHERE name = 'Duplicate identifier'"))).toBe(0);

    const manager = await loginAs('jsmith');
    await manager.http.expectStatus('/projects/ecommerce/copy', 403);
  });
});

test.describe('project bookmarks', () => {
  // pristine projects for every test below (the copy tests above add a project)
  test.use({ as: 'jsmith', dbReset: 'test' });

  test('bookmark from the overview: link toggles through ajax, jump box and projects list follow', async ({ page, ui }) => {
    await page.goto('/projects/checkout');
    const link = page.locator('#content > .contextual a.bookmark');
    await expect(link).toHaveClass(/icon-bookmark-off/);
    await expect(link).toHaveText('Add bookmark');
    await link.click();
    // no reload: the link is replaced and the jump box gets a "Bookmarks" group
    await expect(link).toHaveClass(/icon-bookmark(\s|$)/);
    await expect(link).toHaveText('Remove bookmark');
    await page.locator('#project-jump .drdn-trigger').click();
    const items = page.locator('#project-jump .drdn-items.projects');
    await expect(items.locator('strong').first()).toHaveText('Bookmarks');
    await expect(items.locator('strong + a').first()).toHaveText('Checkout');

    // the bookmark is stored server side: other pages show it
    await page.goto('/projects');
    const board = page.locator('#projects-index');
    await expect(board.locator('span.icon-bookmarked-project')).toHaveCount(1);
    await expect(board.locator('a[href="/projects/checkout"] ~ span.icon-bookmarked-project')).toHaveCount(1);

    // "My bookmarks" filter of the projects list
    await ui.setFilter('id', '=', ['bookmarks']);
    await ui.applyFilters();
    await expect(page.locator('#projects-index a.project')).toHaveText(['Checkout']);
    // My page offers the bookmarked projects through the "My bookmarks" default query of the jump box too
    await page.goto('/my/page');
    await page.locator('#project-jump .drdn-trigger').click();
    await expect(page.locator('#project-jump .drdn-items.projects strong').first()).toHaveText('Bookmarks');

    // remove the bookmark
    await page.goto('/projects/checkout');
    await page.locator('#content > .contextual a.bookmark').click();
    await expect(page.locator('#content > .contextual a.bookmark')).toHaveText('Add bookmark');
    await page.goto('/projects');
    await expect(page.locator('#projects-index a.project')).toHaveCount(3);
    await expect(page.locator('#projects-index span.icon-bookmarked-project')).toHaveCount(0);
  });
});

test.describe('projects list', () => {
  test.use({ as: 'jsmith', dbReset: 'test' });

  test('board by default; list display with chosen columns, sorting and filters', async ({ page, ui }) => {
    await page.goto('/projects?set_filter=1');
    // board: nested lists, subproject under its parent, "my project" markers, description rendered
    const board = page.locator('#projects-index');
    const roots = board.locator('> ul.projects.root > li.root > div.root > a.project');
    await expect(roots).toHaveCount(2);
    await expect(roots.filter({ hasText: 'Internal Tools' })).toHaveCount(1);
    await expect(roots.filter({ hasText: 'eCommerce' })).toHaveCount(1);
    await expect(board.locator('li.root ul.projects li.child a.project')).toHaveText(['Checkout']);
    await expect(board.locator('.wiki.description strong')).toHaveText('shop');
    await expect(board.locator('a.project.my-project')).toHaveCount(3);

    // switch to the list display: the column selector only appears for "List"
    await ui.expandFieldset('Options');
    await expect(page.locator('#list-definition')).toBeHidden();
    await page.locator('#display_type_list').check();
    await expect(page.locator('#list-definition')).toBeVisible();
    await page.locator('#available_c').selectOption(['is_public', 'cf_5']);
    await page.locator('#options .query-columns + .buttons .move-right').click();
    await expect(page.locator('#selected_c option')).toHaveText(['Name', 'Identifier', 'Description', 'Public', 'Customer']);
    await page.locator('#selected_c').selectOption('short_description');
    await page.locator('#options .query-columns + .buttons .move-left').click();
    await ui.applyFilters();

    await expect(page).toHaveURL(/display_type=list/);
    const table = page.locator('table.list.projects');
    await expect(table.locator('thead th')).toHaveText(['Name', 'Identifier', 'Public', 'Customer']);
    const ecommerce = table.locator('tr#project-1');
    await expect(ecommerce.locator('td.name')).toContainText('eCommerce');
    await expect(ecommerce.locator('td.is_public')).toHaveText('Yes');
    await expect(ecommerce.locator('td.cf_5')).toHaveText('ACME Corp');
    await expect(table.locator('tr#project-2 td.is_public')).toHaveText('No');
    // the subproject is indented below its parent
    await expect(table.locator('tr#project-3')).toHaveClass(/idnt-1/);

    // sort by identifier (click the header twice: asc, then desc)
    await table.locator('thead th.identifier a').click();
    await expect(page.locator('table.list.projects tbody td.identifier')).toHaveText(['checkout', 'ecommerce', 'internal']);
    await page.locator('table.list.projects thead th.identifier a').click();
    await expect(page.locator('table.list.projects tbody td.identifier')).toHaveText(['internal', 'ecommerce', 'checkout']);

    // filters: public projects whose name contains "c"
    await ui.setFilter('is_public', '=', ['1']);
    await page.locator('#add_filter_select').selectOption('name');
    await page.locator('#operators_name').selectOption('~');
    await page.locator('#values_name').fill('check');
    await ui.applyFilters();
    await expect(page.locator('table.list.projects tbody tr')).toHaveCount(1);
    await expect(page.locator('table.list.projects tbody td.name')).toContainText('Checkout');

    // "Subproject of" filter
    await page.goto('/projects?set_filter=1&display_type=list&f[]=parent_id&op[parent_id]==&v[parent_id][]=1');
    await expect(page.locator('table.list.projects tbody td.identifier')).toHaveText(['checkout']);
    // Clear goes back to the board with every active project
    await page.locator('#query_form p.buttons a.icon-reload').click();
    await expect(page.locator('#projects-index a.project')).toHaveCount(3);
  });

  test('group the list by "Public" and collapse a group', async ({ page }) => {
    await page.goto('/projects?set_filter=1&display_type=list&group_by=is_public&c[]=name&c[]=identifier');
    const groups = page.locator('table.list.projects tr.group');
    await expect(groups).toHaveCount(2);
    const yes = groups.filter({ hasText: 'Yes' });
    const no = groups.filter({ hasText: 'No' });
    await expect(yes.locator('.count')).toHaveText('2');
    await expect(no.locator('.count')).toHaveText('1');
    // collapse the "Yes" group
    await yes.locator('.expander').click();
    await expect(page.locator('tr#project-1')).toBeHidden();
    await expect(page.locator('tr#project-3')).toBeHidden();
    await expect(page.locator('tr#project-2')).toBeVisible();
    await yes.locator('.expander').click();
    await expect(page.locator('tr#project-1')).toBeVisible();
  });

  test('anonymous visitors only see public projects, without markers', async ({ browser }) => {
    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const anon = await ctx.newPage();
    await anon.goto('/projects');
    await expect(anon.locator('#projects-index a.project')).toHaveText(['eCommerce', 'Checkout']);
    await expect(anon.locator('#projects-index .my-project')).toHaveCount(0);
    await expect(anon.locator('#content > .contextual a.icon-add')).toHaveCount(0);
    await ctx.close();
  });
});
