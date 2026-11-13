import { test, expect, uid } from '../../support/fixtures';
import { sqlValue } from '../../support/db';
import { textFile, uploadAttachment } from './_helpers';

const NEWS = '/projects/ecommerce/news';

test.describe('news', () => {
  test.use({ as: 'jsmith' });

  test('create news with attachment, comment, delete comment, edit, delete', async ({ page, ui }) => {
    const title = uid('News ');
    await page.goto(NEWS);
    await expect(page.locator('#add-news')).toBeHidden();
    await ui.contextual('Add news').click();
    await expect(page.locator('#add-news')).toBeVisible();
    await expect(page.locator('#news_title')).toBeFocused();

    // validation error keeps the form
    await page.locator('#news-form').getByRole('button', { name: 'Create' }).click();
    await ui.expectError(/Title cannot be blank/);

    await page.locator('#news_title').fill(title);
    await page.locator('#news_summary').fill('A short summary');
    await page.locator('#news_description').fill('The **full** story.');
    await uploadAttachment(page.locator('#news-form'), textFile('press-release.txt'));
    await page.locator('#news-form').getByRole('button', { name: 'Create' }).click();

    await ui.expectNotice('Successful creation.');
    await expect(page).toHaveURL(new RegExp(`${NEWS}$`));
    const article = page.locator('#news-list article', { hasText: title });
    await expect(article.locator('.wiki strong')).toHaveText('full');

    // show
    await article.getByRole('link', { name: title }).click();
    await expect(page.locator('#content h2')).toContainText(title);
    await expect(page.locator('.news em').first()).toHaveText('A short summary');
    await expect(page.locator('.news .attachments a', { hasText: 'press-release.txt' }).first()).toBeVisible();

    // add a comment
    await page.getByRole('link', { name: 'Add a comment' }).click();
    await expect(page.locator('#add_comment_form')).toBeVisible();
    await page.locator('#comment_comments').fill('Great news!');
    await page.locator('#add_comment_form').getByRole('button', { name: 'Add' }).click();
    await ui.expectNotice('Comment added');
    const comment = page.locator('#comments .message.reply', { hasText: 'Great news!' });
    await expect(comment).toBeVisible();
    await expect(comment.locator('.journal-info')).toContainText('John Smith');
    const newsId = Number(page.url().match(/\/news\/(\d+)/)![1]);
    expect(Number(sqlValue("SELECT comments_count FROM news WHERE id = ?", [newsId]))).toBe(1);

    // delete the comment
    await comment.locator('a.icon-del').click();
    await expect(page.locator('#comments .message.reply')).toHaveCount(0);

    // edit (inline form)
    await page.locator('#content > .contextual a.icon-edit').click();
    await expect(page.locator('#edit-news')).toBeVisible();
    await page.locator('#edit-news #news_title').fill(`${title} (updated)`);
    await page.locator('#edit-news').getByRole('button', { name: 'Save' }).click();
    await ui.expectNotice('Successful update.');
    await expect(page.locator('#content h2')).toContainText(`${title} (updated)`);

    // delete
    await page.locator('#content > .contextual a.icon-del').click();
    await expect(page).toHaveURL(new RegExp(`${NEWS}$`));
    await expect(page.locator('#news-list article', { hasText: title })).toHaveCount(0);
    expect(Number(sqlValue('SELECT COUNT(*) FROM news WHERE id = ?', [newsId]))).toBe(0);
  });

  test('news is listed globally and readable anonymously, without management links', async ({ browser, page }) => {
    await page.goto('/news');
    await expect(page.locator('#news-list article', { hasText: 'Release 0.9' })).toBeVisible();

    // explicit empty state: browser.newContext() inherits the test's storageState (jsmith)
    const ctx = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const anon = await ctx.newPage();
    await anon.goto(NEWS);
    await expect(anon.locator('#news-list article', { hasText: 'Release 0.9' })).toBeVisible();
    await expect(anon.locator('a.add-news-link')).toHaveCount(0);
    await anon.locator('#news-list a', { hasText: 'Release 0.9' }).click();
    await expect(anon.locator('#content > .contextual a.icon-edit')).toHaveCount(0);
    await expect(anon.locator('#add_comment_form')).toHaveCount(0);
    await ctx.close();
  });
});
