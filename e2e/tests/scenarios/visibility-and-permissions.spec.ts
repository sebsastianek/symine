import type { Page } from '@playwright/test';
import { test, expect, uid } from '../../support/fixtures';
import { apiAnonymous, type RedmineApi } from '../../support/api';
import { sql, sqlValue } from '../../support/db';
import type { UserLogin } from '../../support/env';
import { anonymousPage, attribute, issueIdFromUrl, openEdit, selectStatus, statusOptions, submitIssue } from './_helpers';

/*
 * Who sees what, on every surface (list, search, activity, Atom feed, REST API, direct URL), and how a change made
 * by somebody else (assignment, role settings, membership, project publicity) flips it for a user who stays logged in.
 *
 * Seed: roles Manager ("all issues"), Developer / Reporter / Non member / Anonymous ("all non private issues").
 */

// every story starts from the seeded roles and memberships (they are changed by the tests)
test.use({ dbReset: 'test' });

type Target = { id: number; subject: string };

/** Checks every HTML surface of eCommerce for one browser session. `control` is a public issue everybody sees. */
async function expectIssueOnPages(page: Page, kw: string, secret: Target, control: Target, visible: boolean, who: string): Promise<void> {
  const n = visible ? 1 : 0;
  await page.goto('/projects/ecommerce/issues?set_filter=1&status_id=*&sort=id:desc');
  await expect(page.locator(`tr#issue-${control.id}`), `${who}: list (control)`).toHaveCount(1);
  await expect(page.locator(`tr#issue-${secret.id}`), `${who}: list`).toHaveCount(n);

  await page.goto(`/search?q=${kw}&issues=1`);
  await expect(page.locator('#search-results dt', { hasText: control.subject }), `${who}: search (control)`).toHaveCount(1);
  await expect(page.locator('#search-results dt', { hasText: secret.subject }), `${who}: search`).toHaveCount(n);

  await page.goto('/projects/ecommerce/activity');
  await expect(page.locator('#activity dt', { hasText: control.subject }), `${who}: activity (control)`).toHaveCount(1);
  await expect(page.locator('#activity dt', { hasText: secret.subject }), `${who}: activity`).toHaveCount(n);

  const feed = await page.request.get('/projects/ecommerce/issues.atom?set_filter=1&status_id=*');
  expect(feed.status(), `${who}: atom`).toBe(200);
  const atom = await feed.text();
  expect(atom, `${who}: atom (control)`).toContain(control.subject);
  expect(atom.includes(secret.subject), `${who}: atom`).toBe(visible);
}

async function expectIssueOnApi(client: RedmineApi, secret: Target, control: Target, status: number, who: string): Promise<void> {
  expect((await client.ctx.get(`/issues/${secret.id}.json`)).status(), `${who}: GET /issues/${secret.id}.json`).toBe(status);
  const list = await client.get('/issues.json?project_id=1&status_id=*&limit=100&sort=id:desc');
  const ids = (list.issues as Array<{ id: number }>).map((i) => i.id);
  expect(ids, `${who}: API list (control)`).toContain(control.id);
  expect(ids.includes(secret.id), `${who}: API list`).toBe(status === 200);
}

test.describe('private issue', () => {
  test('visible to the author, the assignee and "all issues" roles only; assignment, role and privacy changes flip it', async ({
    loginAs,
    api,
    browser,
  }) => {
    test.setTimeout(240_000);
    const kw = uid('zq').toLowerCase();
    const secret: Target = { id: 0, subject: `Salary export ${kw} leaks data` };
    const control: Target = { id: 0, subject: `Public ticket ${kw} about export` };

    const manager = await loginAs('jsmith');
    const developer = await loginAs('dlopper');
    const reporter = await loginAs('rhill');
    const nonMember = await loginAs('anna');
    const admin = await loginAs('admin');
    const anon = await anonymousPage(browser);
    const anonApi = await apiAnonymous();

    const everyone = async (expected: Partial<Record<UserLogin | 'anonymous', boolean>>) => {
      const sessions: Array<[UserLogin, Page]> = [
        ['jsmith', manager.page],
        ['dlopper', developer.page],
        ['rhill', reporter.page],
        ['anna', nonMember.page],
        ['admin', admin.page],
      ];
      for (const [login, page] of sessions) {
        const visible = expected[login];
        if (visible === undefined) continue;
        await expectIssueOnPages(page, kw, secret, control, visible, login);
        const res = await page.goto(`/issues/${secret.id}`);
        expect(res?.status(), `${login}: direct URL`).toBe(visible ? 200 : 403);
        await expectIssueOnApi(await api(login), secret, control, visible ? 200 : 403, login);
      }
      if (expected.anonymous !== undefined) {
        await expectIssueOnPages(anon.page, kw, secret, control, expected.anonymous, 'anonymous');
        await anon.page.goto(`/issues/${secret.id}`);
        if (expected.anonymous) await expect(anon.page.locator('#content h2')).toContainText(`#${secret.id}`);
        else await expect(anon.page).toHaveURL(/\/login\?back_url=/);
        // anonymous API calls on something not visible are asked to authenticate
        await expectIssueOnApi(anonApi, secret, control, expected.anonymous ? 200 : 401, 'anonymous');
      }
    };

    await test.step('the manager files a private issue next to a public one', async () => {
      control.id = (await (await api('jsmith')).createIssue({ project_id: 1, tracker_id: 1, subject: control.subject })).id;
      const { page, ui } = manager;
      await page.goto('/projects/ecommerce/issues/new');
      await page.locator('#issue_subject').fill(secret.subject);
      await page.locator('#issue_is_private').check();
      await submitIssue(page);
      await expect(ui.flashNotice()).toContainText(/Issue #\d+ created\./);
      secret.id = issueIdFromUrl(page);
      await expect(page.locator('#content .badge-private')).toBeVisible();
      await expect(page.locator('#content div.issue.details')).toHaveClass(/\bprivate\b/);
    });

    await test.step('only the author (and the administrator) see it', async () => {
      await everyone({ jsmith: true, admin: true, dlopper: false, rhill: false, anna: false, anonymous: false });
      // the 403 is the regular error page, the issue is not revealed
      await developer.page.goto(`/issues/${secret.id}`);
      await expect(developer.page.locator('#errorExplanation')).toHaveText('You are not authorized to access this page.');
      await expect(developer.page.locator('#content')).not.toContainText(secret.subject);
      // and he cannot comment on an issue he cannot see
      const res = await developer.http.post(`/issues/${secret.id}`, { 'issue[notes]': 'let me in' }, 'patch');
      expect(res.status()).toBe(403);
      expect(Number(sqlValue('SELECT COUNT(*) FROM journals WHERE journalized_id = ?', [secret.id]))).toBe(0);
    });

    await test.step('assigning it to the developer shows it to him right away', async () => {
      const { page, ui } = manager;
      await page.goto(`/issues/${secret.id}`);
      await openEdit(page);
      await page.locator('#issue_assigned_to_id').selectOption('6');
      await submitIssue(page);
      await expect(ui.flashNotice()).toHaveText('Successful update.');
      await everyone({ dlopper: true, rhill: false, anna: false });
      // and it is listed in his "assigned to me" block
      await developer.page.goto('/my/page');
      await expect(developer.page.locator('#block-issuesassignedtome')).toContainText(secret.subject);
    });

    await test.step('the administrator lets reporters see all issues, and restricts developers to their own', async () => {
      const { page, ui } = admin;
      await page.goto('/roles/5/edit');
      await page.locator('#role_issues_visibility').selectOption('all');
      await page.locator('form.edit_role input[name=commit]').click();
      await ui.expectNotice('Successful update.');
      await page.goto('/roles/4/edit');
      await page.locator('#role_issues_visibility').selectOption('own');
      await page.locator('form.edit_role input[name=commit]').click();
      await ui.expectNotice('Successful update.');
      expect(sql('SELECT id, issues_visibility FROM roles WHERE id IN (4, 5) ORDER BY id')).toEqual([
        { id: 4, issues_visibility: 'own' },
        { id: 5, issues_visibility: 'all' },
      ]);

      await everyone({ rhill: true, anna: false, anonymous: false });

      // developer: only the issues he created or is assigned to (directly or through the Developers group)
      const dev = developer.page;
      await dev.goto('/projects/ecommerce/issues?set_filter=1&status_id=*&sort=id');
      await expect(dev.locator('table.list.issues tbody tr.issue')).toHaveCount(2);
      await expect(dev.locator('tr#issue-13')).toBeVisible(); // assigned to the Developers group
      await expect(dev.locator(`tr#issue-${secret.id}`)).toBeVisible();
      expect((await dev.goto(`/issues/${control.id}`))?.status()).toBe(403);
      expect((await dev.goto(`/issues/${secret.id}`))?.status()).toBe(200);
      const ids = ((await (await api('dlopper')).get('/issues.json?project_id=1&status_id=*&limit=100')).issues as Array<{ id: number }>).map((i) => i.id);
      expect(ids.sort((a, b) => a - b)).toEqual([13, secret.id]);
      await dev.goto(`/search?q=${kw}&issues=1`);
      await expect(dev.locator('#search-results dt')).toHaveCount(1);
      await expect(dev.locator('#search-results dt')).toContainText(secret.subject);
    });

    await test.step('once the issue is made public, every visitor with "all non private issues" sees it', async () => {
      const { page, ui } = manager;
      await page.goto(`/issues/${secret.id}`);
      await openEdit(page);
      await page.locator('#issue_is_private').uncheck();
      await submitIssue(page);
      await expect(ui.flashNotice()).toHaveText('Successful update.');
      await expect(page.locator('#content .badge-private')).toHaveCount(0);
      await expect(page.locator('#history .journal').last()).toContainText('Private changed from Yes to No');
      await everyone({ jsmith: true, rhill: true, anna: true, anonymous: true });
    });

    await anon.context.close();
    await anonApi.dispose();
  });
});

test.describe('private project', () => {
  /** What a session sees of the private project "Internal Tools" (issue #18 "Internal tool crash"). */
  async function expectInternal(page: Page, client: RedmineApi, member: boolean, who: string): Promise<void> {
    const n = member ? 1 : 0;
    await page.goto('/projects');
    await expect(page.locator('#projects-index a.project', { hasText: 'eCommerce' }), `${who}: projects (control)`).toHaveCount(1);
    await expect(page.locator('#projects-index a.project', { hasText: 'Internal Tools' }), `${who}: projects`).toHaveCount(n);
    expect((await page.goto('/projects/internal'))?.status(), `${who}: overview`).toBe(member ? 200 : 403);
    expect((await page.goto('/issues/18'))?.status(), `${who}: issue`).toBe(member ? 200 : 403);
    await page.goto('/issues?set_filter=1&status_id=*&sort=id');
    await expect(page.locator('tr#issue-14'), `${who}: global list (control)`).toHaveCount(1);
    await expect(page.locator('tr#issue-18'), `${who}: global list`).toHaveCount(n);
    await page.goto('/search?q=crash');
    await expect(page.locator('#search-results dt', { hasText: 'Internal tool crash' }), `${who}: search`).toHaveCount(n);
    await page.goto('/activity');
    await expect(page.locator('#activity dt', { hasText: 'Internal tool crash' }), `${who}: activity`).toHaveCount(n);
    const atom = await (await page.request.get('/issues.atom?set_filter=1&status_id=*')).text();
    expect(atom.includes('Internal tool crash'), `${who}: atom`).toBe(member);

    expect((await client.ctx.get('/projects/internal.json')).status(), `${who}: API project`).toBe(member ? 200 : 403);
    expect((await client.ctx.get('/issues/18.json')).status(), `${who}: API issue`).toBe(member ? 200 : 403);
    const projects = ((await client.get('/projects.json?limit=100')).projects as Array<{ identifier: string }>).map((p) => p.identifier);
    expect(projects, `${who}: API projects (control)`).toContain('ecommerce');
    expect(projects.includes('internal'), `${who}: API projects`).toBe(member);
  }

  test('membership given, changed and removed by the administrator applies to the open session; then the project goes public', async ({
    loginAs,
    api,
    browser,
  }) => {
    test.setTimeout(240_000);
    const admin = await loginAs('admin');
    const developer = await loginAs('dlopper');
    const dev = developer.page;
    const devApi = await api('dlopper');
    const members = admin.page.locator('#tab-content-members table.members');
    const memberRow = admin.page.locator('#tab-content-members tr.member', { hasText: 'Dave Lopper' });

    await test.step('not a member: the project does not exist for him (nor for anonymous visitors)', async () => {
      await expectInternal(dev, devApi, false, 'dlopper before');
      await dev.goto('/projects/internal');
      await expect(dev.locator('#errorExplanation')).toHaveText('You are not authorized to access this page.');

      const anon = await anonymousPage(browser);
      await anon.page.goto('/projects/internal');
      await expect(anon.page).toHaveURL(/\/login\?back_url=/);
      await anon.page.goto('/projects');
      await expect(anon.page.locator('#projects-index a.project', { hasText: 'Internal Tools' })).toHaveCount(0);
      const anonApi = await apiAnonymous();
      expect((await anonApi.ctx.get('/projects/internal.json')).status()).toBe(401);
      await anonApi.dispose();
      await anon.context.close();
    });

    await test.step('the administrator adds him as a Reporter', async () => {
      const { page, ui } = admin;
      await page.goto('/projects/internal/settings/members');
      await expect(members).not.toContainText('Dave Lopper');
      await page.locator('#tab-content-members a.icon-add', { hasText: 'New member' }).click();
      const modal = await ui.expectModal('New member');
      await modal.locator('#principals label', { hasText: 'Dave Lopper' }).locator('input').check();
      await modal.locator('.roles-selection label', { hasText: 'Reporter' }).locator('input').check();
      await modal.locator('#member-add-submit').click();
      await expect(ui.modal()).toBeHidden();
      await expect(memberRow.locator('td.roles')).toHaveText('Reporter');

      // same browser session, no new login
      await expectInternal(dev, devApi, true, 'dlopper as reporter');
      await dev.goto('/projects/internal');
      await expect(dev.locator('#account .user-login')).toHaveText('@dlopper');
      await expect(dev.locator('#main-menu a.settings')).toHaveCount(0);
      await expect(dev.locator('#main-menu a.new-issue-sub')).toHaveCount(1);
      await expect(dev.locator('#content .members.box')).toContainText('Dave Lopper');
      await dev.goto('/issues/18');
      const actions = dev.locator('#content > .contextual').first();
      await expect(actions.locator('a.icon-edit')).toHaveCount(1); // notes
      await expect(actions.locator('a.icon-del')).toHaveCount(0);
      await expect(dev.locator('#issue-form #all_attributes')).toHaveCount(0);
      expect((await developer.http.post('/issues/18', {}, 'delete')).status()).toBe(403);
      expect((await developer.http.get('/projects/internal/settings')).status()).toBe(403);
    });

    await test.step('promoted to Manager: settings, full edit form and delete appear', async () => {
      const { page } = admin;
      await memberRow.locator('a.icon-edit').click();
      const form = memberRow.locator('form.edit_membership');
      await form.locator('label', { hasText: 'Manager' }).locator('input').check();
      await form.locator('label', { hasText: 'Reporter' }).locator('input').uncheck();
      await form.locator('input[name=commit]').click();
      await expect(page.locator('#tab-content-members tr.member', { hasText: 'Dave Lopper' }).locator('td.roles span').first()).toHaveText('Manager');

      await dev.goto('/issues/18');
      const actions = dev.locator('#content > .contextual').first();
      await expect(actions.locator('a.icon-del')).toHaveCount(1);
      await openEdit(dev);
      expect(await statusOptions(dev)).toEqual(['New', 'In Progress', 'Resolved', 'Feedback', 'Closed', 'Rejected']);
      await expect(dev.locator('#main-menu a.settings')).toHaveCount(1);
      await dev.locator('#main-menu a.settings').click();
      await expect(dev.locator('#tab-members')).toBeVisible();
      // he manages the members himself now
      await dev.locator('#tab-members').click();
      await expect(dev.locator('#tab-content-members a.icon-add', { hasText: 'New member' })).toBeVisible();
    });

    await test.step('removed from the project: everything is closed again, mid-session', async () => {
      const { page } = admin;
      await page.goto('/projects/internal/settings/members');
      await memberRow.locator('a.icon-link-break').click();
      await expect(memberRow).toHaveCount(0);
      expect(sql('SELECT id FROM members WHERE user_id = 6 AND project_id = 2')).toEqual([]);

      // the page he still has open keeps showing the old state, the next request is refused
      await expectInternal(dev, devApi, false, 'dlopper after removal');
      expect((await developer.http.post('/issues/18', { 'issue[notes]': 'still here?' }, 'patch')).status()).toBe(403);
      expect(Number(sqlValue("SELECT COUNT(*) FROM journals WHERE journalized_id = 18 AND notes = 'still here?'"))).toBe(0);
    });

    await test.step('the administrator makes the project public: non members and anonymous visitors get their roles', async () => {
      const { page, ui } = admin;
      await page.goto('/projects/internal/settings');
      await page.locator('#project_is_public').check();
      await page.locator('#tab-content-info input[name=commit]').click();
      await ui.expectNotice('Successful update.');
      expect(sqlValue("SELECT is_public FROM projects WHERE identifier = 'internal'")).toBe(1);

      await expectInternal(dev, devApi, true, 'dlopper, public project');
      await dev.goto('/projects/internal');
      // Non member role: may add issues and notes, no settings
      await expect(dev.locator('#main-menu a.new-issue-sub')).toHaveCount(1);
      await expect(dev.locator('#main-menu a.settings')).toHaveCount(0);
      await dev.goto('/issues/18');
      await expect(dev.locator('#content > .contextual').first().locator('a.icon-del')).toHaveCount(0);

      // Anonymous role: read only
      const anon = await anonymousPage(browser);
      await anon.page.goto('/projects/internal');
      await expect(anon.page.locator('#header h1')).toContainText('Internal Tools');
      await expect(anon.page.locator('#main-menu a.new-issue-sub')).toHaveCount(0);
      await anon.page.goto('/issues/18');
      await expect(anon.page.locator('#content h2')).toContainText('#18');
      await expect(anon.page.locator('#content > .contextual a.icon-edit')).toHaveCount(0);
      const anonApi = await apiAnonymous();
      expect((await anonApi.ctx.get('/projects/internal.json')).status()).toBe(200);
      await anonApi.dispose();
      await anon.context.close();
    });
  });
});

test.describe('role permissions', () => {
  test('granting "edit own issues" to reporters gives them the workflow-limited form on their own issues only', async ({ loginAs, api }) => {
    test.setTimeout(120_000);
    const admin = await loginAs('admin');
    const reporter = await loginAs('rhill');
    const { page } = reporter;
    const own = await (await api('rhill')).createIssue({ project_id: 1, tracker_id: 1, subject: uid('Reporter owned ') });

    await test.step('before: a reporter can only comment', async () => {
      await page.goto(`/issues/${own.id}`);
      await page.locator('#content > .contextual a.icon-edit').first().click();
      await expect(page.locator('#issue_notes')).toBeVisible();
      await expect(page.locator('#issue-form #all_attributes')).toHaveCount(0);
      const res = await reporter.http.post(`/issues/${own.id}`, { 'issue[status_id]': '5', 'issue[subject]': 'hijacked' }, 'patch');
      expect(res.status()).toBeLessThan(500);
      expect(sql('SELECT status_id, subject FROM issues WHERE id = ?', [own.id])).toEqual([{ status_id: 1, subject: own.subject }]);
    });

    await test.step('the administrator grants "Edit own issues" to the Reporter role', async () => {
      await admin.page.goto('/roles');
      await admin.page.locator('table.roles a, table.list a', { hasText: 'Reporter' }).first().click();
      await expect(admin.page.locator('#role_name')).toHaveValue('Reporter');
      await expect(admin.page.locator('#role_permissions_edit_own_issues')).not.toBeChecked();
      await admin.page.locator('#role_permissions_edit_own_issues').check();
      await admin.page.locator('form.edit_role input[name=commit]').click();
      await admin.ui.expectNotice('Successful update.');
      expect(String(sqlValue('SELECT permissions FROM roles WHERE id = 5'))).toContain('edit_own_issues');
    });

    await test.step('the reporter (still logged in) edits his own issue within his workflow', async () => {
      await page.reload();
      await openEdit(page);
      await expect(page.locator('#issue-form #all_attributes')).toBeVisible();
      // Reporter workflow: New -> Closed only
      expect(await statusOptions(page)).toEqual(['New', 'Closed']);
      await page.locator('#issue_subject').fill(`${own.subject} (clarified)`);
      await page.locator('#issue_priority_id').selectOption({ label: 'High' });
      await selectStatus(page, 'Closed');
      await page.locator('#issue_notes').fill('Not needed anymore');
      await submitIssue(page);
      await expect(reporter.ui.flashNotice()).toHaveText('Successful update.');
      await expect(attribute(page, 'status')).toHaveText('Closed');
      await expect(attribute(page, 'priority')).toHaveText('High');
      await expect(page.locator('.issue.details .subject h3')).toHaveText(`${own.subject} (clarified)`);
      // closed issues cannot be reopened by a reporter: no transition from Closed, the status is plain text
      await openEdit(page);
      await expect(page.locator('#issue_subject')).toBeVisible();
      await expect(page.locator('#issue_status_id')).toHaveCount(0);
      await reporter.http.post(`/issues/${own.id}`, { 'issue[status_id]': '1' }, 'patch');
      expect(sqlValue('SELECT status_id FROM issues WHERE id = ?', [own.id])).toBe(5);

      // somebody else's issue: still notes only, attribute changes are ignored
      await page.goto('/issues/13');
      await page.locator('#content > .contextual a.icon-edit').first().click();
      await expect(page.locator('#issue_notes')).toBeVisible();
      await expect(page.locator('#issue-form #all_attributes')).toHaveCount(0);
      await reporter.http.post('/issues/13', { 'issue[priority_id]': '1' }, 'patch');
      expect(sqlValue('SELECT priority_id FROM issues WHERE id = 13')).toBe(3);
    });

    await test.step('the administrator takes "add notes" and "edit own issues" away: no update at all', async () => {
      await admin.page.goto('/roles/5/edit');
      await admin.page.locator('#role_permissions_edit_own_issues').uncheck();
      await admin.page.locator('#role_permissions_add_issue_notes').uncheck();
      await admin.page.locator('form.edit_role input[name=commit]').click();
      await admin.ui.expectNotice('Successful update.');

      for (const id of [13, own.id]) {
        await page.goto(`/issues/${id}`);
        await expect(page.locator('#content h2')).toContainText(`#${id}`);
        await expect(page.locator('#content > .contextual a.icon-edit')).toHaveCount(0);
        await expect(page.locator('#issue-form')).toHaveCount(0);
        expect((await reporter.http.post(`/issues/${id}`, { 'issue[notes]': 'one more thing' }, 'patch')).status()).toBe(403);
      }
      expect(Number(sqlValue("SELECT COUNT(*) FROM journals WHERE notes = 'one more thing'"))).toBe(0);
      // the permission report shows the Reporter column without both permissions
      await admin.page.goto('/roles/permissions');
      await expect(admin.page.locator('input[name="permissions[5][]"][value="add_issue_notes"]')).not.toBeChecked();
      await expect(admin.page.locator('input[name="permissions[5][]"][value="add_issues"]')).toBeChecked();
    });
  });
});
