/** Helpers local to the content specs (wiki, news, documents, files, forums). */
import { expect, type Locator, type Page } from '@playwright/test';

export interface TestFile {
  name: string;
  mimeType: string;
  buffer: Buffer;
}

export function textFile(name: string, content = `content of ${name}\n`): TestFile {
  return { name, mimeType: 'text/plain', buffer: Buffer.from(content) };
}

/**
 * Selects a file in the attachments.js widget (`input.file_selector` inside `scope`) and waits for the ajax upload to
 * finish: the widget adds a filename input and a hidden `[token]` field holding "<id>.<digest>".
 */
export async function uploadAttachment(scope: Locator | Page, file: TestFile): Promise<void> {
  const form = scope.locator('.attachments_form').first();
  const entries = form.locator('.attachments_fields > span');
  const before = await entries.count();
  await form.locator('input[type=file].file_selector').setInputFiles(file);
  await expect(entries).toHaveCount(before + 1);
  const entry = entries.nth(before);
  await expect(entry.locator('input.filename')).toHaveValue(file.name);
  await expect(entry.locator('input.token')).toHaveValue(/^\d+\.[0-9a-f]+$/);
  await expect(entry).not.toHaveClass(/ajax-(waiting|loading)/);
}

/** Opens the "..." actions dropdown of the wiki page / content contextual area. */
export async function openActions(page: Page): Promise<Locator> {
  const trigger = page.locator('#content > .contextual .icon-actions.dropdown-trigger').first();
  const menu = page.locator('#content > .contextual .dropdown-content').first();
  // the dropdown is a Stimulus controller (ES module, loaded async): retry until it is connected
  await expect(async () => {
    if (!(await menu.isVisible())) await trigger.click();
    await expect(menu).toBeVisible({ timeout: 1_000 });
  }).toPass({ timeout: 10_000 });
  return menu;
}

/** Fills a wiki textarea (#id) with text through the Edit tab. */
export async function fillWiki(page: Page, id: string, text: string): Promise<void> {
  await page.locator(`#${id}`).fill(text);
}
