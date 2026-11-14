import type { Page } from '@playwright/test';
import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import { addMember, createCustomField, issueIdFromUrl, openActions, submitIssue } from './_helpers';

/*
 * The life of a project, driven by the administrator and its manager, checked from the seats of the people who
 * work in it: dlopper (member through the "Developers" group or directly), rhill and anna (outsiders).
 */

const projectId = (identifier: string) => Number(sqlValue('SELECT id FROM projects WHERE identifier = ?', [identifier]));
const projectStatus = (identifier: string) => sqlValue('SELECT status FROM projects WHERE identifier = ?', [identifier]);
const memberRoles = (pid: number) =>
  sql(
    `SELECT u.lastname AS principal, r.name AS role, mr.inherited_from IS NOT NULL AS inherited
     FROM members m JOIN member_roles mr ON mr.member_id = m.id JOIN roles r ON r.id = mr.role_id JOIN users u ON u.id = m.user_id
     WHERE m.project_id = ? ORDER BY u.lastname, r.name, inherited`,
    [pid],
  );

async function mainMenuItems(page: Page): Promise<string[]> {
  // (the "+" new object menu is left out)
  return page.locator('#main-menu > ul > li > a').evaluateAll((links) => links.map((a) => a.className.split(' ')[0]).filter((c) => c !== 'new-object'));
}

test.describe('project administration', () => {
  test('a private project from scratch: modules, trackers, custom field, a group, a subproject inheriting the members', async ({ loginAs }) => {
    test.setTimeout(240_000);
    const admin = await loginAs('admin');
    const manager = await loginAs('jsmith');
    const developer = await loginAs('dlopper');
    const reporter = await loginAs('rhill');
    const suffix = uid().toLowerCase();
    const name = `Mobile app ${suffix}`;
    const identifier = `mobile-${suffix}`;
    const sub = `${identifier}-ios`;
    let storeField = 0;
    let pid = 0;

    await test.step('the administrator creates the project with three modules', async () => {
      const { page, ui } = admin;
      // an issue custom field that is not "for all projects": projects opt in
      storeField = await createCustomField(page, 'IssueCustomField', 'list', `Store ${suffix}`, async (p) => {
        await p.locator('#custom_field_possible_values').fill('App Store\nGoogle Play');
        await p.locator('#custom_field_tracker_ids_1').check();
        await p.locator('#custom_field_tracker_ids_2').check();
        await expect(p.locator('#custom_field_is_for_all')).not.toBeChecked();
      });

      await page.goto('/projects');
      await ui.contextual(/New project/).click();
      await page.waitForURL(/\/projects\/new$/, { waitUntil: 'load' });
      await page.locator('#project_name').fill(name);
      await page.locator('#project_identifier').fill(identifier);
      await page.locator('#project_description').fill('Native **apps** for the shop');
      await page.locator('#project_is_public').uncheck();
      await page.locator('#project_custom_field_values_5').fill('Globex');
      for (const box of await page.locator('#project_modules input[type=checkbox]').all()) await box.uncheck();
      for (const module of ['issue_tracking', 'time_tracking', 'wiki']) await page.locator(`#project_enabled_module_names_${module}`).check();
      await page.locator('#new_project input[name=commit]').click();
      await ui.expectNotice('Successful creation.');
      await expect(page).toHaveURL(new RegExp(`/projects/${identifier}/settings$`));
      pid = projectId(identifier);
      expect(sql('SELECT is_public, status, inherit_members FROM projects WHERE id = ?', [pid])).toEqual([{ is_public: 0, status: 1, inherit_members: 0 }]);
      expect(sql('SELECT name FROM enabled_modules WHERE project_id = ? ORDER BY name', [pid]).map((r) => r.name)).toEqual(['issue_tracking', 'time_tracking', 'wiki']);
      expect(await mainMenuItems(page)).toEqual(['overview', 'activity', 'issues', 'time-entries', 'wiki', 'settings']);
    });

    await test.step('"Issue tracking" settings: Bug and Feature only, the Store field enabled', async () => {
      const { page, ui } = admin;
      await page.locator('#tab-issues').click();
      const tab = page.locator('#tab-content-issues');
      await expect(tab).toBeVisible();
      // every tracker is preselected for a new project (setting "default trackers")
      for (const id of [1, 2, 3]) await expect(tab.locator(`input[name="project[tracker_ids][]"][value="${id}"]`)).toBeChecked();
      await tab.locator('input[name="project[tracker_ids][]"][value="3"]').uncheck();
      // "for all projects" fields are checked and locked, the new one is opt-in
      await expect(tab.locator('input[name="project[issue_custom_field_ids][]"][value="1"]')).toBeDisabled();
      const store = tab.locator(`input[name="project[issue_custom_field_ids][]"][value="${storeField}"]`);
      await expect(store).not.toBeChecked();
      await store.check();
      await tab.locator('input[name=commit]').click();
      await ui.expectNotice('Successful update.');
      expect(sql('SELECT tracker_id FROM projects_trackers WHERE project_id = ? ORDER BY tracker_id', [pid]).map((r) => r.tracker_id)).toEqual([1, 2]);
      expect(sql('SELECT project_id FROM custom_fields_projects WHERE custom_field_id = ?', [storeField])).toEqual([{ project_id: pid }]);
    });

    await test.step('nobody but the administrator can open it yet', async () => {
      for (const user of [manager, developer, reporter]) {
        expect((await user.page.goto(`/projects/${identifier}`))?.status()).toBe(403);
      }
    });

    await test.step('members: jsmith as Manager, the Developers group as Developer', async () => {
      const { page } = admin;
      await addMember(page, identifier, 'John Smith', 'Manager');
      await addMember(page, identifier, 'Developers', 'Developer');
      // the group member got his own membership, flagged as inherited from the group
      expect(memberRoles(pid)).toEqual([
        { principal: 'Developers', role: 'Developer', inherited: 0 },
        { principal: 'Lopper', role: 'Developer', inherited: 1 },
        { principal: 'Smith', role: 'Manager', inherited: 0 },
      ]);
      // the settings list the group and its user; the membership coming from the group cannot be removed on its own
      const rows = page.locator('#tab-content-members tr.member');
      await expect(rows).toHaveCount(3);
      await expect(rows.filter({ hasText: 'Developers' }).locator('td.roles')).toHaveText('Developer');
      await expect(rows.filter({ hasText: 'Developers' }).locator('a.icon-link-break')).toHaveCount(1);
      await expect(rows.filter({ hasText: 'Dave Lopper' }).locator('td.roles')).toHaveText('Developer');
      await expect(rows.filter({ hasText: 'Dave Lopper' }).locator('a.icon-link-break')).toHaveCount(0);
    });

    await test.step('the developer (through the group) works in it with the trackers and fields of the project', async () => {
      const { page, ui } = developer;
      await page.goto('/projects');
      await page.locator('#projects-index a.project', { hasText: name }).click();
      await expect(page.locator('#header h1')).toContainText(name);
      await expect(page.locator('#content .wiki strong').first()).toHaveText('apps');
      await expect(page.locator('#content .members.box')).toContainText('John Smith');
      // the project menu only offers the enabled modules
      expect((await mainMenuItems(page)).slice(0, 5)).toEqual(['overview', 'activity', 'issues', 'time-entries', 'wiki']);
      await expect(ui.mainMenu('news')).toHaveCount(0);
      expect((await page.goto(`/projects/${identifier}/news`))?.status()).toBe(403);

      await page.goto(`/projects/${identifier}/issues/new`);
      await expect(page.locator('#issue_tracker_id option')).toHaveText(['Bug', 'Feature']);
      const store = page.locator(`#issue_custom_field_values_${storeField}`);
      await expect(store.locator('option')).toHaveText(['', 'App Store', 'Google Play']);
      await page.locator('#issue_subject').fill(`Crash at launch ${suffix}`);
      await store.selectOption('Google Play');
      // assignable: the members of the project, the group included (setting "allow assignment to groups")
      await expect(page.locator('#issue_assigned_to_id option', { hasText: 'Robert Hill' })).toHaveCount(0);
      await page.locator('#issue_assigned_to_id').selectOption({ label: 'Developers' });
      await submitIssue(page);
      await expect(ui.flashNotice()).toContainText(/Issue #\d+ created\./);
      const id = issueIdFromUrl(page);
      await expect(page.locator(`.issue.details .cf_${storeField}.attribute .value`)).toHaveText('Google Play');
      expect(sqlValue('SELECT project_id FROM issues WHERE id = ?', [id])).toBe(pid);

      // the opt-in field is not offered in a project that did not enable it
      await page.goto('/projects/ecommerce/issues/new');
      await expect(page.locator('#issue_custom_field_values_1')).toBeVisible();
      await expect(page.locator(`#issue_custom_field_values_${storeField}`)).toHaveCount(0);
      // outsiders still get nothing
      expect((await reporter.page.goto(`/issues/${id}`))?.status()).toBe(403);
    });

    await test.step('the manager adds a subproject that inherits the members', async () => {
      const { page, ui } = manager;
      await page.goto(`/projects/${identifier}`);
      await (await openActions(page)).getByRole('link', { name: 'New subproject' }).click();
      await page.waitForURL(/\/projects\/new\?parent_id=/, { waitUntil: 'load' });
      await expect(page.locator('#project_parent_id option:checked')).toContainText(name);
      await page.locator('#project_name').fill(`iOS ${suffix}`);
      await page.locator('#project_identifier').fill(sub);
      await page.locator('#project_is_public').uncheck(); // new projects are public by default
      await page.locator('#project_inherit_members').check();
      await page.locator('#new_project input[name=commit]').click();
      await ui.expectNotice('Successful creation.');
      const subId = projectId(sub);
      expect(sqlValue('SELECT parent_id FROM projects WHERE id = ?', [subId])).toBe(pid);
      // the creator (not an administrator) also gets the "role given to a user who creates a project" directly
      expect(memberRoles(subId)).toEqual([
        { principal: 'Developers', role: 'Developer', inherited: 1 },
        { principal: 'Lopper', role: 'Developer', inherited: 1 },
        { principal: 'Smith', role: 'Manager', inherited: 0 },
        { principal: 'Smith', role: 'Manager', inherited: 1 },
      ]);

      // inherited memberships cannot be removed in the subproject, and their inherited roles are locked
      await page.goto(`/projects/${sub}/settings/members`);
      const smith = page.locator('#tab-content-members tr.member', { hasText: 'John Smith' });
      await expect(smith.locator('td.roles')).toContainText('Manager');
      await expect(smith.locator('a.icon-link-break')).toHaveCount(0);
      await smith.locator('a.icon-edit').click();
      await expect(smith.locator('form.edit_membership label', { hasText: 'Manager' }).locator('input[type=checkbox]')).toBeDisabled();
      await expect(smith.locator('form.edit_membership label', { hasText: 'Reporter' }).locator('input[type=checkbox]')).toBeEnabled();

      // the developer reaches the subproject right away
      await developer.page.goto(`/projects/${sub}`);
      await expect(developer.page.locator('#header h1')).toContainText(`iOS ${suffix}`);
      await expect(developer.page.locator('#header h1')).toContainText(name); // breadcrumb with the parent
    });

    await test.step('a member added to (and removed from) the parent follows in the subproject', async () => {
      const subId = projectId(sub);
      expect((await reporter.page.goto(`/projects/${sub}`))?.status()).toBe(403);
      await addMember(admin.page, identifier, 'Robert Hill', 'Reporter');
      expect(memberRoles(subId)).toContainEqual({ principal: 'Hill', role: 'Reporter', inherited: 1 });
      expect((await reporter.page.goto(`/projects/${sub}`))?.status()).toBe(200);
      await expect(reporter.page.locator('#main-menu a.new-issue-sub')).toHaveCount(1);

      await admin.page.locator('#tab-content-members tr.member', { hasText: 'Robert Hill' }).locator('a.icon-link-break').click();
      await expect(admin.page.locator('#tab-content-members tr.member', { hasText: 'Robert Hill' })).toHaveCount(0);
      expect(memberRoles(subId).filter((m) => m.principal === 'Hill')).toEqual([]);
      expect((await reporter.page.goto(`/projects/${sub}`))?.status()).toBe(403);
      expect((await reporter.page.goto(`/projects/${identifier}`))?.status()).toBe(403);

      // leaving the group has the same effect for the developer, in both projects
      await admin.page.goto('/groups/10/edit?tab=users');
      await admin.page.locator('#tab-content-users tr', { hasText: 'Dave Lopper' }).locator('a.icon-link-break').click();
      // removing somebody from a group asks to type "Yes"
      await expect(admin.page.locator('#content h2')).toHaveText('Confirmation');
      await expect(admin.page.locator('#content .warning')).toContainText('Dave Lopper');
      await admin.page.locator('#confirm').fill('Yes');
      await admin.page.locator('#content input[name=commit]').click();
      await expect(admin.page.locator('#tab-content-users')).toBeVisible();
      await expect(admin.page.locator('#tab-content-users tr', { hasText: 'Dave Lopper' })).toHaveCount(0);
      expect(sql('SELECT user_id FROM groups_users WHERE group_id = 10')).toEqual([]);
      expect([...new Set(memberRoles(pid).map((m) => m.principal))]).toEqual(['Developers', 'Smith']);
      expect([...new Set(memberRoles(subId).map((m) => m.principal))]).toEqual(['Developers', 'Smith']);
      expect((await developer.page.goto(`/projects/${identifier}`))?.status()).toBe(403);
      expect((await developer.page.goto(`/projects/${sub}`))?.status()).toBe(403);
      // his direct membership of eCommerce is untouched
      expect((await developer.page.goto('/projects/ecommerce/settings'))?.status()).toBe(200);
    });
  });

  test('copy, close, reopen, archive and unarchive a project tree', async ({ loginAs, api }) => {
    test.setTimeout(240_000);
    const admin = await loginAs('admin');
    const manager = await loginAs('jsmith');
    const developer = await loginAs('dlopper');
    const adminApi = await api('admin');
    const suffix = uid().toLowerCase();
    const identifier = `life-${suffix}`;
    const sub = `${identifier}-sub`;
    const name = `Lifecycle ${suffix}`;

    // arrange through the API: a public project with a subproject (inheriting members), a shared version, a wiki
    // page and one issue in each project
    const project = await adminApi.createProject({ name, identifier, is_public: true, enabled_module_names: ['issue_tracking', 'time_tracking', 'wiki'], tracker_ids: [1, 2] });
    const child = await adminApi.createProject({ name: `${name} sub`, identifier: sub, parent_id: project.id, inherit_members: true, is_public: true, enabled_module_names: ['issue_tracking'], tracker_ids: [1, 2] });
    await adminApi.post(`/projects/${project.id}/memberships.json`, { membership: { user_id: 5, role_ids: [3] } });
    await adminApi.post(`/projects/${project.id}/memberships.json`, { membership: { user_id: 6, role_ids: [4] } });
    const { version } = await adminApi.post(`/projects/${project.id}/versions.json`, { version: { name: `v1-${suffix}`, sharing: 'descendants' } });
    await adminApi.createWikiPage(identifier, 'Wiki', `Handbook of ${name}`);
    const dev = await api('dlopper');
    const issue = await dev.createIssue({ project_id: project.id, tracker_id: 1, subject: `Parent issue ${suffix}`, fixed_version_id: version.id });
    const subIssue = await dev.createIssue({ project_id: child.id, tracker_id: 2, subject: `Sub issue ${suffix}`, fixed_version_id: version.id });

    await test.step('the administrator copies the project', async () => {
      const { page, ui } = admin;
      await page.goto(`/projects/${identifier}/copy`);
      await expect(ui.heading()).toHaveText('New project');
      await page.locator('#project_name').fill(`${name} copy`);
      await page.locator('#project_identifier').fill(`${identifier}-copy`);
      await expect(page.locator('#content fieldset', { hasText: 'Members (2)' })).toContainText('Issues (1)');
      await page.locator('#new_project input[name=commit]').click();
      await ui.expectNotice('Successful creation.');
      const copyId = projectId(`${identifier}-copy`);
      // members, version, wiki and the issue (pointing at the copied version) - not the subproject
      expect(memberRoles(copyId)).toEqual([
        { principal: 'Lopper', role: 'Developer', inherited: 0 },
        { principal: 'Smith', role: 'Manager', inherited: 0 },
      ]);
      const copiedVersion = sql('SELECT id, name, sharing FROM versions WHERE project_id = ?', [copyId]);
      expect(copiedVersion).toEqual([{ id: expect.any(Number), name: `v1-${suffix}`, sharing: 'descendants' }]);
      expect(sql('SELECT subject, fixed_version_id FROM issues WHERE project_id = ?', [copyId])).toEqual([
        { subject: `Parent issue ${suffix}`, fixed_version_id: copiedVersion[0].id },
      ]);
      expect(Number(sqlValue('SELECT COUNT(*) FROM projects WHERE parent_id = ?', [copyId]))).toBe(0);
      await page.goto(`/projects/${identifier}-copy/wiki`);
      await expect(page.locator('#content .wiki.wiki-page')).toContainText(`Handbook of ${name}`);
      // the manager finds the copy among his projects
      await manager.page.goto('/projects');
      await expect(manager.page.locator('#projects-index a.project.my-project', { hasText: `${name} copy` })).toHaveCount(1);
    });

    await test.step('the manager closes the project: the tree becomes read-only for its members', async () => {
      const { page } = manager;
      await page.goto(`/projects/${identifier}`);
      await (await openActions(page)).locator('a.icon-lock', { hasText: 'Close' }).click();
      await expect(page.locator('p.warning', { hasText: 'This project is closed and read-only.' }).first()).toBeVisible();
      expect([projectStatus(identifier), projectStatus(sub), projectStatus(`${identifier}-copy`)]).toEqual([5, 5, 1]);

      const d = developer.page;
      for (const [proj, id] of [[identifier, issue.id], [sub, subIssue.id]] as Array<[string, number]>) {
        await d.goto(`/projects/${proj}`);
        await expect(d.locator('p.warning', { hasText: 'closed and read-only' }).first()).toBeVisible();
        await expect(d.locator('#main-menu a.new-issue-sub')).toHaveCount(0);
        await d.goto(`/issues/${id}`);
        await expect(d.locator('#content h2')).toContainText(`#${id}`); // still readable
        await expect(d.locator('#content > .contextual a.icon-edit')).toHaveCount(0);
        expect((await developer.http.post(`/issues/${id}`, { 'issue[notes]': 'closed?' }, 'patch')).status()).toBe(403);
        expect((await developer.http.get(`/projects/${proj}/issues/new`)).status()).toBe(403);
      }
      expect((await developer.http.get(`/projects/${identifier}/time_entries/new`)).status()).toBe(403);
      await d.goto(`/projects/${identifier}/wiki`);
      await expect(d.locator('#content .wiki.wiki-page')).toContainText('Handbook');
      await expect(d.locator('#content .contextual a.icon-edit')).toHaveCount(0);
      expect(Number(sqlValue("SELECT COUNT(*) FROM journals WHERE notes = 'closed?'"))).toBe(0);
      // the REST API refuses writes too
      expect((await dev.ctx.put(`/issues/${issue.id}.json`, { data: { issue: { notes: 'closed?' } } })).status()).toBe(403);
      // the copy is a project of its own: still writable
      expect((await developer.http.get(`/projects/${identifier}-copy/issues/new`)).status()).toBe(200);
    });

    await test.step('reopened: writable again, subproject included', async () => {
      const { page } = manager;
      await page.goto(`/projects/${identifier}`);
      await (await openActions(page)).locator('a.icon-unlock', { hasText: 'Reopen' }).click();
      await expect(page.locator('p.warning', { hasText: 'closed and read-only' })).toHaveCount(0);
      expect([projectStatus(identifier), projectStatus(sub)]).toEqual([1, 1]);
      await developer.page.goto(`/issues/${subIssue.id}`);
      await developer.page.locator('#content > .contextual a.icon-edit').first().click();
      await developer.page.locator('#issue_notes').fill('Back in business');
      await submitIssue(developer.page);
      await expect(developer.ui.flashNotice()).toHaveText('Successful update.');
    });

    await test.step('the administrator archives the project: it disappears for everybody, with its subproject', async () => {
      const { page, ui } = admin;
      await page.goto('/admin/projects');
      const menu = await ui.openContextMenu(page.locator(`tr#project-${project.id}`));
      await menu.locator('a.icon-lock', { hasText: 'Archive' }).click();
      await expect(page.locator(`tr#project-${project.id}`)).toHaveCount(0);
      expect([projectStatus(identifier), projectStatus(sub), projectStatus(`${identifier}-copy`)]).toEqual([9, 9, 1]);

      for (const user of [developer, manager]) {
        const p = user.page;
        for (const path of [`/projects/${identifier}`, `/projects/${sub}`, `/projects/${identifier}/issues`, `/projects/${identifier}/wiki`]) {
          expect((await p.goto(path))?.status(), path).toBe(403);
          await expect(p.locator('#errorExplanation'), path).toHaveText("The project you're trying to access has been archived.");
        }
        // its issues are simply not visible anymore (like Redmine: the plain 403 page)
        for (const path of [`/issues/${issue.id}`, `/issues/${subIssue.id}`]) {
          expect((await p.goto(path))?.status(), path).toBe(403);
          await expect(p.locator('#errorExplanation'), path).toHaveText('You are not authorized to access this page.');
        }
        await p.goto('/projects');
        await expect(p.locator('#projects-index a.project', { hasText: `${name} copy` })).toHaveCount(1);
        await expect(p.locator('#projects-index a.project', { hasText: new RegExp(`^${name}( sub)?$`) })).toHaveCount(0);
        await p.goto(`/issues?set_filter=1&status_id=*&subject=~${suffix}`);
        await expect(p.locator('table.list.issues tbody tr.issue')).toHaveCount(1); // only the issue of the copy
        await p.goto(`/search?q=${suffix}&issues=1`);
        await expect(p.locator('#search-results dt')).toHaveCount(1);
      }
      expect((await dev.ctx.get(`/issues/${issue.id}.json`)).status()).toBe(403);
      expect((await dev.ctx.get(`/projects/${identifier}.json`)).status()).toBe(403);
    });

    const ARCHIVED = '/admin/projects?set_filter=1&f[]=status&op[status]==&v[status][]=9';
    const unarchive = async (id: number) => {
      const { page, ui } = admin;
      await page.goto(ARCHIVED);
      await expect(page.locator(`tr#project-${id}`)).toHaveClass(/archived/);
      const menu = await ui.openContextMenu(page.locator(`tr#project-${id}`));
      await menu.locator('a.icon-unlock', { hasText: 'Unarchive' }).click();
      await expect(page.locator(`tr#project-${id}`)).toHaveCount(0); // back on the list of archived projects
    };

    await test.step('unarchiving the parent leaves its subproject archived until it is unarchived too', async () => {
      await unarchive(project.id);
      expect([projectStatus(identifier), projectStatus(sub)]).toEqual([1, 9]);
      expect((await developer.page.goto(`/issues/${issue.id}`))?.status()).toBe(200);
      expect((await developer.page.goto(`/issues/${subIssue.id}`))?.status()).toBe(403);
      await developer.page.goto(`/projects/${identifier}`);
      await expect(developer.page.locator('#content .projects.box')).toHaveCount(0); // no visible subproject

      await unarchive(child.id);
      expect([projectStatus(identifier), projectStatus(sub)]).toEqual([1, 1]);
      expect((await developer.page.goto(`/issues/${subIssue.id}`))?.status()).toBe(200);
      await expect(developer.page.locator('#history')).toContainText('Back in business');
      // the shared version is still attached to both issues
      await developer.page.goto(`/projects/${identifier}/roadmap`);
      await expect(developer.page.locator('#roadmap article', { hasText: `v1-${suffix}` })).toBeVisible();
    });

    await test.step('archived again: unarchiving the subproject brings its archived parent back with it', async () => {
      const { page, ui } = admin;
      await page.goto('/admin/projects');
      const menu = await ui.openContextMenu(page.locator(`tr#project-${project.id}`));
      await menu.locator('a.icon-lock', { hasText: 'Archive' }).click();
      await expect(page.locator(`tr#project-${project.id}`)).toHaveCount(0);
      expect([projectStatus(identifier), projectStatus(sub)]).toEqual([9, 9]);

      await unarchive(child.id);
      expect([projectStatus(identifier), projectStatus(sub)]).toEqual([1, 1]);
      await developer.page.goto('/projects');
      await expect(developer.page.locator('#projects-index a.project', { hasText: new RegExp(`^${name}( sub)?$`) })).toHaveCount(2);
    });
  });
});
