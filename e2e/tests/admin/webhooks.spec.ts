import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import * as crypto from 'crypto';
import * as http from 'http';
import * as os from 'os';

/*
 * Webhooks: disabled by default (Administration » Settings » Integrations). Users whose role has "use_webhooks"
 * (Manager: jsmith) manage their own hooks on /webhooks, administrators review all of them on /admin/webhooks.
 * Loopback / link-local endpoints are refused (SSRF protection), so the delivery test listens on a LAN address.
 */

/** First non-internal IPv4 address of this machine (webhook endpoints must not be loopback). */
function lanAddress(): string | null {
  for (const list of Object.values(os.networkInterfaces())) {
    for (const a of list ?? []) {
      if (a.family === 'IPv4' && !a.internal && !a.address.startsWith('169.254.')) return a.address;
    }
  }
  return null;
}

// TEST-NET-3 (RFC 5737): a syntactically public address that is never contacted (the hooks using it stay inactive)
const UNUSED_ENDPOINT = 'https://203.0.113.10/redmine-hook';

test.describe('webhooks', () => {
  test.describe('administrator', () => {
    test.use({ as: 'admin' });

    test('webhooks are disabled until enabled in the Integrations settings', async ({ page, ui, loginAs }) => {
      await page.goto('/admin/webhooks');
      await expect(ui.adminMenu('webhooks')).toHaveClass(/selected/);
      await expect(page.locator('#content p.warning')).toContainText('Webhooks are disabled');
      await expect(page.locator('#content > .contextual a.icon-add')).toHaveCount(0);
      // a manager cannot use them either
      const manager = await loginAs('jsmith');
      await manager.http.expectStatus('/webhooks', 403);

      await page.locator('#content p.warning a', { hasText: 'Integrations' }).click();
      await expect(page).toHaveURL(/\/settings\?tab=integrations/);
      await expect(page.locator('#tab-content-integrations')).toBeVisible();
      await page.locator('#settings_webhooks_enabled').check();
      await page.locator('#tab-content-integrations input[type=submit][value="Save"]').click();
      await ui.expectNotice('Successful update.');
      expect(sqlValue("SELECT value FROM settings WHERE name = 'webhooks_enabled'")).toBe('1');

      await page.goto('/admin/webhooks');
      await expect(page.locator('#content p.warning')).toHaveCount(0);
      await expect(page.locator('#content > .contextual a.icon-add')).toBeVisible();
      await expect(page.locator('#content p.nodata')).toBeVisible();
      await manager.http.expectStatus('/webhooks', 200);
    });
  });

  test.describe('manager', () => {
    test.use({ as: 'jsmith' });

    test('create with validation and "check all" toggles, edit, reviewed by the administrator, delete', async ({ page, ui, loginAs }) => {
      await page.goto('/webhooks');
      await expect(ui.heading()).toHaveText('Webhooks');
      await ui.contextual('New webhook').click();
      await expect(ui.heading()).toHaveText('New webhook');
      // only the projects where the user may use webhooks are offered (Manager on ecommerce and checkout)
      await expect(page.locator('#webhook_project_ids label')).toHaveText([/eCommerce/, /Checkout/]);

      // a loopback endpoint is refused, the form keeps the other values
      await page.locator('#webhook_url').fill('http://127.0.0.1:8080/hook');
      await page.locator('#issue_events legend a').click();
      await expect(page.locator('#issue_events input[type=checkbox]:checked')).toHaveCount(3);
      await page.locator('#new_webhook input[name=commit]').click();
      await ui.expectError(/URL is invalid/);
      await expect(page.locator('#webhook_url')).toHaveValue('http://127.0.0.1:8080/hook');
      await expect(page.locator('#issue_events input[type=checkbox]:checked')).toHaveCount(3);

      // toggles: "Check all" of the events box, the legend toggle of a group, the projects legend
      await page.locator('#events').getByRole('link', { name: 'Check all', exact: true }).click();
      await expect(page.locator('#events input[type=checkbox]:checked')).toHaveCount(15);
      await page.locator('#events').getByRole('link', { name: 'Uncheck all', exact: true }).click();
      await expect(page.locator('#events input[type=checkbox]:checked')).toHaveCount(0);
      await page.locator('#version_events legend a').click();
      await page.locator('#webhook_events_issue\\.created').check();
      await page.locator('#webhook_project_ids legend a').click();
      await expect(page.locator('#webhook_project_ids input[type=checkbox]:checked')).toHaveCount(2);

      await page.locator('#webhook_url').fill(UNUSED_ENDPOINT);
      await page.locator('#webhook_secret').fill('s3cret');
      await expect(page.locator('#webhook_active')).not.toBeChecked();
      await page.locator('#new_webhook input[name=commit]').click();
      await ui.expectNotice('Successful creation.');
      await expect(page).toHaveURL(/\/webhooks$/);

      const id = Number(sqlValue('SELECT id FROM webhooks WHERE url = ?', [UNUSED_ENDPOINT]));
      const row = page.locator(`tr#webhook_${id}`);
      await expect(row.locator('td').nth(0)).toHaveText('No');
      await expect(row.locator('td').nth(1)).toHaveText(UNUSED_ENDPOINT);
      await expect(row.locator('td').nth(2).locator('code')).toHaveText(['issue.created', 'version.created', 'version.updated', 'version.deleted']);
      await expect(row.locator('td').nth(3).locator('a')).toHaveText(['eCommerce', 'Checkout']);
      expect(sql('SELECT user_id, active, secret FROM webhooks WHERE id = ?', [id])[0]).toEqual({ user_id: 5, active: 0, secret: 's3cret' });

      // edit: the form is prefilled; drop a project and the version events
      await row.locator('a.icon-edit').click();
      await expect(ui.heading()).toHaveText('Edit webhook');
      await expect(page.locator('#webhook_url')).toHaveValue(UNUSED_ENDPOINT);
      await expect(page.locator('#webhook_events_version\\.deleted')).toBeChecked();
      await page.locator('#version_events legend a').click();
      await expect(page.locator('#version_events input[type=checkbox]:checked')).toHaveCount(0);
      await page.locator('#webhook_project_ids input[value="3"]').uncheck();
      await page.locator('#content form input[name=commit]').click();
      await ui.expectNotice('Successful update.');
      await expect(row.locator('td').nth(2).locator('code')).toHaveText(['issue.created']);
      await expect(row.locator('td').nth(3).locator('a')).toHaveText(['eCommerce']);
      expect(sql('SELECT project_id FROM projects_webhooks WHERE webhook_id = ?', [id])).toEqual([{ project_id: 1 }]);

      // a developer (no use_webhooks permission) has no access
      const developer = await loginAs('dlopper');
      await developer.http.expectStatus('/webhooks', 403);

      // the administrator sees every user's hooks with their owner, and may remove them
      const admin = await loginAs('admin');
      await admin.page.goto('/admin/webhooks');
      const adminRow = admin.page.locator(`tr#webhook_${id}`);
      await expect(adminRow.locator('td').first()).toHaveText('John Smith');
      await expect(adminRow.locator('td').nth(2)).toHaveText(UNUSED_ENDPOINT);
      await adminRow.locator('a.icon-del').click();
      await admin.ui.expectNotice('Successful deletion.');
      await expect(admin.page).toHaveURL(/\/admin\/webhooks$/);
      await expect(admin.page.locator(`tr#webhook_${id}`)).toHaveCount(0);

      await page.goto('/webhooks');
      await expect(page.locator('#content p.nodata')).toBeVisible();
      expect(Number(sqlValue('SELECT COUNT(*) FROM webhooks'))).toBe(0);
    });

    test('an active webhook delivers a signed "issue.created" payload when an issue is created', async ({ page, ui }) => {
      const address = lanAddress();
      test.skip(!address, 'no non-loopback IPv4 address: webhook endpoints cannot be local');

      type Delivery = { headers: http.IncomingHttpHeaders; body: string };
      const deliveries: Delivery[] = [];
      const server = http.createServer((req, res) => {
        const chunks: Buffer[] = [];
        req.on('data', (c: Buffer) => chunks.push(c));
        req.on('end', () => {
          deliveries.push({ headers: req.headers, body: Buffer.concat(chunks).toString('utf8') });
          res.writeHead(204).end();
        });
      });
      await new Promise<void>((resolve) => server.listen(0, '0.0.0.0', resolve));
      const port = (server.address() as { port: number }).port;
      const secret = uid('secret-');

      try {
        await page.goto('/webhooks/new');
        await page.locator('#webhook_url').fill(`http://${address}:${port}/hooks/redmine`);
        await page.locator('#webhook_secret').fill(secret);
        await page.locator('#webhook_active').check();
        await page.locator('#webhook_events_issue\\.created').check();
        await page.locator('#webhook_project_ids input[value="1"]').check();
        await page.locator('#new_webhook input[name=commit]').click();
        await ui.expectNotice('Successful creation.');
        await expect(page.locator('table.webhooks tbody tr td').first()).toHaveText('Yes');

        // an issue of another project does not trigger the hook, one of eCommerce does
        const subject = uid('Hooked ');
        await page.goto('/projects/checkout/issues/new');
        await page.locator('#issue_subject').fill(`${subject} elsewhere`);
        await page.locator('#issue-form input[name=commit]').click();
        await ui.expectNotice(/Issue #\d+ created/);

        await page.goto('/projects/ecommerce/issues/new');
        await page.locator('#issue_subject').fill(subject);
        await page.locator('#issue-form input[name=commit]').click();
        await ui.expectNotice(/Issue #\d+ created/);
        const issueId = Number(page.url().match(/\/issues\/(\d+)/)![1]);

        await expect.poll(() => deliveries.length, { timeout: 20_000 }).toBe(1);
        const [delivery] = deliveries;
        expect(delivery.headers['content-type']).toContain('application/json');
        expect(delivery.headers['x-redmine-signature-256']).toBe(`sha256=${crypto.createHmac('sha256', secret).update(delivery.body).digest('hex')}`);
        const payload = JSON.parse(delivery.body);
        expect(payload.type).toBe('issue.created');
        expect(JSON.stringify(payload)).toContain(subject);
        expect(JSON.stringify(payload)).not.toContain(`${subject} elsewhere`);
        expect(payload.data.issue.id).toBe(issueId);

        // updates are not subscribed: no further delivery
        await page.locator('#content > .contextual a.icon-edit').first().click();
        await page.locator('#issue_notes').fill('no hook for this');
        await page.locator('#issue-form input[name=commit]').click();
        await ui.expectNotice('Successful update.');
        await page.goto('/webhooks');
        await expect(page.locator('table.webhooks tbody tr')).toHaveCount(1);
        expect(deliveries.length).toBe(1);
      } finally {
        // never leave an active hook behind for the rest of the file
        sql('DELETE FROM webhooks');
        await new Promise((resolve) => server.close(resolve));
      }
    });
  });
});
