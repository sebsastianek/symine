import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import { attribute, issueIdFromUrl } from './_helpers';

test.describe('new issue', () => {
  test.use({ as: 'jsmith' });

  test('form refreshes custom fields when the tracker changes, keeping typed values', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues/new');
    await expect(page.locator('#issue_tracker_id')).toHaveValue('1'); // Bug
    // Bug: Database (1), Platforms (2), Searchable (4), Severity (7); no Budget (3, Feature only)
    await expect(page.locator('#issue_custom_field_values_2')).toBeVisible();
    await expect(page.locator('#issue_custom_field_values_7')).toBeVisible();
    await expect(page.locator('#issue_custom_field_values_3')).toHaveCount(0);

    await page.locator('#issue_subject').fill('Typed before tracker change');
    await page.locator('#issue_tracker_id').selectOption({ label: 'Feature' });

    await expect(page.locator('#issue_custom_field_values_3')).toBeVisible();
    await expect(page.locator('#issue_custom_field_values_2')).toHaveCount(0);
    await expect(page.locator('#issue_custom_field_values_7')).toHaveCount(0);
    await expect(page.locator('#issue_subject')).toHaveValue('Typed before tracker change');
    await expect(page.locator('#issue_tracker_id')).toHaveValue('2');
  });

  test('creates an issue with attributes, custom fields and an uploaded attachment', async ({ page, ui }) => {
    const subject = uid('E2E bug ');
    await page.goto('/projects/ecommerce/issues/new');
    await page.locator('#issue_subject').fill(subject);
    await page.locator('#issue_description').fill('Steps:\n\n* open the cart\n* apply a discount');
    await page.locator('#issue_priority_id').selectOption({ label: 'High' });
    await page.locator('#issue_assigned_to_id').selectOption({ label: 'Dave Lopper' });
    await page.locator('#issue_category_id').selectOption({ label: 'Backend' });
    await page.locator('#issue_fixed_version_id').selectOption({ label: '1.0' });
    await page.locator('#issue_start_date').fill('2026-11-02');
    await page.locator('#issue_due_date').fill('2026-11-20');
    await page.locator('#issue_estimated_hours').fill('3.5');
    await page.locator('#issue_custom_field_values_1').selectOption('PostgreSQL');
    await page.locator('#issue_custom_field_values_2').selectOption(['Linux', 'Windows']);
    await page.locator('#issue_custom_field_values_7').selectOption({ label: 'Major' });

    await ui.attachFile({ name: 'e2e-report.txt', buffer: 'hello from playwright\n' }, page.locator('#issue-form'));
    await page.locator('#issue-form .attachments_fields input.description').first().fill('Log file');

    await page.locator('#issue-form input[name=commit]').click();

    await expect(ui.flashNotice()).toContainText(/Issue #\d+ created\./);
    const id = issueIdFromUrl(page);
    await expect(page.locator('#content h2')).toHaveText(`Bug #${id}`);
    await expect(page.locator('.issue.details .subject h3')).toHaveText(subject);
    await expect(attribute(page, 'status')).toHaveText('New');
    await expect(attribute(page, 'priority')).toHaveText('High');
    await expect(attribute(page, 'assigned-to')).toContainText('Dave Lopper');
    await expect(attribute(page, 'category')).toHaveText('Backend');
    await expect(attribute(page, 'fixed-version')).toHaveText('1.0');
    await expect(attribute(page, 'estimated-hours')).toContainText('3:30');
    await expect(attribute(page, 'cf_1')).toHaveText('PostgreSQL');
    await expect(attribute(page, 'cf_2')).toHaveText('Linux, Windows');
    await expect(attribute(page, 'cf_7')).toHaveText('Major');
    await expect(page.locator('#issue_description_wiki li')).toHaveCount(2);

    // attachment is listed and downloadable with the uploaded content
    const link = page.locator('.attachments a', { hasText: 'e2e-report.txt' }).first();
    await expect(link).toBeVisible();
    await expect(page.locator('.attachments')).toContainText('Log file');
    const attId = Number(sqlValue("SELECT id FROM attachments WHERE container_type = 'Issue' AND container_id = ?", [id]));
    const res = await page.request.get(`/attachments/download/${attId}/e2e-report.txt`);
    expect(res.status()).toBe(200);
    expect(await res.text()).toBe('hello from playwright\n');

    // jsmith (author) watches nothing by default, the issue is in the project list
    await page.goto('/projects/ecommerce/issues?set_filter=1&status_id=o&sort=id:desc');
    await expect(page.locator(`tr#issue-${id} td.subject`)).toHaveText(subject);
  });

  test('"Create and continue" stays on a new form', async ({ page, ui }) => {
    const subject = uid('Continue ');
    await page.goto('/projects/ecommerce/issues/new');
    await page.locator('#issue_tracker_id').selectOption({ label: 'Support' });
    await expect(page.locator('#issue_custom_field_values_2')).toHaveCount(0);
    await page.locator('#issue_subject').fill(subject);
    await page.locator('#issue-form input[name=continue]').click();
    await expect(ui.flashNotice()).toContainText(/Issue #\d+ created\./);
    await expect(page).toHaveURL(/\/projects\/ecommerce\/issues\/new/);
    // tracker is kept, subject is empty
    await expect(page.locator('#issue_tracker_id')).toHaveValue('3');
    await expect(page.locator('#issue_subject')).toHaveValue('');
    expect(sql('SELECT tracker_id FROM issues WHERE subject = ?', [subject])).toEqual([{ tracker_id: 3 }]);
  });

  test('validation errors keep the form and the uploaded attachment', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/issues/new');
    await ui.attachFile({ name: 'kept.txt', buffer: 'kept' }, page.locator('#issue-form'));
    await page.locator('#issue-form input[name=commit]').click();
    await expect(ui.errors()).toContainText('Subject cannot be blank');
    // the already uploaded file is kept (token) and can be submitted
    await expect(page.locator('#issue-form .attachments_fields input.filename')).toHaveValue('kept.txt');
    await page.locator('#issue_subject').fill(uid('After error '));
    await page.locator('#issue-form input[name=commit]').click();
    await expect(ui.flashNotice()).toContainText('created');
    await expect(page.locator('.attachments a', { hasText: 'kept.txt' }).first()).toBeVisible();
  });

  test('watchers can be selected on creation, private flag and parent task', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/issues/new');
    await page.locator('#issue_subject').fill(uid('With watchers '));
    await page.locator('#issue_is_private').check();
    await page.locator('#watchers_form_container input[type=checkbox][value="7"]').check(); // Robert Hill
    await ui.autocomplete(page.locator('#issue_parent_issue_id'), 'Wishlist', /#14/);
    await expect(page.locator('#issue_parent_issue_id')).toHaveValue('14');
    await page.locator('#issue-form input[name=commit]').click();
    await expect(ui.flashNotice()).toContainText('created');
    const id = issueIdFromUrl(page);
    await expect(page.locator('#content .badge-private')).toBeVisible();
    await expect(page.locator('#watchers')).toContainText('Robert Hill');
    await expect(page.locator('.issue.details div.subject')).toContainText('Wishlist');
    expect(sqlValue('SELECT parent_id FROM issues WHERE id = ?', [id])).toBe(14);
  });

  test('a reporter cannot set the private flag and only sees allowed fields', async ({ loginAs }) => {
    const { page, ui } = await loginAs('rhill');
    await page.goto('/projects/ecommerce/issues/new');
    await expect(page.locator('#issue_subject')).toBeVisible();
    await expect(page.locator('#issue_is_private')).toHaveCount(0);
    await expect(page.locator('#watchers_form_container input[type=checkbox]')).toHaveCount(0);
    await page.locator('#issue_subject').fill(uid('Reporter issue '));
    await page.locator('#issue-form input[name=commit]').click();
    await expect(ui.flashNotice()).toContainText('created');
  });

  test('new issue from the global "New issue" page lets the user pick a project', async ({ page, ui }) => {
    await page.goto('/issues/new');
    await expect(page.locator('#issue_project_id')).toBeVisible();
    await page.locator('#issue_project_id').selectOption('2') // Internal;
    // internal: only issue_tracking/time_tracking; refreshed form has no category select (no categories)
    await expect(page.locator('#issue_category_id')).toHaveCount(0);
    await page.locator('#issue_subject').fill(uid('Global new '));
    await page.locator('#issue-form input[name=commit]').click();
    await expect(ui.flashNotice()).toContainText('created');
    await expect(page.locator('#header h1')).toContainText('Internal');
  });
});
