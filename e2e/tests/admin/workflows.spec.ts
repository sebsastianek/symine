import { test, expect } from '../../support/fixtures';
import { sqlValue } from '../../support/db';
import type { Page } from '@playwright/test';

/**
 * Opens the workflow editor (status transitions or field permissions) for a role and a tracker via the form.
 * Symine shows the editor as soon as the page opens and reloads it when a selector changes (no "Edit" click).
 */
async function openEditor(page: Page, tab: 'edit' | 'permissions', role: string, tracker: string): Promise<void> {
  await page.goto(`/workflows/${tab}`);
  await expect(page.locator('#workflow_form')).toBeVisible();
  for (const [select, label] of [['role_id', role], ['tracker_id', tracker]] as const) {
    const value = await page.locator(`#${select} option`, { hasText: new RegExp(`^${label}$`) }).getAttribute('value');
    if ((await page.locator(`#workflow_form input[name="${select}[]"]`).inputValue()) !== value) {
      await Promise.all([
        page.waitForURL((url) => url.searchParams.getAll(`${select}[]`).includes(value!)),
        page.locator(`#${select}`).selectOption(value!),
      ]);
    }
    await expect(page.locator(`#workflow_form input[name="${select}[]"]`)).toHaveValue(value!);
  }
}

function statusOptions(page: Page) {
  return page.locator('#issue_status_id option');
}

test.describe('workflow editor', () => {
  // every test starts from the seeded workflows (the first one changes the status of #13)
  test.use({ as: 'admin', dbReset: 'test' });

  test('status transitions: added and removed transitions are enforced for a Developer', async ({ page, ui, loginAs }) => {
    // dlopper (Developer on ecommerce) on #13 (Bug, In Progress): seeded transitions 2 -> 3, 4, 5
    const dev = await loginAs('dlopper');
    await dev.page.goto('/issues/13/edit');
    await expect(statusOptions(dev.page)).toHaveText(['In Progress', 'Resolved', 'Feedback', 'Closed']);

    await openEditor(page, 'edit', 'Developer', 'Bug');
    const toRejected = page.locator('#transitions_2_6_always');
    const toResolved = page.locator('#transitions_2_3_always');
    await expect(toRejected).not.toBeChecked();
    await expect(toResolved).toBeChecked();
    await toRejected.check();
    await toResolved.uncheck();
    await page.locator('#workflow_form input[type=submit][value="Save"]').click();
    await ui.expectNotice('Successful update.');
    await expect(page.locator('#transitions_2_6_always')).toBeChecked();
    await expect(page.locator('#transitions_2_3_always')).not.toBeChecked();

    await dev.page.goto('/issues/13/edit');
    await expect(statusOptions(dev.page)).toHaveText(['In Progress', 'Feedback', 'Closed', 'Rejected']);

    // the new transition can actually be used
    await dev.page.locator('#issue_status_id').selectOption({ label: 'Rejected' });
    await dev.page.locator('#issue-form input[type=submit][name=commit]').click();
    await dev.ui.expectNotice('Successful update.');
    await expect(dev.page.locator('.issue .status.attribute .value')).toHaveText('Rejected');
    expect(Number(sqlValue('SELECT status_id FROM issues WHERE id = 13'))).toBe(6);
  });

  test('the editor shows what the selectors say: opened at once, reloaded on change, unsaved changes guarded', async ({ page }) => {
    await page.goto('/workflows/edit');
    // no click needed: the first role and tracker are selected and their table is displayed
    const role = page.locator('#role_id');
    const shownRole = page.locator('#workflow_form input[name="role_id[]"]');
    await expect(page.locator('#workflow_form table.transitions-always')).toBeVisible();
    await expect(shownRole).toHaveValue(await role.inputValue());

    // choosing another role reloads the table for it
    const developer = await page.locator('#role_id option', { hasText: /^Developer$/ }).getAttribute('value');
    await role.selectOption(developer!);
    await expect(page).toHaveURL(/role_id/);
    await expect(shownRole).toHaveValue(developer!);
    await expect(page.locator('#transitions_2_6_always')).not.toBeChecked();

    // with unsaved changes in the table, the page asks first; declining keeps the table and puts the selector back
    await page.locator('#transitions_2_6_always').check();
    // (the fixtures accept every dialog: the page's confirm is replaced to answer "no")
    await page.evaluate(() => {
      (window as any).asked = 0;
      window.confirm = () => { (window as any).asked++; return false; };
    });
    const manager = await page.locator('#role_id option', { hasText: /^Manager$/ }).getAttribute('value');
    await role.selectOption(manager!);
    await expect.poll(() => page.evaluate(() => (window as any).asked)).toBe(1);
    await expect(role).toHaveValue(developer!);
    await expect(shownRole).toHaveValue(developer!);
    await expect(page.locator('#transitions_2_6_always')).toBeChecked();

    // "used statuses only" reloads too
    await page.evaluate(() => { window.confirm = () => true; });
    await page.locator('#used_statuses_only').uncheck();
    await expect(page).toHaveURL(/used_statuses_only=0(?!.*used_statuses_only=1)/);
    await expect(shownRole).toHaveValue(developer!);
  });

  test('column "check all" toggle in the transitions table', async ({ page, ui }) => {
    await openEditor(page, 'edit', 'Reporter', 'Feature');
    // Reporter/Feature: only -> Closed (and Resolved -> Feedback). Toggle the "Rejected" column on
    const column = page.locator('table.transitions-always input[type=checkbox].new-status-6:not(:disabled)');
    await expect(page.locator('table.transitions-always input.new-status-6:not(:disabled):checked')).toHaveCount(0);
    await page.locator('table.transitions-always thead a[onclick*="new-status-6"]').click();
    const n = await column.count();
    expect(n).toBeGreaterThan(2);
    await expect(page.locator('table.transitions-always input.new-status-6:not(:disabled):checked')).toHaveCount(n);
    await page.locator('#workflow_form input[type=submit][value="Save"]').click();
    await ui.expectNotice('Successful update.');
    const saved = Number(sqlValue("SELECT COUNT(*) FROM workflows WHERE type = 'WorkflowTransition' AND role_id = 5 AND tracker_id = 2 AND new_status_id = 6"));
    expect(saved).toBeGreaterThanOrEqual(4);
  });

  test('field permissions: read-only and required fields on the issue form', async ({ page, ui, loginAs }) => {
    await openEditor(page, 'permissions', 'Developer', 'Bug');
    // status In Progress (2): subject read-only, estimated time required
    await page.locator('#permissions_2_subject').selectOption('readonly');
    await page.locator('#permissions_2_estimated_hours').selectOption('required');
    await page.locator('#workflow_form input[type=submit][value="Save"]').click();
    await ui.expectNotice('Successful update.');
    await expect(page.locator('#permissions_2_subject')).toHaveValue('readonly');

    const dev = await loginAs('dlopper');
    await dev.page.goto('/issues/13/edit');
    await expect(dev.page.locator('#issue_subject')).toHaveCount(0);
    await expect(dev.page.locator('label[for=issue_estimated_hours] .required')).toBeVisible();
    await expect(dev.page.locator('label[for=issue_due_date] .required')).toBeVisible(); // seeded rule

    // saving without the required estimated time fails
    await dev.page.locator('#issue_estimated_hours').fill('');
    await dev.page.locator('#issue-form input[type=submit][name=commit]').click();
    await expect(dev.page.locator('#errorExplanation')).toContainText('Estimated time cannot be blank');

    // the admin is not concerned by role rules
    await page.goto('/issues/13/edit');
    await expect(page.locator('#issue_subject')).toBeVisible();
  });
});
