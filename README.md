# Symine

Symine is a rewrite of [Redmine](https://www.redmine.org) (7.0) on **Symfony 7.4 LTS** and **PHP 8.5**.

It aims for functional parity with Redmine:
- the **same database schema** (column for column), so an existing Redmine database can be migrated 1:1;
- the **same URLs, HTML markup and CSS/JS**, so it looks and behaves like Redmine (a modern theme is available as an option);
- the **same REST API** (JSON/XML), so existing API clients and `rdm-mailhandler` keep working.

The original Redmine source is used as the reference implementation. Clone it into `reference/redmine` to verify behaviour or re-sync assets and locales:

```
git clone --depth 1 https://github.com/redmine/redmine reference/redmine
```

## Requirements
- PHP 8.5 with these extensions: intl, pdo_sqlite / pdo_pgsql / pdo_mysql, gd, zip (ldap is optional)
- One of: PostgreSQL ≥ 13, MySQL 8 / MariaDB ≥ 10.6, or SQLite
- `git` CLI, only for the repository browser

## Quick start (fresh install)
```
composer install
# set DATABASE_URL in .env.local
bin/console symine:install --lang=en          # creates the schema, admin/admin (must change password) and default data
php -S 127.0.0.1:8000 -t public                # or any PHP-FPM setup; see docker-compose.yml
```

## Migrating from Redmine
```
bin/console symine:migrate-from-redmine \
    --source="mysql://user:pass@host/redmine" \
    --files=/path/to/redmine/files
bin/console symine:redmine:compare --source="mysql://user:pass@host/redmine"
```
- **Data:** every core table is copied with its IDs. Ruby-YAML columns become JSON, dangling references are repaired, and attachment files are copied with their digests checked. Redmine 7 data migrations (for example the CSV export permissions) are applied.
- **Passwords:** existing Redmine passwords keep working. They are re-hashed with bcrypt/argon2 on first login.
- **Tested combinations:** MySQL, MariaDB, PostgreSQL and SQLite sources, into PostgreSQL, MySQL or SQLite targets.

See [docs/MIGRATION.md](docs/MIGRATION.md) for the full procedure.

## Deployment (Docker: PHP 8.5 FPM + Caddy + PostgreSQL)
`docker-compose.yml` runs three containers: `symine` (PHP 8.5 FPM, image built from `docker/Dockerfile`), `web`
(Caddy, serves `public/` and passes PHP requests to FPM) and `db` (PostgreSQL 16). Name the file explicitly with
`-f docker-compose.yml`, because `compose.yaml` is the Symfony development file. The settings use the `SYMINE_` prefix,
because docker compose also reads the application's `.env` file:

| Variable | Default |
|---|---|
| `SYMINE_HTTP_PORT` | `8080` (published port of Caddy) |
| `SYMINE_APP_SECRET` | `change-me` (**set your own**) |
| `SYMINE_DATABASE_URL` | the `db` service: `postgresql://symine:symine@db:5432/symine?serverVersion=16&charset=utf8` |
| `SYMINE_DEFAULT_URI` | `http://localhost:8080` (URL used in e-mails and console commands) |
| `SYMINE_MAILER_DSN` | `null://null`, e.g. `smtp://user:pass@smtp.example.net:587` |
| `SYMINE_REDMINE_DATABASE_URL` | source of `symine:migrate-from-redmine` |

Fresh install:
```
export SYMINE_HTTP_PORT=8080 SYMINE_APP_SECRET=$(openssl rand -hex 16)
docker compose -f docker-compose.yml up -d --build
docker compose -f docker-compose.yml exec symine bin/console symine:install --lang=en
# open http://localhost:8080 and log in as admin/admin (you are asked to change the password)
```
Attachments are stored in the `files` volume (`/srv/symine/var/files`), the database in the `db` volume.

Performance of the stack:
- **PHP**: OPcache never revalidates files and preloads the classes of the compiled container when php-fpm starts
  (`opcache.preload`, `config/preload.php`). Restart the `symine` container after anything that changes the code or
  the container: installing a plugin, `bin/console cache:clear`.
- **Static files** (`/assets`, `/plugin_assets`) are sent with `Cache-Control: public, max-age=3600`: their URLs
  carry no version, so browsers keep them for an hour and then revalidate (304). After an upgrade a browser may
  therefore use the previous stylesheets and scripts for up to an hour (a forced reload fixes it at once).
- **Cache formatted text** (*Administration → Settings → General*, off by default as in Redmine) keeps the HTML
  conversion of texts longer than 2 KB (wiki pages, long descriptions) in the application cache (`cache.app`,
  the filesystem by default; see `config/packages/cache.yaml` to use Redis).
Migrating an existing Redmine into the stack: the migration reads any database that the `symine` container can reach.
The following was tested with a PostgreSQL copy of a Redmine 7.0 database in the stack's `db` service:
```
C="docker compose -f docker-compose.yml"
$C exec db createdb -U symine redmine
# load the Redmine data into it, e.g. with pg_restore from a pg_dump of Redmine,
# or from a SQLite Redmine with pgloader:
#   docker run --rm --network <project>_default -v /path/to/sqlite:/data dimitri/pgloader \
#       pgloader /data/redmine.db postgresql://symine:symine@db/redmine
docker cp /path/to/redmine/files "$($C ps -q symine)":/tmp/redmine-files
$C exec symine bin/console symine:migrate-from-redmine \
    --source="postgresql://symine:symine@db:5432/redmine?serverVersion=16" --dry-run
$C exec symine bin/console symine:migrate-from-redmine \
    --source="postgresql://symine:symine@db:5432/redmine?serverVersion=16" --files=/tmp/redmine-files --force
$C exec symine bin/console symine:redmine:compare \
    --source="postgresql://symine:symine@db:5432/redmine?serverVersion=16"
```
The migration replaces the data of the Symine database, so you can run it right after `symine:install`. Redmine users
keep their passwords. Background jobs to schedule with cron in the `symine` container: `symine:receive-imap` or
`symine:receive-pop3` (incoming e-mail), `symine:send-reminders`, and `symine:fetch-changesets`.

## Themes
Symine keeps Redmine's interface as the default theme and adds **Strata**, a modern one (side navigation, light and
dark modes, accent colours, density, logo), switchable in *Administration → Themes*. Each person can set the
personal options of the theme in *My account → Appearance* or from the switcher in the top menu.

Themes are directories in `themes/`. Redmine themes work unchanged; Symine themes add a `theme.yaml` with colour
schemes, typed options, inheritance and Twig template overrides. See [docs/THEMES.md](docs/THEMES.md).

## Plugins
Optional features are plugins in `plugins/`: Symfony bundles using Symine's port of Redmine's plugin API
([docs/PLUGINS.md](docs/PLUGINS.md)). The core works the same with or without them.

- **Kanban** ([plugins/symine-kanban](plugins/symine-kanban/README.md)): Trello-like boards over the issues of one
  or several projects. Issue statuses are mapped to columns (a status that is neither mapped nor hidden on purpose
  shows in an "Unmapped" column, so nothing is lost), cards are dragged within what the workflow allows, with
  swimlanes, work-in-progress limits and manual ordering. Install: `bin/console symine:plugins:migrate symine_kanban`,
  then enable the *Kanban* module on a project.
- **Hello** (`plugins/symine-hello`, dev and test only): the example plugin of the API documentation.

## Console commands
| Command | Purpose |
|---|---|
| `symine:install`, `symine:load-default-data` | fresh install / Redmine default configuration (roles, trackers, statuses, workflows) |
| `symine:migrate-from-redmine`, `symine:redmine:compare` | import and verify an existing Redmine |
| `symine:receive-email`, `symine:receive-imap`, `symine:receive-pop3` | incoming e-mail (create/update issues) |
| `symine:send-reminders` | due-date reminder e-mails |
| `symine:user:create`, `symine:user:password` | user administration |
| `symine:locales:import` | regenerate `translations/` from Redmine locales |
| `symine:themes:list`, `symine:themes:lint`, `symine:themes:create`, `symine:themes:use` | installed themes, manifest checks, scaffolding, site theme |

## Development
- Architecture and conventions: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- Tests (see [docs/TEST_COVERAGE.md](docs/TEST_COVERAGE.md) for the mapping to Redmine's own test suite):
  - `bin/test`: PHPUnit, 2272 tests (unit/integration/functional, REST API in JSON and XML, security matrix, CSRF, private-data leaks).
  - Parity tests compare Symine with behaviour recorded from a real Redmine on the same data: `tests/Integration/RedmineParityTest.php`, `tests/Query/IssueQueryParityTest.php`, `tests/Parity/`. To regenerate the recorded data, run `tests/fixtures/seed_redmine.rb` and `tests/fixtures/redmine_expectations.rb` inside a Redmine container.
  - `SYMINE_REFERENCE_URL=http://localhost:3300 bin/test --differential`: live API comparison against a running Redmine.
  - `bin/test-e2e`: Playwright end-to-end suite, 408 browser tests driving Google Chrome, from single screens to multi-user scenarios (see [e2e/README.md](e2e/README.md)).
  - `bin/test-real-services [--e2e]`: LDAP, IMAP/POP3, SMTP and webhook tests against real servers started in Docker.
- Frontend assets come from Redmine: `bin/sync-redmine-assets`.

## Known gaps compared with Redmine 7.0
- **Plugins:** Ruby plugins cannot run. Symine has its own plugin API ([docs/PLUGINS.md](docs/PLUGINS.md)) with the same extension points, but plugins cannot monkey-patch core classes.
- **Unreleased Redmine master features** (avatar upload, non-working dates calendar) are not included.
- **Not enforced:** sudo mode (password re-confirmation) and session lifetime/timeout settings.
- **Smaller differences:**
  - HTML e-mails use a `<style>` block instead of inlined CSS.
  - Member and group dialogs are full pages rather than modals.
  - The Gantt PNG uses DejaVu Sans only, so CJK subjects don't render.
  - File-format issue custom fields have no upload widget.
  - *Administration → Workflow* opens the editor for the first role and tracker at once and reloads it when
    another one is selected; Redmine shows an empty page until *Edit* is pressed.
  - The account menu has an *Appearance* entry: people may pick their own theme unless an administrator turns
    that off in *Administration → Themes*. Redmine has one theme for everybody.
- **Text formatting:** Textile is rendered by a PHP Textile parser with Redmine's rules on top, not by a port of Redmine's RedCloth3, so some edge cases differ (image alignment, the language of `@|lang|code@`). PDF thumbnails and pandoc previews of attachments are not available.
- **Login:** failed logins are throttled (10 attempts per 5 minutes), which Redmine does not do.
- **Project deletion** runs in the request instead of a background job.
- **Repositories:** need `SYMINE_SCM_<NAME>_PATH_REGEXP` to be set before an SCM can be used (like Redmine 7's `scm_*_path_regexp`).

## License
GPL-2.0-or-later, like Redmine. Symine is a port of Redmine, © 2006- Jean-Philippe Lang, and ships Redmine's stylesheets, icons, JavaScript and translations.
