import type { Page } from '@playwright/test';
import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';

/*
 * Forum administration and navigation (forums.spec.ts covers posting): several boards with a sub-forum, the forums
 * index with its counters, moving a topic to another board, editing / deleting a board, watching a board and sorting
 * the topics. Seeded (ecommerce): board 1 "General" with the topic #1 "Hello" (John Smith) and one reply (Robert Hill).
 */
const SETTINGS = '/projects/ecommerce/settings/boards';

async function createBoard(page: Page, name: string, description: string, parent?: string): Promise<number> {
  await page.goto(SETTINGS);
  await page.locator('#tab-content-boards').getByRole('link', { name: 'New forum' }).click();
  await page.locator('#board_name').fill(name);
  await page.locator('#board_description').fill(description);
  if (parent) await page.locator('#board_parent_id').selectOption({ label: parent });
  await page.getByRole('button', { name: 'Create' }).click();
  await expect(page.locator('#flash_notice')).toHaveText('Successful creation.');
  return Number(sqlValue('SELECT id FROM boards WHERE name = ?', [name]));
}

const boardRow = (page: Page, name: string) => page.locator('table.list.boards tr.board', { has: page.locator('td.name a.board', { hasText: name }) });

test.describe('forums management', () => {
  test.use({ as: 'jsmith' });

  test('several forums: validation, sub-forum, index with counters, move a topic to another forum', async ({ page, ui }) => {
    // with a single forum the "Forums" tab opens it directly
    await page.goto('/projects/ecommerce');
    await ui.mainMenu('boards').click();
    await expect(page.locator('#content h2:visible').first()).toHaveText('General');

    // validation of the board form
    await page.goto(SETTINGS);
    await page.locator('#tab-content-boards').getByRole('link', { name: 'New forum' }).click();
    await page.getByRole('button', { name: 'Create' }).click();
    await ui.expectError(/Name cannot be blank/);
    await expect(page.locator('#errorExplanation')).toContainText('Description cannot be blank');

    const support = uid('Support ');
    const billing = uid('Billing ');
    const supportId = await createBoard(page, support, 'Customer questions');
    await createBoard(page, billing, 'Invoices', support);
    expect(Number(sqlValue('SELECT parent_id FROM boards WHERE name = ?', [billing]))).toBe(supportId);

    // the tab now lists the forums: counters, last message, the sub-forum indented under its parent
    await page.goto('/projects/ecommerce');
    await ui.mainMenu('boards').click();
    await expect(page.locator('#content h2')).toHaveText('Forums');
    await expect(page.locator('table.list.boards tr.board td.name a.board')).toHaveText(['General', support, billing]);
    await expect(boardRow(page, 'General').locator('td.topic-count')).toHaveText('1');
    await expect(boardRow(page, 'General').locator('td.message-count')).toHaveText('2');
    await expect(boardRow(page, 'General').locator('td.last-message')).toContainText('Robert Hill');
    await expect(boardRow(page, support).locator('td.topic-count')).toHaveText('0');
    await expect(boardRow(page, support).locator('td.last-message')).toBeEmpty();
    await expect(boardRow(page, billing)).toHaveClass(/idnt-1/);
    await expect(boardRow(page, support).locator('p.description')).toHaveText('Customer questions');

    // the "last message" link of the index opens the topic at the reply
    await boardRow(page, 'General').locator('td.last-message a').last().click();
    await expect(page).toHaveURL(/\/boards\/1\/topics\/1(\?r=2)?#message-2$/);
    await expect(page.locator('#message-2')).toBeInViewport();

    // move the topic (and its reply) to the other forum with the edit form
    await page.locator('#content .contextual a.icon-edit').first().click();
    await expect(page.locator('#message_board_id option:checked')).toHaveText('General');
    await page.locator('#message_board_id').selectOption({ label: support });
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page).toHaveURL(new RegExp(`/boards/${supportId}/topics/1$`));
    await expect(page.locator('#content h2:visible').first()).toContainText('Hello');
    await expect(page.locator('#replies .message.reply')).toHaveCount(1);
    expect(sql('SELECT board_id FROM messages WHERE id IN (1, 2)')).toEqual([{ board_id: supportId }, { board_id: supportId }]);
    // the breadcrumb leads to the new forum; the counters moved with the topic
    await page.locator('#content p.breadcrumb a, #content h2 a').filter({ hasText: support }).first().click();
    await expect(page.locator('table.messages tbody tr')).toHaveCount(1);
    await page.goto('/projects/ecommerce/boards');
    await expect(boardRow(page, 'General').locator('td.topic-count')).toHaveText('0');
    await expect(boardRow(page, 'General').locator('td.message-count')).toHaveText('0');
    await expect(boardRow(page, support).locator('td.topic-count')).toHaveText('1');
    await expect(boardRow(page, support).locator('td.message-count')).toHaveText('2');
    // the old address of the topic no longer exists in the first forum
    expect((await page.request.get('/boards/1/topics/1', { maxRedirects: 0 })).status()).toBe(404);
  });

  test('edit a forum, reorder by drag and drop, delete a forum with its messages', async ({ page, ui }) => {
    const first = uid('Alpha ');
    const second = uid('Beta ');
    const firstId = await createBoard(page, first, 'first');
    const secondId = await createBoard(page, second, 'second');

    // edit
    await page.goto(SETTINGS);
    const rows = page.locator('#tab-content-boards .table-list-row');
    await rows.filter({ hasText: first }).locator('a.icon-edit').click();
    await expect(page.locator('#board_name')).toHaveValue(first);
    await page.locator('#board_name').fill(`${first} renamed`);
    await page.locator('#board_description').fill('first, edited');
    await page.getByRole('button', { name: 'Save' }).click();
    await ui.expectNotice('Successful update.');
    await expect(page).toHaveURL(/\/settings\/boards$/);
    await expect(rows.filter({ hasText: `${first} renamed` }).locator('.description')).toHaveText('first, edited');

    // drag the last forum above the previous one
    const handle = rows.filter({ hasText: second }).locator('.sort-handle');
    const target = rows.filter({ hasText: `${first} renamed` });
    const before = Number(sqlValue('SELECT position FROM boards WHERE id = ?', [secondId]));
    const reorder = page.waitForResponse((r) => new URL(r.url()).pathname === `/projects/ecommerce/boards/${secondId}` && r.request().method() !== 'GET');
    const hb = (await handle.boundingBox())!;
    const tb = (await target.boundingBox())!;
    await page.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2);
    await page.mouse.down();
    await page.mouse.move(hb.x + hb.width / 2, hb.y - 10, { steps: 5 });
    await page.mouse.move(hb.x + hb.width / 2, tb.y + 2, { steps: 15 });
    await page.mouse.up();
    expect((await reorder).status()).toBeLessThan(400);
    await expect.poll(() => Number(sqlValue('SELECT position FROM boards WHERE id = ?', [secondId]))).toBe(before - 1);
    await page.goto('/projects/ecommerce/boards');
    const names = await page.locator('table.list.boards td.name a.board').allTextContents();
    expect(names.map((n) => n.trim()).indexOf(second)).toBeLessThan(names.map((n) => n.trim()).indexOf(`${first} renamed`));

    // a topic in the forum, then delete the forum: its messages go with it
    const subject = uid('Doomed topic ');
    await page.goto(`/projects/ecommerce/boards/${firstId}`);
    await ui.contextual('New message').click();
    await page.locator('#message_subject').fill(subject);
    await page.locator('#message_content').fill('will be removed with the forum');
    await page.locator('#message-form').getByRole('button', { name: 'Create' }).click();
    await expect(page.locator('#content h2:visible').first()).toContainText(subject);

    await page.goto(SETTINGS);
    await rows.filter({ hasText: `${first} renamed` }).locator('a.icon-del').click(); // confirm() accepted
    await expect(page).toHaveURL(/\/settings\/boards$/);
    await expect(rows.filter({ hasText: `${first} renamed` })).toHaveCount(0);
    expect(Number(sqlValue('SELECT COUNT(*) FROM boards WHERE id = ?', [firstId]))).toBe(0);
    expect(Number(sqlValue('SELECT COUNT(*) FROM messages WHERE subject = ?', [subject]))).toBe(0);
    await page.goto('/projects/ecommerce/boards');
    await expect(page.locator('table.list.boards')).not.toContainText(`${first} renamed`);
  });

  test('watch a forum: ajax toggle and a mail for new topics; topics sort by replies', async ({ page, loginAs, mailbox }) => {
    const boardId = await createBoard(page, uid('Watched '), 'watch me');
    await page.goto(`/projects/ecommerce/boards/${boardId}`);
    const watch = page.locator(`#content > .contextual a.board-${boardId}-watcher`);
    await expect(watch).toHaveText('Watch');
    await watch.click();
    await expect(watch).toHaveText('Unwatch');
    await expect.poll(() => Number(sqlValue("SELECT COUNT(*) FROM watchers WHERE watchable_type = 'Board' AND watchable_id = ? AND user_id = 5", [boardId]))).toBe(1);

    // message notifications are off by default: the administrator enables them
    const admin = await loginAs('admin');
    await admin.page.goto('/settings?tab=notifications');
    await admin.page.locator('input[name="settings[notified_events][]"][value="message_posted"]').check();
    await admin.page.locator('#tab-content-notifications input[type=submit][value="Save"]').click();
    await admin.ui.expectNotice('Successful update.');
    sql("UPDATE users SET mail_notification = 'only_my_events' WHERE id = 5");

    // a reporter posts two topics, the manager replies to the first one
    const reporter = await loginAs('rhill');
    const quiet = uid('Quiet topic ');
    const busy = uid('Busy topic ');
    for (const subject of [busy, quiet]) {
      await reporter.page.goto(`/projects/ecommerce/boards/${boardId}`);
      await reporter.page.locator('#content > .contextual a.icon-add').click();
      await reporter.page.locator('#message_subject').fill(subject);
      await reporter.page.locator('#message_content').fill(`About ${subject}`);
      await reporter.page.locator('#message-form').getByRole('button', { name: 'Create' }).click();
      await expect(reporter.page.locator('#content h2:visible').first()).toContainText(subject);
    }
    const mail = await mailbox.waitFor({ to: 'jsmith@example.net', subject: new RegExp(busy) });
    expect(mail.text).toContain(`About ${busy}`);
    await mailbox.waitFor({ to: 'jsmith@example.net', subject: new RegExp(quiet) });

    await page.goto(`/projects/ecommerce/boards/${boardId}`);
    await page.locator('table.messages td.subject a', { hasText: busy }).click();
    await page.locator('#content > p a', { hasText: 'Reply' }).click();
    await page.locator('#message_content').fill('An answer.');
    await page.locator('#reply').getByRole('button', { name: 'Submit' }).click();
    await expect(page.locator('#replies .message.reply')).toHaveCount(1);

    // default order: last message first; sorting by replies (asc, then desc) reorders the topics
    await page.goto(`/projects/ecommerce/boards/${boardId}`);
    const subjects = page.locator('table.messages tbody td.subject a');
    await expect(subjects).toHaveText([busy, quiet]);
    await expect(page.locator('table.messages tr', { hasText: busy }).locator('td.reply-count')).toHaveText('1');
    await page.locator('table.messages thead th a', { hasText: 'Replies' }).click();
    await expect(subjects).toHaveText([quiet, busy]);
    await page.locator('table.messages thead th a', { hasText: 'Replies' }).click();
    await expect(subjects).toHaveText([busy, quiet]);

    // unwatch
    await page.locator(`#content > .contextual a.board-${boardId}-watcher`).click();
    await expect(page.locator(`#content > .contextual a.board-${boardId}-watcher`)).toHaveText('Watch');
  });
});
