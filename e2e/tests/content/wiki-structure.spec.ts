import { test, expect, uid, ensureFileReset } from '../../support/fixtures';
import { apiAs } from '../../support/api';
import { sql, sqlValue } from '../../support/db';
import { openActions } from './_helpers';

/*
 * Wiki beyond a single page: page hierarchy (parent through the rename form, breadcrumb, indexes, {{child_pages}}),
 * macros that need the browser ({{toc}} anchors, {{collapse}}), the custom sidebar page, watching a page and
 * deleting a page that has children.
 * Seeded (ecommerce): "Wiki" (start page) with the child "Checkout_flow".
 * Pages created in beforeAll: Handbook, Handbook_setup, Handbook_usage, Loose_page.
 */
const P = '/projects/ecommerce/wiki';

function pageId(title: string): number {
  return Number(sqlValue('SELECT p.id FROM wiki_pages p JOIN wikis w ON w.id = p.wiki_id WHERE w.project_id = 1 AND p.title = ?', [title]));
}

test.beforeAll(async ({}, testInfo) => {
  await ensureFileReset(testInfo);
  const jsmith = await apiAs('jsmith');
  await jsmith.createWikiPage('ecommerce', 'Handbook', '# Handbook\n\n{{child_pages}}');
  await jsmith.createWikiPage('ecommerce', 'Handbook_setup', '# Setup\n\nInstall it.');
  await jsmith.createWikiPage('ecommerce', 'Handbook_usage', '# Usage\n\nUse it.');
  await jsmith.createWikiPage('ecommerce', 'Loose_page', '# Loose\n\nNo parent yet.');
  await jsmith.dispose();
});

test.describe('wiki structure', () => {
  test.use({ as: 'jsmith' });

  test('parent page through "Rename": breadcrumb, child pages macro and both indexes', async ({ page, ui }) => {
    // set the parent of the two handbook chapters with the rename form
    for (const title of ['Handbook_setup', 'Handbook_usage']) {
      await page.goto(`${P}/${title}`);
      const menu = await openActions(page);
      await menu.locator('a.icon-move').click();
      await expect(page).toHaveURL(new RegExp(`/wiki/${title}/rename$`));
      await expect(page.locator('#wiki_page_title')).toHaveValue(new RegExp(title.replace('_', '[ _]')));
      await page.locator('#wiki_page_parent_id').selectOption({ label: 'Handbook' });
      await page.locator('#content form input[type=submit]').click();
      await ui.expectNotice('Successful update.');
      await expect(page.locator('#content p.breadcrumb a')).toHaveText(['Handbook']);
    }
    expect(sql('SELECT parent_id FROM wiki_pages WHERE id IN (?, ?)', [pageId('Handbook_setup'), pageId('Handbook_usage')])).toEqual([
      { parent_id: pageId('Handbook') },
      { parent_id: pageId('Handbook') },
    ]);

    // {{child_pages}} on the parent lists them; the breadcrumb of a child leads back
    await page.goto(`${P}/Handbook`);
    const children = page.locator('#content .wiki-page ul.pages-hierarchy a');
    await expect(children).toHaveText(['Handbook setup', 'Handbook usage']);
    await children.first().click();
    await expect(page.locator('#content .wiki-page h1')).toContainText('Setup');
    await page.locator('#content p.breadcrumb a', { hasText: 'Handbook' }).click();
    await expect(page).toHaveURL(new RegExp(`${P}/Handbook$`));

    // a page cannot become a child of its own descendant: only valid parents are offered
    await page.goto(`${P}/Handbook/rename`);
    await expect(page.locator('#wiki_page_parent_id option', { hasText: 'Handbook setup' })).toHaveCount(0);
    await expect(page.locator('#wiki_page_parent_id option', { hasText: 'Loose page' })).toHaveCount(1);

    // index by title (sidebar link of the wiki pages): nested lists follow the hierarchy
    await page.goto(`${P}/Handbook`);
    await page.locator('#sidebar a', { hasText: 'Index by title' }).click();
    await expect(page.locator('#content h2')).toHaveText('Index by title');
    const index = page.locator('#content > ul.pages-hierarchy');
    await expect(index.locator('> li > a')).toHaveText(['Handbook', 'Loose page', 'Wiki']);
    await expect(index.locator('> li', { has: page.locator('> a', { hasText: 'Handbook' }) }).locator('ul.pages-hierarchy a')).toHaveText(['Handbook setup', 'Handbook usage']);
    await expect(index.locator('> li', { has: page.locator('> a', { hasText: /^Wiki$/ }) }).locator('ul.pages-hierarchy a')).toHaveText(['Checkout flow']);

    // index by date: flat, grouped under the day of the last change, most recent day first
    await page.locator('#sidebar a', { hasText: 'Index by date' }).click();
    await expect(page.locator('#content h2')).toHaveText('Index by date');
    const days = page.locator('#content h3');
    await expect(days).toHaveCount(2);
    await expect(days.last()).toHaveText('11/07/2026');
    await expect(page.locator('#content h3 + ul').first().locator('a')).toHaveText(['Handbook', 'Handbook setup', 'Handbook usage', 'Loose page']);
    await expect(page.locator('#content h3 + ul').last().locator('a')).toHaveText(['Checkout flow', 'Wiki']);
  });

  test('{{toc}} links jump to the headings, {{collapse}} toggles its block, {{include}} embeds a page', async ({ page }) => {
    const title = uid('Macros_');
    const filler = Array.from({ length: 40 }, (_, i) => `Paragraph ${i + 1}.`).join('\n\n');
    const text = [
      '{{toc}}',
      '',
      '# Guide',
      '',
      '## First part',
      '',
      filler,
      '',
      '## Second part',
      '',
      '{{collapse(Show the details)',
      'Hidden **details** here.',
      '}}',
      '',
      '### Deeper',
      '',
      '{{include(Loose_page)}}',
    ].join('\n');
    const jsmith = await apiAs('jsmith');
    await jsmith.createWikiPage('ecommerce', title, text);
    await jsmith.dispose();

    await page.goto(`${P}/${title}`);
    const wiki = page.locator('#content .wiki-page');
    // table of contents: nested by heading level
    const toc = wiki.locator('ul.toc');
    // (the headings of the included page are part of it, like in Redmine)
    await expect(toc.locator('> li > a')).toHaveText(['Guide', 'Loose']);
    await expect(toc.locator('> li > ul > li > a')).toHaveText(['First part', 'Second part']);
    await expect(toc.locator('> li > ul > li > ul > li > a')).toHaveText(['Deeper']);
    // the last heading is below the fold; its toc link scrolls to it
    const second = wiki.locator('h2', { hasText: 'Second part' });
    await expect(second).not.toBeInViewport();
    await toc.getByRole('link', { name: 'Second part' }).click();
    await expect(page).toHaveURL(/#Second-part$/);
    await expect(second).toBeInViewport();
    // headings carry a permalink anchor
    await expect(second.locator('a.wiki-anchor')).toHaveAttribute('href', '#Second-part');

    // collapse: hidden until the link is clicked, then the label toggles
    const details = wiki.locator('.collapsed-text');
    await expect(details).toBeHidden();
    await wiki.locator('a.collapsible', { hasText: 'Show the details' }).first().click();
    await expect(details).toBeVisible();
    await expect(details.locator('strong')).toHaveText('details');
    await wiki.locator('a.collapsible:visible', { hasText: 'Show the details' }).click();
    await expect(details).toBeHidden();

    // include: the other page is rendered inline
    await expect(wiki).toContainText('No parent yet.');
  });

  test('the "Sidebar" page is displayed in the sidebar of every wiki page', async ({ page, loginAs }) => {
    await page.goto(P);
    await page.locator('#sidebar .contextual a.icon-edit').click();
    await expect(page).toHaveURL(/\/wiki\/sidebar\/edit$/i);
    await page.locator('#content_text').fill('### Team links\n\n* [[Handbook]]\n* [Status page](https://status.example.net/)');
    await page.locator('#wiki_form input[name=commit]').click();
    await expect(page).toHaveURL(/\/wiki\/Sidebar$/);

    for (const path of [P, `${P}/Checkout_flow`, `${P}/index`]) {
      await page.goto(path);
      const sidebar = page.locator('#sidebar');
      await expect(sidebar.locator('h3', { hasText: 'Team links' })).toBeVisible();
      await expect(sidebar.locator('a.wiki-page', { hasText: 'Handbook' })).toHaveAttribute('href', `${P}/Handbook`);
      await expect(sidebar.locator('a.external', { hasText: 'Status page' })).toHaveAttribute('href', 'https://status.example.net/');
    }
    // readers get the same sidebar
    const reporter = await loginAs('rhill');
    await reporter.page.goto(P);
    await expect(reporter.page.locator('#sidebar h3', { hasText: 'Team links' })).toBeVisible();
    await reporter.page.locator('#sidebar a.wiki-page', { hasText: 'Handbook' }).click();
    await expect(reporter.page).toHaveURL(new RegExp(`${P}/Handbook$`));
  });

  test('watch a page: ajax toggle, watchers box, and a mail when somebody else edits it', async ({ page, ui, loginAs, mailbox }) => {
    await page.goto(`${P}/Loose_page`);
    const id = pageId('Loose_page');
    const watch = page.locator(`#content > .contextual a.wiki_page-${id}-watcher`);
    await expect(watch).toHaveText('Watch');
    await watch.click();
    await expect(watch).toHaveText('Unwatch');
    await expect(watch).toHaveClass(/icon-fav(\s|$)/);
    await expect.poll(() => Number(sqlValue("SELECT COUNT(*) FROM watchers WHERE watchable_type = 'WikiPage' AND watchable_id = ? AND user_id = 5", [id]))).toBe(1);
    // the sidebar lists the watchers; a manager adds another one through the modal
    await page.reload();
    await expect(page.locator('#watchers h3')).toContainText('Watchers (1)');
    await page.locator('#watchers .contextual a', { hasText: 'Add' }).click();
    const modal = await ui.expectModal();
    await modal.locator('#user_search').pressSequentially('Hill', { delay: 30 });
    await modal.locator('#users_for_watcher label', { hasText: 'Robert Hill' }).locator('input').check();
    await modal.locator('p.buttons input[type=submit]').click();
    await expect(ui.modal()).toBeHidden();
    await expect(page.locator('#watchers h3')).toContainText('Watchers (2)');
    await expect(page.locator('#watchers ul.watchers')).toContainText('Robert Hill');

    // a developer edits the page: the watchers are notified (the author of the change is not)
    sql("UPDATE users SET mail_notification = 'only_my_events' WHERE id IN (5, 7)");
    // wiki notifications are off by default: the administrator enables them
    const admin = await loginAs('admin');
    await admin.page.goto('/settings?tab=notifications');
    await admin.page.locator('input[name="settings[notified_events][]"][value="wiki_content_updated"]').check();
    await admin.page.locator('#tab-content-notifications input[type=submit][value="Save"]').click();
    await admin.ui.expectNotice('Successful update.');
    const developer = await loginAs('dlopper');
    const marker = uid('Edited by Dave ');
    await developer.page.goto(`${P}/Loose_page/edit`);
    await developer.page.locator('#content_text').fill(`# Loose\n\n${marker}`);
    await developer.page.locator('#content_comments').fill('small fix');
    await developer.page.locator('#wiki_form input[name=commit]').click();
    await expect(developer.page.locator('#content .wiki-page')).toContainText(marker);
    const mail = await mailbox.waitFor({ to: 'jsmith@example.net', subject: /Loose/ });
    expect(mail.text).toContain('Loose_page');
    await mailbox.waitFor({ to: 'rhill@example.net', subject: /Loose/ });

    // unwatch
    await page.reload();
    await page.locator(`#content > .contextual a.wiki_page-${id}-watcher`).click();
    await expect(page.locator(`#content > .contextual a.wiki_page-${id}-watcher`)).toHaveText('Watch');
    await expect.poll(() => Number(sqlValue("SELECT COUNT(*) FROM watchers WHERE watchable_type = 'WikiPage' AND watchable_id = ? AND user_id = 5", [id]))).toBe(0);
  });

  test('deleting a page with children asks what to do with them: reassign to another parent', async ({ page }) => {
    // Handbook has two children (first test); Loose_page will inherit them
    const handbook = pageId('Handbook');
    sql('UPDATE wiki_pages SET parent_id = ? WHERE id IN (?, ?)', [handbook, pageId('Handbook_setup'), pageId('Handbook_usage')]);
    await page.goto(`${P}/Handbook`);
    const menu = await openActions(page);
    await menu.locator('a.icon-del').click();
    // instead of deleting right away, the question page is displayed
    await expect(page.locator('#content h2')).toHaveText('Handbook');
    await expect(page.locator('#content form .box')).toContainText('This page has 2 child page(s) and descendant(s)');
    await expect(page.locator('#todo_nullify')).toBeChecked();
    // picking a target in the select checks "reassign"
    await page.locator('#reassign_to_id').click();
    await page.locator('#reassign_to_id').selectOption({ label: 'Loose page' });
    await expect(page.locator('#todo_reassign')).toBeChecked();
    await expect(page.locator('#reassign_to_id option', { hasText: 'Handbook setup' })).toHaveCount(0);
    await page.locator('#content form input[type=submit]').click();

    await expect(page).toHaveURL(new RegExp(`${P}/index$`));
    const index = page.locator('#content > ul.pages-hierarchy');
    await expect(index.locator('> li > a', { hasText: /^Handbook$/ })).toHaveCount(0);
    await expect(index.locator('> li', { has: page.locator('> a', { hasText: 'Loose page' }) }).locator('ul.pages-hierarchy a')).toHaveText(['Handbook setup', 'Handbook usage']);
    expect(Number(sqlValue('SELECT COUNT(*) FROM wiki_pages WHERE id = ?', [handbook]))).toBe(0);
    await page.goto(`${P}/Handbook_usage`);
    await expect(page.locator('#content p.breadcrumb a')).toHaveText(['Loose page']);
  });
});
