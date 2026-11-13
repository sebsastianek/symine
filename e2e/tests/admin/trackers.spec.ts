import { test, expect, uid } from '../../support/fixtures';
import { sqlValue } from '../../support/db';
import { rowWithLink, submitButton } from './_helpers';

test.describe('admin trackers', () => {
  test.use({ as: 'admin' });

  test('create a tracker with default status, fields, projects and copied workflow', async ({ page, ui }) => {
    const name = uid('Task ');
    await page.goto('/trackers');
    await ui.contextual('New tracker').click();
    await submitButton(page, 'Create').click();
    await expect(ui.errors()).toContainText('Name cannot be blank');

    await page.locator('#tracker_name').fill(name);
    await page.locator('#tracker_default_status_id').selectOption({ label: 'In Progress' });
    // disable two standard fields
    await page.locator('input[name="tracker[core_fields][]"][value="estimated_hours"]').uncheck();
    await page.locator('input[name="tracker[core_fields][]"][value="category_id"]').uncheck();
    // custom field "Budget" (id 3)
    await page.locator('input[name="tracker[custom_field_ids][]"][value="3"]').check();
    // projects: eCommerce
    await page.locator('#tracker_project_ids label', { hasText: 'eCommerce' }).locator('input').check();
    await page.locator('#copy_workflow_from').selectOption({ label: 'Bug' });
    await submitButton(page, 'Create').click();
    await ui.expectNotice('Successful creation.');
    await expect(rowWithLink(page, name)).toBeVisible();

    const id = Number(sqlValue('SELECT id FROM trackers WHERE name = ?', [name]));
    expect(Number(sqlValue('SELECT default_status_id FROM trackers WHERE id = ?', [id]))).toBe(2);
    expect(Number(sqlValue('SELECT COUNT(*) FROM projects_trackers WHERE tracker_id = ? AND project_id = 1', [id]))).toBe(1);
    expect(Number(sqlValue('SELECT COUNT(*) FROM workflows WHERE tracker_id = ?', [id]))).toBe(
      Number(sqlValue('SELECT COUNT(*) FROM workflows WHERE tracker_id = 1')),
    );

    // the tracker is available on the ecommerce new issue form, with its fields
    await page.goto('/projects/ecommerce/issues/new');
    await page.locator('#issue_tracker_id').selectOption({ label: name });
    await expect(page.locator('#issue_estimated_hours')).toHaveCount(0);
    await expect(page.locator('#issue_category_id')).toHaveCount(0);
    await expect(page.locator('#issue_custom_field_values_3')).toBeVisible();
    await expect(page.locator('#issue_status_id')).toHaveValue('2');

    // edit: rename and re-enable estimated time
    await page.goto(`/trackers/${id}/edit`);
    await page.locator('#tracker_name').fill(`${name} edited`);
    await page.locator('input[name="tracker[core_fields][]"][value="estimated_hours"]').check();
    await submitButton(page, 'Save').click();
    await ui.expectNotice('Successful update.');
    await expect(rowWithLink(page, `${name} edited`)).toBeVisible();

    await page.goto('/projects/ecommerce/issues/new');
    await page.locator('#issue_tracker_id').selectOption({ label: `${name} edited` });
    await expect(page.locator('#issue_estimated_hours')).toBeVisible();

    // delete (unused tracker)
    await page.goto('/trackers');
    // like Redmine: redirect to the list without a flash message
    await rowWithLink(page, `${name} edited`).getByRole('link', { name: 'Delete' }).click();
    await expect(rowWithLink(page, `${name} edited`)).toHaveCount(0);
    expect(Number(sqlValue('SELECT COUNT(*) FROM trackers WHERE id = ?', [id]))).toBe(0);
  });

  test('a tracker used by issues cannot be deleted', async ({ page, ui }) => {
    await page.goto('/trackers');
    await rowWithLink(page, 'Bug').getByRole('link', { name: 'Delete' }).click();
    await ui.expectError(/This tracker contains issues and cannot be deleted/);
    await expect(rowWithLink(page, 'Bug')).toBeVisible();
  });

  test('fields summary: toggle a field for a tracker and save', async ({ page, ui }) => {
    await page.goto('/trackers');
    await ui.contextual('Summary').click();
    await expect(page).toHaveURL(/\/trackers\/fields/);
    const box = page.locator('input.tracker-3.core-field-due_date');
    await expect(box).toBeChecked();
    await box.uncheck();
    // custom field Database (1) for Support (3)
    const cf = page.locator('input[name="trackers[3][custom_field_ids][]"][value="1"]');
    await expect(cf).not.toBeChecked();
    await cf.check();
    await submitButton(page, 'Save').click();
    await ui.expectNotice('Successful update.');
    await expect(page.locator('input.tracker-3.core-field-due_date')).not.toBeChecked();
    await expect(page.locator('input[name="trackers[3][custom_field_ids][]"][value="1"]')).toBeChecked();

    // Support issue form: no due date, Database field shown
    await page.goto('/projects/ecommerce/issues/new');
    await page.locator('#issue_tracker_id').selectOption({ label: 'Support' });
    await expect(page.locator('#issue_due_date')).toHaveCount(0);
    await expect(page.locator('#issue_custom_field_values_1')).toBeVisible();
  });
});
