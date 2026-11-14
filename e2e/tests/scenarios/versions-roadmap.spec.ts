import { test, expect, uid } from '../../support/fixtures';
import { sql, sqlValue } from '../../support/db';
import { attribute, issueIdFromUrl, openEdit, selectAndRefresh, selectStatus, submitIssue } from './_helpers';

/*
 * A release from planning to closing: a version shared with the subproject, issues planned from several places,
 * the roadmap following the work, then the version locked, completed and closed.
 * Actors: jsmith (Manager of eCommerce and Checkout), dlopper (Developer of eCommerce), rhill (Reporter).
 */
test.describe('versions and roadmap', () => {
  test('plan a shared version, follow its progress on the roadmap, lock it and close it', async ({ loginAs, api }) => {
    test.setTimeout(240_000);
    const manager = await loginAs('jsmith');
    const developer = await loginAs('dlopper');
    const { page, ui } = manager;
    const suffix = uid().toLowerCase();
    const name = `2.0-${suffix}`;
    const localName = `hotfix-${suffix}`;
    let versionId = 0;
    let feature = 0;
    let checkoutBug = 0;
    const article = (p = page) => p.locator('#roadmap article', { has: p.locator('h3', { hasText: name }) });

    await test.step('the manager creates a version shared with subprojects and one that is not', async () => {
      await page.goto('/projects/ecommerce/settings/versions');
      for (const v of [
        { name, date: '2027-02-01', sharing: 'descendants', label: 'With subprojects' },
        { name: localName, date: '', sharing: 'none', label: 'Not shared' },
      ]) {
        await page.locator('#tab-content-versions a.icon-add', { hasText: 'New version' }).click();
        await page.locator('#version_name').fill(v.name);
        if (v.date) await page.locator('#version_effective_date').fill(v.date);
        await page.locator('#version_sharing').selectOption(v.sharing);
        await page.locator('#new_version input[name=commit]').click();
        await ui.expectNotice('Successful creation.');
        await expect(page.locator('#tab-content-versions tr.version', { hasText: v.name }).locator('td.sharing')).toHaveText(v.label);
      }
      versionId = Number(sqlValue('SELECT id FROM versions WHERE name = ?', [name]));
    });

    await test.step('issues are planned from the version page, from the subproject and from the list context menu', async () => {
      // 1. "New issue" of the version page pre-selects the version
      await page.goto(`/versions/${versionId}`);
      await expect(page.locator('#roadmap')).toContainText('No issues for this version');
      await page.locator('#content .contextual a.icon-add', { hasText: 'New issue' }).click();
      await expect(page.locator('#issue_fixed_version_id')).toHaveValue(String(versionId));
      // the form resets its tracker and status selects once the document is ready (as Redmine's does): choose after that
      await page.waitForLoadState('load');
      await selectAndRefresh(page, '#issue_tracker_id', { label: 'Feature' });
      await expect(page.locator('#issue_fixed_version_id')).toHaveValue(String(versionId)); // kept by the form refresh
      await page.locator('#issue_subject').fill(`One page checkout ${suffix}`);
      await page.locator('#issue_estimated_hours').fill('6');
      await page.locator('#issue_assigned_to_id').selectOption('6');
      await submitIssue(page);
      await expect(ui.flashNotice()).toContainText('created');
      // "Create" comes back to the version, which now lists the issue
      await expect(page).toHaveURL(new RegExp(`/versions/${versionId}$`));
      feature = Number(sqlValue('SELECT id FROM issues WHERE subject = ?', [`One page checkout ${suffix}`]));
      await expect(page.locator('table.related-issues tr.issue')).toHaveCount(1);
      await expect(page.locator('table.related-issues tr.issue td.subject')).toContainText(`Feature #${feature}: One page checkout ${suffix}`);

      // 2. the subproject is offered the shared version (prefixed with its project), not the local one
      await page.goto('/projects/checkout/issues/new');
      const versions = page.locator('#issue_fixed_version_id');
      await expect(versions.locator('option', { hasText: name })).toHaveCount(1);
      await expect(versions.locator('option', { hasText: localName })).toHaveCount(0);
      await page.locator('#issue_subject').fill(`Apple Pay button ${suffix}`);
      await versions.selectOption(String(versionId));
      await submitIssue(page);
      await expect(ui.flashNotice()).toContainText('created');
      checkoutBug = issueIdFromUrl(page);
      await expect(page.locator('#header h1')).toContainText('Checkout');
      await expect(attribute(page, 'fixed-version')).toContainText(name);

      // 3. two seeded features (#15: 4 h, 50 % done; #16: 2 h) through the context menu of the issue list
      await page.goto('/projects/ecommerce/issues?set_filter=1&status_id=o&sort=id');
      await ui.issueRow(15).locator('td.checkbox input').check();
      await ui.issueRow(16).locator('td.checkbox input').check();
      await ui.openContextMenu(ui.issueRow(16));
      await ui.contextMenuPick('Target version', name);
      await expect(ui.flashNotice()).toHaveText('Successful update.');
      expect(sql('SELECT id FROM issues WHERE fixed_version_id = ? ORDER BY id', [versionId]).map((r) => r.id)).toEqual([15, 16, feature, checkoutBug]);
      await page.goto('/issues/15');
      await expect(page.locator('#history .journal').last()).toContainText(`Target version set to ${name}`);
    });

    await test.step('the roadmap shows the version with the issues of the trackers selected', async () => {
      await page.goto('/projects/ecommerce');
      await ui.mainMenu('roadmap').click();
      await expect(article()).toBeVisible();
      await expect(article().locator('p.progress-info')).toContainText('4 issues');
      await expect(article().locator('p.progress-info')).toContainText('0 closed');
      await expect(article().locator('p.progress-info')).toContainText('4 open');
      // the related issues list follows the tracker boxes of the sidebar: features by default
      const related = article().locator('table.related-issues');
      await expect(related.locator('tr.issue')).toHaveCount(3);
      await expect(related).not.toContainText(`Apple Pay button ${suffix}`);
      await page.locator('#sidebar label', { hasText: 'Bug' }).locator('input').check();
      await page.locator('#sidebar input[type=submit][value=Apply]').click();
      await expect(related.locator('tr.issue')).toHaveCount(4);
      await expect(related.locator('tr.issue', { hasText: `Apple Pay button ${suffix}` })).toContainText('Checkout'); // other project: prefixed
      // without the subprojects only the issues of eCommerce itself are counted
      await page.locator('#with_subprojects').uncheck();
      await page.locator('#sidebar input[type=submit][value=Apply]').click();
      await expect(related.locator('tr.issue')).toHaveCount(3);
      // the subproject has its own roadmap listing the shared version with its issue
      await page.goto('/projects/checkout/roadmap?tracker_ids[]=1&tracker_ids[]=2');
      await expect(article().locator('table.related-issues tr.issue')).toHaveCount(1);
      await expect(article().locator('table.related-issues')).toContainText(`Apple Pay button ${suffix}`);
    });

    await test.step('the developer works: the progress of the version follows', async () => {
      const dev = developer.page;
      await dev.goto(`/versions/${versionId}`);
      // estimates: 6 h + 4 h + 2 h
      await expect(dev.locator('#version-summary fieldset.time-tracking tr', { hasText: 'Estimated time' }).first().locator('td.total-hours')).toContainText('12:00');
      // 50 % of #15 (4 h) = 200 over (average estimate 4 h x 4 issues) = 12.5 %, truncated like Redmine ('%i%%')
      await expect(dev.locator('.version-overview p.percent')).toHaveText('12%');

      await dev.locator('table.related-issues tr.issue', { hasText: `One page checkout ${suffix}` }).locator('td.subject a').click();
      await openEdit(dev);
      await selectStatus(dev, 'Closed');
      await dev.locator('#time_entry_hours').fill('5');
      await dev.locator('#time_entry_activity_id').selectOption({ label: 'Development' });
      await submitIssue(dev);
      await expect(developer.ui.flashNotice()).toHaveText('Successful update.');

      await dev.goto(`/versions/${versionId}`);
      // closed: 6 h x 100 = 600; open: 200 -> 800 / 16 = 50 %
      await expect(dev.locator('.version-overview p.percent')).toHaveText('50%');
      await expect(dev.locator('.version-overview p.progress-info')).toContainText('4 issues');
      await expect(dev.locator('.version-overview p.progress-info')).toContainText('1 closed');
      await expect(dev.locator('.version-overview p.progress-info')).toContainText('3 open');
      await expect(dev.locator('#version-summary fieldset.time-tracking tr', { hasText: 'Spent time' }).locator('td.total-hours')).toContainText('5:00');
      // "Issues by": the select reloads the breakdown through ajax
      await dev.locator('#status_by_select').selectOption('status');
      const byStatus = dev.locator('#status_by table tr');
      await expect(byStatus.filter({ hasText: 'Closed' }).locator('p.percent')).toHaveText('1/1');
      await expect(byStatus.filter({ hasText: 'New' }).locator('p.percent')).toHaveText('0/3');
      await dev.locator('#status_by_select').selectOption('assigned_to');
      await expect(dev.locator('#status_by table tr').filter({ hasText: 'Dave Lopper' }).locator('p.percent')).toHaveText('1/1');
      // closed issues are struck through on the roadmap
      await dev.goto('/projects/ecommerce/roadmap');
      await expect(article(dev).locator('table.related-issues tr.issue.closed')).toHaveCount(1);
    });

    await test.step('locked: nothing new can be planned into it, what is planned stays', async () => {
      await page.goto(`/versions/${versionId}/edit`);
      await page.locator('#version_status').selectOption('locked');
      await page.locator('#content input[name=commit]').click();
      await ui.expectNotice('Successful update.');
      await page.goto(`/versions/${versionId}`);
      await expect(page.locator('#roadmap .badge-status-locked')).toHaveText('locked');

      await page.goto('/projects/ecommerce/issues/new');
      await expect(page.locator('#issue_fixed_version_id option', { hasText: localName })).toHaveCount(1);
      await expect(page.locator('#issue_fixed_version_id option', { hasText: name })).toHaveCount(0);
      await page.goto('/issues/14');
      await openEdit(page);
      await expect(page.locator('#issue_fixed_version_id option', { hasText: name })).toHaveCount(0);
      // an issue already in the version keeps it as its current value
      await page.goto('/issues/16');
      await openEdit(page);
      await expect(page.locator('#issue_fixed_version_id')).toHaveValue(String(versionId));
      await page.locator('#issue_notes').fill('Still planned');
      await submitIssue(page);
      await expect(ui.flashNotice()).toHaveText('Successful update.');
      expect(sqlValue('SELECT fixed_version_id FROM issues WHERE id = 16')).toBe(versionId);
      // through the REST API the locked version is refused with a validation error
      const jsmith = await api('jsmith');
      const res = await jsmith.ctx.put('/issues/14.json', { data: { issue: { fixed_version_id: versionId } } });
      expect(res.status()).toBe(422);
      expect((await res.json()).errors).toEqual(['Target version is not included in the list']);
      expect(sqlValue('SELECT fixed_version_id FROM issues WHERE id = 14')).toBeNull();
      // and the list context menu does not offer it
      await page.goto('/projects/ecommerce/issues?set_filter=1&status_id=o&sort=id');
      await ui.openContextMenu(ui.issueRow(14));
      const folder = page.locator('#context-menu li.folder').filter({ has: page.locator('> a', { hasText: 'Target version' }) });
      await folder.hover();
      await expect(folder.locator('ul a', { hasText: localName })).toBeVisible();
      await expect(folder.locator('ul a', { hasText: name })).toHaveCount(0);
    });

    await test.step('"Close completed versions" only closes versions that are past their date and have no open issue', async () => {
      const closeCompleted = async () => {
        await page.goto('/projects/ecommerce/settings/versions');
        await page.locator('#tab-content-versions a.icon-locked', { hasText: 'Close completed versions' }).click();
        await expect(page).toHaveURL(/settings\/versions/);
      };
      const setVersion = async (fields: { status?: string; date?: string }) => {
        await page.goto(`/versions/${versionId}/edit`);
        if (fields.status) await page.locator('#version_status').selectOption(fields.status);
        if (fields.date) await page.locator('#version_effective_date').fill(fields.date);
        await page.locator('#content input[name=commit]').click();
        await ui.expectNotice('Successful update.');
      };
      const status = () => sqlValue('SELECT status FROM versions WHERE id = ?', [versionId]);

      // open again, due in the past, but three issues are still open
      await setVersion({ status: 'open', date: '2026-01-15' });
      await closeCompleted();
      expect(status()).toBe('open');
      await page.goto('/projects/ecommerce/roadmap');
      await expect(article().locator('.version-overview, p').filter({ hasText: /late/ }).first()).toBeVisible();

      // the remaining issues are closed from the related issues of the version page (context menu on a selection)
      await page.goto(`/versions/${versionId}`);
      const rows = page.locator('table.related-issues tr.issue:not(.closed)');
      await expect(rows).toHaveCount(3);
      // (no checkboxes on this list: click the first row, ctrl/cmd-click the others, right-click the selection;
      // the clicks land on the free end of the subject cell, not on the issue link)
      const cells = await rows.locator('td.subject').all();
      for (const [i, cell] of cells.entries()) {
        const box = (await cell.boundingBox())!;
        await cell.click({ position: { x: box.width - 6, y: box.height / 2 }, modifiers: i === 0 ? [] : ['ControlOrMeta'] });
      }
      await expect(page.locator('table.related-issues tr.issue.context-menu-selection')).toHaveCount(3);
      const last = (await cells[2].boundingBox())!;
      await cells[2].click({ button: 'right', position: { x: last.width - 6, y: last.height / 2 } });
      await expect(page.locator('#context-menu')).toBeVisible();
      await ui.contextMenuPick('Status', 'Closed');
      await expect(ui.flashNotice()).toHaveText('Successful update.');
      expect(Number(sqlValue('SELECT COUNT(*) FROM issues i JOIN issue_statuses s ON s.id = i.status_id WHERE i.fixed_version_id = ? AND s.is_closed = ?', [versionId, false]))).toBe(0);

      // now it is completed: closed, even while locked (open and locked versions are considered)
      await setVersion({ status: 'locked' });
      await closeCompleted();
      expect(status()).toBe('closed');
      expect(sqlValue('SELECT status FROM versions WHERE name = ?', [localName])).toBe('open'); // no due date: not completed
      await expect(page.locator('#tab-content-versions tr.version', { hasText: name })).toHaveCount(0); // open versions only by default
    });

    await test.step('closed: 100 %, out of the roadmap, and its issues cannot be reopened', async () => {
      await page.goto(`/versions/${versionId}`);
      await expect(page.locator('#roadmap .badge-status-closed')).toHaveText('closed');
      await expect(page.locator('.version-overview p.percent')).toHaveText('100%');
      await expect(page.locator('.version-overview p.progress-info')).toContainText('4 closed');

      await page.goto('/projects/ecommerce/roadmap');
      await expect(article()).toHaveCount(0);
      await page.locator('#toggle-completed-versions').click();
      await expect(page.locator('#completed-versions a', { hasText: name })).toBeVisible();
      await page.locator('#completed').check();
      await page.locator('#sidebar input[type=submit][value=Apply]').click();
      await expect(article()).toBeVisible();

      // reopening an issue of a closed version is refused...
      await page.goto(`/issues/${feature}`);
      await openEdit(page);
      await selectStatus(page, 'In Progress');
      await submitIssue(page);
      await expect(ui.errors()).toContainText('An issue assigned to a closed version cannot be reopened');
      expect(sqlValue('SELECT status_id FROM issues WHERE id = ?', [feature])).toBe(5);
      // ...unless it is taken out of the version at the same time
      await page.locator('#issue_fixed_version_id').selectOption('');
      await submitIssue(page);
      await expect(ui.flashNotice()).toHaveText('Successful update.');
      await expect(attribute(page, 'status')).toHaveText('In Progress');
      await page.goto(`/versions/${versionId}`);
      await expect(page.locator('.version-overview p.progress-info')).toContainText('3 issues');

      // a reporter reads the roadmap but cannot manage versions
      const reporter = await loginAs('rhill');
      await reporter.page.goto('/projects/ecommerce/roadmap?completed=1');
      await expect(article(reporter.page)).toBeVisible();
      await expect(reporter.page.locator('#roadmap a.icon-edit')).toHaveCount(0);
      expect((await reporter.http.get(`/versions/${versionId}/edit`)).status()).toBe(403);
      expect((await reporter.http.post(`/versions/${versionId}`, { 'version[status]': 'open' }, 'patch')).status()).toBe(403);
      expect(sqlValue('SELECT status FROM versions WHERE id = ?', [versionId])).toBe('closed');
    });
  });
});
