/**
 * Creating an issue in the browser delivers the notification e-mail over SMTP to a real Mailpit.
 * Env-gated: SYMINE_TEST_MAILPIT_URL (Mailpit HTTP API, e.g. http://localhost:18125) and an external server
 * (E2E_BASE_URL) whose MAILER_DSN points at that Mailpit: bin/test-real-services --e2e starts both.
 */
import { test, expect, uid } from '../../support/fixtures';
import { IS_EXTERNAL } from '../../support/env';

const MAILPIT = (process.env.SYMINE_TEST_MAILPIT_URL || '').replace(/\/$/, '');

type Summary = { ID: string; Subject: string; To: { Address: string }[]; From: { Name: string; Address: string } };

async function findMessage(subject: string): Promise<Summary | undefined> {
  const res = await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`subject:"${subject}"`)}`);
  const json = (await res.json()) as { messages: Summary[] };
  return json.messages[0];
}

test.describe('notification e-mails delivered to Mailpit', () => {
  test.skip(!MAILPIT || !IS_EXTERNAL, 'SYMINE_TEST_MAILPIT_URL and E2E_BASE_URL (server sending to Mailpit) are required');
  test.use({ as: 'jsmith', dbReset: 'none' });

  test('a new issue sends issue_add to the assignee', async ({ page, ui }) => {
    const subject = uid('Mailpit e2e ');
    await page.goto('/projects/ecommerce/issues/new');
    await page.locator('#issue_subject').fill(subject);
    await page.locator('#issue_description').fill('Sent through *SMTP*');
    await page.locator('#issue_assigned_to_id').selectOption({ label: 'Dave Lopper' });
    await page.locator('#issue-form input[name=commit]').click();
    await expect(ui.flashNotice()).toContainText(/Issue #\d+ created\./);
    const id = /\/issues\/(\d+)/.exec(page.url())![1];

    await expect.poll(async () => (await findMessage(subject))?.Subject, { timeout: 15_000 }).toBe(`[eCommerce - Bug #${id}] (New) ${subject}`);
    const msg = (await findMessage(subject))!;
    expect(msg.To.map((t) => t.Address)).toEqual(['dlopper@example.net']);
    expect(msg.From).toMatchObject({ Name: 'John Smith', Address: 'redmine@example.net' });

    const headers = (await (await fetch(`${MAILPIT}/api/v1/message/${msg.ID}/headers`)).json()) as Record<string, string[]>;
    expect(headers['X-Redmine-Issue-Id']).toEqual([id]);
    expect(headers['X-Redmine-Project']).toEqual(['ecommerce']);
    expect((headers['Message-Id'] ?? headers['Message-ID'])[0]).toMatch(new RegExp(`^<redmine\\.issue-${id}\\.\\d{14}\\.6@example\\.net>$`));
    const full = (await (await fetch(`${MAILPIT}/api/v1/message/${msg.ID}`)).json()) as { Text: string; HTML: string };
    expect(full.Text).toContain(`Issue #${id} has been reported by John Smith.`);
    expect(full.Text).toContain('Sent through *SMTP*');
    expect(full.HTML).toContain('<p>Sent through <em>SMTP</em></p>');
  });
});
