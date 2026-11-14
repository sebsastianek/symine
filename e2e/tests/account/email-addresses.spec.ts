import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';

/*
 * Additional e-mail addresses (My account » Emails): a modal driven by remote (ajax) forms and links.
 * Seeded: every user has one default address (dlopper@example.net = user 6).
 */
test.describe('additional e-mail addresses', () => {
  test.describe('My account', () => {
    test.use({ as: 'dlopper' });

    test('add (with validation), disable notifications, delete: all inside the modal', async ({ page, ui }) => {
      const address = `${uid('dave.')}@example.net`;
      await page.goto('/my/account');
      await ui.contextual('Emails').click();
      const modal = await ui.expectModal('Emails');
      // the default address is not listed, only the additional ones
      await expect(modal.locator('table.email_addresses')).toHaveCount(0);
      await expect(modal.locator('#email_address_address')).toBeFocused();

      // invalid address: the error is rendered in the modal, which stays open with the typed value
      await modal.locator('#email_address_address').fill('not-an-address');
      await modal.locator('input[name=commit]').click();
      await expect(modal.locator('#errorExplanation')).toContainText('Email is invalid');
      await expect(modal.locator('#email_address_address')).toHaveValue('not-an-address');
      // an address of another user is refused too
      await modal.locator('#email_address_address').fill('jsmith@example.net');
      await modal.locator('input[name=commit]').click();
      await expect(modal.locator('#errorExplanation')).toContainText('Email has already been taken');
      expect(Number(sqlValue('SELECT COUNT(*) FROM email_addresses WHERE user_id = 6'))).toBe(1);

      // Enter submits the form through ajax; the list is refreshed and the field emptied
      await modal.locator('#email_address_address').fill(address);
      await modal.locator('#email_address_address').press('Enter');
      const row = modal.locator('table.email_addresses tr', { hasText: address });
      await expect(row).toBeVisible();
      await expect(modal.locator('#errorExplanation')).toHaveCount(0);
      await expect(modal.locator('#email_address_address')).toHaveValue('');
      expect(sql('SELECT is_default, notify FROM email_addresses WHERE address = ?', [address])[0]).toEqual({ is_default: 0, notify: 1 });

      // toggle the notifications of that address
      await row.locator('a.icon-email').click();
      await expect(row.locator('a.icon-email-disabled')).toBeVisible();
      await expect.poll(() => Number(sqlValue('SELECT notify FROM email_addresses WHERE address = ?', [address]))).toBe(0);
      await row.locator('a.icon-email-disabled').click();
      await expect(row.locator('a.icon-email')).toBeVisible();
      await expect.poll(() => Number(sqlValue('SELECT notify FROM email_addresses WHERE address = ?', [address]))).toBe(1);

      // the modal survives a page reload of the account page (data is server side)
      await page.reload();
      await ui.contextual('Emails').click();
      await expect(ui.modal().locator('table.email_addresses td.email')).toHaveText([address]);

      // delete (confirm dialog accepted)
      await ui.modal().locator('table.email_addresses tr', { hasText: address }).locator('a.icon-del').click();
      await expect(ui.modal().locator('table.email_addresses')).toHaveCount(0);
      await expect.poll(() => Number(sqlValue('SELECT COUNT(*) FROM email_addresses WHERE user_id = 6'))).toBe(1);
    });

    test('notifications are also sent to an additional address, unless disabled for it', async ({ page, ui, loginAs, mailbox }) => {
      const address = `${uid('dave.alt.')}@example.net`;
      sql("UPDATE users SET mail_notification = 'all' WHERE id = 6");
      await page.goto('/my/account');
      await ui.contextual('Emails').click();
      const modal = await ui.expectModal('Emails');
      await modal.locator('#email_address_address').fill(address);
      await modal.locator('input[name=commit]').click();
      const row = modal.locator('table.email_addresses tr', { hasText: address });
      await expect(row).toBeVisible();

      const manager = await loginAs('jsmith');
      const addNote = async (note: string) => {
        await manager.page.goto('/issues/14');
        await manager.page.locator('#content > .contextual a.icon-edit').first().click();
        await manager.page.locator('#issue_notes').fill(note);
        await manager.page.locator('#issue-form input[name=commit]').click();
        await manager.ui.expectNotice('Successful update.');
      };

      const first = uid('First note ');
      await addNote(first);
      await mailbox.waitFor({ to: 'dlopper@example.net', body: first });
      await mailbox.waitFor({ to: address, body: first });

      // notifications disabled for the additional address: only the default one gets the next mail
      await row.locator('a.icon-email').click();
      await expect(row.locator('a.icon-email-disabled')).toBeVisible();
      const second = uid('Second note ');
      await addNote(second);
      await mailbox.waitFor({ to: 'dlopper@example.net', body: second });
      expect(mailbox.find({ to: address, body: second })).toHaveLength(0);
    });
  });

  test.describe('administration', () => {
    test.use({ as: 'admin' });

    test('the limit of additional addresses hides the form, then the "Emails" link', async ({ page, ui, loginAs }) => {
      const first = `${uid('rob.one.')}@example.net`;
      const second = `${uid('rob.two.')}@example.net`;
      const setLimit = async (limit: string) => {
        await page.goto('/settings?tab=users');
        await page.locator('#settings_max_additional_emails').fill(limit);
        await page.locator('#tab-content-users input[type=submit][value="Save"]').click();
        await ui.expectNotice('Successful update.');
      };

      await setLimit('1');
      const rhill = await loginAs('rhill');
      await rhill.page.goto('/my/account');
      await rhill.ui.contextual('Emails').click();
      const modal = await rhill.ui.expectModal('Emails');
      await modal.locator('#email_address_address').fill(first);
      await modal.locator('input[name=commit]').click();
      await expect(modal.locator('table.email_addresses td.email')).toHaveText([first]);
      // limit reached: the form is gone
      await expect(modal.locator('#email_address_address')).toHaveCount(0);
      expect(Number(sqlValue('SELECT COUNT(*) FROM email_addresses WHERE user_id = 7'))).toBe(2);

      // deleting the address frees the slot
      await modal.locator('table.email_addresses a.icon-del').click();
      await expect(modal.locator('table.email_addresses')).toHaveCount(0);
      await modal.locator('#email_address_address').fill(second);
      await modal.locator('input[name=commit]').click();
      await expect(modal.locator('table.email_addresses td.email')).toHaveText([second]);

      // the administrator sees the additional address on the user form
      await page.goto('/users/7/edit');
      await expect(ui.contextual('Emails')).toBeVisible();

      // with a limit of 0 the "Emails" link disappears from My account
      await setLimit('0');
      await rhill.page.goto('/my/account');
      await expect(rhill.page.locator('#content > .contextual a.icon-email-add')).toHaveCount(0);
      await expect(rhill.page.locator('#content > .contextual a.icon-passwd')).toBeVisible();

      // back to Redmine's default, for the tests that follow
      await setLimit('5');
    });

    test('the administrator manages the addresses of another user from the user form', async ({ page, ui }) => {
      const address = `${uid('rob.admin.')}@example.net`;
      await page.goto('/users/7/edit');
      await ui.contextual('Emails').click();
      await expect(page).toHaveURL(/\/users\/7\/email_addresses$/);
      await expect(ui.heading()).toHaveText('Robert Hill');
      await page.locator('#email_address_address').fill(address);
      await page.locator('#new_email_address input[name=commit]').click();
      await expect(page.locator('table.email_addresses td.email', { hasText: address })).toHaveCount(1);
      expect(Number(sqlValue('SELECT user_id FROM email_addresses WHERE address = ?', [address]))).toBe(7);
    });
  });
});
