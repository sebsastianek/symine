import type { Page } from '@playwright/test';
import { test, expect, uid } from '../../support/fixtures';
import { sqlValue } from '../../support/db';
import { textFile, uploadAttachment } from '../content/_helpers';

/*
 * Keyboard and accessibility basics that only a browser shows: forms driven by labels and the keyboard alone,
 * keyboard navigation of the project jump box, row selection with Ctrl/Shift + click, Escape on dialogs, arrow keys
 * between attachments, and the warning when leaving a page with unsaved text.
 */
const LIST = '/projects/ecommerce/issues?set_filter=1&status_id=*&sort=id';
const selected = (page: Page) => page.locator('table.issues tr.issue.context-menu-selection');

test.describe('keyboard and accessibility', () => {
  test.use({ as: 'jsmith' });

  test('a new issue can be created with labels and the keyboard only', async ({ page, ui }) => {
    const subject = uid('Keyboard issue ');
    await page.goto('/projects/ecommerce/issues/new');
    // the first text field of the form has the focus, every input is reachable by its label
    await expect(page.locator('#issue_subject')).toBeFocused();
    await expect(page.getByLabel('Subject')).toHaveAttribute('id', 'issue_subject');
    // the tracker change re-renders the fields through ajax (new nodes): wait for that before typing
    await page.locator('#issue_subject').evaluate((el) => el.setAttribute('data-e2e-stale', '1'));
    await page.getByLabel('Tracker').selectOption({ label: 'Feature' });
    await expect(page.locator('#issue_subject[data-e2e-stale]')).toHaveCount(0);
    await expect(page.locator('#issue_tracker_id')).toHaveValue('2');
    await page.getByLabel('Subject').focus();
    await page.keyboard.type(subject);
    await page.getByLabel('Priority').selectOption({ label: 'High' });
    await page.getByLabel('Start date').fill('2026-11-16');
    // Enter in a text field submits the form
    await page.getByLabel('Subject').press('Enter');
    await ui.expectNotice(/Issue #\d+ created/);
    await expect(page.locator('.issue.details .subject h3')).toHaveText(subject);
    await expect(page.locator('.issue.details .attributes .priority .value')).toHaveText('High');
    await expect(page.locator('.issue.details .attributes .start-date .value')).toHaveText('11/16/2026');
    expect(Number(sqlValue('SELECT tracker_id FROM issues WHERE subject = ?', [subject]))).toBe(2);

    // landmarks of the page: one h1 (project), the content heading, access keys for edit and search
    await expect(page.locator('h1')).toHaveCount(1);
    await expect(page.locator('#content > .contextual a.icon-edit').first()).toHaveAttribute('accesskey', 'e');
    await expect(page.locator('#quick-search #q')).toHaveAttribute('accesskey', 'f');
    // images and icon-only controls have a text alternative
    const unnamed = await page.locator('#content a.icon-only').evaluateAll((links) =>
      links.filter((a) => !(a.getAttribute('title') || a.getAttribute('aria-label') || (a.textContent || '').trim())).length,
    );
    expect(unnamed).toBe(0);
  });

  test('the project jump box is usable with the keyboard', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues?set_filter=1');
    await page.locator('#project-jump .drdn-trigger').click();
    const search = page.locator('#projects-quick-search');
    await expect(search).toBeFocused();
    const items = page.locator('#project-jump .drdn-items.projects a');

    // arrow keys move the focus from the field into the entries, Home / End jump to the first / last one
    const focused = page.locator('#project-jump .drdn-items.projects a:focus');
    await page.keyboard.press('ArrowDown');
    await expect(focused).toHaveCount(1);
    await page.keyboard.press('Home');
    await expect(items.first()).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(items.nth(1)).toBeFocused();
    await page.keyboard.press('ArrowUp');
    await expect(items.first()).toBeFocused();
    await page.keyboard.press('End');
    await expect(items.last()).toBeFocused();
    // Up from the first entry returns to the search field
    await page.keyboard.press('Home');
    await page.keyboard.press('ArrowUp');
    await expect(search).toBeFocused();

    // typing filters the list; Enter on the focused entry jumps, keeping the current module
    await search.focus();
    await search.pressSequentially('check', { delay: 30 });
    await expect(items).toHaveText(['Checkout']);
    await page.keyboard.press('ArrowDown');
    await expect(items.first()).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/projects\/checkout\/issues/);
    await expect(page.locator('#project-jump .drdn-trigger')).toHaveText('Checkout');

    // a click outside closes the box
    await page.locator('#project-jump .drdn-trigger').click();
    await expect(page.locator('#project-jump')).toHaveClass(/expanded/);
    await page.locator('#content h2').click();
    await expect(page.locator('#project-jump')).not.toHaveClass(/expanded/);
  });

  test('issue rows: click selects one, Ctrl/Cmd+click toggles, Shift+click selects a range', async ({ page }) => {
    await page.goto(LIST);
    const cell = (id: number) => page.locator(`tr#issue-${id} td.status`);

    await cell(13).click();
    await expect(selected(page)).toHaveCount(1);
    await expect(page.locator('tr#issue-13')).toHaveClass(/context-menu-selection/);
    await expect(page.locator('tr#issue-13 td.checkbox input')).toBeChecked();
    // a plain click on another row replaces the selection
    await cell(14).click();
    await expect(selected(page)).toHaveCount(1);
    await expect(page.locator('tr#issue-13 td.checkbox input')).not.toBeChecked();

    // Ctrl/Cmd adds and removes single rows
    await cell(16).click({ modifiers: ['ControlOrMeta'] });
    await expect(selected(page)).toHaveCount(2);
    await cell(14).click({ modifiers: ['ControlOrMeta'] });
    await expect(selected(page)).toHaveCount(1);
    await expect(page.locator('tr#issue-16')).toHaveClass(/context-menu-selection/);

    // Shift selects everything between the last selected row and the clicked one (#13..#16 in id order)
    await cell(13).click();
    await cell(16).click({ modifiers: ['Shift'] });
    await expect(selected(page)).toHaveCount(4);
    for (const id of [13, 14, 15, 16]) await expect(page.locator(`tr#issue-${id} td.checkbox input`)).toBeChecked();
    await expect(page.locator('tr#issue-19 td.checkbox input')).not.toBeChecked();

    // the context menu acts on the whole selection; a click elsewhere closes it and clears the selection
    await cell(15).click({ button: 'right' });
    const menu = page.locator('#context-menu');
    await expect(menu).toBeVisible();
    await expect(menu.locator('a.icon-edit')).toHaveAttribute('href', /ids%5B%5D=13.*ids%5B%5D=14.*ids%5B%5D=15.*ids%5B%5D=16/);
    await page.locator('#content h2').click();
    await expect(menu).toBeHidden();
    await expect(selected(page)).toHaveCount(0);
    await expect(page.locator('tr#issue-13 td.checkbox input')).not.toBeChecked();
  });

  test('Escape closes dialogs and gives the page back', async ({ page, ui }) => {
    await page.goto('/issues/14');
    await page.locator('#watchers .contextual a', { hasText: 'Add' }).click();
    const modal = await ui.expectModal();
    await expect(modal.locator('#user_search')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(ui.modal()).toBeHidden();
    await expect(page.locator('.ui-widget-overlay')).toHaveCount(0);
    // the page is interactive again
    await page.locator('#content > .contextual a.icon-edit').first().click();
    await expect(page.locator('#issue_notes')).toBeFocused();

    // same for the CSV export dialog of the issue list
    await page.goto(LIST);
    await page.locator('p.other-formats a.csv').click();
    await expect(page.locator('#csv-export-options')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('#csv-export-options')).toBeHidden();
  });

  test('arrow keys move between the attachments of an issue', async ({ page, ui }) => {
    const first = `${uid('one-')}.txt`;
    const second = `${uid('two-')}.txt`;
    await page.goto('/issues/15');
    await page.locator('#content > .contextual a.icon-edit').first().click();
    await uploadAttachment(page.locator('#issue-form'), textFile(first, 'first file\n'));
    await uploadAttachment(page.locator('#issue-form'), textFile(second, 'second file\n'));
    await page.locator('#issue-form input[name=commit]').click();
    await ui.expectNotice('Successful update.');

    await page.locator('.issue.details .attachments a.icon-attachment', { hasText: first }).click();
    await expect(page.locator('#content h2')).toContainText(first);
    await expect(page.locator('#content')).toContainText('first file');
    const pager = page.locator('.pagination.filepreview');
    await expect(pager.locator('li.current')).toHaveText('1');

    await page.keyboard.press('ArrowRight');
    await expect(page.locator('#content h2')).toContainText(second);
    await expect(page.locator('#content')).toContainText('second file');
    await expect(pager.locator('li.current')).toHaveText('2');
    // no next file: the key does nothing; Left goes back
    await page.keyboard.press('ArrowRight');
    await expect(page.locator('#content h2')).toContainText(second);
    await page.keyboard.press('ArrowLeft');
    await expect(page.locator('#content h2')).toContainText(first);
  });

  test('leaving a page with unsaved text asks for confirmation, a saved form does not', async ({ page, ui }) => {
    const dialogs: string[] = [];
    page.on('dialog', (d) => dialogs.push(`${d.type()}`));

    await page.goto('/issues/14');
    await page.locator('#content > .contextual a.icon-edit').first().click();
    await page.locator('#issue_notes').pressSequentially('half-written note');
    // leave through a link: the browser shows the "unsaved changes" prompt (accepted by the fixtures)
    await ui.mainMenu('roadmap').click();
    await expect(page).toHaveURL(/\/roadmap$/);
    expect(dialogs).toEqual(['beforeunload']);

    // untouched form: no prompt
    await page.goto('/issues/14');
    await page.locator('#content > .contextual a.icon-edit').first().click();
    await ui.mainMenu('roadmap').click();
    await expect(page).toHaveURL(/\/roadmap$/);
    expect(dialogs).toEqual(['beforeunload']);

    // submitting the text does not warn either
    await page.goto('/issues/14');
    await page.locator('#content > .contextual a.icon-edit').first().click();
    const note = uid('Saved note ');
    await page.locator('#issue_notes').pressSequentially(note);
    await page.locator('#issue-form input[name=commit]').click();
    await ui.expectNotice('Successful update.');
    await expect(page.locator('#tab-content-history .journal').last()).toContainText(note);
    expect(dialogs).toEqual(['beforeunload']);
  });
});
