import * as fs from 'fs';
import type { Download, Page } from '@playwright/test';
import { test, expect } from '../../support/fixtures';
import { applyQuery } from './_helpers';

async function readDownload(download: Download): Promise<string> {
  const file = await download.path();
  return fs.readFileSync(file!, 'utf8').replace(/^﻿/, '');
}

/** Very small CSV parser (quoted fields, given separator). */
function parseCsv(text: string, sep = ','): string[][] {
  const rows: string[][] = [];
  let row: string[] = [];
  let field = '';
  let quoted = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (quoted) {
      if (c === '"' && text[i + 1] === '"') { field += '"'; i++; }
      else if (c === '"') quoted = false;
      else field += c;
    } else if (c === '"') quoted = true;
    else if (c === sep) { row.push(field); field = ''; }
    else if (c === '\n' || c === '\r') {
      if (c === '\r' && text[i + 1] === '\n') i++;
      row.push(field); rows.push(row); row = []; field = '';
    } else field += c;
  }
  if (field !== '' || row.length) { row.push(field); rows.push(row); }
  return rows;
}

async function openCsvDialog(page: Page): Promise<void> {
  await page.locator('p.other-formats a.csv').click();
  await expect(page.locator('.ui-dialog #csv-export-options')).toBeVisible();
  await expect(page.locator('.ui-dialog .ui-dialog-title, .ui-dialog h3.title').first()).toContainText('CSV export options');
}

test.describe('issues CSV export dialog', () => {
  test.use({ as: 'admin', dbReset: 'none' });

  test('exports the selected columns of the filtered list', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues?set_filter=1');
    await page.locator('#add_filter_select').selectOption('tracker_id');
    await page.locator('#values_tracker_id_1').selectOption('2');
    await applyQuery(page);

    await openCsvDialog(page);
    await expect(page.locator('#csv-export-options input#c__')).toBeChecked(); // "Selected Columns"
    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.locator('#csv-export-options input[type=submit]').click(),
    ]);
    expect(download.suggestedFilename()).toMatch(/\.csv$/);
    await expect(page.locator('.ui-dialog #csv-export-options')).toBeHidden();

    const rows = parseCsv(await readDownload(download)).filter((r) => r.length > 1);
    expect(rows[0]).toEqual(['#', 'Tracker', 'Status', 'Priority', 'Subject', 'Assignee', 'Updated']);
    expect(rows.slice(1).map((r) => r[0]).sort()).toEqual(['14', '15', '16']);
    const wishlist = rows.find((r) => r[0] === '14')!;
    expect(wishlist.slice(1, 5)).toEqual(['Feature', 'New', 'Urgent', 'Wishlist']);
  });

  test('all columns + description, semicolon separator', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues?set_filter=1');
    await openCsvDialog(page);
    await page.locator('#csv-export-options input#c__all_inline').check();
    await page.locator('#csv-export-block-columns input[value="description"]').check();
    await page.locator('#csv-export-options #field_separator').selectOption(';');
    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.locator('#csv-export-options input[type=submit]').click(),
    ]);
    const rows = parseCsv(await readDownload(download), ';').filter((r) => r.length > 1);
    const header = rows[0];
    for (const col of ['#', 'Project', 'Tracker', 'Subject', 'Author', 'Estimated time', 'Database', 'Description']) {
      expect(header, `column ${col}`).toContain(col);
    }
    expect(rows).toHaveLength(6); // header + 5 open issues
    const db = header.indexOf('Database');
    expect(rows.find((r) => r[0] === '13')![db]).toBe('PostgreSQL');
  });

  test('Cancel closes the dialog without download', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues?set_filter=1');
    await openCsvDialog(page);
    await page.locator('#csv-export-options a', { hasText: 'Cancel' }).click();
    await expect(page.locator('.ui-dialog #csv-export-options')).toBeHidden();
  });
});
