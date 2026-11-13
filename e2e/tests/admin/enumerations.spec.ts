import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import type { Page } from '@playwright/test';
import { submitButton } from './_helpers';

function section(page: Page, title: string) {
  // each enumeration type: <h3>Title</h3> <p>New value</p> <table class="list enumerations">
  return page.locator(`xpath=//h3[normalize-space()="${title}"]/following-sibling::table[1]`);
}

async function createValue(page: Page, type: string, name: string, opts: { isDefault?: boolean } = {}): Promise<number> {
  await page.goto('/enumerations');
  await page.locator(`#content a[href="/enumerations/new?type=${type}"]`).click();
  await page.locator('#enumeration_name').fill(name);
  if (opts.isDefault) await page.locator('#enumeration_is_default').check();
  await submitButton(page, 'Create').click();
  await expect(page.locator('#flash_notice')).toHaveText('Successful creation.');
  return Number(sqlValue('SELECT id FROM enumerations WHERE name = ? AND type = ?', [name, type]));
}

test.describe('admin enumerations', () => {
  test.use({ as: 'admin' });

  test('create a priority, an activity and a document category', async ({ page }) => {
    const prio = uid('Prio ');
    const act = uid('Activity ');
    const cat = uid('Category ');
    await createValue(page, 'IssuePriority', prio);
    await createValue(page, 'TimeEntryActivity', act);
    await createValue(page, 'DocumentCategory', cat);

    await expect(section(page, 'Issue priorities').getByRole('link', { name: prio })).toBeVisible();
    await expect(section(page, 'Activities (time tracking)').getByRole('link', { name: act })).toBeVisible();
    await expect(section(page, 'Document categories').getByRole('link', { name: cat })).toBeVisible();

    // the new values are offered in the forms
    await page.goto('/projects/ecommerce/issues/new');
    await expect(page.locator('#issue_priority_id option', { hasText: prio })).toHaveCount(1);
    await page.goto('/projects/ecommerce/time_entries/new');
    await expect(page.locator('#time_entry_activity_id option', { hasText: act })).toHaveCount(1);
    await page.goto('/projects/ecommerce/documents/new');
    await expect(page.locator('#document_category_id option', { hasText: cat })).toHaveCount(1);
  });

  test('validation, edit (default, inactive)', async ({ page, ui }) => {
    await page.goto('/enumerations/new?type=IssuePriority');
    await submitButton(page, 'Create').click();
    await expect(ui.errors()).toContainText('Name cannot be blank');

    const name = uid('Default prio ');
    const id = await createValue(page, 'IssuePriority', name, { isDefault: true });
    expect(Number(sqlValue('SELECT is_default FROM enumerations WHERE id = ?', [id]))).toBe(1);
    // only one default priority
    expect(Number(sqlValue("SELECT COUNT(*) FROM enumerations WHERE type = 'IssuePriority' AND is_default = 1"))).toBe(1);
    await page.goto('/projects/ecommerce/issues/new');
    await expect(page.locator('#issue_priority_id option:checked')).toHaveText(name);

    // deactivate: no longer offered on the issue form
    await page.goto(`/enumerations/${id}/edit`);
    await page.locator('#enumeration_active').uncheck();
    await submitButton(page, 'Save').click();
    await ui.expectNotice('Successful update.');
    await page.goto('/projects/ecommerce/issues/new');
    await expect(page.locator('#issue_priority_id option', { hasText: name })).toHaveCount(0);
  });

  test('reorder priorities by drag and drop', async ({ page }) => {
    await page.goto('/enumerations');
    const table = section(page, 'Issue priorities');
    await expect(table.locator('tbody td.name').first()).toHaveText('Low');
    const handle = table.locator('tbody tr').filter({ hasText: 'Immediate' }).locator('.sort-handle');
    const target = table.locator('tbody tr').first();
    const reorder = page.waitForResponse((r) => new URL(r.url()).pathname === '/enumerations/5' && r.request().method() !== 'GET');

    // jQuery UI sortable needs real mouse moves
    const hb = (await handle.boundingBox())!;
    const tb = (await target.boundingBox())!;
    await page.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2);
    await page.mouse.down();
    await page.mouse.move(hb.x + hb.width / 2, hb.y - 10, { steps: 5 });
    await page.mouse.move(hb.x + hb.width / 2, tb.y + 2, { steps: 15 });
    await page.mouse.up();
    expect((await reorder).status()).toBeLessThan(400);

    await expect.poll(() => Number(sqlValue("SELECT position FROM enumerations WHERE id = 5"))).toBe(1);
    await page.reload();
    await expect(section(page, 'Issue priorities').locator('tbody td.name').first()).toHaveText('Immediate');
  });

  test('delete an unused value, and a used one with reassignment', async ({ page, ui, api }) => {
    const unused = uid('Unused ');
    const unusedId = await createValue(page, 'DocumentCategory', unused);
    await section(page, 'Document categories').locator('tr').filter({ hasText: unused }).getByRole('link', { name: 'Delete' }).click();
    await expect(section(page, 'Document categories').getByRole('link', { name: unused })).toHaveCount(0);
    expect(sql('SELECT id FROM enumerations WHERE id = ?', [unusedId])).toHaveLength(0);

    // a priority used by an issue asks where to reassign the issues
    const used = uid('Used ');
    const usedId = await createValue(page, 'IssuePriority', used);
    const jsmith = await api('jsmith');
    const issue = await jsmith.createIssue({ project_id: 1, subject: uid('Prio issue '), priority_id: usedId });

    await page.goto('/enumerations');
    await section(page, 'Issue priorities').locator('tr').filter({ hasText: used }).getByRole('link', { name: 'Delete' }).click();
    await expect(page.locator('#content')).toContainText(/1 objects are assigned to the value/);
    await page.locator('#reassign_to_id').selectOption({ label: 'Urgent' });
    await submitButton(page, 'Apply').click();
    await expect(page).toHaveURL(/\/enumerations$/);
    await expect(section(page, 'Issue priorities').getByRole('link', { name: used })).toHaveCount(0);
    expect(Number(sqlValue('SELECT priority_id FROM issues WHERE id = ?', [issue.id]))).toBe(4);
  });
});
