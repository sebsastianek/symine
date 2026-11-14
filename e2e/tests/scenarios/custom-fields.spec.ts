import type { Page } from '@playwright/test';
import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import { applyQuery, createCustomField, exportCsv, issueIdFromUrl, journal, listedIssueIds, openEdit, selectStatus, submitIssue } from './_helpers';

/*
 * Issue custom fields from their definition by the administrator to what each role gets of them: form, validation,
 * issue page, history, list columns and filters, CSV and REST API.
 *
 *   Environment  list, required, filter                 Go-live   date, read-only for developers on new bugs
 *   Reviewer     user, required for developers when a bug is resolved
 *   Risk         key/value list                         Cost      float, visible to managers only
 */
test.describe('custom fields end to end', () => {
  const suffix = uid();
  const names = {
    env: `Environment ${suffix}`,
    golive: `Go-live ${suffix}`,
    reviewer: `Reviewer ${suffix}`,
    risk: `Risk ${suffix}`,
    cost: `Cost ${suffix}`,
  };
  type Key = keyof typeof names;
  const ids = {} as Record<Key, number>;
  const input = (page: Page, key: Key) => page.locator(`#issue_custom_field_values_${ids[key]}`);
  const shown = (page: Page, key: Key) => page.locator(`.issue.details .cf_${ids[key]}.attribute .value`);

  test('definition, role visibility, workflow rules, validation, list, filters, CSV and API', async ({ loginAs, api }) => {
    test.setTimeout(300_000);
    const admin = await loginAs('admin');
    const manager = await loginAs('jsmith');
    const developer = await loginAs('dlopper');
    const subject = `Custom fields story ${suffix}`;
    let id = 0;

    await test.step('the administrator defines five fields for bugs', async () => {
      const { page, ui } = admin;
      const bugsEverywhere = async (p: Page) => {
        await p.locator('#custom_field_tracker_ids_1').check();
        await p.locator('#custom_field_is_for_all').check();
      };
      ids.env = await createCustomField(page, 'IssueCustomField', 'list', names.env, async (p) => {
        await p.locator('#custom_field_possible_values').fill('Production\nStaging\nDevelopment');
        await p.locator('#custom_field_is_required').check();
        await p.locator('#custom_field_is_filter').check();
        await bugsEverywhere(p);
      });
      ids.golive = await createCustomField(page, 'IssueCustomField', 'date', names.golive, async (p) => {
        await p.locator('#custom_field_is_filter').check();
        await bugsEverywhere(p);
      });
      ids.reviewer = await createCustomField(page, 'IssueCustomField', 'user', names.reviewer, bugsEverywhere);
      ids.risk = await createCustomField(page, 'IssueCustomField', 'enumeration', names.risk, bugsEverywhere);
      ids.cost = await createCustomField(page, 'IssueCustomField', 'float', names.cost, async (p) => {
        await bugsEverywhere(p);
        // visible to the Manager role only
        await p.locator('#custom_field_visible_off').check();
        await p.locator('input[name="custom_field[role_ids][]"][value="3"]').check();
      });
      expect(sql('SELECT visible, is_required, is_for_all FROM custom_fields WHERE id IN (?, ?) ORDER BY id', [ids.env, ids.cost])).toEqual([
        { visible: 1, is_required: 1, is_for_all: 1 },
        { visible: 0, is_required: 0, is_for_all: 1 },
      ]);
      expect(sql('SELECT role_id FROM custom_fields_roles WHERE custom_field_id = ?', [ids.cost])).toEqual([{ role_id: 3 }]);

      // the key/value list gets its values on its own page
      await page.goto(`/custom_fields/${ids.risk}/enumerations`);
      for (const v of ['Low risk', 'High risk']) {
        await page.locator('#custom_field_enumeration_name').fill(v);
        await page.locator('#content input[type=submit][value="Add"]').click();
        await expect(page.locator(`input[id$="_name"][value="${v}"]`)).toHaveCount(1);
      }

      // workflow field permissions for Developer / Bug: Go-live read-only while New, Reviewer required once Resolved
      await page.goto('/workflows/permissions?role_id[]=4&tracker_id[]=1');
      await page.locator(`#permissions_1_${ids.golive}`).selectOption('readonly');
      await page.locator(`#permissions_3_${ids.reviewer}`).selectOption('required');
      await page.locator('#workflow_form input[type=submit]').click();
      await ui.expectNotice('Successful update.');
      expect(
        sql("SELECT old_status_id, field_name, rule FROM workflows WHERE type = 'WorkflowPermission' AND role_id = 4 AND tracker_id = 1 AND field_name IN (?, ?) ORDER BY old_status_id", [
          String(ids.golive),
          String(ids.reviewer),
        ]),
      ).toEqual([
        { old_status_id: 1, field_name: String(ids.golive), rule: 'readonly' },
        { old_status_id: 3, field_name: String(ids.reviewer), rule: 'required' },
      ]);
    });

    await test.step('the manager files a bug: every field is on the form and validated', async () => {
      const { page, ui } = manager;
      await page.goto('/projects/ecommerce/issues/new');
      for (const key of Object.keys(names) as Key[]) await expect(page.locator(`label[for=issue_custom_field_values_${ids[key]}]`), key).toContainText(names[key]);
      await expect(page.locator(`label[for=issue_custom_field_values_${ids.env}] .required`)).toBeVisible();
      await expect(input(page, 'env').locator('option')).toHaveText(['--- Please select ---', 'Production', 'Staging', 'Development']);
      await expect(input(page, 'risk').locator('option')).toHaveText(['', 'Low risk', 'High risk']);
      await expect(input(page, 'reviewer').locator('option', { hasText: 'Dave Lopper' })).toHaveCount(1);
      // the fields belong to the Bug tracker only
      await page.locator('#issue_tracker_id').selectOption({ label: 'Feature' });
      await expect(input(page, 'env')).toHaveCount(0);
      await page.locator('#issue_tracker_id').selectOption({ label: 'Bug' });
      await expect(input(page, 'env')).toBeVisible();

      await page.locator('#issue_subject').fill(subject);
      await input(page, 'cost').fill('a lot');
      await submitIssue(page);
      await expect(ui.errors()).toContainText(`${names.env} cannot be blank`);
      await expect(ui.errors()).toContainText(`${names.cost} is invalid`);
      expect(Number(sqlValue('SELECT COUNT(*) FROM issues WHERE subject = ?', [subject]))).toBe(0);

      await input(page, 'env').selectOption('Staging');
      await input(page, 'golive').fill('2026-12-24');
      await input(page, 'reviewer').selectOption({ label: 'Dave Lopper' });
      await input(page, 'risk').selectOption({ label: 'High risk' });
      await input(page, 'cost').fill('1200.5');
      await page.locator('#issue_assigned_to_id').selectOption('6');
      await submitIssue(page);
      await expect(ui.flashNotice()).toContainText(/Issue #\d+ created\./);
      id = issueIdFromUrl(page);
      await expect(shown(page, 'env')).toHaveText('Staging');
      await expect(shown(page, 'golive')).toHaveText('12/24/2026');
      await expect(shown(page, 'reviewer')).toHaveText('Dave Lopper');
      await expect(shown(page, 'risk')).toHaveText('High risk');
      await expect(shown(page, 'cost')).toHaveText('1200.50');
    });

    await test.step('the developer does not see the managers-only field and is bound by the workflow rules', async () => {
      const { page, ui } = developer;
      await page.goto(`/issues/${id}`);
      await expect(shown(page, 'env')).toHaveText('Staging');
      await expect(shown(page, 'risk')).toHaveText('High risk');
      await expect(page.locator(`.issue.details .cf_${ids.cost}`)).toHaveCount(0);
      await expect(page.locator('.issue.details')).not.toContainText(names.cost);

      await openEdit(page);
      await expect(input(page, 'env')).toHaveValue('Staging');
      await expect(input(page, 'cost')).toHaveCount(0);
      // Go-live is read-only for him while the bug is New: no input, and forged values for it or for the field he
      // cannot see are ignored
      await expect(input(page, 'golive')).toHaveCount(0);
      await developer.http.post(`/issues/${id}`, { [`issue[custom_field_values][${ids.golive}]`]: '2027-01-01', [`issue[custom_field_values][${ids.cost}]`]: '1' }, 'patch');
      expect(sql('SELECT custom_field_id, value FROM custom_values WHERE customized_id = ? AND custom_field_id IN (?, ?) ORDER BY custom_field_id', [id, ids.golive, ids.cost])).toEqual([
        { custom_field_id: ids.golive, value: '2026-12-24' },
        { custom_field_id: ids.cost, value: '1200.5' },
      ]);

      // In Progress: the date becomes editable; Resolved: the reviewer is required
      // (same form as above: the ignored request did not touch the issue, so there is no edit conflict)
      await selectStatus(page, 'In Progress');
      await expect(input(page, 'golive')).toHaveValue('2026-12-24');
      await expect(page.locator('label[for=issue_due_date] .required')).toBeVisible(); // seeded rule
      await selectStatus(page, 'Resolved');
      await expect(page.locator(`label[for=issue_custom_field_values_${ids.reviewer}] .required`)).toBeVisible();
      await input(page, 'reviewer').selectOption('');
      await input(page, 'golive').fill('2026-12-31');
      await submitIssue(page);
      await expect(ui.errors()).toContainText(`${names.reviewer} cannot be blank`);
      expect(sqlValue('SELECT status_id FROM issues WHERE id = ?', [id])).toBe(1);
      await input(page, 'reviewer').selectOption({ label: 'John Smith' });
      await submitIssue(page);
      await expect(ui.flashNotice()).toHaveText('Successful update.');
      await expect(shown(page, 'reviewer')).toHaveText('John Smith');
      await expect(shown(page, 'golive')).toHaveText('12/31/2026');
      const details = page.locator('#history .journal').last().locator('.journal-details');
      await expect(details).toContainText(`${names.reviewer} changed from Dave Lopper to John Smith`);
      await expect(details).toContainText(`${names.golive} changed from 12/24/2026 to 12/31/2026`);
    });

    await test.step('a change of the restricted field is only shown to those who may see the field', async () => {
      const { page, ui } = manager;
      await page.goto(`/issues/${id}`);
      await openEdit(page);
      await input(page, 'cost').fill('1500');
      await page.locator('#issue_notes').fill('Budget raised');
      await submitIssue(page);
      await expect(ui.flashNotice()).toHaveText('Successful update.');
      await expect(journal(page, 'Budget raised').locator('.journal-details')).toContainText(`${names.cost} changed from 1200.50 to 1500.00`);

      await developer.page.goto(`/issues/${id}`);
      const forDeveloper = journal(developer.page, 'Budget raised');
      await expect(forDeveloper).toBeVisible();
      await expect(forDeveloper).not.toContainText(names.cost);
      await expect(developer.page.locator('#history')).not.toContainText('1500');

      // REST API: same rule
      const cf = async (login: 'jsmith' | 'dlopper' | 'rhill') => {
        const issue = await (await api(login)).issue(id, 'journals');
        return Object.fromEntries((issue.custom_fields as Array<{ id: number; value: string }>).map((f) => [f.id, f.value]));
      };
      const asManager = await cf('jsmith');
      expect(asManager[ids.cost]).toBe('1500');
      expect(asManager[ids.env]).toBe('Staging');
      for (const login of ['dlopper', 'rhill'] as const) {
        const fields = await cf(login);
        expect(fields[ids.env], login).toBe('Staging');
        expect(Object.keys(fields), login).not.toContain(String(ids.cost));
      }
    });

    await test.step('issue list: columns, filters and CSV follow the same visibility', async () => {
      const columns = (['env', 'golive', 'reviewer', 'risk', 'cost'] as Key[]).map((k) => `c[]=cf_${ids[k]}`).join('&');
      const list = `/projects/ecommerce/issues?set_filter=1&status_id=*&sort=id:desc&c[]=subject&${columns}`;

      const { page, ui } = manager;
      await page.goto(list);
      const row = ui.issueRow(id);
      await expect(row.locator(`td.cf_${ids.env}`)).toHaveText('Staging');
      await expect(row.locator(`td.cf_${ids.golive}`)).toHaveText('12/31/2026');
      await expect(row.locator(`td.cf_${ids.reviewer}`)).toHaveText('John Smith');
      await expect(row.locator(`td.cf_${ids.risk}`)).toHaveText('High risk');
      await expect(row.locator(`td.cf_${ids.cost}`)).toHaveText('1500.00');
      // seeded bug #13 has no value
      await expect(ui.issueRow(13).locator(`td.cf_${ids.env}`)).toHaveText('');

      // filter on the list field (declared "used as a filter"), then on the date
      await ui.setFilter(`cf_${ids.env}`, '=', ['Staging']);
      await applyQuery(page);
      expect(await listedIssueIds(page)).toEqual([id]);
      await ui.setFilter(`cf_${ids.env}`, '!', ['Staging']);
      await applyQuery(page);
      expect(await listedIssueIds(page)).not.toContain(id);
      expect(await listedIssueIds(page)).toContain(13);
      await page.goto(`${list}&f[]=cf_${ids.golive}&op[cf_${ids.golive}]=><&v[cf_${ids.golive}][]=2026-12-01&v[cf_${ids.golive}][]=2026-12-31`);
      expect(await listedIssueIds(page)).toEqual([id]);
      // fields not declared as filters are not offered
      await expect(page.locator(`#add_filter_select option[value="cf_${ids.env}"]`)).toHaveCount(1);
      await expect(page.locator(`#add_filter_select option[value="cf_${ids.risk}"]`)).toHaveCount(0);

      // CSV of the manager has the five columns
      await page.goto(list);
      const csv = await exportCsv(page);
      expect(csv[0]).toEqual(['#', 'Subject', names.env, names.golive, names.reviewer, names.risk, names.cost]);
      expect(csv.find((r) => r[0] === String(id))).toEqual([String(id), subject, 'Staging', '12/31/2026', 'John Smith', 'High risk', '1500.00']);

      // the developer asking for the same columns does not get the restricted one, anywhere
      const dev = developer.page;
      await dev.goto(list);
      await expect(developer.ui.issueRow(id).locator(`td.cf_${ids.env}`)).toHaveText('Staging');
      await expect(dev.locator(`table.list.issues th.cf_${ids.cost}, table.list.issues td.cf_${ids.cost}`)).toHaveCount(0);
      await developer.ui.expandFieldset('Options');
      await expect(dev.locator(`#available_c option[value="cf_${ids.risk}"], #selected_c option[value="cf_${ids.risk}"]`)).toHaveCount(1);
      await expect(dev.locator(`#available_c option[value="cf_${ids.cost}"], #selected_c option[value="cf_${ids.cost}"]`)).toHaveCount(0);
      const devCsv = await exportCsv(dev);
      expect(devCsv[0]).toEqual(['#', 'Subject', names.env, names.golive, names.reviewer, names.risk]);
      expect(devCsv.flat().join('|')).not.toContain('1500');
      // nor can he filter on it to guess the value
      await dev.goto(`/projects/ecommerce/issues?set_filter=1&status_id=*&f[]=cf_${ids.cost}&op[cf_${ids.cost}]==&v[cf_${ids.cost}][]=1500`);
      await expect(dev.locator(`#filters #tr_cf_${ids.cost}`)).toHaveCount(0);
    });

    await test.step('the administrator retires a list value and deletes a field', async () => {
      const { page, ui } = admin;
      // "Staging" is removed from the possible values: existing issues keep it, new ones cannot pick it
      await page.goto(`/custom_fields/${ids.env}/edit`);
      await page.locator('#custom_field_possible_values').fill('Production\nDevelopment');
      await page.locator('#content input[type=submit][value="Save"]').first().click();
      await ui.expectNotice('Successful update.');
      await manager.page.goto('/projects/ecommerce/issues/new');
      await expect(input(manager.page, 'env').locator('option')).toHaveText(['--- Please select ---', 'Production', 'Development']);
      await manager.page.goto(`/issues/${id}`);
      await expect(shown(manager.page, 'env')).toHaveText('Staging');

      // deleting the field removes its values and its workflow rules
      await page.goto('/custom_fields');
      await page.locator('table.list tbody tr').filter({ has: page.getByRole('link', { name: names.reviewer, exact: true }) }).getByRole('link', { name: 'Delete' }).click();
      await ui.expectNotice('Successful deletion.');
      expect(Number(sqlValue('SELECT COUNT(*) FROM custom_values WHERE custom_field_id = ?', [ids.reviewer]))).toBe(0);
      expect(Number(sqlValue("SELECT COUNT(*) FROM workflows WHERE type = 'WorkflowPermission' AND field_name = ?", [String(ids.reviewer)]))).toBe(0);
      await manager.page.reload();
      await expect(manager.page.locator('.issue.details')).not.toContainText(names.reviewer);
      await expect(shown(manager.page, 'risk')).toHaveText('High risk');
    });
  });
  // Redmine: Issue#editable_custom_field_values rejects the fields the workflow makes read-only.
  // (Rule set through SQL so that the test stands alone; the story above sets one through Administration > Workflow.)
  test('a custom field made read-only by the workflow is not on the form and cannot be changed', async ({ loginAs }) => {
    // Developer / Bug / In Progress (seeded #13, Database = PostgreSQL): Database is read-only
    sql("INSERT INTO workflows (tracker_id, old_status_id, new_status_id, role_id, assignee, author, type, field_name, rule) VALUES (1, 2, 0, 4, 0, 0, 'WorkflowPermission', '1', 'readonly')");
    const { page, http } = await loginAs('dlopper');
    await page.goto('/issues/13');
    await openEdit(page);
    await expect(page.locator('#issue_custom_field_values_2')).toBeVisible(); // Platforms: no rule
    await expect(page.locator('#issue_custom_field_values_1')).toHaveCount(0);
    await http.post('/issues/13', { 'issue[custom_field_values][1]': 'Oracle' }, 'patch');
    expect(sqlValue("SELECT value FROM custom_values WHERE customized_type = 'Issue' AND customized_id = 13 AND custom_field_id = 1")).toBe('PostgreSQL');
  });
});
