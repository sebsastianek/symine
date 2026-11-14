import { test, expect, uid, ensureFileReset } from '../../support/fixtures';
import { apiAs } from '../../support/api';
import { openEdit, submitEdit } from './_helpers';
import { textFile, uploadAttachment } from '../content/_helpers';

/*
 * The history block of the issue page: tabs (client-side filtering + the ajax "Spent time" tab), the description
 * diff, links between attachments and the note that added them, "Copy link" entries and the sticky issue header.
 *
 * Issue #13 after beforeAll (order = note numbers):
 *   #1  dlopper  private note "Started working on it" + status / % done changes   (seed, journal 15)
 *   #2  jsmith   note only "A plain note"
 *   #3  jsmith   property change only (priority Urgent)
 * and one time entry (dlopper 2:30 Design "Investigation").
 */
test.beforeAll(async ({}, testInfo) => {
  await ensureFileReset(testInfo);
  const jsmith = await apiAs('jsmith');
  await jsmith.updateIssue(13, { notes: 'A plain note' });
  await jsmith.updateIssue(13, { priority_id: 4 });
  await jsmith.dispose();
});

test.describe('issue history', () => {
  test.use({ as: 'jsmith' });

  test('History / Notes / Property changes filter the journals without reloading; Spent time is loaded on demand', async ({ page }) => {
    await page.goto('/issues/13');
    const journals = page.locator('#tab-content-history .journal');
    await expect(page.locator('#tab-history')).toHaveClass(/selected/);
    await expect(journals).toHaveCount(3);
    for (let i = 0; i < 3; i++) await expect(journals.nth(i)).toBeVisible();

    // Notes: journals without a note are hidden
    await page.locator('#tab-notes').click();
    await expect(page.locator('#tab-notes')).toHaveClass(/selected/);
    await expect(page.locator('#tab-history')).not.toHaveClass(/selected/);
    await expect(page).toHaveURL(/\/issues\/13\?tab=notes$/);
    await expect(journals.nth(0)).toBeVisible();
    await expect(journals.nth(1)).toBeVisible();
    await expect(journals.nth(2)).toBeHidden();
    await expect(journals.nth(1).locator('.journal-note')).toHaveText('A plain note');

    // Property changes: journals without details are hidden, and the notes of the others too
    await page.locator('#tab-properties').click();
    await expect(page).toHaveURL(/\?tab=properties$/);
    await expect(journals.nth(0)).toBeVisible();
    await expect(journals.nth(0).locator('ul.journal-details')).toContainText('Status changed from New to In Progress');
    await expect(journals.nth(0).locator('.journal-note')).toBeHidden();
    await expect(journals.nth(1)).toBeHidden();
    await expect(journals.nth(2)).toBeVisible();
    await expect(journals.nth(2).locator('ul.journal-details')).toContainText('Priority changed from High to Urgent');

    // Spent time: fetched through ajax the first time
    const request = page.waitForResponse((r) => new URL(r.url()).pathname === '/issues/13/tab/time_entries');
    await page.locator('#tab-time_entries').click();
    expect((await request).status()).toBe(200);
    const spent = page.locator('#tab-content-time_entries');
    await expect(spent).toBeVisible();
    await expect(page.locator('#tab-content-history')).toBeHidden();
    await expect(spent).toContainText('Dave Lopper');
    await expect(spent).toContainText('Investigation');
    await expect(spent).toContainText('2:30');
    await expect(page).toHaveURL(/\?tab=time_entries$/);

    // back to the full history
    await page.locator('#tab-history').click();
    await expect(spent).toBeHidden();
    for (let i = 0; i < 3; i++) await expect(journals.nth(i)).toBeVisible();

    // the tab of the URL is applied on load (e.g. a shared link)
    await page.goto('/issues/13?tab=notes');
    await expect(page.locator('#tab-notes')).toHaveClass(/selected/);
    await expect(journals.nth(2)).toBeHidden();
    await expect(journals.nth(1)).toBeVisible();
  });

  test('a description change is journalized with a diff page', async ({ page, ui }) => {
    await page.goto('/issues/16');
    const form = await openEdit(page);
    // the description editor is collapsed behind a pencil link
    await expect(page.locator('#issue_description_and_toolbar')).toBeHidden();
    await form.locator('#issue_description_and_toolbar').locator('xpath=..').locator('a.icon-edit').click();
    await expect(page.locator('#issue_description')).toBeVisible();
    const before = await page.locator('#issue_description').inputValue();
    await page.locator('#issue_description').fill(`${before}\nSend a weekly digest of the wishlist.`.trim());
    await submitEdit(page);
    await ui.expectNotice('Successful update.');

    const detail = page.locator('#tab-content-history .journal').last().locator('ul.journal-details li');
    await expect(detail).toContainText('Description updated');
    await detail.getByRole('link', { name: 'diff' }).click();
    await expect(page).toHaveURL(/\/journals\/\d+\/diff\?detail_id=\d+/);
    await expect(page.locator('#content h2')).toHaveText('Feature #16');
    await expect(page.locator('#content')).toContainText('Updated by John Smith');
    const diff = page.locator('pre.text-diff');
    await expect(diff.locator('.diff_in')).toContainText('weekly digest');

    // "Back" returns to the issue
    await page.locator('#content').getByRole('link', { name: 'Back' }).click();
    await expect(page).toHaveURL(/\/issues\/16/);
    await expect(page.locator('.issue.details .description .wiki')).toContainText('Send a weekly digest of the wishlist.');
  });

  test('an attachment links to the note that added it, switching back to the History tab if needed', async ({ page, ui }) => {
    const name = `${uid('trace-')}.txt`;
    await page.goto('/issues/13');
    await openEdit(page);
    await uploadAttachment(page.locator('#issue-form'), textFile(name));
    await submitEdit(page);
    await ui.expectNotice('Successful update.');

    // the upload is the last journal, without notes
    const journals = page.locator('#tab-content-history .journal');
    const added = journals.last();
    await expect(added.locator('ul.journal-details')).toContainText(new RegExp(`File\\s+${name}[\\s\\S]*added`));
    await expect(added).not.toHaveClass(/has-notes/);
    const anchor = await added.locator('a.journal-link').getAttribute('href');
    expect(anchor).toMatch(/^#note-\d+$/);
    const row = page.locator('.issue.details .attachments tr', { hasText: name });
    const link = row.locator('.attachment-journal a');
    await expect(link).toHaveAttribute('href', anchor!);

    // on the Notes tab that journal is hidden: the link brings the full history back and jumps to it
    await page.locator('#tab-notes').click();
    await expect(added).toBeHidden();
    await link.click();
    await expect(page.locator('#tab-history')).toHaveClass(/selected/);
    await expect(added).toBeVisible();
    await expect(page).toHaveURL(new RegExp(`${anchor}$`));
    await expect(page.locator(anchor!)).toBeInViewport();
  });

  test('"Copy link" of the issue and of a note put the URL on the clipboard and close the menu', async ({ page, ui, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await page.goto('/issues/13');
    const origin = new URL(page.url()).origin;

    const menu = await ui.openActionsMenu();
    await menu.locator('a.icon-copy-link').click();
    await expect(menu).toBeHidden();
    await expect.poll(() => page.evaluate(() => navigator.clipboard.readText())).toBe(`${origin}/issues/13`);

    const note = page.locator('#tab-content-history .journal').nth(1);
    const noteMenu = await ui.openActionsMenu(note);
    await noteMenu.locator('a.icon-copy-link').click();
    await expect(noteMenu).toBeHidden();
    await expect.poll(() => page.evaluate(() => navigator.clipboard.readText())).toBe(`${origin}/issues/13#note-2`);

    // opening the copied link shows the note
    await page.goto(`${origin}/issues/13#note-2`);
    await expect(page.locator('#note-2')).toBeInViewport();
    await expect(page.locator('#note-2 .journal-note')).toHaveText('A plain note');
  });

  test('the sticky header shows the issue subject once the title is scrolled out of view', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 500 });
    await page.goto('/issues/13');
    const sticky = page.locator('#sticky-issue-header');
    await expect(sticky).not.toHaveClass(/is-visible/);

    await page.locator('#history').scrollIntoViewIfNeeded();
    await page.mouse.wheel(0, 600);
    await expect(sticky).toHaveClass(/is-visible/);
    await expect(sticky).toBeInViewport();
    await expect(sticky.locator('.issue-heading')).toHaveText('Bug #13:');
    await expect(sticky.locator('.subject')).toHaveText('Cart total is wrong with discounts');

    await page.evaluate(() => window.scrollTo(0, 0));
    await expect(sticky).not.toHaveClass(/is-visible/);
  });
});
