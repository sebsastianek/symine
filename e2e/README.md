# Symine end-to-end tests (Playwright)

Real browser (Google Chrome, `channel: 'chrome'`, headless), real JavaScript, real HTTP server, real e-mails.

```
bin/test-e2e                                   # whole suite (installs node deps on first run)
bin/test-e2e tests/issues                      # one area (paths are relative to e2e/)
bin/test-e2e tests/issues/new-issue.spec.ts -g "attachment"
bin/test-e2e --headed --debug ...              # any Playwright CLI option is passed through
E2E_BASE_URL=http://localhost:3300 bin/test-e2e --grep @parity   # dual-run against the reference Redmine
npx playwright show-report ../var/e2e-report   # (from e2e/) HTML report of the last run
```

Never download browsers (`npx playwright install` is not needed): Chrome from /Applications is used.

## What happens on a run
1. `webServer` (`support/serve.mjs`) copies `tests/fixtures/symine-seeded.db` to `var/e2e.db`, resets `var/files-e2e`
   from `var/redmine-src/files`, then starts
   `php -d variables_order=EGPCS -S 127.0.0.1:8765 -t public` with `APP_ENV=dev`, `APP_CACHE_DIR=var/cache-e2e`,
   `DATABASE_URL=sqlite:///…/var/e2e.db`, `SYMINE_FILES_PATH=var/files-e2e`, `MAILER_DSN=smtp://127.0.0.1:9765`.
   Server output: `var/e2e-server.log`.
2. `support/global-setup.ts` makes a fresh DB copy again, enables the REST API, creates an API key for every seeded user,
   starts an SMTP sink (e-mails land in `var/e2e-mail/*.eml`), logs every user in **through the real login form** and
   saves `var/e2e-auth/symine/<login>.json` (storageState), then snapshots the DB to `var/e2e-pristine.db`.
3. Before the **first test of every spec file** the DB is restored from the pristine snapshot, the files dir is reset
   and the PHP session files of the stored logins are restored (sessions live in `var/e2e-sessions`, not in the DB).
   Tests inside one file share state and run in order (workers = 1: one SQLite file, one single-process PHP server).

The Symfony web debug toolbar is stubbed out (requests to `/_wdt/*` return an empty body) so it never covers the page.

## Writing a spec
- Put specs under `e2e/tests/<area>/<topic>.spec.ts` (areas: `account`, `issues`, `issue-list`, `projects`, `admin`,
  `content`, `timelog`, `cross-cutting`, `parity`; new areas welcome: `twofa`, `pdf`, `scm`, `plugins`, …).
- Always import from the fixtures, never directly from `@playwright/test`:
  ```ts
  import { test, expect, uid } from '../../support/fixtures';

  test.describe('news', () => {
    test.use({ as: 'jsmith' });                       // logged in as jsmith (anonymous when omitted)
    test('create', async ({ page, ui }) => {
      await page.goto('/projects/ecommerce/news/new');
      const title = uid('News ');                      // unique names: data survives until the next file
      await page.locator('#news_title').fill(title);
      ...
      await ui.expectNotice('Successful creation.');
    });
  });
  ```
- Users (`support/env.ts`, same on the reference Redmine): `admin`/admin (admin), `jsmith` (Manager on ecommerce and
  checkout, Developer on internal), `dlopper` (Developer on ecommerce), `rhill` (Reporter on ecommerce), `anna`
  (German UI, no membership = non-member). Passwords `password123`. Projects: `ecommerce` (public, subproject
  `checkout`), `internal` (private), `legacy` (archived). Issues #13–#19.
- Web-first assertions only (`await expect(locator).toHaveText(...)`); **no `waitForTimeout`**. For server-side
  effects use `expect.poll(() => sqlValue(...))`.
- `confirm()` dialogs (`data-confirm`) are accepted automatically.
- Stimulus controllers (dropdowns, quote reply, clipboard…) are ES modules loaded asynchronously: a click right after
  navigation can be lost. Use `ui.openActionsMenu(scope?)` for "…" action dropdowns (retries until open).
- Issue/time-entry queries are remembered in the server-side session (like Redmine), and every test using the same
  `as` user shares that session: open lists with `?set_filter=1` (plus your filters) instead of relying on defaults.
- Inside a test using `test.use({ as })`, `browser.newContext()` inherits that user's storageState; for a truly
  anonymous context pass `{ storageState: { cookies: [], origins: [] } }`.
- Uncaught JS errors (`pageerror`) and any HTTP 5xx response fail the test. Opt out with
  `test.use({ allowJsErrors: true })` / `test.use({ allowServerErrors: true })` when the error is the point.

### Fixtures and helpers (`support/`)
| Name | What |
|---|---|
| `test.use({ as: 'jsmith' })` | page starts logged in as that user (stored session) |
| `loginAs('dlopper')` | `{ page, ui, http }` in a *second* browser context as another user (multi-user flows, e.g. lock conflicts) |
| `ui` (`support/ui.ts`) | Redmine UI helpers: `topMenu()`, `mainMenu()`, `adminMenu()`, `contextual()`, `flashNotice()`, `expectNotice()`, `expectError()`, `modal()`/`expectModal()`, `openContextMenu(row)`, `contextMenuPick(folder, item)`, `issueRow(id)`, `addFilter()`, `setFilter(field, op, values)`, `applyFilters()`, `expandFieldset()`, `autocomplete()`, `jumpToProject()`, `quickSearch()`, `openAccountMenu()`, `openActionsMenu()`, `attachFile()`, `loggedAs()` |
| `http` (`support/http.ts`) | CSRF-aware requests sharing the page cookies: `http.post(path, form, method?)`, `http.xhr()`, `http.expectStatus(path, 403)`. Reads `<meta name="csrf-param/csrf-token">`, so it also works on Redmine |
| `api('jsmith')` (`support/api.ts`) | REST client with the user's API key: `get/post/put/delete`, `createIssue`, `issue`, `updateIssue`, `createProject`, `createWikiPage`, `logTime`. Use it to arrange data quickly; test the UI with the UI |
| `mailbox` (`support/mail.ts`) | e-mails received during the test: `await mailbox.waitFor({ to: 'jsmith@example.net', subject: /Lost password/ })`, then `.text`, `.html`, `.links()` |
| `sql()`, `sqlValue()` (`support/db.ts`) | read (or tweak) `var/e2e.db` directly through the sqlite3 CLI, e.g. tokens, settings |
| `uid(prefix)` | unique suffix for created records |

### Resetting data
- Default `test.use({ dbReset: 'file' })`: one reset before the first test of the file.
- `test.use({ dbReset: 'test' })`: reset before every test of the file/describe (slower, ~50 ms + page loads).
- `test.use({ dbReset: 'none' })`: no reset for that file: it then sees whatever earlier files left behind
  (DB and sessions), so avoid it unless the file must keep state across a worker restart.
- `beforeAll` hooks run *before* the per-test reset: call `await ensureFileReset(testInfo)` first if they create data
  (`test.beforeAll(async ({}, testInfo) => { await ensureFileReset(testInfo); ... })`).
- Never log out a stored session's user in a way that invalidates it for later files (password change, lock, 2FA
  activation…) unless the spec file restores it: the DB reset restores the user, the session cookie stays valid.
  For logout tests log in through the form in a fresh context instead of using `as`.
- Settings can be changed in the UI or via `sql("UPDATE settings SET value = ? WHERE name = ?", [...])`; the next file
  gets the pristine values back.

### Running in parallel with other suites
If a run reports "port already used", an orphaned `php -S 127.0.0.1:<port>` from an interrupted run is still
alive: `pkill -f "127.0.0.1:<port>"`.
Everything is namespaced by `E2E_TOKEN`: `E2E_TOKEN=pdf E2E_PORT=8771 bin/test-e2e tests/pdf` uses
`var/e2e-pdf.db`, `var/cache-e2e-pdf`, `var/files-e2e-pdf`, `var/e2e-pdf-mail`, `var/e2e-pdf-report`, port 8771 and SMTP
port 9771 (`E2E_SMTP_PORT` overrides). Use a distinct token *and* port when another run may be active.

### Dual-run (UI parity) specs
Specs tagged `@parity` (in `tests/parity/`) only read data and use selectors common to Redmine and Symine; they run
against both servers:
```
bin/test-e2e --grep @parity                                   # Symine
E2E_BASE_URL=http://localhost:3300 bin/test-e2e --grep @parity   # reference Redmine (docker symine-redmine-ref)
```
With `E2E_BASE_URL` there is no webServer, no DB reset, no SMTP sink, no `sql()`, no API keys; only the stored logins
(`var/e2e-auth/localhost_3300/`). Use `IS_EXTERNAL` from `support/env.ts` to skip Symine-only checks.
Logins that fail on the external server (e.g. a user with 2FA enabled on the reference — currently `rhill`) only print
a warning in global setup; specs using that user then fail with "No stored session for <login>". Dual-run specs
should therefore use `admin`, `jsmith`, `dlopper`, `anna` or anonymous. Use your own `E2E_TOKEN` for external runs too
(the auth/results dirs are namespaced by it).
