import { execFileSync } from 'child_process';
import * as fs from 'fs';
import * as os from 'os';
import * as path from 'path';
import { test, expect } from '../../support/fixtures';
import { sqlValue } from '../../support/db';

/**
 * Git repository: configured in the project settings, tree browsing, file with syntax highlighting,
 * revision page + diff and revision graph. The repository is created on disk by the spec (git CLI).
 */

let repoPath = '';

function git(args: string[], date?: string): void {
  const env = {
    ...process.env,
    GIT_AUTHOR_NAME: 'jsmith', GIT_AUTHOR_EMAIL: 'jsmith@somenet.foo',
    GIT_COMMITTER_NAME: 'jsmith', GIT_COMMITTER_EMAIL: 'jsmith@somenet.foo',
    ...(date ? { GIT_AUTHOR_DATE: date, GIT_COMMITTER_DATE: date } : {}),
  };
  execFileSync('git', args, { cwd: repoPath, env, stdio: 'pipe' });
}

function createRepository(): string {
  repoPath = fs.mkdtempSync(path.join(os.tmpdir(), 'symine-e2e-git-'));
  fs.mkdirSync(path.join(repoPath, 'lib'));
  fs.writeFileSync(path.join(repoPath, 'README.md'), '# Demo\n\nhello\n');
  fs.writeFileSync(path.join(repoPath, 'lib', 'greeter.rb'), 'class Greeter\n  # say hello\n  def hello(name)\n    "Hello #{name}"\n  end\nend\n');
  git(['init', '-q', '-b', 'main']);
  git(['add', '.']);
  git(['commit', '-qm', 'Initial import refs #13'], '2026-10-01T10:00:00Z');
  git(['checkout', '-qb', 'feature']);
  fs.writeFileSync(path.join(repoPath, 'lib', 'greeter.rb'), 'class Greeter\n  # say hello\n  def hello(name)\n    "Hi #{name}!"\n  end\nend\n');
  git(['commit', '-qam', 'Friendlier greeting'], '2026-10-02T10:00:00Z');
  git(['checkout', '-q', 'main']);
  fs.writeFileSync(path.join(repoPath, 'README.md'), '# Demo\n\nhello world\n');
  git(['commit', '-qam', 'Update readme'], '2026-10-03T10:00:00Z');
  git(['merge', '-q', '--no-ff', '-m', 'Merge branch feature', 'feature'], '2026-10-04T10:00:00Z');
  return repoPath;
}

test.describe('git repository', () => {
  test.use({ as: 'admin' });

  test.beforeAll(() => {
    createRepository();
  });

  test.afterAll(() => {
    if (repoPath) fs.rmSync(repoPath, { recursive: true, force: true });
  });

  test('configure the repository in the project settings', async ({ page }) => {
    await page.goto('/projects/ecommerce/settings/repositories');
    await page.locator('#tab-content-repositories a.icon-add, a.icon-add[href$="/repositories/new"]').first().click();
    await expect(page.locator('#content h2')).toHaveText('New repository');
    await expect(page.locator('#repository_scm')).toHaveValue('Git');
    await page.locator('#repository_identifier').fill('demo');
    await page.locator('#repository_url').fill(repoPath);
    await page.locator('#repository_report_last_commit').check();
    await page.getByRole('button', { name: 'Create' }).click();

    await expect(page).toHaveURL(/\/projects\/ecommerce\/settings\/repositories$/);
    const row = page.locator('#tab-content-repositories table.list tbody tr, table.list tbody tr').filter({ hasText: 'demo' });
    await expect(row).toContainText('Git');
    await expect(row).toContainText(repoPath);
  });

  test('browse the tree and the latest revisions', async ({ page }) => {
    await page.goto('/projects/ecommerce/repository/demo');
    const browser = page.locator('#browser');
    await expect(browser.locator('tr.entry.dir a').filter({ hasText: 'lib' })).toBeVisible();
    await expect(browser.locator('tr.entry.file a').filter({ hasText: 'README.md' })).toBeVisible();
    // report_last_commit columns
    await expect(browser.locator('th').filter({ hasText: 'Revision' })).toBeVisible();
    // changesets fetched (autofetch) and linked to the referenced issue
    await expect.poll(() => sqlValue("SELECT COUNT(*) FROM changesets c JOIN repositories r ON r.id = c.repository_id WHERE r.identifier = 'demo'")).toBe(4);
    await expect.poll(() => sqlValue("SELECT COUNT(*) FROM changesets_issues ci JOIN changesets c ON c.id = ci.changeset_id WHERE ci.issue_id = 13")).toBe(1);

    // expand the "lib" directory in place (ajax)
    await browser.locator('tr.entry.dir').filter({ hasText: 'lib' }).locator('.expander').click();
    await expect(browser.locator('tr.entry.file a').filter({ hasText: 'greeter.rb' })).toBeVisible();

    await expect(page.locator('table.changesets tr.changeset')).toHaveCount(4);
    // branch selector switches the revision
    await page.locator('#branch').selectOption('feature');
    await expect(page).toHaveURL(/rev=feature/);
    await expect(page.locator('h2.breadcrumbs')).toContainText('@ feature');
  });

  test('open a file with syntax highlighting', async ({ page }) => {
    await page.goto('/projects/ecommerce/repository/demo/show/lib');
    await page.locator('#browser a').filter({ hasText: 'greeter.rb' }).click();
    await expect(page).toHaveURL(/\/repository\/demo\/(revisions\/main\/)?entry\/lib\/greeter\.rb/);
    const code = page.locator('table.filecontent.syntaxhl');
    await expect(code.locator('tr')).toHaveCount(6);
    await expect(code.locator('span.k').first()).toHaveText('class');
    await expect(code.locator('span.nc')).toHaveText('Greeter');
    await expect(code.locator('span.c1')).toHaveText('# say hello');
    await expect(code.locator('span.nf')).toHaveText('hello');
    // Rouge token classes are styled by Redmine's application.css
    const keywordWeight = await code.locator('span.k').first().evaluate((el) => getComputedStyle(el).fontWeight);
    expect(Number(keywordWeight)).toBeGreaterThanOrEqual(700);

    // annotate and history tabs
    await page.locator('#tab-annotate').click();
    await expect(page.locator('table.annotate td.author').first()).toContainText('jsmith');
    await expect(page.locator('table.annotate span.k').first()).toHaveText('class');
    await page.locator('#tab-changes').click();
    await expect(page.locator('table.changesets tr.changeset')).toHaveCount(2);
  });

  test('view a revision and its diff', async ({ page }) => {
    await page.goto('/projects/ecommerce/repository/demo/revisions');
    await page.locator('table.changesets td.comments').filter({ hasText: 'Friendlier greeting' }).locator('xpath=..').locator('td.id a').click();
    await expect(page.locator('#content h2').first()).toContainText('Revision');
    await expect(page.locator('.changeset-comments')).toContainText('Friendlier greeting');
    await expect(page.locator('.changeset-changes li.change-M')).toContainText('greeter.rb');

    await page.locator('#tab-diff').click();
    const diff = page.locator('table.diffcontent');
    await expect(diff.locator('th.filename')).toContainText('lib/greeter.rb');
    await expect(diff.locator('td.line-code.diff_out')).toContainText('Hello');
    await expect(diff.locator('td.line-code.diff_in')).toContainText('Hi');
    // side by side
    await page.locator('#type_sbs').check();
    await expect(page).toHaveURL(/type=sbs/);
    await expect(page.locator('table.diffcontent th.filename[colspan="4"]')).toBeVisible();
  });

  test('revision graph is rendered', async ({ page }) => {
    await page.goto('/projects/ecommerce/repository/demo/revisions');
    const holder = page.locator('#holder.revision-graph');
    await expect(holder.locator('svg')).toBeVisible();
    // one dot per revision, merge => two columns
    const dots = holder.locator('svg circle[r="3"]');
    await expect(dots).toHaveCount(4);
    const xs = await dots.evaluateAll((els) => [...new Set(els.map((e) => e.getAttribute('cx')))]);
    expect(xs.length).toBe(2);
    await expect(holder.locator('svg path').first()).toBeAttached();
    // the dots link to the revisions
    await expect(holder.locator('svg a[href*="/repository/demo/revisions/"]')).toHaveCount(4);
  });
});
