import { test, expect } from '../../support/fixtures';
import { sqlValue } from '../../support/db';

/**
 * Remaining Redmine 7 features (gap agent): reactions, admin user list context menu + bulk lock, wiki [[ autocomplete,
 * issue previous/next navigation, bulk edit form refresh. Seed: ecommerce issues #13 (Bug), #14-#16 (Feature),
 * #17 (Bug, subproject checkout); wiki pages "Wiki" and "Checkout_flow".
 */

test.describe('reactions', () => {
  test.use({ as: 'jsmith' });

  test('clicking the reaction button toggles the reaction and updates the count', async ({ page }) => {
    await page.goto('/issues/14');
    const wrapper = page.locator('.reaction [data-reaction-button-id="reaction_issue_14"]');
    const button = wrapper.locator('a.reaction-button');
    await expect(button).not.toHaveClass(/reacted/);
    await expect(button.locator('.icon-label')).toHaveCount(0);

    await button.click();
    await expect(wrapper.locator('a.reaction-button.reacted.has-reactions')).toBeVisible();
    await expect(wrapper.locator('.icon-label')).toHaveText('1');
    // the user names are shown in the hover tooltip (jQuery UI tooltip built from the title)
    await page.mouse.move(0, 0);
    await wrapper.locator('a.reaction-button').hover();
    await expect(page.locator('.ui-tooltip-content').last()).toHaveText('John Smith');
    expect(Number(sqlValue("SELECT COUNT(*) FROM reactions WHERE reactable_type = 'Issue' AND reactable_id = 14"))).toBe(1);

    // persisted: still reacted after a reload
    await page.reload();
    await expect(page.locator('[data-reaction-button-id="reaction_issue_14"] a.reacted .icon-label')).toHaveText('1');

    await page.locator('[data-reaction-button-id="reaction_issue_14"] a.reaction-button').click();
    await expect(page.locator('[data-reaction-button-id="reaction_issue_14"] a.reaction-button')).not.toHaveClass(/reacted/);
    await expect(page.locator('[data-reaction-button-id="reaction_issue_14"] .icon-label')).toHaveCount(0);
    expect(Number(sqlValue("SELECT COUNT(*) FROM reactions WHERE reactable_type = 'Issue' AND reactable_id = 14"))).toBe(0);
  });

  test('journal and news reactions', async ({ page }) => {
    await page.goto('/issues/14?tab=history');
    const journal = page.locator('[data-reaction-button-id="reaction_journal_13"]');
    await journal.locator('a.reaction-button').click();
    await expect(journal.locator('a.reacted .icon-label')).toHaveText('1');

    await page.goto('/news/1');
    const news = page.locator('[data-reaction-button-id="reaction_news_1"]');
    await news.locator('a.reaction-button').click();
    await expect(news.locator('a.reacted .icon-label')).toHaveText('1');
  });

  test('two users: count and tooltip list both', async ({ page, loginAs }) => {
    await page.goto('/issues/15');
    await page.locator('[data-reaction-button-id="reaction_issue_15"] a.reaction-button').click();
    await expect(page.locator('[data-reaction-button-id="reaction_issue_15"] a.reacted')).toBeVisible();

    const other = await loginAs('dlopper');
    await other.page.goto('/issues/15');
    const btn = other.page.locator('[data-reaction-button-id="reaction_issue_15"] a.reaction-button');
    await expect(btn.locator('.icon-label')).toHaveText('1');
    await btn.click();
    const reacted = other.page.locator('[data-reaction-button-id="reaction_issue_15"] a.reacted');
    await expect(reacted.locator('.icon-label')).toHaveText('2');
    await other.page.mouse.move(0, 0);
    await reacted.hover();
    await expect(other.page.locator('.ui-tooltip-content').last()).toHaveText('Dave Lopper and John Smith');
  });
});

test.describe('anonymous reactions', () => {
  test('anonymous users see a read-only button', async ({ page }) => {
    await page.goto('/issues/14');
    await expect(page.locator('[data-reaction-button-id="reaction_issue_14"] span.reaction-button.readonly')).toBeVisible();
    await expect(page.locator('[data-reaction-button-id="reaction_issue_14"] a')).toHaveCount(0);
  });
});

test.describe('admin user list', () => {
  test.use({ as: 'admin' });

  test('context menu on the user list and bulk lock', async ({ page, ui }) => {
    await page.goto('/users');
    const dlopper = page.locator('tr#user-6');
    const rhill = page.locator('tr#user-7');
    await expect(dlopper).toBeVisible();

    // single user: right-click shows edit / lock / delete
    const menu = await ui.openContextMenu(dlopper);
    await expect(menu.locator('a.icon-edit')).toBeVisible();
    await expect(menu.locator('a.icon-lock')).toBeVisible();
    await expect(menu.locator('a.icon-del')).toBeVisible();
    await page.keyboard.press('Escape');
    await page.locator('#content h2').click();

    // two users selected: bulk lock
    await dlopper.locator('td.checkbox input').check();
    await rhill.locator('td.checkbox input').check();
    const bulk = await ui.openContextMenu(rhill);
    await expect(bulk.locator('a.icon-edit')).toHaveCount(0);
    await Promise.all([page.waitForURL(/\/users/), bulk.locator('a.icon-lock').click()]);
    await expect.poll(() => Number(sqlValue('SELECT COUNT(*) FROM users WHERE id IN (6, 7) AND status = 3'))).toBe(2);

    // locked users left the default (active) list
    await page.goto('/users');
    await expect(page.locator('tr#user-6')).toHaveCount(0);
  });
});

test.describe('wiki autocomplete', () => {
  test.use({ as: 'jsmith' });

  test('typing [[ in a textarea suggests wiki pages', async ({ page }) => {
    await page.goto('/projects/ecommerce/issues/new');
    const textarea = page.locator('#issue_description');
    await textarea.click();
    await textarea.pressSequentially('See [[Check', { delay: 50 });
    const item = page.locator('.tribute-container li', { hasText: 'Checkout_flow' });
    await expect(item).toBeVisible();
    await item.click();
    await expect(textarea).toHaveValue(/See \[\[Checkout_flow\]\]/);
  });
});

test.describe('issue navigation', () => {
  test.use({ as: 'jsmith' });

  test('previous / next links follow the filtered issue list', async ({ page, ui }) => {
    await page.goto('/projects/ecommerce/issues');
    await ui.setFilter('tracker_id', '=', ['2']);
    await ui.applyFilters();
    await expect(ui.listRows()).toHaveCount(3); // Feature #16, #15, #14 (id desc)

    await ui.issueRow(16).locator('td.subject a').click();
    await expect(page).toHaveURL(/\/issues\/16$/);
    const nav = page.locator('.next-prev-links');
    await expect(nav.locator('li.previous span')).toHaveText('« Previous');
    await expect(nav.locator('li.position')).toHaveText('1 of 3');

    await nav.getByRole('link', { name: 'Next »' }).click();
    await expect(page).toHaveURL(/\/issues\/15$/);
    await expect(page.locator('.next-prev-links li.position')).toHaveText('2 of 3');
    await page.locator('.next-prev-links').getByRole('link', { name: 'Next »' }).click();
    await expect(page).toHaveURL(/\/issues\/14$/);
    await expect(page.locator('.next-prev-links li.next span')).toHaveText('Next »');

    // the position links back to the list
    await page.locator('.next-prev-links li.position a').click();
    await expect(page).toHaveURL(/\/projects\/ecommerce\/issues\?.*tracker_id/);
    await expect(ui.listRows()).toHaveCount(3);
  });
});

test.describe('bulk edit', () => {
  test.use({ as: 'jsmith' });

  test('changing the tracker refreshes the bulk edit form', async ({ page }) => {
    await page.goto('/issues/bulk_edit?ids[]=13&ids[]=14');
    await expect(page.locator('#bulk_edit_form')).toBeVisible();
    await page.locator('#bulk_edit_form').evaluate((el) => el.setAttribute('data-stale', '1'));

    const refreshed = page.waitForResponse((r) => r.url().includes('/issues/bulk_edit.js') && r.request().method() === 'POST');
    await page.locator('#issue_tracker_id').selectOption('2');
    expect((await refreshed).status()).toBe(200);

    // the form was replaced (no stale marker) and keeps the selected tracker
    await expect(page.locator('#bulk_edit_form[data-stale]')).toHaveCount(0);
    await expect(page.locator('#bulk_edit_form')).toBeVisible();
    await expect(page.locator('#issue_tracker_id')).toHaveValue('2');

    // submitting applies the change
    await Promise.all([page.waitForURL(/\/issues/), page.locator('#bulk_edit_form input[type=submit]').click()]);
    expect(Number(sqlValue('SELECT tracker_id FROM issues WHERE id = 13'))).toBe(2);
  });
});
