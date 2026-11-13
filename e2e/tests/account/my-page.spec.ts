import { test, expect } from '../../support/fixtures';
import { sqlValue } from '../../support/db';
import type { Locator, Page } from '@playwright/test';

function layout(userId: number): Record<string, string[]> {
  const others = JSON.parse(String(sqlValue('SELECT others FROM user_preferences WHERE user_id = ?', [userId])));
  return others.my_page_layout;
}

function blockIds(list: Locator): Promise<string[]> {
  return list.locator('> .mypage-box').evaluateAll((els) => els.map((e) => e.id.replace(/^block-/, '')));
}

/** jQuery UI sortable needs real mouse moves (mousedown, several mousemoves, mouseup). */
async function dragBlock(page: Page, handle: Locator, target: Locator): Promise<void> {
  const from = (await handle.boundingBox())!;
  const to = (await target.boundingBox())!;
  await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
  await page.mouse.down();
  await page.mouse.move(from.x + 20, from.y + 20, { steps: 5 });
  await page.mouse.move(to.x + to.width / 2, to.y + 10, { steps: 15 });
  await expect(page.locator('.ui-sortable-placeholder')).toBeAttached();
  await page.mouse.up();
}

test.describe('My page', () => {
  test.use({ as: 'jsmith' });

  test('default blocks list my issues', async ({ page, ui }) => {
    await page.goto('/my/page');
    await expect(ui.heading()).toHaveText('My page');
    await expect(page.locator('#list-left #block-issuesassignedtome')).toBeVisible();
    await expect(page.locator('#list-right #block-issuesreportedbyme')).toBeVisible();
    // jsmith reported #13 (assigned to the Developers group he belongs to)
    await expect(page.locator('#block-issuesreportedbyme')).toContainText('Cart total is wrong with discounts');
    // blocks already shown are disabled in the "Add" select
    await expect(page.locator('#block-select option', { hasText: 'Issues assigned to me' })).toBeDisabled();
  });

  test('add a block through the "Add" select (AJAX)', async ({ page }) => {
    await page.goto('/my/page');
    await page.locator('#block-select').selectOption('news');
    const news = page.locator('#list-top #block-news');
    await expect(news).toBeVisible();
    await expect(news.locator('h3')).toContainText('Latest news');
    await expect(page.locator('#block-select option', { hasText: 'Latest news' })).toBeDisabled();
    await expect.poll(() => layout(5).top).toEqual(['news']);
    await page.reload();
    await expect(page.locator('#list-top #block-news')).toBeVisible();

    await page.locator('#block-select').selectOption('documents');
    await expect(page.locator('#list-top > .mypage-box').first()).toHaveId('block-documents');
    await expect.poll(() => layout(5).top).toEqual(['documents', 'news']);
  });

  test('remove a block', async ({ page }) => {
    await page.goto('/my/page');
    await page.locator('#block-news .contextual a.icon-close').click();
    await expect(page.locator('#block-news')).toHaveCount(0);
    await expect(page.locator('#block-select option', { hasText: 'Latest news' })).toBeEnabled();
    await expect.poll(() => layout(5).top).toEqual(['documents']);
    await page.reload();
    await expect(page.locator('#block-news')).toHaveCount(0);
    await expect(page.locator('#block-documents')).toBeVisible();
  });

  test('drag and drop a block to another column and reorder', async ({ page }) => {
    await page.goto('/my/page');
    const left = page.locator('#list-left');
    // move "Reported issues" from the right column on top of the left one
    await dragBlock(page, page.locator('#block-issuesreportedbyme .sort-handle'), page.locator('#block-issuesassignedtome'));
    await expect(page.locator('#list-left #block-issuesreportedbyme')).toBeVisible();
    await expect.poll(() => layout(5).left).toContain('issuesreportedbyme');
    const order = await blockIds(left);
    expect(order).toHaveLength(2);
    expect(order[0]).toBe('issuesreportedbyme'); // dropped above "Issues assigned to me"
    await expect.poll(() => layout(5).left).toEqual(order);
    await expect.poll(() => layout(5).right ?? []).toEqual([]);

    await page.reload();
    expect(await blockIds(page.locator('#list-left'))).toEqual(order);
    await expect(page.locator('#list-right > .mypage-box')).toHaveCount(0);
  });
});
