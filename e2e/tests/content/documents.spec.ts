import { test, expect, uid } from '../../support/fixtures';
import { sqlValue } from '../../support/db';
import { textFile, uploadAttachment } from './_helpers';

const DOCS = '/projects/ecommerce/documents';

test.describe('documents', () => {
  test.use({ as: 'jsmith' });

  test('create with category and attachment, show, edit, add attachment, delete', async ({ page, ui }) => {
    const title = uid('Doc ');
    await page.goto(DOCS);
    await expect(page.locator('.document-group h3.group-name', { hasText: 'User documentation' })).toBeVisible();
    await expect(page.locator('#add-document')).toBeHidden();

    await ui.contextual('New document').click();
    await expect(page.locator('#add-document')).toBeVisible();
    await page.locator('#document_category_id').selectOption({ label: 'Technical documentation' });
    await page.locator('#document_title').fill(title);
    await page.locator('#document_description').fill('Architecture *overview*');
    await uploadAttachment(page.locator('#new_document'), textFile('design.txt', 'design v1'));
    await page.locator('#new_document').getByRole('button', { name: 'Create' }).click();

    await ui.expectNotice('Successful creation.');
    const group = page.locator('.document-group', { has: page.locator('h3', { hasText: 'Technical documentation' }) });
    await expect(group.locator('.document-item h4 a', { hasText: title })).toBeVisible();

    // show
    await group.getByRole('link', { name: title }).click();
    await expect(page.locator('#content h2')).toHaveText(title);
    await expect(page.locator('#content em').first()).toContainText('Technical documentation');
    await expect(page.locator('#content .wiki em')).toHaveText('overview');
    const attachments = page.locator('#content .attachments');
    await expect(attachments.locator('a', { hasText: 'design.txt' }).first()).toBeVisible();

    // the attachment is downloadable
    const href = await attachments.locator('a.icon-download, a[href*="/attachments/download/"]').first().getAttribute('href');
    expect(href).toBeTruthy();
    const download = await page.request.get(href!);
    expect(download.status()).toBe(200);
    expect(await download.text()).toBe('design v1');

    // add another attachment from the show page (hidden form)
    await page.locator('#attach_files_link').click();
    const addForm = page.locator('#add_attachment_form');
    await expect(addForm).toBeVisible();
    await uploadAttachment(addForm, textFile('design-v2.txt', 'design v2'));
    await addForm.getByRole('button', { name: 'Add' }).click();
    await expect(page.locator('#content .attachments a', { hasText: 'design-v2.txt' }).first()).toBeVisible();
    const docId = Number(page.url().match(/\/documents\/(\d+)/)![1]);
    expect(Number(sqlValue("SELECT COUNT(*) FROM attachments WHERE container_type = 'Document' AND container_id = ?", [docId]))).toBe(2);

    // edit: change title and category
    await page.locator('#content > .contextual a.icon-edit').click();
    await expect(page.locator('#document_title')).toHaveValue(title);
    await page.locator('#document_title').fill(`${title} v2`);
    await page.locator('#document_category_id').selectOption({ label: 'User documentation' });
    await page.getByRole('button', { name: 'Save' }).click();
    await ui.expectNotice('Successful update.');
    await expect(page.locator('#content h2')).toHaveText(`${title} v2`);
    await expect(page.locator('#content em').first()).toContainText('User documentation');

    // delete
    await page.locator('#content > .contextual a.icon-del').click();
    await expect(page).toHaveURL(new RegExp(`${DOCS}$`));
    await expect(page.locator('.document-item', { hasText: title })).toHaveCount(0);
    expect(Number(sqlValue('SELECT COUNT(*) FROM documents WHERE id = ?', [docId]))).toBe(0);
  });

  test('sort the list by title / author through the sidebar', async ({ page }) => {
    await page.goto(DOCS);
    await page.locator('#sidebar a', { hasText: 'Title' }).click();
    await expect(page).toHaveURL(/sort_by=title/);
    await expect(page.locator('#sidebar a.selected')).toHaveText('Title');
    await expect(page.locator('.document-group-title h3.group-name').first()).toHaveText('S');

    // like Redmine, grouping by author only lists documents with attachments (author of the last one)
    await page.locator('#sidebar a', { hasText: 'Author' }).click();
    await expect(page.locator('#document-list p.nodata')).toBeVisible();
    await page.goto(DOCS);
    await page.getByRole('link', { name: 'Specs' }).click();
    await page.locator('#attach_files_link').click();
    await uploadAttachment(page.locator('#add_attachment_form'), textFile('specs.txt'));
    await page.locator('#add_attachment_form').getByRole('button', { name: 'Add' }).click();
    await expect(page.locator('#content .attachments a', { hasText: 'specs.txt' }).first()).toBeVisible();
    await page.goto(`${DOCS}?sort_by=author`);
    const group = page.locator('.document-group-author');
    await expect(group.locator('h3.group-name')).toHaveText('John Smith');
    await expect(group.locator('.document-item a', { hasText: 'Specs' })).toBeVisible();
  });

  test('reporter can view but not manage documents', async ({ loginAs }) => {
    const { page } = await loginAs('rhill');
    await page.goto(DOCS);
    await expect(page.locator('.document-item', { hasText: 'Specs' })).toBeVisible();
    await expect(page.locator('#content > .contextual a.icon-add')).toHaveCount(0);
    await page.getByRole('link', { name: 'Specs' }).click();
    await expect(page.locator('#content > .contextual a.icon-edit')).toHaveCount(0);
    await expect(page.locator('#content > .contextual a.icon-del')).toHaveCount(0);
  });
});
