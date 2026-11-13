import { test, expect, uid } from '../../support/fixtures';
import { sqlValue } from '../../support/db';
import type { Page } from '@playwright/test';
import { submitButton } from './_helpers';

type Format = 'string' | 'text' | 'int' | 'float' | 'list' | 'date' | 'bool' | 'link' | 'user' | 'version' | 'enumeration' | 'attachment';
const FORMATS: Format[] = ['string', 'text', 'int', 'float', 'list', 'date', 'bool', 'link', 'user', 'version', 'enumeration', 'attachment'];

/** Creates an issue custom field through the admin form: Bug tracker, for all projects. Returns its id. */
async function createIssueCustomField(page: Page, format: Format, name: string): Promise<number> {
  await page.goto('/custom_fields');
  await page.locator('#content .contextual a', { hasText: 'New custom field' }).click();
  await expect(page.locator('#type_IssueCustomField')).toBeChecked();
  await page.locator('#content input[type=submit][value="Next »"]').click();
  await expect(page.locator('#custom_field_field_format')).toBeVisible();
  if (format !== 'string') {
    // changing the format reloads the form with the format-specific options
    await Promise.all([page.waitForURL(/field_format/), page.locator('#custom_field_field_format').selectOption(format)]);
  }
  await expect(page.locator('#custom_field_field_format')).toHaveValue(format);
  await page.locator('#custom_field_name').fill(name);
  if (format === 'list') {
    await page.locator('#custom_field_possible_values').fill('Alpha\nBeta\nGamma');
  }
  await page.locator('#custom_field_tracker_ids_1').check();
  await page.locator('#custom_field_is_for_all').check();
  await submitButton(page, 'Create').click();
  await expect(page.locator('#flash_notice')).toHaveText('Successful creation.');
  return Number(sqlValue('SELECT id FROM custom_fields WHERE name = ?', [name]));
}

test.describe('issue custom fields of every format', () => {
  test.use({ as: 'admin' });
  // the tests build on each other (fields created by the first one)
  test.describe.configure({ mode: 'serial' });

  const ids = {} as Record<Format, number>;
  const names = {} as Record<Format, string>;
  const suffix = uid();

  test('create one field per format through the admin UI', async ({ page }) => {
    test.setTimeout(240_000); // 12 forms, ~4 page loads each
    for (const format of FORMATS) {
      names[format] = `CF ${format} ${suffix}`;
      ids[format] = await createIssueCustomField(page, format, names[format]);
      expect(ids[format], format).toBeGreaterThan(0);
      expect(String(sqlValue('SELECT field_format FROM custom_fields WHERE id = ?', [ids[format]]))).toBe(format);
    }
    await page.goto('/custom_fields');
    for (const format of FORMATS) await expect(page.getByRole('link', { name: names[format], exact: true })).toBeVisible();
  });

  test('key/value list: add, rename and deactivate values', async ({ page, ui }) => {
    await page.goto(`/custom_fields/${ids.enumeration}/edit`);
    await page.locator('#content a.icon-edit', { hasText: 'Edit' }).first().click();
    await expect(page).toHaveURL(new RegExp(`/custom_fields/${ids.enumeration}/enumerations`));
    for (const v of ['Bronze', 'Silver', 'Gold']) {
      await page.locator('#custom_field_enumeration_name').fill(v);
      await page.locator('#content input[type=submit][value="Add"]').click();
      await expect(page.locator(`input[id$="_name"][value="${v}"]`)).toHaveCount(1);
    }
    // rename Bronze -> Copper and deactivate Silver
    const bronze = page.locator('input[id^="custom_field_enumerations_"][id$="_name"][value="Bronze"]');
    await bronze.fill('Copper');
    const silverId = String(sqlValue("SELECT id FROM custom_field_enumerations WHERE custom_field_id = ? AND name = 'Silver'", [ids.enumeration]));
    await page.locator(`input[type=checkbox]#custom_field_enumerations_${silverId}_active`).uncheck();
    await page.locator('#content input[type=submit][value="Save"]').click();
    await ui.expectNotice('Successful update.');
    expect(Number(sqlValue("SELECT COUNT(*) FROM custom_field_enumerations WHERE custom_field_id = ? AND name = 'Copper'", [ids.enumeration]))).toBe(1);
    expect(Number(sqlValue('SELECT active FROM custom_field_enumerations WHERE id = ?', [silverId]))).toBe(0);
  });

  test('every field appears on the new issue form with the right input', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues/new');
    await expect(page.locator('#issue_tracker_id')).toHaveValue('1');
    const field = (f: Format) => page.locator(`#issue_custom_field_values_${ids[f]}`);
    const tag = async (f: Format) => field(f).evaluate((el) => `${el.tagName.toLowerCase()}:${(el as HTMLInputElement).type}`);

    for (const f of FORMATS) {
      await expect(page.locator('#all_attributes label', { hasText: names[f] }), f).toBeVisible();
    }
    expect(await tag('string')).toBe('input:text');
    expect(await tag('text')).toBe('textarea:textarea');
    expect(await tag('int')).toBe('input:text');
    expect(await tag('float')).toBe('input:text');
    expect(await tag('link')).toBe('input:text');
    expect(await tag('date')).toBe('input:date');
    expect(await tag('list')).toBe('select:select-one');
    expect(await tag('bool')).toBe('select:select-one');
    expect(await tag('user')).toBe('select:select-one');
    expect(await tag('version')).toBe('select:select-one');
    expect(await tag('enumeration')).toBe('select:select-one');

    await expect(field('list').locator('option')).toHaveText(['', 'Alpha', 'Beta', 'Gamma']);
    await expect(field('bool').locator('option')).toHaveText(['', 'Yes', 'No']);
    await expect(field('user').locator('option', { hasText: 'Dave Lopper' })).toHaveCount(1);
    await expect(field('version').locator('option', { hasText: '1.0' })).toHaveCount(1);
    // inactive values are not offered
    await expect(field('enumeration').locator('option')).toHaveText(['', 'Copper', 'Gold']);

    // the fields are not shown for a tracker they are not enabled for (Support)
    await page.locator('#issue_tracker_id').selectOption({ label: 'Support' });
    await expect(page.locator(`#issue_custom_field_values_${ids.string}`)).toHaveCount(0);
  });

  // Known gap: Symine renders only the label for "File" (attachment) custom fields on the issue form
  // (IssuesExtension::customFieldTag returns '' for the attachment format) and has no backend for their values.
  test.fixme('attachment (File) custom field offers a file input on the issue form', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues/new');
    const attachmentP = page.locator('#all_attributes p').filter({ has: page.locator('label', { hasText: names.attachment }) });
    await expect(attachmentP.locator('input[type=file]')).toHaveCount(1);
  });

  test('values are validated, saved and displayed on the issue', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/issues/new');
    await page.locator('#issue_subject').fill(`Custom values ${suffix}`);
    const field = (f: Format) => page.locator(`#issue_custom_field_values_${ids[f]}`);
    await field('string').fill('hello world');
    await field('int').fill('abc');
    await page.locator('#issue-form input[type=submit][name=commit]').click();
    await expect(ui.errors()).toContainText(`${names.int} is not a number`);

    await field('int').fill('42');
    await field('float').fill('3.5');
    await field('list').selectOption('Beta');
    await field('bool').selectOption({ label: 'Yes' });
    await field('date').fill('2026-12-24');
    await field('link').fill('https://example.org/x');
    await field('user').selectOption({ label: 'Dave Lopper' });
    await field('version').selectOption({ label: 'eCommerce - 1.0' }).catch(async () => field('version').selectOption({ label: '1.0' }));
    await field('enumeration').selectOption({ label: 'Gold' });
    await page.locator('#issue-form input[type=submit][name=commit]').click();
    await expect(ui.flashNotice()).toContainText(/Issue #\d+ created/);

    const value = (f: Format) => page.locator(`.issue .cf_${ids[f]}.attribute .value`);
    await expect(value('string')).toHaveText('hello world');
    await expect(value('int')).toHaveText('42');
    await expect(value('float')).toHaveText('3.50');
    await expect(value('list')).toHaveText('Beta');
    await expect(value('bool')).toHaveText('Yes');
    await expect(value('date')).toHaveText('12/24/2026');
    await expect(value('link').locator('a')).toHaveAttribute('href', 'https://example.org/x');
    await expect(value('user')).toHaveText('Dave Lopper');
    await expect(value('version')).toHaveText('1.0');
    await expect(value('enumeration')).toHaveText('Gold');
  });

  test('a required field blocks issue creation', async ({ page, ui }) => {
    await page.goto(`/custom_fields/${ids.string}/edit`);
    await page.locator('#custom_field_is_required').check();
    await submitButton(page, 'Save').click();
    await ui.expectNotice('Successful update.');

    await page.goto('/projects/ecommerce/issues/new');
    await expect(page.locator(`label[for=issue_custom_field_values_${ids.string}] .required`)).toBeVisible();
    await page.locator('#issue_subject').fill('Missing required');
    await page.locator('#issue-form input[type=submit][name=commit]').click();
    await expect(ui.errors()).toContainText(`${names.string} cannot be blank`);
  });

  test('delete a custom field', async ({ page, ui }) => {
    await page.goto('/custom_fields');
    const row = page.locator('table.list tbody tr').filter({ has: page.getByRole('link', { name: names.text, exact: true }) });
    await row.getByRole('link', { name: 'Delete' }).click();
    await ui.expectNotice('Successful deletion.');
    await expect(page.getByRole('link', { name: names.text, exact: true })).toHaveCount(0);
  });
});
