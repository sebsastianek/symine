import { expect, type Page } from '@playwright/test';

/** Id of the issue shown on the current page (/issues/:id). */
export function issueIdFromUrl(page: Page): number {
  const m = page.url().match(/\/issues\/(\d+)/);
  if (!m) throw new Error(`Not on an issue page: ${page.url()}`);
  return Number(m[1]);
}

/** Opens the edit form of the issue page ("Edit" contextual link) and returns the update form. */
export async function openEdit(page: Page) {
  await page.locator('#content > .contextual a.icon-edit').first().click();
  const form = page.locator('#issue-form');
  await expect(form).toBeVisible();
  await expect(page.locator('#issue_notes')).toBeFocused();
  return form;
}

/** Submits the issue update form and waits for the issue page. */
export async function submitEdit(page: Page) {
  await page.locator('#issue-form input[name=commit]').click();
}

/** Value of an attribute in the issue details block (e.g. 'status', 'priority', 'assigned-to', 'cf_1'). */
export function attribute(page: Page, cssClass: string) {
  return page.locator(`.issue.details .attributes .${cssClass}.attribute .value`);
}
