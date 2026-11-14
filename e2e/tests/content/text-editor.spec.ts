import type { Locator, Page } from '@playwright/test';
import { test, expect, uid } from '../../support/fixtures';
import { sqlValue } from '../../support/db';

/*
 * The text editor used by every formatted field (wiki toolbar + textarea helpers, all Stimulus controllers):
 * toolbar buttons and their keyboard shortcuts, the table / highlighted code menus, list continuation on Enter,
 * Tab indentation, pasting an HTML table, the preview shortcut, and the rendered result (code copy button).
 * Text formatting of the fixture is CommonMark. Exercised on the wiki page form (#content_text).
 */
const area = (page: Page) => page.locator('#content_text');
const toolbar = (page: Page, button: string) => page.locator(`.jstElements button.jstb_${button}`);

async function openEditor(page: Page, title: string): Promise<void> {
  await page.goto(`/projects/ecommerce/wiki/${title}/edit`);
  await expect(area(page)).toBeVisible();
  // the toolbar is a Stimulus controller (ES module, loaded async): its connect() adds the shortcut to the titles
  await expect(toolbar(page, 'strong')).toHaveAttribute('title', /Strong \(.+B\)/i);
  // a new page starts with its title as a heading: work on an empty field
  await expect(area(page)).toHaveValue(/^# /);
  await area(page).fill('');
}

/** Selects the first occurrence of `text` in the textarea (focusing it). */
async function select(field: Locator, text: string): Promise<void> {
  await field.evaluate((el: HTMLTextAreaElement, t) => {
    const i = el.value.indexOf(t);
    if (i < 0) throw new Error(`"${t}" not found in the textarea`);
    el.focus();
    el.setSelectionRange(i, i + t.length);
  }, text);
}

/** Puts the caret at the end of the textarea. */
async function caretAtEnd(field: Locator): Promise<void> {
  await field.evaluate((el: HTMLTextAreaElement) => {
    el.focus();
    el.setSelectionRange(el.value.length, el.value.length);
  });
}

/** The modifier of the toolbar shortcuts: Cmd on macOS, Ctrl elsewhere (same detection as the controller). */
async function modifier(page: Page): Promise<'Meta' | 'Control'> {
  const mac = await page.evaluate(() => /mac|iphone|ipad/i.test((navigator as any).userAgentData?.platform || navigator.platform));
  return mac ? 'Meta' : 'Control';
}

test.describe('text editor', () => {
  test.use({ as: 'jsmith' });

  test('toolbar buttons and shortcuts wrap the selection or the selected lines', async ({ page }) => {
    await openEditor(page, uid('Toolbar_'));
    const field = area(page);
    const mod = await modifier(page);
    await field.fill('alpha beta gamma delta\nline one\nline two\nlast line');

    // inline styles: button, then keyboard shortcuts
    await select(field, 'beta');
    await toolbar(page, 'strong').click();
    await select(field, 'gamma');
    await page.keyboard.press(`${mod}+i`);
    await select(field, 'alpha');
    await page.keyboard.press(`${mod}+u`);
    await select(field, 'delta');
    await toolbar(page, 'code').click();
    await expect(field).toHaveValue('<u>alpha</u> **beta** *gamma* `delta`\nline one\nline two\nlast line');
    await expect(field).toBeFocused();

    // without selection the markers are inserted around the caret
    await caretAtEnd(field);
    await page.keyboard.type(' ');
    await toolbar(page, 'del').click();
    await page.keyboard.type('gone');
    await expect(field).toHaveValue(/last line ~~gone~~$/);

    // block styles work on whole lines, even with a partial selection
    await select(field, 'ne one\nline t');
    await toolbar(page, 'ul').click();
    await expect(field).toHaveValue(/\n\* line one\n\* line two\n/);
    await select(field, '* line one\n* line two');
    await toolbar(page, 'ol').click();
    await expect(field).toHaveValue(/\n1\. line one\n1\. line two\n/);
    await select(field, '1. line one\n1. line two');
    await toolbar(page, 'bq').click();
    await expect(field).toHaveValue(/\n> 1\. line one\n> 1\. line two\n/);
    await select(field, '> 1. line one\n> 1. line two');
    await toolbar(page, 'unbq').click();
    await expect(field).toHaveValue(/\n1\. line one\n1\. line two\n/);

    // headings replace each other
    await select(field, 'alpha');
    await toolbar(page, 'h2').click();
    await expect(field).toHaveValue(/^## <u>alpha<\/u> /);
    await select(field, 'alpha');
    await toolbar(page, 'h3').click();
    await expect(field).toHaveValue(/^### <u>alpha<\/u> /);

    // wiki link and task list
    await select(field, 'last line');
    await toolbar(page, 'link').click();
    await expect(field).toHaveValue(/\n\[\[last line\]\] ~~gone~~$/);
    await select(field, 'last line');
    await toolbar(page, 'tl').click();
    await expect(field).toHaveValue(/\n\* \[ \] \[\[last line\]\] ~~gone~~$/);
  });

  test('table generator and highlighted code menus insert their markup', async ({ page }) => {
    await openEditor(page, uid('Menus_'));
    const field = area(page);
    const grid = page.locator('.jstBlock table.table-generator');

    await toolbar(page, 'table').click();
    await expect(grid).toBeVisible();
    // hovering a cell highlights the table that would be created
    await grid.locator('button[title="3×2"]').hover();
    await expect(grid.locator('td.selected-cell')).toHaveCount(6);
    await grid.locator('button[title="2×1"]').hover();
    await expect(grid.locator('td.selected-cell')).toHaveCount(2);
    // Escape closes the menu without inserting anything
    await page.keyboard.press('Escape');
    await expect(grid).toHaveCount(0);
    await expect(field).toHaveValue('');
    // so does a click outside
    await toolbar(page, 'table').click();
    await expect(grid).toBeVisible();
    await page.locator('#content h2').first().click();
    await expect(grid).toHaveCount(0);

    await toolbar(page, 'table').click();
    await grid.locator('button[title="3×2"]').click();
    await expect(grid).toHaveCount(0);
    await expect(field).toHaveValue('|A |B |C |\n|--|--|--|\n|  |  |  |\n|  |  |  |\n');

    // highlighted code: language menu
    await caretAtEnd(field);
    await page.keyboard.type('\nputs 1');
    await toolbar(page, 'precode').click();
    const languages = page.locator('.jstBlock ul.jstMenu');
    await expect(languages).toBeVisible();
    await expect(languages.locator('button')).toContainText(['php', 'python', 'ruby']);
    await languages.getByRole('menuitem', { name: 'ruby', exact: true }).click();
    await expect(languages).toHaveCount(0);
    await expect(field).toHaveValue(/\n``` ruby\nputs 1\n```\n$/);
  });

  test('Enter continues lists, Tab indents the selected lines, an HTML table is pasted as a Markdown table', async ({ page }) => {
    await openEditor(page, uid('Typing_'));
    const field = area(page);
    await field.click();

    // bullet list: the marker is repeated; Enter on an empty item ends the list
    await page.keyboard.type('- first');
    await page.keyboard.press('Enter');
    await expect(field).toHaveValue('- first\n- ');
    await page.keyboard.type('second');
    await page.keyboard.press('Enter');
    await page.keyboard.press('Enter');
    await expect(field).toHaveValue('- first\n- second\n');

    // ordered list: the number is incremented; task list: a new unchecked box
    await page.keyboard.type('1. one');
    await page.keyboard.press('Enter');
    await page.keyboard.type('two');
    await page.keyboard.press('Enter');
    await page.keyboard.press('Enter');
    await page.keyboard.type('- [x] done');
    await page.keyboard.press('Enter');
    await page.keyboard.type('todo');
    await expect(field).toHaveValue('- first\n- second\n1. one\n2. two\n- [x] done\n- [ ] todo');
    // a plain line is not continued
    await page.keyboard.press('Enter');
    await page.keyboard.press('Enter');
    await page.keyboard.type('plain');
    await page.keyboard.press('Enter');
    await expect(field).toHaveValue(/- \[ \] todo\nplain\n$/);

    // Tab / Shift+Tab indent the selected lines by two spaces
    await select(field, 'one\n2. two');
    await page.keyboard.press('Tab');
    await expect(field).toHaveValue(/\n  1\. one\n  2\. two\n/);
    await expect(field).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(field).toHaveValue(/\n    1\. one\n    2\. two\n/);
    await page.keyboard.press('Shift+Tab');
    await page.keyboard.press('Shift+Tab');
    await expect(field).toHaveValue(/\n1\. one\n2\. two\n/);
    // without a selection Tab keeps its normal role (keyboard users can leave the field)
    await caretAtEnd(field);
    await page.keyboard.press('Tab');
    await expect(field).not.toBeFocused();

    // paste a table copied from a spreadsheet / web page
    await field.fill('');
    await field.evaluate((el: HTMLTextAreaElement) => {
      el.focus();
      const data = new DataTransfer();
      data.setData('text/html', '<meta charset="utf-8"><table><tr><th>Name</th><th>Qty</th></tr><tr><td>Apples | pears</td><td>3</td></tr><tr><td>Plums<br>fresh</td></tr></table>');
      data.setData('text/plain', 'Name\tQty\nApples | pears\t3\nPlums fresh');
      el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
    });
    await expect(field).toHaveValue('| Name | Qty |\n| -- | -- |\n| Apples \\| pears | 3 |\n| Plums<br>fresh |  |\n\n');
  });

  test('preview shortcut, Ctrl+Enter saves, and the rendered page', async ({ page }) => {
    const title = uid('Rendered_');
    await openEditor(page, title);
    const field = area(page);
    const mod = await modifier(page);
    const text = [
      '# Editor result',
      '',
      '| Name | Qty |',
      '| -- | -- |',
      '| Apples | 3 |',
      '',
      '* [x] done',
      '* [ ] todo',
      '',
      '``` ruby',
      'puts "hello"',
      '```',
    ].join('\n');
    await field.fill(text);

    // Ctrl/Cmd+Shift+P previews the focused field...
    await field.focus();
    await page.keyboard.press(`${mod}+Shift+p`);
    const preview = page.locator('#preview_content_text');
    await expect(preview).toBeVisible();
    await expect(field).toBeHidden();
    await expect(page.locator('.jstTabs a.tab-preview')).toHaveClass(/selected/);
    await expect(page.locator('.jstElements')).toBeHidden();
    await expect(preview.locator('table td')).toHaveText(['Apples', '3']);
    await expect(preview.locator('pre code.ruby, pre code.syntaxhl').first()).toContainText('puts "hello"');
    // ...and switches back to the editor, focused, with the text intact
    await page.keyboard.press(`${mod}+Shift+p`);
    await expect(field).toBeVisible();
    await expect(field).toBeFocused();
    await expect(field).toHaveValue(text);
    await expect(page.locator('.jstTabs a.tab-edit')).toHaveClass(/selected/);

    // Ctrl/Cmd+Enter in the textarea submits the form
    await page.keyboard.press(`${mod}+Enter`);
    await expect(page).toHaveURL(new RegExp(`/projects/ecommerce/wiki/${title}$`));
    expect(sqlValue('SELECT c.text FROM wiki_contents c JOIN wiki_pages p ON p.id = c.page_id WHERE p.title = ?', [title])).toBe(text);

    const wiki = page.locator('#content .wiki.wiki-page');
    await expect(wiki.locator('h1')).toContainText('Editor result');
    await expect(wiki.locator('table th')).toHaveText(['Name', 'Qty']);
    await expect(wiki.locator('table td')).toHaveText(['Apples', '3']);
    const boxes = wiki.locator('li input[type=checkbox]');
    await expect(boxes).toHaveCount(2);
    await expect(boxes.first()).toBeChecked();
    await expect(boxes.nth(1)).not.toBeChecked();
    await expect(boxes.first()).toBeDisabled();

    // code block: syntax highlighted
    const code = wiki.locator('pre code');
    await expect(code).toHaveText(/puts "hello"/);
    await expect(code).toHaveClass(/syntaxhl/);
    await expect(code.locator('span').first()).toBeVisible();
  });

  test('rendered code blocks get a "Copy" button that fills the clipboard', async ({ page, context, api }) => {
    const title = uid('Copy_');
    await (await api('jsmith')).createWikiPage('ecommerce', title, '``` ruby\nputs "hello"\n```');
    await page.goto(`/projects/ecommerce/wiki/${title}`);
    const wrapper = page.locator('#content .wiki .pre-wrapper');
    await expect(wrapper.locator('pre code')).toHaveText(/puts "hello"/);
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await wrapper.hover(); // the button only shows while the pointer is over the block
    await wrapper.locator('a.copy-pre-content-link').click();
    await expect.poll(() => page.evaluate(() => navigator.clipboard.readText())).toBe('puts "hello"');
  });

  test('"@" offers the users who can be mentioned and inserts the login', async ({ page }) => {
    // the issue, bulk edit and wiki forms tell the editor where to look for users (update_data_sources_for_auto_complete)
    for (const [url, selector] of [['/projects/ecommerce/issues/new', '#issue_description'], ['/projects/ecommerce/wiki/Wiki/edit', '#content_text'], ['/issues/bulk_edit?ids[]=15&ids[]=16', '#notes']]) {
      await page.goto(url);
      const field = page.locator(selector);
      await field.fill('');
      await field.pressSequentially('cc @dlo', { delay: 60 });
      const menu = page.locator('.tribute-container li');
      await expect(menu, url).toHaveText(['Dave Lopper']);
      await menu.first().click();
      await expect(field, url).toHaveValue(/^cc @dlopper\s?$/);
    }
  });

  test('the help button opens the syntax reference in a popup; the editor is the same on issue notes', async ({ page }) => {
    await page.goto('/issues/14');
    await page.locator('#content > .contextual a.icon-edit').first().click();
    const notes = page.locator('#issue_notes');
    await expect(notes).toBeFocused();
    const block = page.locator('.jstBlock', { has: notes });
    await expect(block.locator('button.jstb_strong')).toHaveAttribute('title', /Strong \(.+B\)/i);

    await notes.fill('see wiki');
    await select(notes, 'wiki');
    await block.locator('button.jstb_em').click();
    await expect(notes).toHaveValue('see *wiki*');
    // list continuation works there too
    await caretAtEnd(notes);
    await page.keyboard.press('Enter');
    await page.keyboard.type('* item');
    await page.keyboard.press('Enter');
    await expect(notes).toHaveValue('see *wiki*\n* item\n* ');

    const [popup] = await Promise.all([page.waitForEvent('popup'), block.locator('button.jstb_help').click()]);
    await popup.waitForLoadState('domcontentloaded');
    await expect(popup).toHaveURL(/\/help\/wiki_syntax/);
    await expect(popup.locator('body')).toContainText(/Wiki formatting|Font Styles|Strong/i);
    await popup.close();
    // the half-written note is still there
    await expect(notes).toHaveValue('see *wiki*\n* item\n* ');
  });
});
