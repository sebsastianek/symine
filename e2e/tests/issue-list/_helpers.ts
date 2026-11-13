/** Helpers shared by the issue-list and timelog specs (query forms). */
import type { Locator, Page } from '@playwright/test';
import { expect } from '../../support/fixtures';

/**
 * Clicks "Apply" of the query form and waits for the reloaded list.
 * (ui.applyFilters() waits for /set_filter=1/, which already matches after a first apply.)
 */
export async function applyQuery(page: Page): Promise<void> {
  const nav = page.waitForResponse((r) => r.request().isNavigationRequest() && r.request().frame() === page.mainFrame());
  await page.locator('#query_form p.buttons a.icon-checked').click();
  await nav;
  await page.waitForLoadState('domcontentloaded');
}

/** Clicks a link that navigates (headers, pagination...) and waits for the new document. */
export async function clickAndWait(page: Page, link: Locator): Promise<void> {
  const nav = page.waitForResponse((r) => r.request().isNavigationRequest() && r.request().frame() === page.mainFrame());
  await link.click();
  await nav;
  await page.waitForLoadState('domcontentloaded');
}

/** Ids of the issues rows currently listed, in display order. */
export async function listedIssueIds(page: Page): Promise<number[]> {
  const ids = await page.locator('table.list.issues tbody tr.issue').evaluateAll((rows) => rows.map((r) => Number(r.id.replace('issue-', ''))));
  return ids;
}

export async function expectIssues(page: Page, ids: number[], opts: { ordered?: boolean } = {}): Promise<void> {
  await expect
    .poll(async () => {
      const got = await listedIssueIds(page);
      return opts.ordered ? got : [...got].sort((a, b) => a - b);
    })
    .toEqual(opts.ordered ? ids : [...ids].sort((a, b) => a - b));
}

/** Opens the "Options" fieldset of the query form. */
export async function openOptions(page: Page): Promise<void> {
  const fs = page.locator('fieldset#options');
  if ((await fs.getAttribute('class'))?.includes('collapsed')) await fs.locator('> legend').click();
  await expect(fs).not.toHaveClass(/collapsed/);
}

/** Moves columns between the available/selected selects with the arrow buttons. */
export async function addColumns(page: Page, values: string[]): Promise<void> {
  await page.locator('#available_c').selectOption(values);
  await page.locator('button.move-right').click();
  for (const v of values) await expect(page.locator(`#selected_c option[value="${v}"]`)).toHaveCount(1);
}

export async function removeColumns(page: Page, values: string[]): Promise<void> {
  await page.locator('#selected_c').selectOption(values);
  await page.locator('button.move-left').click();
  for (const v of values) await expect(page.locator(`#available_c option[value="${v}"]`)).toHaveCount(1);
}

/** Header labels of the list table (without checkbox/buttons columns). */
export async function headerColumns(page: Page): Promise<string[]> {
  return page.locator('table.list thead th').evaluateAll((ths) =>
    ths.map((th) => th.className.split(' ')[0]).filter((c) => c && c !== 'checkbox' && c !== 'buttons'),
  );
}
