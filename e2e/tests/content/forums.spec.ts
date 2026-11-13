import { test, expect, uid } from '../../support/fixtures';
import { sqlValue } from '../../support/db';
import { textFile, uploadAttachment } from './_helpers';

test.describe('forums', () => {
  test.use({ as: 'jsmith' });

  test('manager creates a board in the project settings', async ({ page, ui }) => {
    const name = uid('Board ');
    await page.goto('/projects/ecommerce/settings/boards');
    await expect(page.locator('#tab-content-boards .table-list.boards')).toContainText('General');
    await page.locator('#tab-content-boards').getByRole('link', { name: 'New forum' }).click();
    await page.locator('#board_name').fill(name);
    await page.locator('#board_description').fill('Board for e2e');
    await page.getByRole('button', { name: 'Create' }).click();
    await ui.expectNotice('Successful creation.');
    await expect(page).toHaveURL(/\/settings\/boards$/);
    await expect(page.locator('#tab-content-boards .table-list-row', { hasText: name })).toBeVisible();

    // listed in the forums index
    await page.goto('/projects/ecommerce/boards');
    await expect(page.locator('table.boards, .boards', { hasText: name }).first()).toBeVisible();
  });

  test('topic: create sticky+locked, reply, quote, edit, delete', async ({ page, ui, loginAs }) => {
    const subject = uid('Topic ');
    await page.goto('/projects/ecommerce/boards/1');
    await expect(page.locator('#content h2:visible').first()).toHaveText('General');

    await ui.contextual('New message').click();
    await expect(page.locator('#add-message')).toBeVisible();
    await page.locator('#message_subject').fill(subject);
    await page.locator('#message_content').fill('First **post** of the topic.');
    await page.locator('#message_sticky').check();
    await uploadAttachment(page.locator('#message-form'), textFile('topic.txt'));
    await page.locator('#message-form').getByRole('button', { name: 'Create' }).click();

    await expect(page.locator('#content h2:visible').first()).toContainText(subject);
    await expect(page.locator('#message_topic_wiki strong')).toHaveText('post');
    await expect(page.locator('.message .attachments a', { hasText: 'topic.txt' }).first()).toBeVisible();
    const topicId = Number(page.url().match(/\/topics\/(\d+)/)![1]);
    expect(Number(sqlValue('SELECT sticky FROM messages WHERE id = ?', [topicId]))).toBe(1);

    // sticky topic is listed first with the sticky class
    await page.goto('/projects/ecommerce/boards/1');
    const first = page.locator('table.messages tbody tr').first();
    await expect(first).toHaveClass(/sticky/);
    await expect(first).toContainText(subject);
    await first.getByRole('link', { name: subject }).click();

    // reply
    await page.locator('#content > p a', { hasText: 'Reply' }).click();
    await expect(page.locator('#reply')).toBeVisible();
    await expect(page.locator('#message_subject')).toHaveValue(`RE: ${subject}`);
    await page.locator('#message_content').fill('A plain reply.');
    await page.locator('#reply').getByRole('button', { name: 'Submit' }).click();
    const replies = page.locator('#replies .message.reply');
    await expect(replies).toHaveCount(1);
    await expect(replies.first()).toContainText('A plain reply.');
    await expect(page.locator('#replies h3')).toContainText('(1)');

    // quote the topic: ajax call fills the reply form
    await page.locator('#content > [data-controller="quote-reply"] > .contextual a.icon-quote').click();
    await expect(page.locator('#reply')).toBeVisible();
    await expect(page.locator('#message_content')).toHaveValue(/John Smith wrote:\n> First \*\*post\*\* of the topic\./);
    await expect(page.locator('#message_subject')).toHaveValue(`RE: ${subject}`);
    await page.locator('#message_content').press('End');
    await page.locator('#message_content').pressSequentially('\n\nAgreed.');
    await page.locator('#reply').getByRole('button', { name: 'Submit' }).click();
    await expect(replies).toHaveCount(2);
    await expect(replies.nth(1).locator('blockquote')).toContainText('First post of the topic.');
    await expect(replies.nth(1)).toContainText('Agreed.');

    // quote a reply (icon in the reply header)
    await replies.first().locator('a.icon-quote').click();
    await expect(page.locator('#message_content')).toHaveValue(/John Smith wrote in message#\d+:\n> A plain reply\./);

    // lock the topic through edit; a reporter can no longer reply
    await page.locator('#content .contextual a.icon-edit').first().click();
    await expect(page.locator('#message_subject')).toHaveValue(subject);
    await page.locator('#message_locked').check();
    await page.locator('#message_subject').fill(`${subject} (edited)`);
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('#content h2:visible').first()).toContainText(`${subject} (edited)`);
    expect(Number(sqlValue('SELECT locked FROM messages WHERE id = ?', [topicId]))).toBe(1);

    const reporter = await loginAs('rhill');
    await reporter.page.goto(page.url());
    await expect(reporter.page.locator('#content h2:visible').first()).toContainText(subject);
    await expect(reporter.page.locator('#reply')).toHaveCount(0);
    await expect(reporter.page.locator('a.icon-quote')).toHaveCount(0);

    // delete a reply then the topic
    await replies.first().locator('a.icon-del').click();
    await expect(replies).toHaveCount(1);
    await page.locator('#content .contextual a.icon-del').first().click();
    await expect(page).toHaveURL(/\/boards\/1$/);
    await expect(page.locator('table.messages', { hasText: subject })).toHaveCount(0);
    expect(Number(sqlValue('SELECT COUNT(*) FROM messages WHERE id = ? OR parent_id = ?', [topicId, topicId]))).toBe(0);
  });

  test('reporter can post a topic and edit only his own messages', async ({ loginAs }) => {
    const { page } = await loginAs('rhill');
    const subject = uid('Reporter topic ');
    await page.goto('/projects/ecommerce/boards/1');
    await page.locator('#content > .contextual a.icon-add').click();
    // no sticky/locked flags without manage_messages
    await expect(page.locator('#message_sticky')).toHaveCount(0);
    await page.locator('#message_subject').fill(subject);
    await page.locator('#message_content').fill('question');
    await page.locator('#message-form').getByRole('button', { name: 'Create' }).click();
    await expect(page.locator('#content h2:visible').first()).toContainText(subject);
    await expect(page.locator('#content .contextual a.icon-edit').first()).toBeVisible();
    // the seeded topic "Hello" belongs to someone else
    await page.goto('/boards/1/topics/1');
    await expect(page.locator('#content h2:visible').first()).toContainText('Hello');
    await expect(page.locator('#content > [data-controller="quote-reply"] > .contextual a.icon-edit')).toHaveCount(0);
  });
});
