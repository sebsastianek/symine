/** Helpers shared by the multi-actor scenario specs. */
import * as fs from 'fs';
import { expect, type Browser, type BrowserContext, type Locator, type Page } from '@playwright/test';
import { sql } from '../../support/db';

/** Id of the issue shown on the current page (/issues/:id). */
export function issueIdFromUrl(page: Page): number {
  const m = page.url().match(/\/issues\/(\d+)/);
  if (!m) throw new Error(`Not on an issue page: ${page.url()}`);
  return Number(m[1]);
}

/** Opens the update form of an issue page ("Edit" contextual link) and returns the form. */
export async function openEdit(page: Page): Promise<Locator> {
  await page.locator('#content > .contextual a.icon-edit').first().click();
  const form = page.locator('#issue-form');
  await expect(form).toBeVisible();
  await expect(page.locator('#issue_notes')).toBeVisible();
  return form;
}

/** Submits the issue form (new or update). */
export async function submitIssue(page: Page): Promise<void> {
  await page.locator('#issue-form input[name=commit]').click();
}

/** Value of an attribute of the issue details block (e.g. 'status', 'priority', 'assigned-to', 'cf_1'). */
export function attribute(page: Page, cssClass: string): Locator {
  return page.locator(`.issue.details .attributes .${cssClass}.attribute .value`);
}

/** Labels of the statuses offered by the issue form. */
export async function statusOptions(page: Page): Promise<string[]> {
  return (await page.locator('#issue_status_id option').allTextContents()).map((o) => o.trim());
}

/**
 * Changes a select whose `onchange` refreshes the form through ajax (updateIssueFrom / updateBulkEditFrom) and waits
 * until the response was applied: the select itself is replaced by the refresh, so a marker set on the old element
 * disappears. Fields filled before that would be lost with the old form.
 */
export async function selectAndRefresh(page: Page, selector: string, option: string | { label: string }): Promise<void> {
  const select = page.locator(selector);
  await select.evaluate((el) => el.setAttribute('data-e2e-stale', '1'));
  await select.selectOption(option);
  await expect(page.locator(`${selector}:not([data-e2e-stale])`)).toHaveCount(1);
  if (typeof option === 'string') await expect(select).toHaveValue(option);
  else await expect(select.locator('option:checked')).toHaveText(option.label);
}

/** Changes the status on the issue form and waits for the ajax refresh of the form. */
export function selectStatus(page: Page, label: string): Promise<void> {
  return selectAndRefresh(page, '#issue_status_id', { label });
}

/** The journal of the issue history containing `text`. */
export function journal(page: Page, text: string | RegExp): Locator {
  return page.locator('#history .journal').filter({ hasText: text });
}

/** Upserts a setting directly in the e2e DB (the pristine values come back with the next spec file). */
export function setSetting(name: string, value: string): void {
  if (sql('SELECT id FROM settings WHERE name = ?', [name]).length > 0) {
    sql("UPDATE settings SET value = ?, updated_on = datetime('now') WHERE name = ?", [value, name]);
  } else {
    sql("INSERT INTO settings (name, value, updated_on) VALUES (?, ?, datetime('now'))", [name, value]);
  }
}

/**
 * Anonymous browser context + page (browser.newContext() inherits the storageState of `test.use({ as })`, so it is
 * reset explicitly). The web debug toolbar is stubbed out and confirm() dialogs are accepted, like the fixtures do.
 */
export async function anonymousPage(browser: Browser): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  await context.route(/\/_wdt\//, (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '' }));
  const page = await context.newPage();
  page.on('dialog', (d) => void d.accept().catch(() => undefined));
  return { context, page };
}

/** Logs in through the real login form (the page must belong to a context without a stored session). */
export async function loginViaForm(page: Page, login: string, password: string, opts: { expectSuccess?: boolean } = {}): Promise<void> {
  if (!/\/login/.test(page.url())) await page.goto('/login');
  await page.locator('#username').fill(login);
  await page.locator('#password').fill(password);
  await page.locator('#login-submit').click();
  if (opts.expectSuccess !== false) await expect(page.locator('#account .user-login')).toHaveText(`@${login}`);
}

/** Clicks something that navigates and waits for the new document of the main frame. */
export async function clickAndWait(page: Page, target: Locator): Promise<void> {
  const nav = page.waitForResponse((r) => r.request().isNavigationRequest() && r.request().frame() === page.mainFrame());
  await target.click();
  await nav;
  await page.waitForLoadState('domcontentloaded');
}

/** Clicks "Apply" of a query form and waits for the reloaded list. */
export async function applyQuery(page: Page): Promise<void> {
  await clickAndWait(page, page.locator('#query_form p.buttons a.icon-checked').first());
}

/** Ids of the issue rows currently listed, in display order. */
export function listedIssueIds(page: Page): Promise<number[]> {
  return page.locator('table.list.issues tbody tr.issue').evaluateAll((rows) => rows.map((r) => Number(r.id.replace('issue-', ''))));
}

/** Small CSV parser (quoted fields, given separator). */
export function parseCsv(text: string, sep = ','): string[][] {
  const rows: string[][] = [];
  let row: string[] = [];
  let field = '';
  let quoted = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (quoted) {
      if (c === '"' && text[i + 1] === '"') {
        field += '"';
        i++;
      } else if (c === '"') quoted = false;
      else field += c;
    } else if (c === '"') quoted = true;
    else if (c === sep) {
      row.push(field);
      field = '';
    } else if (c === '\n' || c === '\r') {
      if (c === '\r' && text[i + 1] === '\n') i++;
      row.push(field);
      rows.push(row);
      row = [];
      field = '';
    } else field += c;
  }
  if (field !== '' || row.length) {
    row.push(field);
    rows.push(row);
  }
  return rows.filter((r) => r.length > 1 || r[0] !== '');
}

/**
 * Opens the "CSV" export dialog of a list (issues, time entries, time report), submits it with the given tweaks and
 * returns the parsed rows of the downloaded file.
 */
export async function exportCsv(page: Page, prepare?: (dialog: Locator) => Promise<void>): Promise<string[][]> {
  await page.locator('p.other-formats a.csv').click();
  const dialog = page.locator('.ui-dialog #csv-export-options');
  await expect(dialog).toBeVisible();
  if (prepare) await prepare(dialog);
  const [download] = await Promise.all([page.waitForEvent('download'), dialog.locator('input[type=submit]').click()]);
  const text = fs.readFileSync((await download.path())!, 'utf8').replace(new RegExp(`^${String.fromCharCode(0xfeff)}`), '');
  return parseCsv(text);
}

/** "h:mm" as Redmine prints hours (timespan format "minutes"). */
export function hm(hours: number): string {
  const minutes = Math.round(hours * 60);
  return `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`;
}

/**
 * Creates a custom field through the administration form (format preselected through the URL, like the "Next"
 * step of the wizard does). `fill` receives the page to set the format-specific options. Returns the new id.
 */
export async function createCustomField(
  page: Page,
  type: 'IssueCustomField' | 'ProjectCustomField' | 'TimeEntryCustomField' | 'UserCustomField',
  format: string,
  name: string,
  fill?: (page: Page) => Promise<void>,
): Promise<number> {
  await page.goto(`/custom_fields/new?type=${type}&custom_field[field_format]=${format}`);
  await expect(page.locator('#custom_field_field_format')).toHaveValue(format);
  await page.locator('#custom_field_name').fill(name);
  if (fill) await fill(page);
  await page.locator('#content input[type=submit][name=commit]').first().click();
  await expect(page.locator('#flash_notice')).toHaveText('Successful creation.');
  const id = Number(sql('SELECT id FROM custom_fields WHERE name = ?', [name])[0]?.id);
  expect(id, `custom field ${name}`).toBeGreaterThan(0);
  return id;
}

/** Opens the "..." actions dropdown of the contextual links of the page and returns its menu. */
export async function openActions(page: Page): Promise<Locator> {
  const trigger = page.locator('#content > .contextual .icon-actions.dropdown-trigger').first();
  const menu = page.locator('#content > .contextual .dropdown-content').first();
  // the dropdown is a Stimulus controller (ES module, loaded async): retry until it is connected
  await expect(async () => {
    if (!(await menu.isVisible())) await trigger.click();
    await expect(menu).toBeVisible({ timeout: 1_000 });
  }).toPass({ timeout: 10_000 });
  return menu;
}

/** Adds a principal (user or group) with a role through the "New member" dialog of the project settings. */
export async function addMember(page: Page, project: string, principal: string, role: string): Promise<void> {
  if (!page.url().includes(`/projects/${project}/settings/members`)) await page.goto(`/projects/${project}/settings/members`);
  await page.locator('#tab-content-members a.icon-add', { hasText: 'New member' }).click();
  const modal = page.locator('#ajax-modal');
  await expect(modal).toBeVisible();
  await modal.locator('#principals label', { hasText: principal }).locator('input').check();
  await modal.locator('.roles-selection label', { hasText: role }).locator('input').check();
  await modal.locator('#member-add-submit').click();
  await expect(modal).toBeHidden();
  await expect(page.locator('#tab-content-members tr.member', { hasText: principal }).locator('td.roles')).toContainText(role);
}
