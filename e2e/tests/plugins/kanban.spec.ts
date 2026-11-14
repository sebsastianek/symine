import { execFileSync } from 'child_process';
import type { Locator, Page } from '@playwright/test';
import { test, expect, ensureFileReset } from '../../support/fixtures';
import { sqlValue } from '../../support/db';
import { IS_EXTERNAL, PATHS, PHP, ROOT } from '../../support/env';

/**
 * Kanban plugin (plugins/symine-kanban): boards over several projects, statuses mapped to columns,
 * cards dragged within what the workflow allows.
 */

test.skip(IS_EXTERNAL, 'Symine plugin (not available on the reference Redmine)');

function status(issueId: number): string {
  return String(sqlValue('SELECT s.name FROM issues i JOIN issue_statuses s ON s.id = i.status_id WHERE i.id = ?', [issueId]));
}

/** Drags with real mouse events (jQuery UI sortable does not listen to HTML5 drag events) */
async function drag(page: Page, card: Locator, target: Locator, where: 'center' | 'top' = 'center'): Promise<void> {
  await card.scrollIntoViewIfNeeded();
  const from = (await card.boundingBox())!;
  await page.mouse.move(from.x + from.width / 2, from.y + 12);
  await page.mouse.down();
  await page.mouse.move(from.x + from.width / 2 + 12, from.y + 24, { steps: 4 });
  await target.scrollIntoViewIfNeeded();
  const to = (await target.boundingBox())!;
  const y = where === 'top' ? to.y + 6 : to.y + Math.min(to.height / 2, 60);
  await page.mouse.move(to.x + to.width / 2, y, { steps: 12 });
  await page.mouse.move(to.x + to.width / 2 + 2, y + 2, { steps: 2 });
  await page.mouse.up();
}

const cell = (page: Page, columnName: string) =>
  page.locator('.kanban-column-head', { has: page.locator('.kanban-column-name', { hasText: new RegExp(`^${columnName}$`) }) }).first()
    .evaluate((head) => head.getAttribute('data-column'))
    .then((id) => page.locator(`.kanban-cell[data-column="${id}"]`).first());

const count = (page: Page, columnName: string) =>
  page.locator('.kanban-column-head', { has: page.locator('.kanban-column-name', { hasText: new RegExp(`^${columnName}$`) }) }).first().locator('.kanban-count-value');

test.describe('kanban boards', () => {
  test.use({ as: 'admin' });
  test.describe.configure({ mode: 'serial' });

  test.beforeAll(async ({}, testInfo) => {
    await ensureFileReset(testInfo);
    // the tables of the plugin, on the e2e database
    execFileSync(PHP, ['bin/console', 'symine:plugins:migrate', 'symine_kanban', '--no-interaction'], {
      cwd: ROOT,
      env: { ...process.env, APP_ENV: 'dev', APP_DEBUG: '1', APP_CACHE_DIR: PATHS.cache, DATABASE_URL: `sqlite:///${PATHS.db}`, SYMINE_FILES_PATH: PATHS.files },
      stdio: 'pipe',
    });
    expect(Number(sqlValue("SELECT COUNT(*) FROM sqlite_master WHERE name = 'kanban_boards'"))).toBe(1);
  });

  test('enable the module, create a board over a project and its subproject', async ({ page }) => {
    await page.goto('/projects/ecommerce/settings');
    await page.locator('#project_enabled_module_names_kanban').check();
    await page.locator('#tab-content-info input[type=submit], form#edit_project input[type=submit]').first().click();
    await expect(page.locator('#flash_notice')).toBeVisible();

    await page.goto('/projects/ecommerce');
    await page.locator('#main-menu a.kanban').click();
    await expect(page.locator('p.nodata')).toContainText('There is no board here yet');
    await page.locator('#content .contextual a.icon-add').click();

    await page.locator('#board_name').fill('Delivery');
    await page.locator('input[name="board[visibility]"][value="2"]').check();
    await page.locator('#board_include_subprojects').check();
    await page.locator('#kanban-board-form input[name=commit]').click();
    await expect(page.locator('#flash_notice')).toHaveText('Successful creation.');

    // the settings page: one column per status, every status mapped
    await expect(page.locator('#kanban-column-rows tr:not(.kanban-column-new)')).toHaveCount(6);
    await expect(page.locator('#kanban-mapping-warning')).toHaveCount(0);
    await expect(page.locator('select[name="status_column[1]"] option:checked')).toHaveText('New');
    await page.locator('#kanban-board-form a', { hasText: 'Cancel' }).click();

    await expect(page.locator('h2.kanban-title')).toContainText('Delivery');
    await expect(page.locator('.kanban-column-head .kanban-column-name')).toHaveText(['New', 'In Progress', 'Resolved', 'Feedback', 'Closed', 'Rejected']);
    await expect(count(page, 'New')).toHaveText('4');
    await expect(page.locator('#kanban-card-17 .kanban-card-project')).toContainText('Checkout');
  });

  test('dragging a card changes the status, within the workflow', async ({ page }) => {
    await page.goto('/projects/ecommerce/kanban');
    await expect(page.locator('#kanban')).toBeVisible();
    expect(status(16)).toBe('New');

    await drag(page, page.locator('#kanban-card-16'), await cell(page, 'In Progress'));
    await expect((await cell(page, 'In Progress')).locator('#kanban-card-16')).toBeVisible();
    await expect(count(page, 'In Progress')).toHaveText('2');
    await expect(count(page, 'New')).toHaveText('3');
    await expect.poll(() => status(16)).toBe('In Progress');

    // the change is a normal journal entry of the issue
    await page.goto('/issues/16');
    await expect(page.locator('#history')).toContainText('Status changed from New to In Progress');

    // #14 has open subtasks: Redmine does not let it be closed, and neither does the board
    await page.goto('/projects/ecommerce/kanban');
    await drag(page, page.locator('#kanban-card-14'), await cell(page, 'Closed'));
    await expect(page.locator('#kanban-messages .flash.error')).toContainText('cannot go there');
    await expect((await cell(page, 'New')).locator('#kanban-card-14')).toBeVisible();
    expect(status(14)).toBe('New');
  });

  test('cards keep the order they are given', async ({ page }) => {
    await page.goto('/projects/ecommerce/kanban');
    const first = (await cell(page, 'New')).locator('.kanban-card').first();
    const firstId = await first.getAttribute('data-issue-id');
    const last = (await cell(page, 'New')).locator('.kanban-card').last();
    const lastId = await last.getAttribute('data-issue-id');
    expect(firstId).not.toBe(lastId);

    const saved = page.waitForResponse((r) => r.url().includes('/move') && r.request().method() === 'POST');
    await drag(page, last, first, 'top');
    expect((await saved).status()).toBe(200);
    await expect((await cell(page, 'New')).locator('.kanban-card').first()).toHaveAttribute('data-issue-id', lastId!);
    await page.reload();
    await expect((await cell(page, 'New')).locator('.kanban-card').first()).toHaveAttribute('data-issue-id', lastId!);
  });

  test('adding a card creates an issue', async ({ page }) => {
    await page.goto('/projects/ecommerce/kanban');
    const head = page.locator('.kanban-column-head', { has: page.locator('.kanban-column-name', { hasText: /^New$/ }) }).first();
    await head.locator('.kanban-add-button').click();
    const form = page.locator('.kanban-add');
    await expect(form.locator('input[name=subject]')).toBeFocused();
    await form.locator('input[name=subject]').fill('Gift cards from the board');
    await form.locator('select[name=project_id]').selectOption({ label: 'Checkout' });
    await expect(form.locator('select[name=tracker_id] option')).toHaveText(['Bug', 'Feature']);
    await form.locator('select[name=tracker_id]').selectOption({ label: 'Feature' });
    await form.locator('input[type=submit]').click();

    const card = page.locator('.kanban-card', { hasText: 'Gift cards from the board' });
    await expect(card).toBeVisible();
    await expect(card.locator('.kanban-card-project')).toContainText('Checkout');
    expect(String(sqlValue('SELECT p.identifier FROM issues i JOIN projects p ON p.id = i.project_id WHERE i.subject = ?', ['Gift cards from the board']))).toBe('checkout');

    // without a subject the form says why
    await form.locator('input[name=subject]').fill('   ');
    await form.locator('input[type=submit]').click();
    await expect(form.locator('.kanban-add-errors')).toContainText('Subject cannot be blank');
    await form.locator('.kanban-add-cancel').click();
    await expect(form).toHaveCount(0);
  });

  test('a card opens in place: fields, comments, subtasks and files without leaving the board', async ({ page }) => {
    await page.goto('/projects/ecommerce/kanban');
    const dialog = page.locator('#kanban-dialog');

    // a click on the card opens its dialog; the address can be shared
    await page.locator('#kanban-card-15 .kanban-card-subject').click();
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('.kanban-dialog-title')).toHaveText('Wishlist sharing');
    await expect(page).toHaveURL(/#card-15$/);
    await expect(page).toHaveURL(/\/kanban\/boards\/\d+/);

    // a field saves as soon as it changes, and the card behind follows
    await dialog.locator('#kanban-field-priority').selectOption({ label: 'Urgent' });
    await expect.poll(() => String(sqlValue('SELECT e.name FROM issues i JOIN enumerations e ON e.id = i.priority_id WHERE i.id = 15'))).toBe('Urgent');
    await expect(page.locator('#kanban-card-15 .kanban-card-priority')).toHaveText('Urgent');
    await expect(dialog.locator('#kanban-field-priority option:checked')).toHaveText('Urgent');

    // the assignee (the admin is not a member of the project: only people who can be assigned are offered)
    await expect(dialog.locator('[data-set="issue[assigned_to_id]"]')).toHaveCount(0);
    await dialog.locator('#kanban-field-assignee').selectOption({ label: 'John Smith' });
    await expect(dialog.locator('#kanban-field-assignee option:checked')).toHaveText('John Smith');
    await expect.poll(() => Number(sqlValue('SELECT assigned_to_id FROM issues WHERE id = 15'))).toBe(Number(sqlValue("SELECT id FROM users WHERE login = 'jsmith'")));

    // rename in place
    await dialog.locator('[data-edit="subject"]').click();
    await dialog.locator('.kanban-dialog-inline[data-field="subject"] input[type=text]').fill('Wishlist sharing by link');
    await dialog.locator('.kanban-dialog-inline[data-field="subject"] input[type=submit]').click();
    await expect(dialog.locator('.kanban-dialog-title')).toHaveText('Wishlist sharing by link');
    await expect(page.locator('#kanban-card-15 .kanban-card-subject')).toHaveText('Wishlist sharing by link');

    // a quick comment, formatted, counted on the card
    await dialog.locator('.kanban-dialog-comment textarea').fill('Ready for **review**');
    await dialog.locator('.kanban-dialog-comment input[type=submit]').click();
    await expect(dialog.locator('.kanban-dialog-journals .kanban-dialog-note').first()).toContainText('Ready for review');
    await expect(dialog.locator('.kanban-dialog-journals .kanban-dialog-note strong').first()).toHaveText('review');
    await expect(dialog.locator('.kanban-dialog-comment textarea')).toHaveValue('');
    await expect(page.locator('#kanban-card-15 .kanban-badge-comments')).toContainText('1');
    expect(String(sqlValue("SELECT notes FROM journals WHERE journalized_id = 15 AND journalized_type = 'Issue' ORDER BY id DESC LIMIT 1"))).toBe('Ready for **review**');

    // a subtask
    await dialog.locator('.kanban-dialog-subtask input[type=text]').fill('Copy link button');
    await dialog.locator('.kanban-dialog-subtask input[type=submit]').click();
    await expect(dialog.locator('.kanban-dialog-checklist li')).toContainText(['Copy link button']);
    expect(Number(sqlValue('SELECT parent_id FROM issues WHERE subject = ?', ['Copy link button']))).toBe(15);

    // a file; a picture becomes the cover of the card
    await dialog.locator('.kanban-dialog-attach input[type=file]').setInputFiles({
      name: 'mockup.png',
      mimeType: 'image/png',
      buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64'),
    });
    await expect(dialog.locator('.kanban-dialog-attachments')).toContainText('mockup.png');
    await expect(page.locator('#kanban-card-15 .kanban-badge-files')).toContainText('1');

    // changing the status moves the card to its new column behind the dialog
    await dialog.locator('#kanban-field-status').selectOption({ label: 'Resolved' });
    await expect((await cell(page, 'Resolved')).locator('#kanban-card-15')).toHaveCount(1);
    await expect.poll(() => status(15)).toBe('Resolved');

    // a refused change says why and shows the issue as it is
    await dialog.locator('[data-edit="subject"]').click();
    await dialog.locator('.kanban-dialog-inline[data-field="subject"] input[type=text]').evaluate((el: HTMLInputElement) => { el.required = false; el.value = '   '; });
    await dialog.locator('.kanban-dialog-inline[data-field="subject"] input[type=submit]').click();
    await expect(dialog.locator('.kanban-dialog-errors')).toContainText('Subject cannot be blank');

    // Escape closes; the new subtask is on the board once it is redrawn; the link opens the dialog again
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(page).not.toHaveURL(/#card-/);
    await expect(page.locator('.kanban-card', { hasText: 'Copy link button' })).toBeVisible();
    await page.goto(page.url().replace(/#.*$/, '') + '#card-15');
    await page.reload();
    await expect(page.locator('#kanban-dialog .kanban-dialog-title')).toHaveText('Wishlist sharing by link');
  });

  test('filters narrow the board and can be bookmarked', async ({ page }) => {
    await page.goto('/projects/ecommerce/kanban');
    await page.locator('#kanban-filters select[name=tracker_id]').selectOption({ label: 'Bug' });
    await expect(page).toHaveURL(/tracker_id=1/);
    await expect(page.locator('#kanban-card-13')).toBeVisible();
    await expect(page.locator('#kanban-card-15')).toHaveCount(0);
    await page.locator('#kanban-filters input[name=q]').fill('safari');
    await page.locator('#kanban-filters input[name=q]').press('Enter');
    await expect(page.locator('.kanban-card')).toHaveCount(1);
    await expect(page.locator('#kanban-card-17')).toBeVisible();
    await page.locator('#kanban-filters a.icon-reload').click();
    await expect(page.locator('#kanban-card-15')).toBeVisible();
  });

  test('settings: merge columns, hide a status, leave one unmapped', async ({ page }) => {
    await page.goto('/projects/ecommerce/kanban');
    await page.locator('#content .contextual a.icon-settings').click();

    // a new column "Review" takes Resolved and Feedback; their own columns go
    await page.locator('#kanban-add-column').click();
    const fresh = page.locator('#kanban-column-rows tr.kanban-column-new').last();
    await fresh.locator('.kanban-column-name-input').fill('Review');
    await fresh.locator('.kanban-wip-input').fill('1');
    const key = await fresh.getAttribute('data-key');
    await expect(page.locator('select[name="status_column[3]"] option', { hasText: /^Review$/ })).toHaveCount(1);
    await page.locator('select[name="status_column[3]"]').selectOption(key!);
    await page.locator('select[name="status_column[4]"]').selectOption(key!);
    for (const name of ['Resolved', 'Feedback']) {
      await page.locator('#kanban-column-rows tr', { has: page.locator(`input.kanban-column-name-input[value="${name}"]`) }).locator('input[type=checkbox]').check();
    }
    // Rejected is hidden on purpose; In Progress is left without a column
    await page.locator('select[name="status_column[6]"]').selectOption('hidden');
    await page.locator('#kanban-column-rows tr', { has: page.locator('input.kanban-column-name-input[value="Rejected"]') }).locator('input[type=checkbox]').check();
    await page.locator('select[name="status_column[2]"]').selectOption('');
    await page.locator('#kanban-column-rows tr', { has: page.locator('input.kanban-column-name-input[value="In Progress"]') }).locator('input[type=checkbox]').check();
    await page.locator('#board_swimlane').selectOption('tracker');
    await page.locator('#kanban-board-form input[name=commit]').click();
    await expect(page.locator('#flash_notice')).toHaveText('Successful update.');

    // nothing is lost: the unmapped status has its own column and a notice says so
    await expect(page.locator('.kanban-column-head .kanban-column-name')).toHaveText(['New', 'Closed', 'Review', 'Unmapped']);
    await expect(page.locator('#kanban-unmapped-note')).toContainText('In Progress');
    await expect(page.locator('.kanban-cell[data-column="unmapped"] .kanban-card').first()).toBeVisible();
    await expect(page.locator('.kanban-lane .kanban-lane-name')).toHaveText(['Bug', 'Feature', 'Support']);

    // lanes fold, and stay folded
    await page.locator('.kanban-lane', { hasText: 'Feature' }).locator('.kanban-lane-toggle').click();
    await expect(page.locator('.kanban-lane', { hasText: 'Feature' }).locator('.kanban-body')).toBeHidden();
    await page.reload();
    await expect(page.locator('.kanban-lane', { hasText: 'Feature' }).locator('.kanban-body')).toBeHidden();

    // a card dropped on the merged column asks which status it takes
    await page.locator('.kanban-lane', { hasText: 'Feature' }).locator('.kanban-lane-toggle').click();
    const card = page.locator('.kanban-lane', { hasText: 'Feature' }).locator('.kanban-card').first();
    const id = Number(await card.getAttribute('data-issue-id'));
    const review = await page.locator('.kanban-column-head', { has: page.locator('.kanban-column-name', { hasText: /^Review$/ }) }).getAttribute('data-column');
    await drag(page, card, page.locator(`.kanban-lane[data-lane="2"] .kanban-cell[data-column="${review}"]`));
    await expect(page.locator('.kanban-chooser')).toBeVisible();
    await page.locator('.kanban-chooser-option', { hasText: 'Feedback' }).click();
    await expect.poll(() => status(id)).toBe('Feedback');
    // with the card the dialog test resolved, the column now holds two: over its limit of one, and flagged
    await expect(page.locator(`.kanban-column-head[data-column="${review}"] .kanban-count-value`)).toHaveText('2');
    await expect(page.locator(`.kanban-column-head[data-column="${review}"]`)).toHaveClass(/kanban-over-limit/);
  });
});
