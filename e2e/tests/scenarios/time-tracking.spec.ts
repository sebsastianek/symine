import type { Page } from '@playwright/test';
import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import { attribute, exportCsv, hm } from './_helpers';

/*
 * Time logged by three people on an issue tree spanning eCommerce and its subproject Checkout:
 *
 *   parent  (eCommerce, estimate 2 h)       rhill   0:45 Design
 *   ├─ api  (eCommerce, estimate 5 h)       dlopper 2:00 Development, + 4:00 Design logged for him by jsmith
 *   └─ pay  (Checkout,  estimate 3 h)       jsmith  3:15 Development
 *
 * plus the seeded entries of eCommerce (dlopper 2:30 Design on #13, jsmith 1:00 Design without issue).
 * Roles: Manager edits every entry and logs for others; Developer and Reporter only log their own time.
 */
// (inside a project the list has no "Project" column by default: asked for explicitly, the tree spans two projects)
const LIST = `/projects/ecommerce/time_entries?set_filter=1&sort=spent_on,id&${['project', 'spent_on', 'user', 'activity', 'issue', 'comments', 'hours'].map((c) => `c[]=${c}`).join('&')}`;
const total = (page: Page) => page.locator('p.query-totals .total-for-hours .value');
const entryRow = (page: Page, comment: string) => page.locator('table.time-entries tr.time-entry').filter({ hasText: comment });

test.describe('time tracking across a project tree', () => {
  test('log, roll up on the parent, report by user / activity / project, edit rights, CSV, visibility', async ({ loginAs, api }) => {
    test.setTimeout(300_000);
    const manager = await loginAs('jsmith');
    const developer = await loginAs('dlopper');
    const reporter = await loginAs('rhill');
    const admin = await loginAs('admin');
    const tag = uid('tt');
    const jsmith = await api('jsmith');
    const parent = await jsmith.createIssue({ project_id: 1, tracker_id: 2, subject: `${tag} parent`, estimated_hours: 2 });
    const apiTask = await jsmith.createIssue({ project_id: 1, tracker_id: 2, subject: `${tag} api`, parent_issue_id: parent.id, estimated_hours: 5 });
    const payTask = await jsmith.createIssue({ project_id: 3, tracker_id: 2, subject: `${tag} pay`, parent_issue_id: parent.id, estimated_hours: 3 });
    const comments = { dev: `${tag} endpoints`, forDev: `${tag} mockups`, rep: `${tag} review`, mgr: `${tag} gateway` };

    await test.step('the developer logs time from the issue; he cannot log in the subproject he is not a member of', async () => {
      const { page, ui } = developer;
      await page.goto(`/issues/${apiTask.id}`);
      await page.locator('#content > .contextual a.icon-time-add').first().click();
      await expect(page.locator('#time_entry_issue_id')).toHaveValue(String(apiTask.id));
      await expect(page.locator('#time_entry_user_id')).toHaveCount(0); // only for himself
      await page.locator('#time_entry_spent_on').fill('2026-11-10');
      await page.locator('#time_entry_hours').fill('2');
      await page.locator('#time_entry_activity_id').selectOption({ label: 'Development' });
      await page.locator('#time_entry_comments').fill(comments.dev);
      await page.locator('input[name=commit]').click();
      await ui.expectNotice('Successful creation.');

      await page.goto(`/issues/${payTask.id}`);
      await expect(page.locator('#header h1')).toContainText('Checkout');
      await expect(page.locator('#content > .contextual a.icon-time-add')).toHaveCount(0);
      expect((await developer.http.get('/projects/checkout/time_entries/new')).status()).toBe(403);
      // the REST API applies the same rule
      const res = await (await api('dlopper')).ctx.post('/time_entries.json', { data: { time_entry: { issue_id: payTask.id, hours: 1, activity_id: 9 } } });
      expect([403, 422]).toContain(res.status());
      expect(Number(sqlValue('SELECT COUNT(*) FROM time_entries WHERE issue_id = ?', [payTask.id]))).toBe(0);
    });

    await test.step('the reporter logs on the parent from the project, the manager logs for the developer and in the subproject', async () => {
      const rep = reporter.page;
      await rep.goto('/projects/ecommerce/time_entries');
      await rep.locator('#content .contextual a.icon-time-add').click();
      await rep.locator('#time_entry_spent_on').fill('2026-11-11');
      await rep.locator('#time_entry_hours').fill('0:45');
      await rep.locator('#time_entry_comments').fill(comments.rep);
      await rep.locator('#time_entry_activity_id').selectOption({ label: 'Design' });
      // the issue is picked last: choosing it refreshes the form (issue link, activities of its project)
      await reporter.ui.autocomplete(rep.locator('#time_entry_issue_id'), `${tag} parent`, new RegExp(`#${parent.id}`));
      await expect(rep.locator('#time_entry_issue_id')).toHaveValue(String(parent.id));
      await expect(rep.locator('#time_entry_issue')).toContainText(`${tag} parent`);
      await expect(rep.locator('#time_entry_activity_id option:checked')).toHaveText('Design');
      await rep.locator('input[name=commit]').click();
      await reporter.ui.expectNotice('Successful creation.');

      const { page, ui } = manager;
      await page.goto(`/issues/${apiTask.id}/time_entries/new`);
      // "log time for other users": the members of the project who may log time
      await expect(page.locator('#time_entry_user_id option', { hasText: 'Robert Hill' })).toHaveCount(1);
      await page.locator('#time_entry_user_id').selectOption({ label: 'Dave Lopper' });
      await page.locator('#time_entry_spent_on').fill('2026-11-12');
      await page.locator('#time_entry_hours').fill('4');
      await page.locator('#time_entry_activity_id').selectOption({ label: 'Design' });
      await page.locator('#time_entry_comments').fill(comments.forDev);
      await page.locator('input[name=commit]').click();
      await ui.expectNotice('Successful creation.');
      expect(sql('SELECT user_id, author_id, hours, project_id FROM time_entries WHERE comments = ?', [comments.forDev])).toEqual([
        { user_id: 6, author_id: 5, hours: 4, project_id: 1 },
      ]);
      await jsmith.logTime({ issue_id: payTask.id, hours: 3.25, activity_id: 9, spent_on: '2026-11-12', comments: comments.mgr });
      expect(sqlValue('SELECT project_id FROM time_entries WHERE comments = ?', [comments.mgr])).toBe(3);
    });

    await test.step('estimated and spent time roll up on the parent, across the projects', async () => {
      const { page } = manager;
      await page.goto(`/issues/${parent.id}`);
      await expect(attribute(page, 'estimated-hours')).toHaveText('2:00 h (Total: 10:00 h)');
      await expect(attribute(page, 'spent-time')).toHaveText('0:45 h (Total: 10:00 h)');
      // the subtasks tree, then the "Spent time" tab of the subtask
      await page.locator(`#issue_tree tr#issue-${apiTask.id} td.subject a`).click();
      await expect(attribute(page, 'spent-time')).toHaveText('6:00 h');
      await page.locator('#tab-time_entries').click();
      const tab = page.locator('#tab-content-time_entries');
      await expect(tab).toContainText(comments.dev);
      await expect(tab).toContainText(comments.forDev);
      // the total links to the time entries of the whole tree
      await page.goto(`/issues/${parent.id}`);
      await attribute(page, 'spent-time').locator('a').last().click();
      await expect(page.locator('table.time-entries tr.time-entry')).toHaveCount(4);
      await expect(total(page)).toHaveText('10:00');
    });

    await test.step('list and report of the project tree', async () => {
      const { page } = manager;
      await page.goto(LIST);
      await expect(page.locator('table.time-entries tr.time-entry')).toHaveCount(6); // 4 + 2 seeded
      await expect(total(page)).toHaveText('13:30');
      await expect(entryRow(page, comments.mgr).locator('td.project')).toHaveText('Checkout');
      await expect(entryRow(page, comments.forDev).locator('td.user')).toHaveText('Dave Lopper');
      // without the subproject
      await page.goto(`${LIST}&f[]=subproject_id&op[subproject_id]=!*`);
      await expect(total(page)).toHaveText('10:15');

      const report = page.locator('table#time-report');
      const hours = (name: string) => report.locator('tbody tr').filter({ has: page.locator('td.name', { hasText: name }) }).first().locator('td.hours').last();
      await page.goto('/projects/ecommerce/time_entries/report?set_filter=1&columns=month&criteria[]=user&criteria[]=activity');
      await expect(report.locator('thead th.period')).toHaveText(['2026-11']);
      await expect(hours('Dave Lopper')).toHaveText('8:30');
      await expect(hours('John Smith')).toHaveText('4:15');
      await expect(hours('Robert Hill')).toHaveText('0:45');
      await expect(report.locator('tr.total td.hours').last()).toHaveText('13:30');
      // second level: activities of each user (Dave Lopper: Design 2:30 + 4:00, Development 2:00)
      const lopper = report.locator('tbody tr').filter({ hasText: 'Dave Lopper' }).first();
      await expect(lopper.locator('xpath=following-sibling::tr[1]')).toContainText('Design');
      await expect(lopper.locator('xpath=following-sibling::tr[1]').locator('td.hours').last()).toHaveText('6:30');
      await expect(lopper.locator('xpath=following-sibling::tr[2]')).toContainText('Development');
      await expect(lopper.locator('xpath=following-sibling::tr[2]').locator('td.hours').last()).toHaveText('2:00');

      await page.goto('/projects/ecommerce/time_entries/report?set_filter=1&columns=year&criteria[]=project');
      await expect(hours('eCommerce')).toHaveText('10:15');
      await expect(hours('Checkout')).toHaveText('3:15');
      // by issue: the parent only counts what was logged on it
      await page.goto('/projects/ecommerce/time_entries/report?set_filter=1&columns=year&criteria[]=issue');
      await expect(hours(`#${parent.id}`)).toHaveText('0:45');
      await expect(hours(`#${apiTask.id}`)).toHaveText('6:00');
      await expect(hours(`#${payTask.id}`)).toHaveText('3:15');
    });

    await test.step('who may change what: nothing for developers until the role allows their own entries', async () => {
      const dev = developer.page;
      const own = Number(sqlValue('SELECT id FROM time_entries WHERE comments = ?', [comments.dev]));
      const loggedForHim = Number(sqlValue('SELECT id FROM time_entries WHERE comments = ?', [comments.forDev]));
      const reporters = Number(sqlValue('SELECT id FROM time_entries WHERE comments = ?', [comments.rep]));
      await dev.goto(LIST);
      await expect(dev.locator('table.time-entries tr.time-entry')).toHaveCount(6);
      await expect(dev.locator('table.time-entries a.icon-edit')).toHaveCount(0);
      expect((await developer.http.get(`/time_entries/${own}/edit`)).status()).toBe(403);

      // the administrator grants "Edit own time logs" to developers
      await admin.page.goto('/roles/4/edit');
      await admin.page.locator('#role_permissions_edit_own_time_entries').check();
      await admin.page.locator('form.edit_role input[name=commit]').click();
      await admin.ui.expectNotice('Successful update.');

      await dev.reload();
      // his entries (the one logged for him included), nobody else's
      for (const id of [own, loggedForHim, 1]) await expect(dev.locator(`tr#time-entry-${id} a.icon-edit`), `entry ${id}`).toHaveCount(1);
      for (const id of [reporters, 2]) await expect(dev.locator(`tr#time-entry-${id} a.icon-edit`), `entry ${id}`).toHaveCount(0);
      expect((await developer.http.post(`/time_entries/${reporters}`, { 'time_entry[hours]': '9' }, 'patch')).status()).toBe(403);
      expect((await developer.http.post(`/time_entries/${reporters}`, {}, 'delete')).status()).toBe(403);
      expect(sqlValue('SELECT hours FROM time_entries WHERE id = ?', [reporters])).toBe(0.75);

      await dev.locator(`tr#time-entry-${own} a.icon-edit`).click();
      await expect(dev.locator('#time_entry_hours')).toHaveValue(/^2[:.]00?$/);
      await dev.locator('#time_entry_hours').fill('2:30');
      // he may not reassign the entry to somebody else
      await expect(dev.locator('#time_entry_user_id')).toHaveCount(0);
      await dev.locator('input[name=commit]').click();
      await developer.ui.expectNotice('Successful update.');
      await expect(dev.locator(`tr#time-entry-${own} td.hours`)).toHaveText('2:30');

      // the manager corrects the reporter's entry and moves it to the subtask
      const { page, ui } = manager;
      await page.goto(LIST);
      await page.locator(`tr#time-entry-${reporters} a.icon-edit`).click();
      await page.locator('#time_entry_hours').fill('1');
      await page.locator('#time_entry_issue_id').fill(String(apiTask.id));
      await page.locator('#time_entry_issue_id').press('Tab');
      await expect(page.locator('#time_entry_issue')).toContainText(`${tag} api`); // form refreshed for the new issue
      await expect(page.locator('#time_entry_hours')).toHaveValue('1');
      await page.locator('input[name=commit]').click();
      await ui.expectNotice('Successful update.');
      expect(sql('SELECT user_id, issue_id, hours FROM time_entries WHERE id = ?', [reporters])).toEqual([{ user_id: 7, issue_id: apiTask.id, hours: 1 }]);

      // the parent: nothing logged on it anymore, total 2:30 + 4:00 + 1:00 + 3:15
      await page.goto(`/issues/${parent.id}`);
      await expect(attribute(page, 'spent-time')).toHaveText('(Total: 10:45 h)'); // own hours are not printed when there are none
    });

    await test.step('CSV export of the list', async () => {
      const { page } = manager;
      await page.goto(LIST);
      const csv = await exportCsv(page);
      expect(csv[0]).toEqual(['Project', 'Date', 'User', 'Activity', 'Issue', 'Comment', 'Hours']);
      const byComment = Object.fromEntries(csv.slice(1).map((r) => [r[5], r]));
      expect(byComment[comments.dev]).toEqual(['eCommerce', '11/10/2026', 'Dave Lopper', 'Development', `Feature #${apiTask.id}: ${tag} api`, comments.dev, '2.50']);
      expect(byComment[comments.mgr].slice(0, 4)).toEqual(['Checkout', '11/12/2026', 'John Smith', 'Development']);
      expect(byComment[comments.rep][6]).toBe('1.00');
      const sum = csv.slice(1).filter((r) => /^\d/.test(r[6]) && r[1] !== '').reduce((s, r) => s + Number(r[6]), 0);
      expect(hm(sum)).toBe('14:15');
    });

    await test.step('a role restricted to its own time entries only lists (and sums) its own hours', async () => {
      await admin.page.goto('/roles/5/edit');
      await admin.page.locator('#role_time_entries_visibility').selectOption('own');
      await admin.page.locator('form.edit_role input[name=commit]').click();
      await admin.ui.expectNotice('Successful update.');

      const rep = reporter.page;
      await rep.goto(LIST);
      // in eCommerce (Reporter) only his own entry; the entry of Checkout stays visible: there he is a non member,
      // a role that sees all time entries
      await expect(rep.locator('table.time-entries td.comments')).toHaveText([comments.rep, comments.mgr]);
      await expect(total(rep)).toHaveText('4:15');
      await rep.goto(`${LIST}&f[]=subproject_id&op[subproject_id]=!*`);
      await expect(rep.locator('table.time-entries td.comments')).toHaveText([comments.rep]);
      await expect(total(rep)).toHaveText('1:00');
      // like Redmine, the issue itself still prints the hours of everybody (Issue#spent_hours is not filtered);
      // the entries behind the link are his own only
      await rep.goto(`/issues/${apiTask.id}`);
      await expect(attribute(rep, 'spent-time')).toHaveText('7:30 h');
      await attribute(rep, 'spent-time').locator('a').click();
      await expect(rep.locator('table.time-entries td.comments')).toHaveText([comments.rep]);
      await expect(total(rep)).toHaveText('1:00');
      const entries = (await (await api('rhill')).get('/time_entries.json?project_id=1&limit=100')).time_entries as Array<{ comments: string }>;
      expect(entries.map((e) => e.comments).sort()).toEqual([comments.mgr, comments.rep].sort());
      // everybody else still sees everything
      await developer.page.goto(LIST);
      await expect(total(developer.page)).toHaveText('14:15');
    });
  });
});
