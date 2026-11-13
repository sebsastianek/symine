import { test, expect, uid } from '../../support/fixtures';
import { sqlValue } from '../../support/db';
import { textFile, uploadAttachment } from './_helpers';

const FILES = '/projects/ecommerce/files';

test.describe('files', () => {
  test.use({ as: 'jsmith' });

  test('upload files to a version and to the project, list and download them', async ({ page, ui }) => {
    const versionFile = textFile(`${uid('release-')}.txt`, 'release notes 1.0');
    const projectFile = textFile(`${uid('project-')}.txt`, 'project file');

    await page.goto(FILES);
    await ui.contextual('New file').click();
    await expect(page).toHaveURL(/\/files\/new$/);

    // to version 1.0, two files at once is possible but one is enough here
    await page.locator('#version_id').selectOption({ label: '1.0' });
    await uploadAttachment(page, versionFile);
    await page.getByRole('button', { name: 'Add' }).click();
    await ui.expectNotice('File added');
    await expect(page).toHaveURL(new RegExp(`${FILES}$`));

    // to the project (no version), with a description
    await ui.contextual('New file').click();
    await uploadAttachment(page, projectFile);
    await page.locator('.attachments_fields input.description').last().fill('Project level file');
    await page.getByRole('button', { name: 'Add' }).click();
    await ui.expectNotice('File added');

    const table = page.locator('table.list.files');
    // the version file is listed under the version header row
    const versionHeader = table.locator('tbody tr th a.icon-package', { hasText: '1.0' });
    await expect(versionHeader).toBeVisible();
    const versionRow = table.locator('tr.file', { hasText: versionFile.name });
    await expect(versionRow).toBeVisible();
    const order = await table.locator('tbody tr').evaluateAll((rows, names) => {
      const texts = rows.map((r) => r.textContent || '');
      return { header: texts.findIndex((t) => t.includes('1.0') && !t.includes('.txt')), file: texts.findIndex((t) => t.includes(names[0])) };
    }, [versionFile.name]);
    expect(order.file).toBeGreaterThan(order.header);

    const projectRow = table.locator('tr.file', { hasText: projectFile.name });
    await expect(projectRow.locator('td.filename a')).toHaveAttribute('title', 'Project level file');
    await expect(projectRow.locator('td.digest')).toContainText('SHA256');
    await expect(projectRow.locator('td.downloads')).toHaveText('0');

    // download link works and counts the download
    const href = await projectRow.locator('a.icon-download').getAttribute('href');
    const res = await page.request.get(href!);
    expect(res.status()).toBe(200);
    expect(await res.text()).toBe('project file');
    expect(res.headers()['content-disposition']).toContain('attachment');
    await page.reload();
    await expect(projectRow.locator('td.downloads')).toHaveText('1');

    expect(sqlValue("SELECT container_type FROM attachments WHERE filename = ?", [versionFile.name])).toBe('Version');
    expect(sqlValue("SELECT container_type FROM attachments WHERE filename = ?", [projectFile.name])).toBe('Project');

    // delete a file
    await projectRow.locator('a.icon-del').click();
    await expect(table.locator('tr.file', { hasText: projectFile.name })).toHaveCount(0);
  });

  test('the upload widget removes a pending upload before submit', async ({ page }) => {
    const file = textFile(`${uid('pending-')}.txt`);
    await page.goto(`${FILES}/new`);
    await uploadAttachment(page, file);
    const entry = page.locator('.attachments_fields > span').first();
    await entry.locator('a.remove-upload').click();
    await expect(page.locator('.attachments_fields > span')).toHaveCount(0);
    // the uploaded (unattached) attachment is deleted server-side
    await expect.poll(() => Number(sqlValue('SELECT COUNT(*) FROM attachments WHERE filename = ?', [file.name]))).toBe(0);
  });

  test('reporter without manage_files cannot add files', async ({ loginAs }) => {
    const { page, http } = await loginAs('rhill');
    await page.goto(FILES);
    await expect(page.locator('#content > .contextual a.icon-add')).toHaveCount(0);
    expect((await http.get(`${FILES}/new`)).status()).toBe(403);
  });
});
