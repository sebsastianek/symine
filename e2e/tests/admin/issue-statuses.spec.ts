import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import { rowWithLink, submitButton } from './_helpers';

/*
 * Administration » Issue statuses. Seeded: New(1), In Progress(2), Resolved(3), Feedback(4), Closed(5, closed),
 * Rejected(6, closed), in that order. "New" is the default status of the trackers, 1/2/5 are used by issues.
 */
test.describe('admin issue statuses', () => {
  test.use({ as: 'admin' });

  test('create a status: it has no workflow yet, then becomes available once a transition allows it', async ({ page, ui }) => {
    const name = uid('On hold ');
    await page.goto('/admin');
    await page.locator('#admin-menu a.issue-statuses').first().click();
    await expect(ui.heading()).toHaveText('Issue statuses');
    await expect(page.locator('table.issue_statuses tbody td.name')).toHaveText(['New', 'In Progress', 'Resolved', 'Feedback', 'Closed', 'Rejected']);

    await ui.contextual('New status').click();
    // validation: the name is required
    await submitButton(page, 'Create').click();
    await ui.expectError(/Name cannot be blank/);
    await page.locator('#issue_status_name').fill(name);
    await page.locator('#issue_status_description').fill('Waiting for the customer');
    await submitButton(page, 'Create').click();
    await ui.expectNotice('Successful creation.');
    await expect(page).toHaveURL(/\/issue_statuses$/);

    // appended at the end, flagged as unused by the workflows
    const row = rowWithLink(page, name);
    await expect(page.locator('table.issue_statuses tbody td.name').last()).toHaveText(name);
    await expect(row.locator('td.description')).toHaveText('Waiting for the customer');
    await expect(row.locator('.icon-warning')).toContainText('No tracker uses this status in the workflows');
    const id = Number(sqlValue('SELECT id FROM issue_statuses WHERE name = ?', [name]));
    expect(Number(sqlValue('SELECT position FROM issue_statuses WHERE id = ?', [id]))).toBe(7);

    // a manager does not get it on an issue until a workflow transition uses it
    await page.goto('/issues/14/edit');
    await expect(page.locator('#issue_status_id option', { hasText: name })).toHaveCount(0);

    // the warning links to the workflow editor showing all statuses: allow New -> <status> for Manager / Feature
    await page.goto('/issue_statuses');
    await rowWithLink(page, name).locator('.icon-warning a').click();
    await expect(page).toHaveURL(/\/workflows\/edit\?used_statuses_only=0/);
    await page.locator('#role_id').selectOption({ label: 'Manager' });
    await page.locator('#tracker_id').selectOption({ label: 'Feature' });
    await submitButton(page, 'Edit').click();
    const transition = page.locator(`input[name="transitions[1][${id}][always]"][type=checkbox]`);
    await expect(transition).toBeVisible();
    await transition.check();
    await submitButton(page, 'Save').click();
    await ui.expectNotice('Successful update.');

    await page.goto('/issue_statuses');
    await expect(rowWithLink(page, name).locator('.icon-warning')).toHaveCount(0);
    await page.goto('/issues/14/edit');
    await page.locator('#issue_status_id').selectOption({ label: name });
    await page.locator('#issue-form input[name=commit]').click();
    await ui.expectNotice('Successful update.');
    await expect(page.locator('.issue.details .attributes .status .value')).toHaveText(name);
  });

  test('edit: marking a status as closed closes its issues', async ({ page, ui }) => {
    await page.goto('/issue_statuses');
    await rowWithLink(page, 'Feedback').getByRole('link', { name: 'Feedback' }).click();
    await expect(ui.heading()).toContainText('Feedback');
    await page.locator('#issue_status_name').fill('');
    await submitButton(page, 'Save').click();
    await ui.expectError(/Name cannot be blank/);
    await page.locator('#issue_status_name').fill('Needs feedback');
    await page.locator('#issue_status_is_closed').check();
    await submitButton(page, 'Save').click();
    await ui.expectNotice('Successful update.');
    const row = rowWithLink(page, 'Needs feedback');
    await expect(row.locator('.icon-checked')).toHaveCount(1);
    expect(Number(sqlValue('SELECT is_closed FROM issue_statuses WHERE id = 4'))).toBe(1);

    // an issue moved to that status is closed: struck through in lists, hidden from the open filter
    sql('UPDATE issues SET status_id = 4 WHERE id = 16');
    await page.goto('/projects/ecommerce/issues?set_filter=1&status_id=c');
    await expect(page.locator('tr#issue-16')).toHaveClass(/closed/);
    await expect(page.locator('tr#issue-16 td.status')).toHaveText('Needs feedback');
    await page.goto('/projects/ecommerce/issues?set_filter=1&status_id=o');
    await expect(page.locator('tr#issue-13')).toBeVisible();
    await expect(page.locator('tr#issue-16')).toHaveCount(0);
  });

  test('reorder by drag and drop changes the order of the status select', async ({ page }) => {
    await page.goto('/issue_statuses');
    const table = page.locator('table.issue_statuses');
    const handle = table.locator('tbody tr').filter({ has: page.getByRole('link', { name: 'Rejected', exact: true }) }).locator('.sort-handle');
    const target = table.locator('tbody tr').first();
    const reorder = page.waitForResponse((r) => new URL(r.url()).pathname === '/issue_statuses/6' && r.request().method() !== 'GET');

    // jQuery UI sortable needs real mouse moves
    const hb = (await handle.boundingBox())!;
    const tb = (await target.boundingBox())!;
    await page.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2);
    await page.mouse.down();
    await page.mouse.move(hb.x + hb.width / 2, hb.y - 10, { steps: 5 });
    await page.mouse.move(hb.x + hb.width / 2, tb.y + 2, { steps: 15 });
    await page.mouse.up();
    expect((await reorder).status()).toBeLessThan(400);
    await expect.poll(() => Number(sqlValue('SELECT position FROM issue_statuses WHERE id = 6'))).toBe(1);

    await page.reload();
    await expect(page.locator('table.issue_statuses tbody td.name').first()).toHaveText('Rejected');
    // statuses are offered in that order (a manager may use any status)
    await page.goto('/issues/15/edit');
    await expect(page.locator('#issue_status_id option').first()).toHaveText('Rejected');
    // and the status filter of the issue list follows it too
    await page.goto('/projects/ecommerce/issues?set_filter=1&f[]=status_id&op[status_id]==&v[status_id][]=1');
    await expect(page.locator('#values_status_id_1 option').first()).toHaveText('Rejected');
  });

  test('a status in use cannot be deleted, an unused one can', async ({ page, ui }) => {
    await page.goto('/issue_statuses');
    // "In Progress" is the status of #13
    await rowWithLink(page, 'In Progress').locator('a.icon-del').click();
    await ui.expectError('Unable to delete issue status (This status is used by some issues)');
    await expect(rowWithLink(page, 'In Progress')).toBeVisible();
    // "New" is the default status of the trackers (and used)
    await rowWithLink(page, 'New').locator('a.icon-del').click();
    await ui.expectError(/Unable to delete issue status/);
    expect(Number(sqlValue('SELECT COUNT(*) FROM issue_statuses WHERE id IN (1, 2)'))).toBe(2);

    await rowWithLink(page, 'Resolved').locator('a.icon-del').click();
    await expect(page).toHaveURL(/\/issue_statuses$/);
    await expect(rowWithLink(page, 'Resolved')).toHaveCount(0);
    expect(Number(sqlValue('SELECT COUNT(*) FROM issue_statuses WHERE id = 3'))).toBe(0);
    // its workflow transitions are gone too
    expect(Number(sqlValue('SELECT COUNT(*) FROM workflows WHERE old_status_id = 3 OR new_status_id = 3'))).toBe(0);
  });

  test('"% Done" by status: set the ratios, update the issues, the field becomes read-only', async ({ page, ui }) => {
    // without the setting there is neither a ratio column nor the update link
    await page.goto('/issue_statuses');
    await expect(page.locator('table.issue_statuses thead th', { hasText: '% Done' })).toHaveCount(0);
    await expect(page.locator('#content > .contextual a.icon-multiple')).toHaveCount(0);

    await page.goto('/settings?tab=issues');
    await page.locator('#settings_issue_done_ratio').selectOption('issue_status');
    await page.locator('#tab-content-issues input[type=submit][value="Save"]').click();
    await ui.expectNotice('Successful update.');

    await page.goto('/issue_statuses');
    await expect(page.locator('table.issue_statuses thead th', { hasText: '% Done' })).toHaveCount(1);
    await rowWithLink(page, 'In Progress').getByRole('link', { name: 'In Progress' }).click();
    await page.locator('#issue_status_default_done_ratio').selectOption('40');
    await submitButton(page, 'Save').click();
    await ui.expectNotice('Successful update.');
    await expect(rowWithLink(page, 'In Progress').locator('td').nth(1)).toHaveText('40');

    // "Update issue done ratios" applies the ratio to the existing issues (#13 is In Progress)
    await ui.contextual('Update issue done ratios').click();
    await ui.expectNotice('Issue done ratios updated.');
    expect(Number(sqlValue('SELECT done_ratio FROM issues WHERE id = 13'))).toBe(40);
    await page.goto('/issues/13');
    await expect(page.locator('.issue.details .attributes .progress .value')).toContainText('40%');
    // the ratio is no longer editable on the issue form
    await page.goto('/issues/13/edit');
    await expect(page.locator('#issue_status_id')).toBeVisible();
    await expect(page.locator('#issue_done_ratio')).toHaveCount(0);
  });
});
