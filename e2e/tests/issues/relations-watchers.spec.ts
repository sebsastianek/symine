import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';

test.use({ as: 'jsmith' });

test.describe('relations', () => {
  test('add a relation through the autocomplete and remove it', async ({ page }) => {
    await page.goto('/issues/16');
    const relations = page.locator('#relations');
    await relations.locator('.contextual a.icon-link-add').click();
    const form = page.locator('#new-relation-form');
    await expect(form).toBeVisible();
    await form.locator('#relation_relation_type').selectOption('blocks');
    await page.locator('#relation_issue_to_id').pressSequentially('Cart', { delay: 30 });
    await page.locator('ul.ui-autocomplete:visible li', { hasText: '#13' }).first().click();
    await expect(page.locator('#relation_issue_to_id')).toHaveValue(/13/);
    await form.locator('input[type=submit]').click();

    const row = relations.locator('table.list tr.issue', { hasText: 'Cart total is wrong with discounts' });
    await expect(row).toBeVisible();
    await expect(row).toContainText('Blocks');
    // like Redmine (create.js): the form stays open, emptied and focused for the next relation
    await expect(form).toBeVisible();
    await expect(page.locator('#relation_issue_to_id')).toHaveValue('');
    expect(Number(sqlValue("SELECT COUNT(*) FROM issue_relations WHERE issue_from_id = 16 AND issue_to_id = 13 AND relation_type = 'blocks'"))).toBe(1);

    // the related issue shows the reverse relation
    await page.goto('/issues/13');
    await expect(page.locator('#relations tr', { hasText: 'Wishlist emails' })).toContainText('Blocked by');

    await page.goto('/issues/16');
    await row.locator('a.icon-link-break').click(); // confirm accepted, ajax delete
    await expect(row).toHaveCount(0);
    expect(Number(sqlValue("SELECT COUNT(*) FROM issue_relations WHERE issue_from_id = 16 AND issue_to_id = 13"))).toBe(0);
  });

  test('"precedes" shows the delay field and invalid relations show an error', async ({ page }) => {
    await page.goto('/issues/18');
    await page.locator('#relations .contextual a.icon-link-add').click();
    await expect(page.locator('#predecessor_fields')).toBeHidden();
    await page.locator('#relation_relation_type').selectOption('precedes');
    await expect(page.locator('#predecessor_fields')).toBeVisible();
    await page.locator('#relation_relation_type').selectOption('relates');

    await page.locator('#relation_issue_to_id').fill('999999');
    await page.locator('#new-relation-form input[type=submit]').click();
    await expect(page.locator('#new-relation-form #errorExplanation')).toBeVisible();

    await page.locator('#relation_issue_to_id').fill('14, 15');
    await page.locator('#new-relation-form input[type=submit]').click();
    await expect(page.locator('#relations table.list tr.issue')).toHaveCount(2);
    await expect(page.locator('#relations')).toContainText('Wishlist sharing');
  });
});

test.describe('subtasks', () => {
  test('add a subtask from the parent and see it in the tree', async ({ page, ui }) => {
    await page.goto('/issues/14');
    await expect(page.locator('#issue_tree table.list tr.issue')).toHaveCount(2);
    await page.locator('#issue_tree .contextual a.icon-add').click();
    await expect(page).toHaveURL(/issues\/new/);
    await expect(page.locator('#issue_parent_issue_id')).toHaveValue('14');
    const subject = uid('Child ');
    await page.locator('#issue_subject').fill(subject);
    await page.locator('#issue_estimated_hours').fill('2');
    await page.locator('#issue-form input[name=commit]').click();
    await expect(ui.flashNotice()).toContainText('created');
    // "Create" goes back to the parent (back_url), like Redmine; "Create and follow" would open the child
    await expect(page).toHaveURL(/\/issues\/14$/);
    const child = Number(sqlValue('SELECT id FROM issues WHERE subject = ?', [subject]));

    await expect(page.locator('#issue_tree table.list tr.issue')).toHaveCount(3);
    await expect(page.locator(`#issue_tree tr#issue-${child}`)).toContainText(subject);
    // parent estimated time is derived from the children: "(Total: h:mm h)"
    const total = Number(sqlValue('SELECT SUM(estimated_hours) FROM issues WHERE parent_id = 14'));
    const hm = `${Math.floor(total)}:${String(Math.round((total % 1) * 60)).padStart(2, '0')}`;
    await expect(page.locator('.estimated-hours.attribute .value')).toContainText(`Total: ${hm} h`);

    // "Remove subtask" link of the tree
    await page.locator(`#issue_tree tr#issue-${child} a`, { hasText: 'Remove subtask' }).click();
    await expect(page.locator(`#issue_tree tr#issue-${child}`)).toHaveCount(0);
    expect(sqlValue('SELECT parent_id FROM issues WHERE id = ?', [child])).toBeNull();
  });

  test('change the parent through the edit form and remove it', async ({ page, ui }) => {
    await page.goto('/issues/18'); // internal, jsmith is Developer (manage_subtasks)
    await page.locator('#content > .contextual a.icon-edit').first().click();
    await page.locator('#issue_parent_issue_id').fill('16'); // other project: not allowed by default (tree)
    await page.locator('#issue-form input[name=commit]').click();
    await expect(ui.errors()).toContainText('Parent task is invalid');

    await page.goto('/issues/15');
    await page.locator('#content > .contextual a.icon-edit').first().click();
    await page.locator('#issue_parent_issue_id').fill('');
    await page.locator('#issue-form input[name=commit]').click();
    await expect(ui.flashNotice()).toHaveText('Successful update.');
    expect(sqlValue('SELECT parent_id FROM issues WHERE id = 15')).toBeNull();
    await expect(page.locator('#history .journal').last()).toContainText('Parent task deleted');
  });
});

test.describe('watchers', () => {
  test('watch / unwatch toggles through ajax', async ({ page }) => {
    await page.goto('/issues/18');
    const link = page.locator('#content > .contextual a.issue-18-watcher').first();
    await expect(link).toHaveText(/^\s*Watch\s*$/);
    await link.click();
    await expect(link).toHaveText(/Unwatch/);
    expect(Number(sqlValue("SELECT COUNT(*) FROM watchers WHERE watchable_type = 'Issue' AND watchable_id = 18 AND user_id = 5"))).toBe(1);
    await link.click();
    await expect(link).toHaveText(/^\s*Watch\s*$/);
    expect(Number(sqlValue("SELECT COUNT(*) FROM watchers WHERE watchable_type = 'Issue' AND watchable_id = 18 AND user_id = 5"))).toBe(0);
  });

  test('add watchers through the modal search and remove one', async ({ page, ui }) => {
    await page.goto('/issues/16');
    await expect(page.locator('#watchers h3')).toContainText('Watchers (1)');
    await page.locator('#watchers .contextual a', { hasText: 'Add' }).click();
    const modal = await ui.expectModal();
    await modal.locator('#user_search').pressSequentially('Lop', { delay: 30 });
    await expect(modal.locator('#users_for_watcher label', { hasText: 'Robert Hill' })).toHaveCount(0);
    await modal.locator('#users_for_watcher label', { hasText: 'Dave Lopper' }).locator('input').check();
    await modal.locator('#user_search').fill('');
    await modal.locator('#user_search').pressSequentially('Rob', { delay: 30 });
    await modal.locator('#users_for_watcher label', { hasText: 'Robert Hill' }).locator('input').check();
    await modal.locator('p.buttons input[type=submit]').click();
    await expect(ui.modal()).toBeHidden();

    await expect(page.locator('#watchers h3')).toContainText('Watchers (3)');
    await expect(page.locator('#watchers ul.watchers')).toContainText('Dave Lopper');
    await expect(page.locator('#watchers ul.watchers')).toContainText('Robert Hill');

    await page.locator('#watchers ul.watchers li', { hasText: 'Dave Lopper' }).locator('a.delete').click();
    await expect(page.locator('#watchers h3')).toContainText('Watchers (2)');
    await expect(page.locator('#watchers ul.watchers')).not.toContainText('Dave Lopper');
    expect(sql("SELECT user_id FROM watchers WHERE watchable_type = 'Issue' AND watchable_id = 16 ORDER BY user_id")).toEqual([
      { user_id: 5 },
      { user_id: 7 },
    ]);
  });

  test('a watcher receives a notification e-mail for an update', async ({ page, mailbox }) => {
    // rhill watches #13 (seed); notification option of rhill: make sure he gets watched issues
    sql("UPDATE users SET mail_notification = 'only_my_events' WHERE id = 7");
    await page.goto('/issues/13');
    await page.locator('#content > .contextual a.icon-edit').first().click();
    const note = uid('Notify watchers ');
    await page.locator('#issue_notes').fill(note);
    await page.locator('#issue-form input[name=commit]').click();
    await expect(page.locator('#flash_notice')).toHaveText('Successful update.');
    const mail = await mailbox.waitFor({ to: 'rhill@example.net', body: note });
    expect(mail.subject).toContain('#13');
  });
});
