import { test, expect } from '../../support/fixtures';
import { sql } from '../../support/db';
import { clickAndWait, listedIssueIds } from './_helpers';

// Small page sizes so the 5 open issues of ecommerce span several pages (restored by the next file's DB reset).
test.describe('issue list pagination', () => {
  test.use({ as: 'admin' });

  test.beforeEach(() => {
    const updated = sql("UPDATE settings SET value = '2,3' WHERE name = 'per_page_options' RETURNING id");
    if (updated.length === 0) sql("INSERT INTO settings (name, value, updated_on) VALUES ('per_page_options', '2,3', datetime('now'))");
  });

  test('pages and per-page links', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues?set_filter=1');
    const pagination = page.locator('span.pagination').first();
    await expect(pagination.locator('.items')).toHaveText('(1-2/5)');
    expect(await listedIssueIds(page)).toEqual([17, 16]);
    await expect(pagination.locator('.per-page .selected')).toHaveText('2');

    await clickAndWait(page, pagination.locator('ul.pages a', { hasText: '2' }).first());
    await expect(page.locator('span.pagination .items').first()).toHaveText('(3-4/5)');
    expect(await listedIssueIds(page)).toEqual([15, 14]);

    await clickAndWait(page, page.locator('span.pagination li.next a').first());
    await expect(page.locator('span.pagination .items').first()).toHaveText('(5-5/5)');
    expect(await listedIssueIds(page)).toEqual([13]);

    await clickAndWait(page, page.locator('span.pagination li.previous a').first());
    await expect(page.locator('span.pagination .items').first()).toHaveText('(3-4/5)');

    // per page: 3
    await clickAndWait(page, page.locator('span.pagination .per-page a', { hasText: '3' }).first());
    await expect(page.locator('span.pagination .items').first()).toHaveText('(1-3/5)');
    await expect(page.locator('span.pagination .per-page .selected').first()).toHaveText('3');
    expect(await listedIssueIds(page)).toEqual([17, 16, 15]);
  });
});
