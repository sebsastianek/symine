import { test, expect } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import { openEdit, submitEdit } from './_helpers';

test.use({ as: 'jsmith' });

test.describe('issue attachments', () => {
  test('add files on update (two uploads), remove one before submit', async ({ page, ui }) => {
    await page.goto('/issues/14');
    const form = await openEdit(page);
    await ui.attachFile({ name: 'one.txt', buffer: 'first' }, form);
    await ui.attachFile({ name: 'two.txt', buffer: 'second' }, form);
    await expect(form.locator('.attachments_fields > span')).toHaveCount(2);
    // remove the second upload before submitting (ajax delete of the pending upload)
    await form.locator('.attachments_fields > span').nth(1).locator('a.remove-upload').click();
    await expect(form.locator('.attachments_fields > span')).toHaveCount(1);
    await submitEdit(page);

    await expect(ui.flashNotice()).toHaveText('Successful update.');
    await expect(page.locator('.attachments a', { hasText: 'one.txt' }).first()).toBeVisible();
    await expect(page.locator('.attachments')).not.toContainText('two.txt');
    await expect(page.locator('#history .journal').last()).toContainText(/File one\.txt.*added/);
    expect(sql("SELECT filename FROM attachments WHERE container_type = 'Issue' AND container_id = 14")).toEqual([{ filename: 'one.txt' }]);
  });

  test('delete an existing attachment through "Edit attachments" on the update form', async ({ page, ui }) => {
    await page.goto('/issues/14');
    await openEdit(page);
    await page.locator('#add_attachments .contextual a').click();
    await expect(page.locator('#existing-attachments')).toBeVisible();
    await page.locator('#existing-attachments .existing-attachment').first().locator('input.deleted_attachment').check();
    await submitEdit(page);
    await expect(ui.flashNotice()).toHaveText('Successful update.');
    await expect(page.locator('.attachments')).toHaveCount(0);
    await expect(page.locator('#history .journal').last()).toContainText('File deleted (one.txt)');
  });

  test('attachment page shows the text content and the issue link', async ({ page }) => {
    const id = Number(sqlValue("SELECT id FROM attachments WHERE filename = 'notes.txt'"));
    await page.goto('/issues/13');
    await page.locator('.attachments a.icon-attachment, .attachments a', { hasText: 'notes.txt' }).first().click();
    await expect(page).toHaveURL(new RegExp(`/attachments/${id}`));
    await expect(page.locator('#content h2')).toContainText('notes.txt');
    await expect(page.locator('#content .filecontent, #content pre').first()).toBeVisible();
  });
});
