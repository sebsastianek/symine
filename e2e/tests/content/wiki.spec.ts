import { test, expect, uid } from '../../support/fixtures';
import { sqlValue } from '../../support/db';
import { openActions, textFile, uploadAttachment } from './_helpers';

const WIKI = '/projects/ecommerce/wiki';

test.describe('wiki', () => {
  test.use({ as: 'jsmith' });

  test('create a page through the "New wiki page" modal, with toolbar, preview and attachment', async ({ page, ui }) => {
    const title = uid('Page_');
    await page.goto(WIKI);
    await expect(page.locator('#content > div.wiki.wiki-page')).toBeVisible();

    const menu = await openActions(page);
    await menu.getByRole('link', { name: 'New wiki page' }).click();
    const modal = await ui.expectModal('New wiki page');
    // created from the start page: "Parent page" checkbox is offered
    await expect(modal.locator('#parent')).toBeChecked();
    await modal.locator('#page_title').fill(title);
    await modal.getByRole('button', { name: 'Next' }).click();

    await expect(page).toHaveURL(new RegExp(`/wiki/${title}/?(edit)?(\\?.*)?$`));
    await expect(page.locator('#content h2')).toHaveText(title.replace(/_/g, ' '));

    // the wiki toolbar (stimulus wiki-toolbar controller) is rendered and works
    const editor = page.locator('#content_text');
    await expect(page.locator('.jstElements button.jstb_strong')).toBeVisible();
    await editor.fill('word');
    await editor.selectText();
    await page.locator('.jstElements button.jstb_strong').click();
    await expect(editor).toHaveValue('**word**');

    await editor.fill('# Heading\n\nSome **bold** text.');
    // ajax preview tab
    await page.locator('.jstTabs a.tab-preview').click();
    const preview = page.locator('#preview_content_text');
    await expect(preview.locator('strong')).toHaveText('bold');
    await expect(preview.locator('h1')).toContainText('Heading');
    await page.locator('.jstTabs a.tab-edit').click();
    await expect(editor).toBeVisible();

    await page.locator('#content_comments').fill('first version');
    await uploadAttachment(page.locator('#wiki_form'), textFile('wiki-notes.txt'));
    await page.getByRole('button', { name: 'Save' }).click();

    // like Redmine: no flash after saving a wiki page, the page is shown
    await expect(page).toHaveURL(new RegExp(`/wiki/${title}$`));
    await expect(page.locator('#content > div.wiki.wiki-page strong')).toHaveText('bold');
    await expect(page.locator('#content > div.wiki.wiki-page h1')).toContainText('Heading');
    // parent (start page) in the breadcrumb
    await expect(page.locator('p.breadcrumb')).toContainText('Wiki');
    // attachment listed in the collapsed fieldset
    await page.locator('fieldset.collapsible legend', { hasText: 'Files' }).click();
    await expect(page.locator('.attachments a', { hasText: 'wiki-notes.txt' }).first()).toBeVisible();

    // the page is in the index
    await page.goto(`${WIKI}/index`);
    await expect(page.locator('ul.pages-hierarchy a', { hasText: title.replace(/_/g, ' ') })).toBeVisible();
  });

  test('edit, section edit, history, diff, annotate and version view', async ({ page, ui, api }) => {
    const title = uid('Sections_');
    const jsmith = await api('jsmith');
    await jsmith.createWikiPage('ecommerce', title, '# Alpha\n\nalpha text\n\n# Beta\n\nbeta text\n');

    await page.goto(`${WIKI}/${title}`);
    await expect(page.locator('#content > div.wiki.wiki-page h1').first()).toContainText('Alpha');

    // full edit through the contextual Edit link
    await page.locator('#content .contextual a.icon-edit').first().click();
    await expect(page.locator('#content_text')).toHaveValue(/# Alpha[\s\S]*# Beta/);
    await page.locator('#content_text').fill('# Alpha\n\nalpha text v2\n\n# Beta\n\nbeta text\n');
    await page.locator('#content_comments').fill('alpha changed');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page).toHaveURL(new RegExp(`/wiki/${title}(#section-2)?$`));
    await expect(page.locator('#content > div.wiki.wiki-page')).toContainText('alpha text v2');

    // section edit: pencil of the 2nd heading edits only that section
    await page.locator('#section-2 a.icon-edit').click();
    await expect(page).toHaveURL(/section=2/);
    await expect(page.locator('#content_text')).toHaveValue(/^# Beta\s+beta text\s*$/);
    await expect(page.locator('#section_hash')).toHaveValue(/.+/);
    await page.locator('#content_text').fill('# Beta\n\nbeta text v3\n');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page).toHaveURL(new RegExp(`/wiki/${title}(#section-2)?$`));
    await expect(page.locator('#content > div.wiki.wiki-page')).toContainText('alpha text v2');
    await expect(page.locator('#content > div.wiki.wiki-page')).toContainText('beta text v3');
    await expect(page.locator('p.wiki-update-info')).toContainText('3 revisions');

    // history: 3 versions, compare v3 with v1
    const actions = await openActions(page);
    await actions.getByRole('link', { name: 'History' }).click();
    const rows = page.locator('table.wiki-page-versions tbody tr');
    await expect(rows).toHaveCount(3);
    await expect(rows.first().locator('td.comments')).toHaveText('');
    await expect(rows.nth(1).locator('td.comments')).toHaveText('alpha changed');
    await page.locator('#cbto-3').check();
    await page.getByRole('button', { name: 'View differences' }).first().click();
    await expect(page).toHaveURL(/\/diff\?.*version=3.*version_from=1|\/diff\?.*version_from=1.*version=3/);
    await expect(page.locator('#content h2')).toContainText('History » Revision 3');
    await expect(page.locator('#content > p').first()).toContainText(/Revision 1[\s\S]*Revision 3\/3/);
    await expect(page.locator('.text-diff span.diff_in').first()).toBeVisible();
    await expect(page.locator('.text-diff span.diff_in', { hasText: 'v3' })).toBeVisible();

    // annotate the current version
    await page.goto(`${WIKI}/${title}/history`);
    await rows.first().getByRole('link', { name: 'Annotate' }).click();
    const annotate = page.locator('table.filecontent.annotate');
    await expect(annotate).toBeVisible();
    await expect(annotate.locator('tr', { hasText: 'beta text v3' }).locator('.revision a')).toHaveText('3');
    await expect(annotate.locator('tr', { hasText: 'alpha text v2' }).locator('.revision a')).toHaveText('2');

    // old version view with navigation
    await page.goto(`${WIKI}/${title}/1`);
    await expect(page.locator('#content h2')).toContainText('Revision 1');
    await expect(page.locator('#content > div.wiki.wiki-page')).toContainText('alpha text');
    await expect(page.locator('#content > div.wiki.wiki-page')).not.toContainText('v2');
    await page.getByRole('link', { name: 'Next »' }).click();
    await expect(page).toHaveURL(new RegExp(`/wiki/${title}/2$`));
  });

  test('rename with redirect of existing links', async ({ page, ui, api }) => {
    const title = uid('Old_');
    const newTitle = uid('Renamed_');
    const jsmith = await api('jsmith');
    await jsmith.createWikiPage('ecommerce', title, 'rename me');

    await page.goto(`${WIKI}/${title}`);
    const actions = await openActions(page);
    await actions.getByRole('link', { name: 'Rename' }).click();
    await expect(page.locator('#wiki_page_title')).toHaveValue(title);
    await expect(page.locator('#wiki_page_redirect_existing_links')).toBeChecked();
    await page.locator('#wiki_page_title').fill(newTitle);
    await page.getByRole('button', { name: 'Rename' }).click();

    await ui.expectNotice('Successful update.');
    await expect(page).toHaveURL(new RegExp(`/wiki/${newTitle}$`));
    await expect(page.locator('#content > div.wiki.wiki-page')).toContainText('rename me');

    // the old title redirects to the new page
    await page.goto(`${WIKI}/${title}`);
    await expect(page).toHaveURL(new RegExp(`/wiki/${newTitle}$`));
  });

  test('protect / unprotect a page; developers cannot edit a protected page', async ({ page, ui, api, loginAs }) => {
    const title = uid('Protected_');
    const jsmith = await api('jsmith');
    await jsmith.createWikiPage('ecommerce', title, 'protected content');

    await page.goto(`${WIKI}/${title}`);
    let actions = await openActions(page);
    await actions.getByRole('link', { name: 'Lock' }).click();
    await expect(page.locator('p.wiki-update-info .badge-status-locked')).toHaveText(/locked/i);
    expect(Number(sqlValue('SELECT protected FROM wiki_pages WHERE title = ?', [title]))).toBe(1);

    // dlopper (Developer: edit_wiki_pages but no protect_wiki_pages) sees no edit link
    const dev = await loginAs('dlopper');
    await dev.page.goto(`${WIKI}/${title}`);
    await expect(dev.page.locator('#content > div.wiki.wiki-page')).toContainText('protected content');
    await expect(dev.page.locator('#content .contextual a.icon-edit')).toHaveCount(0);
    const res = await dev.http.get(`${WIKI}/${title}/edit`);
    expect(res.status()).toBe(403);

    actions = await openActions(page);
    await actions.getByRole('link', { name: 'Unlock' }).click();
    await expect(page.locator('p.wiki-update-info .badge-status-locked')).toHaveCount(0);
    await dev.page.reload();
    await expect(dev.page.locator('#content .contextual a.icon-edit')).toBeVisible();
    await expect(ui.flashError()).toHaveCount(0);
  });

  test('delete a page (confirm dialog)', async ({ page, api }) => {
    const title = uid('Doomed_');
    const jsmith = await api('jsmith');
    await jsmith.createWikiPage('ecommerce', title, 'to be deleted');

    await page.goto(`${WIKI}/${title}`);
    const actions = await openActions(page);
    await actions.getByRole('link', { name: 'Delete' }).click();
    await expect(page).toHaveURL(/\/wiki\/index$/);
    await expect(page.locator('ul.pages-hierarchy').first()).toBeVisible();
    await expect(page.locator('ul.pages-hierarchy a', { hasText: title.replace(/_/g, ' ') })).toHaveCount(0);
    await page.goto(`${WIKI}/${title}`);
    // Redmine renders the edit form for a missing page when the user can edit (the page is gone)
    await expect(page.locator('#content_text')).toHaveValue(/^# Doomed/);
    expect(Number(sqlValue('SELECT COUNT(*) FROM wiki_pages WHERE title = ?', [title]))).toBe(0);
  });
});
