import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import { openActions, setSetting } from './_helpers';

/*
 * People working on the same wiki page and the same forum topic of eCommerce:
 * jsmith (Manager: everything), dlopper (Developer: edits wiki pages, cannot rename; posts messages),
 * rhill (Reporter: reads the wiki, posts messages and edits his own).
 * E-mail notifications for wiki changes and forum messages are switched on for these stories (off in the seed).
 */
const WIKI = '/projects/ecommerce/wiki';

test.beforeEach(() => {
  setSetting('notified_events', JSON.stringify(['issue_added', 'issue_updated', 'wiki_content_added', 'wiki_content_updated', 'message_posted']));
});

test.describe('wiki collaboration', () => {
  test('two editors collide, history / diff / rollback, rename keeps the links of an issue working', async ({ loginAs, api, mailbox }) => {
    test.setTimeout(180_000);
    const manager = await loginAs('jsmith');
    const developer = await loginAs('dlopper');
    const reporter = await loginAs('rhill');
    const suffix = uid();
    const title = `Release_plan_${suffix}`;
    const shown = `Release plan ${suffix}`;
    const renamed = `Launch_plan_${suffix}`;
    const content = manager.page.locator('#content > div.wiki.wiki-page');
    await (await api('jsmith')).createWikiPage('ecommerce', title, '# Plan\n\nShip in **December**.\n');
    const issue = await (await api('jsmith')).createIssue({ project_id: 1, subject: `Follow the plan ${suffix}`, description: `See [[${title}]] and [[Missing_${suffix}]].` });

    await test.step('the reporter reads the page and watches it', async () => {
      const { page } = reporter;
      await page.goto(`${WIKI}/${title}`);
      await expect(page.locator('#content > div.wiki.wiki-page strong')).toHaveText('December');
      await expect(page.locator('#content .contextual a.icon-edit')).toHaveCount(0); // no edit permission
      const watch = page.locator('#content > .contextual a[class*="-watcher"]');
      await watch.click();
      await expect(watch).toHaveText(/Unwatch/);
      const pageId = Number(sqlValue('SELECT id FROM wiki_pages WHERE title = ?', [title]));
      await expect.poll(() => sql("SELECT user_id FROM watchers WHERE watchable_type = 'WikiPage' AND watchable_id = ?", [pageId])).toEqual([{ user_id: 7 }]);
    });

    await test.step('manager and developer edit at the same time: the second save is refused, nothing is lost', async () => {
      const { page, ui } = manager;
      await page.goto(`${WIKI}/${title}`);
      await page.locator('#content .contextual a.icon-edit').first().click();
      await expect(page.locator('#content_text')).toHaveValue(/Ship in \*\*December\*\*/);
      await page.locator('#content_text').fill('# Plan\n\nShip in **December**.\n\nManager: budget approved.\n');
      await page.locator('#content_comments').fill('budget');

      const dev = developer.page;
      await dev.goto(`${WIKI}/${title}/edit`);
      await dev.locator('#content_text').fill('# Plan\n\nShip in **December**.\n\nDeveloper: code freeze on the 10th.\n');
      await dev.locator('#content_comments').fill('code freeze');
      await dev.getByRole('button', { name: 'Save' }).click();
      await expect(dev.locator('#content > div.wiki.wiki-page')).toContainText('code freeze on the 10th');

      await page.getByRole('button', { name: 'Save' }).click();
      await ui.expectError('Data has been updated by another user.');
      // still on the form with his text; the stored page is the developer's version
      await expect(page.locator('#content_text')).toHaveValue(/Manager: budget approved\./);
      expect(sql('SELECT c.version, c.text, c.author_id FROM wiki_contents c JOIN wiki_pages p ON p.id = c.page_id WHERE p.title = ?', [title])).toEqual([
        { version: 2, text: expect.stringContaining('code freeze'), author_id: 6 },
      ]);

      // he starts again from the current version and adds his line
      await page.goto(`${WIKI}/${title}/edit`);
      await expect(page.locator('#content_text')).toHaveValue(/Developer: code freeze on the 10th\./);
      await page.locator('#content_text').press('ControlOrMeta+End');
      await page.locator('#content_text').pressSequentially('\nManager: budget approved.\n');
      await page.locator('#content_comments').fill('budget');
      await page.getByRole('button', { name: 'Save' }).click();
      await expect(content).toContainText('code freeze on the 10th');
      await expect(content).toContainText('budget approved');
      await expect(page.locator('p.wiki-update-info')).toContainText('Updated by John Smith');
      await expect(page.locator('p.wiki-update-info')).toContainText('3 revisions');

      // the watcher got one mail per saved version (the refused save sent nothing)
      await expect.poll(() => mailbox.find({ to: 'rhill@example.net', subject: new RegExp(`${shown}|${title}`) }).length).toBe(2);
      const mails = mailbox.find({ to: 'rhill@example.net', subject: new RegExp(`${shown}|${title}`) });
      for (const m of mails) expect(m.subject).toMatch(/^\[eCommerce\] .*wiki page has been updated/);
    });

    await test.step('history, differences and rollback to the first version', async () => {
      const { page } = manager;
      await (await openActions(page)).getByRole('link', { name: 'History' }).click();
      const rows = page.locator('table.wiki-page-versions tbody tr');
      await expect(rows).toHaveCount(3);
      await expect(rows.locator('td.comments')).toHaveText(['budget', 'code freeze', '']);
      await expect(rows.locator('td.author')).toHaveText(['John Smith', 'Dave Lopper', 'John Smith']);
      // compare the first and the last version
      await page.locator('#cbto-3').check();
      await page.getByRole('button', { name: 'View differences' }).first().click();
      await expect(page.locator('.text-diff .diff_in', { hasText: 'budget' }).first()).toBeVisible();
      await expect(page.locator('.text-diff .diff_out')).toHaveCount(0);

      // the first version, then "Rollback to this version"
      await page.goto(`${WIKI}/${title}/1`);
      await expect(page.locator('#content h2')).toContainText('Revision 1');
      await expect(content).not.toContainText('code freeze');
      await (await openActions(page)).getByRole('link', { name: 'Rollback to this version' }).click();
      await expect(page.locator('#content_text')).toHaveValue('# Plan\n\nShip in **December**.\n');
      await page.locator('#content_comments').fill('back to the original');
      await page.getByRole('button', { name: 'Save' }).click();
      await expect(content).not.toContainText('code freeze');
      await expect(page.locator('p.wiki-update-info')).toContainText('4 revisions');
      // nothing is erased: version 3 is still there, the developer can read it
      await developer.page.goto(`${WIKI}/${title}/3`);
      await expect(developer.page.locator('#content > div.wiki.wiki-page')).toContainText('budget approved');
    });

    await test.step('renamed by the manager: the old title redirects, the link of the issue still works', async () => {
      const { page, ui } = manager;
      // the issue links to the page; a link to a page that does not exist is marked as new
      await page.goto(`/issues/${issue.id}`);
      const link = page.locator('#issue_description_wiki a.wiki-page').first();
      await expect(link).toHaveAttribute('href', `${WIKI}/${title}`);
      await expect(page.locator('#issue_description_wiki a.wiki-page.new')).toHaveCount(1);

      // a developer may edit but not rename
      await developer.page.goto(`${WIKI}/${title}`);
      const devMenu = await openActions(developer.page);
      await expect(devMenu.getByRole('link', { name: 'History' })).toBeVisible();
      await expect(devMenu.getByRole('link', { name: 'Rename' })).toHaveCount(0);
      expect((await developer.http.get(`${WIKI}/${title}/rename`)).status()).toBe(403);

      await page.goto(`${WIKI}/${title}`);
      await (await openActions(page)).getByRole('link', { name: 'Rename' }).click();
      await page.locator('#wiki_page_title').fill(renamed);
      await expect(page.locator('#wiki_page_redirect_existing_links')).toBeChecked();
      await page.getByRole('button', { name: 'Rename' }).click();
      await ui.expectNotice('Successful update.');
      await expect(page).toHaveURL(new RegExp(`/wiki/${renamed}$`));
      expect(sql('SELECT title, redirects_to FROM wiki_redirects WHERE title = ?', [title])).toEqual([{ title, redirects_to: renamed }]);

      await page.goto(`/issues/${issue.id}`);
      await link.click();
      await expect(page).toHaveURL(new RegExp(`/wiki/${renamed}$`));
      await expect(content).toContainText('Ship in December');
      // history and watcher moved with the page
      await expect(page.locator('p.wiki-update-info')).toContainText('4 revisions');
      await reporter.page.goto(`${WIKI}/${renamed}`);
      await expect(reporter.page.locator('#content > .contextual a[class*="-watcher"]')).toHaveText(/Unwatch/);
      // the page index lists the new title only
      await page.goto(`${WIKI}/index`);
      await expect(page.locator('ul.pages-hierarchy a', { hasText: `Launch plan ${suffix}` })).toHaveCount(1);
      await expect(page.locator('ul.pages-hierarchy a', { hasText: shown })).toHaveCount(0);
    });
  });
});

test.describe('forum collaboration', () => {
  test('a topic with replies and quotes: watchers are mailed, the moderator pins and locks, counters follow', async ({ loginAs, mailbox }) => {
    test.setTimeout(180_000);
    const manager = await loginAs('jsmith');
    const developer = await loginAs('dlopper');
    const reporter = await loginAs('rhill');
    const subject = uid('Deploy window ');
    let topicId = 0;
    const board = () => sql('SELECT topics_count, messages_count, last_message_id FROM boards WHERE id = 1')[0];
    const before = board();

    await test.step('the manager watches the forum, the reporter opens a topic', async () => {
      const watch = manager.page.locator('#content .contextual a.board-1-watcher');
      await manager.page.goto('/projects/ecommerce/boards/1');
      await watch.click();
      await expect(watch).toHaveText(/Unwatch/);

      const { page } = reporter;
      await page.goto('/projects/ecommerce');
      await reporter.ui.mainMenu('boards').click(); // a single forum: opened directly
      await expect(page.locator('#content h2:visible').first()).toHaveText('General');
      await page.locator('#content .contextual a.icon-add', { hasText: 'New message' }).click();
      await expect(page.locator('#message_sticky')).toHaveCount(0); // moderators only
      await page.locator('#message_subject').fill(subject);
      await page.locator('#message_content').fill('When can we deploy the **cart** fix?');
      await page.locator('#message-form').getByRole('button', { name: 'Create' }).click();
      await expect(page.locator('#content h2:visible').first()).toContainText(subject);
      topicId = Number(page.url().match(/\/topics\/(\d+)/)![1]);
      // the author watches his topic
      await expect(page.locator(`#content .contextual a.message-${topicId}-watcher`)).toHaveText(/Unwatch/);
      expect(board()).toEqual({ topics_count: Number(before.topics_count) + 1, messages_count: Number(before.messages_count) + 1, last_message_id: topicId });

      // forum watcher: mailed; the developer watches nothing: not mailed
      const mail = await mailbox.waitFor({ to: 'jsmith@example.net', subject: new RegExp(subject) });
      expect(mail.subject).toBe(`[eCommerce - General - msg${topicId}] ${subject}`);
      expect(mail.text).toContain('When can we deploy');
      expect(mailbox.find({ to: 'dlopper@example.net', subject: new RegExp(subject) })).toHaveLength(0);
    });

    await test.step('the developer replies with a quote, the reporter answers', async () => {
      const dev = developer.page;
      await dev.goto(`/boards/1/topics/${topicId}`);
      await dev.locator('#content > [data-controller="quote-reply"] > .contextual a.icon-quote').click();
      await expect(dev.locator('#message_content')).toHaveValue(/Robert Hill wrote:\n> When can we deploy the \*\*cart\*\* fix\?/);
      await dev.locator('#message_content').press('ControlOrMeta+End');
      await dev.locator('#message_content').pressSequentially('\n\nThursday night.');
      await dev.locator('#reply').getByRole('button', { name: 'Submit' }).click();
      const replies = dev.locator('#replies .message.reply');
      await expect(replies).toHaveCount(1);
      await expect(replies.first().locator('blockquote')).toContainText('When can we deploy the cart fix?');
      const replyId = Number(sqlValue('SELECT id FROM messages WHERE parent_id = ? ORDER BY id DESC LIMIT 1', [topicId]));

      // topic watcher (its author) and forum watcher are told about the reply
      const toAuthor = await mailbox.waitFor({ to: 'rhill@example.net', body: 'Thursday night.' });
      expect(toAuthor.subject).toBe(`[eCommerce - General - msg${topicId}] RE: ${subject}`);
      await mailbox.waitFor({ to: 'jsmith@example.net', body: 'Thursday night.' });

      const rep = reporter.page;
      await rep.reload();
      await expect(rep.locator('#replies h3')).toContainText('(1)');
      // he may edit his own message, not the developer's reply
      await expect(rep.locator('#content > [data-controller="quote-reply"] > .contextual a.icon-edit')).toHaveCount(1);
      await expect(rep.locator(`#message-${replyId} a.icon-edit`)).toHaveCount(0);
      expect((await reporter.http.get(`/boards/1/topics/${replyId}/edit`)).status()).toBe(403);
      await rep.locator('#content > p a', { hasText: 'Reply' }).click();
      await rep.locator('#message_content').fill('Perfect, thanks.');
      await rep.locator('#reply').getByRole('button', { name: 'Submit' }).click();
      await expect(rep.locator('#replies .message.reply')).toHaveCount(2);
      const lastId = Number(sqlValue('SELECT MAX(id) FROM messages WHERE parent_id = ?', [topicId]));
      expect(sql('SELECT replies_count, last_reply_id FROM messages WHERE id = ?', [topicId])).toEqual([{ replies_count: 2, last_reply_id: lastId }]);
      expect(board()).toEqual({ topics_count: Number(before.topics_count) + 1, messages_count: Number(before.messages_count) + 3, last_message_id: lastId });
    });

    await test.step('the moderator pins and locks the topic: listed first, no more replies', async () => {
      const { page } = manager;
      await page.goto(`/boards/1/topics/${topicId}`);
      await page.locator('#content > [data-controller="quote-reply"] > .contextual a.icon-edit').click();
      await page.locator('#message_sticky').check();
      await page.locator('#message_locked').check();
      await page.getByRole('button', { name: 'Save' }).click();
      await expect(page.locator('#content h2:visible').first()).toContainText(subject);

      await page.goto('/projects/ecommerce/boards/1');
      const first = page.locator('table.messages tbody tr').first();
      await expect(first).toHaveClass(/sticky/);
      await expect(first).toHaveClass(/locked/);
      await expect(first.locator('td.subject')).toContainText(subject);
      await expect(first.locator('td.reply-count')).toHaveText('2');
      await expect(first.locator('td.last_message')).toContainText('Robert Hill');

      for (const user of [reporter, developer]) {
        await user.page.goto(`/boards/1/topics/${topicId}`);
        await expect(user.page.locator('#replies .message.reply')).toHaveCount(2);
        await expect(user.page.locator('#reply')).toHaveCount(0);
        await expect(user.page.locator('a.icon-quote')).toHaveCount(0);
        // a forged reply is not saved (like Redmine: validation "topic is locked", the request just redirects)
        const res = await user.http.post(`/boards/1/topics/${topicId}/replies`, { 'reply[subject]': 'RE', 'reply[content]': 'sneaky' });
        expect(res.status()).toBeLessThan(500);
      }
      expect(Number(sqlValue("SELECT COUNT(*) FROM messages WHERE content = 'sneaky'"))).toBe(0);
      // the author may still correct his own message, but he cannot unlock the topic
      await reporter.page.locator('#content > [data-controller="quote-reply"] > .contextual a.icon-edit').click();
      await expect(reporter.page.locator('#message_subject')).toHaveValue(subject);
      await expect(reporter.page.locator('#message_locked')).toHaveCount(0);
      await reporter.http.post(`/boards/1/topics/${topicId}/edit`, { 'message[locked]': '0', 'message[subject]': subject, 'message[content]': 'When can we deploy the **cart** fix?' });
      expect(sql('SELECT locked, sticky FROM messages WHERE id = ?', [topicId])).toEqual([{ locked: 1, sticky: 1 }]);
    });

    await test.step('unlocked again; the moderator removes a reply and the counters follow', async () => {
      const { page } = manager;
      await page.goto(`/boards/1/topics/${topicId}/edit`);
      await page.locator('#message_locked').uncheck();
      await page.getByRole('button', { name: 'Save' }).click();
      await expect(page.locator('#content h2:visible').first()).toContainText(subject);
      await reporter.page.goto(`/boards/1/topics/${topicId}`);
      await expect(reporter.page.locator('#content > p a', { hasText: 'Reply' })).toBeVisible();

      await page.goto(`/boards/1/topics/${topicId}`);
      const replies = page.locator('#replies .message.reply');
      await replies.filter({ hasText: 'Perfect, thanks.' }).locator('a.icon-del').click();
      await expect(replies).toHaveCount(1);
      const remaining = Number(sqlValue('SELECT id FROM messages WHERE parent_id = ?', [topicId]));
      expect(sql('SELECT replies_count, last_reply_id FROM messages WHERE id = ?', [topicId])).toEqual([{ replies_count: 1, last_reply_id: remaining }]);
      expect(board()).toEqual({ topics_count: Number(before.topics_count) + 1, messages_count: Number(before.messages_count) + 2, last_message_id: remaining });

      // the topic shows up in the activity and in the search of the project
      await page.goto('/projects/ecommerce/activity?show_messages=1');
      await expect(page.locator('#activity dt.message', { hasText: subject }).first()).toBeVisible();
      await page.goto(`/projects/ecommerce/search?q=${encodeURIComponent('Thursday night')}&messages=1`);
      await expect(page.locator('#search-results dt')).toHaveCount(1);
      await expect(page.locator('#search-results dt.reply')).toContainText(`RE: ${subject}`);
    });
  });
});
