/**
 * PDF / PNG exports, end to end: the "Also available in:" links of the issue page, issue list, gantt and wiki
 * produce real documents (download with Redmine's filename, %PDF header, page count; PNG signature and size).
 * Works against Symine and the reference Redmine (E2E_BASE_URL=http://localhost:3300) - read-only.
 */
import * as fs from 'fs';
import { test, expect } from '../../support/fixtures';
import type { Download, Page } from '@playwright/test';

function pdfPages(buf: Buffer): number {
  return (buf.toString('latin1').match(/\/Type\s*\/Page\b(?!s)/g) || []).length;
}

/** Clicks the "Also available in" link and returns the downloaded file */
async function downloadFrom(page: Page, linkClass: string): Promise<{ download: Download; body: Buffer }> {
  const link = page.locator(`p.other-formats a.${linkClass}`);
  await expect(link).toBeVisible();
  const [download] = await Promise.all([page.waitForEvent('download'), link.click()]);
  const file = await download.path();
  return { download, body: fs.readFileSync(file) };
}

function expectPdf(body: Buffer, pages?: number): void {
  expect(body.subarray(0, 5).toString('latin1')).toBe('%PDF-');
  expect(body.toString('latin1')).toContain('%%EOF');
  if (pages !== undefined) expect(pdfPages(body)).toBe(pages);
  else expect(pdfPages(body)).toBeGreaterThan(0);
}

test.describe('PDF exports as jsmith', () => {
  test.use({ as: 'jsmith', dbReset: 'none' });

  test('issue page: PDF link downloads the issue', async ({ page }) => {
    await page.goto('/issues/13');
    await expect(page.locator('p.other-formats')).toContainText('PDF');
    const { download, body } = await downloadFrom(page, 'pdf');
    expect(download.suggestedFilename()).toBe('ecommerce-13.pdf');
    expectPdf(body, 1);
  });

  test('issue list: PDF link keeps the query (columns, grouping, totals)', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues?set_filter=1&f[]=status_id&op[status_id]=*&c[]=subject&c[]=estimated_hours&c[]=description&group_by=status&t[]=estimated_hours');
    const link = page.locator('p.other-formats a.pdf');
    await expect(link).toHaveAttribute('href', /\/projects\/ecommerce\/issues\.pdf\?.*group_by=status/);
    const { download, body } = await downloadFrom(page, 'pdf');
    expect(download.suggestedFilename()).toBe('issues.pdf');
    expectPdf(body);
    // landscape A4
    expect(body.toString('latin1')).toMatch(/\/MediaBox \[0\.0+ 0\.0+ 841\.89\d* 595\.27\d*\]/);
  });

  test('issue list: CSV export dialog still opens next to the PDF link', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues');
    await page.locator('p.other-formats a.csv').click();
    await expect(page.locator('#csv-export-options')).toBeVisible();
  });

  test('gantt: PDF and PNG links', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues/gantt?year=2026&month=11&months=6');
    const pdf = await downloadFrom(page, 'pdf');
    expect(pdf.download.suggestedFilename()).toBe('ecommerce-gantt.pdf');
    expectPdf(pdf.body, 1);

    const png = page.locator('p.other-formats a.png');
    await expect(png).toHaveAttribute('href', /gantt\.png\?.*months=6/);
    const [response] = await Promise.all([page.waitForResponse(/gantt\.png/), png.click()]);
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toBe('image/png');
    expect(response.headers()['content-disposition']).toContain('filename="ecommerce-gantt.png"');
    const img = await response.body();
    expect(img.subarray(0, 8).toString('latin1')).toBe('\x89PNG\r\n\x1a\n');
    // IHDR width: subject width 400 + 181 days * 4 px + 1
    expect(img.readUInt32BE(16)).toBe(400 + 181 * 4 + 1);
  });

  test('wiki: page PDF and whole wiki export', async ({ page }) => {
    await page.goto('/projects/ecommerce/wiki/Checkout_flow');
    const one = await downloadFrom(page, 'pdf');
    expect(one.download.suggestedFilename()).toBe('Checkout_flow.pdf');
    expectPdf(one.body, 1);

    await page.goto('/projects/ecommerce/wiki/index');
    const all = await downloadFrom(page, 'pdf');
    expect(all.download.suggestedFilename()).toBe('ecommerce.pdf');
    expectPdf(all.body, 2);
  });
});

test.describe('without export permission', () => {
  test.use({ as: 'dlopper', dbReset: 'none' });

  test('Developer has no wiki PDF link and gets 403 on the wiki export', async ({ page }) => {
    await page.goto('/projects/ecommerce/wiki/Checkout_flow');
    await expect(page.locator('#content')).toBeVisible();
    await expect(page.locator('a.pdf')).toHaveCount(0);
    const res = await page.request.get('/projects/ecommerce/wiki/export.pdf');
    expect(res.status()).toBe(403);
  });
});
