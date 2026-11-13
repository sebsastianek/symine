# Symine

Symine is a rewrite of [Redmine](https://www.redmine.org) (7.0) on **Symfony 7.4 LTS** and **PHP 8.5**.

It aims for functional parity with Redmine:
- the **same database schema** (column for column), so an existing Redmine database can be migrated 1:1;
- the **same URLs, HTML markup and CSS/JS**, so it looks and behaves like Redmine;
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

## Console commands
| Command | Purpose |
|---|---|
| `symine:install`, `symine:load-default-data` | fresh install / Redmine default configuration (roles, trackers, statuses, workflows) |
| `symine:migrate-from-redmine`, `symine:redmine:compare` | import and verify an existing Redmine |
| `symine:receive-email`, `symine:receive-imap`, `symine:receive-pop3` | incoming e-mail (create/update issues) |
| `symine:send-reminders` | due-date reminder e-mails |
| `symine:user:create`, `symine:user:password` | user administration |
| `symine:locales:import` | regenerate `translations/` from Redmine locales |

## Development
- Architecture and conventions: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- Tests (see [docs/TEST_COVERAGE.md](docs/TEST_COVERAGE.md) for the mapping to Redmine's own test suite):
  - `bin/test`: PHPUnit, 1704 tests (unit/integration/functional, REST API in JSON and XML, security matrix, CSRF, private-data leaks).
  - Parity tests compare Symine with behaviour recorded from a real Redmine on the same data: `tests/Integration/RedmineParityTest.php`, `tests/Query/IssueQueryParityTest.php`, `tests/Parity/`. To regenerate the recorded data, run `tests/fixtures/seed_redmine.rb` and `tests/fixtures/redmine_expectations.rb` inside a Redmine container.
  - `SYMINE_REFERENCE_URL=http://localhost:3300 bin/test --differential`: live API comparison against a running Redmine.
  - `bin/test-e2e`: Playwright end-to-end suite, 306 browser tests driving Google Chrome (see [e2e/README.md](e2e/README.md)).
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
- **Repositories:** need `SYMINE_SCM_<NAME>_PATH_REGEXP` to be set before an SCM can be used (like Redmine 7's `scm_*_path_regexp`).

## License
GPL-2.0-or-later, like Redmine. Symine is a port of Redmine, © 2006- Jean-Philippe Lang, and ships Redmine's stylesheets, icons, JavaScript and translations.
