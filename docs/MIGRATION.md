# Migrating from Redmine to Symine

Symine uses the **same physical database schema** as Redmine (same tables, columns and ids), so a
migration is a straight copy of every core table. The copy is done by
`bin/console symine:migrate-from-redmine`; `bin/console symine:redmine:compare` then verifies the result.

The Redmine database is only **read**: Redmine can keep running until you switch over, and you can
re-run the migration as often as you want (each run replaces the Symine data).

| | Supported / tested |
|---|---|
| Source engines | PostgreSQL (tested 16), MySQL (tested 8.4), MariaDB, SQLite. SQL Server through `pdo_sqlsrv` (untested). |
| Target engines | SQLite, PostgreSQL (tested 16), MySQL (tested 8.4), MariaDB (tested 10.11). |
| Source versions | Redmine 7.0 (reference). Older versions are handled: missing tables/columns get defaults, Redmine < 3.0 `users.mail` is converted to `email_addresses`, Syck YAML (1.x) and Psych YAML are decoded. |

## 1. Prerequisites

- PHP 8.5 with `pdo_sqlite`, `pdo_pgsql` and/or `pdo_mysql` for the source **and** target engines, plus `openssl`
  (to decrypt Redmine-ciphered passwords) and `zlib`.
- Network access from the Symine host to the Redmine database (a read-only database user is enough:
  `SELECT` on all tables).
- Read access to Redmine's `files/` directory (attachments), e.g. `/usr/src/redmine/files`.
- Redmine's `config/configuration.yml` if it defines `database_cipher_key` (see §5).
- Free disk space for the copy. Memory needs are constant (≈ 40 MB, rows are streamed in batches).
- **Stop writes in Redmine** (maintenance mode, or simply stop the application server) for the final run so that
  no data is created after the copy.

## 2. Prepare the Symine database

Point `DATABASE_URL` (in `.env.local` or the environment) at an empty database:

```bash
# SQLite
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"
# PostgreSQL
DATABASE_URL="postgresql://symine:secret@db:5432/symine?serverVersion=16&charset=utf8"
# MySQL / MariaDB (use utf8mb4!)
DATABASE_URL="mysql://symine:secret@db:3306/symine?serverVersion=8.4&charset=utf8mb4"
DATABASE_URL="mysql://symine:secret@db:3306/symine?serverVersion=10.11.2-MariaDB&charset=utf8mb4"
```

Create the schema (optional: the migration command creates/updates missing tables itself):

```bash
bin/console doctrine:schema:create
```

For MySQL/MariaDB create the database with `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`.

## 3. Validate the source (dry run)

```bash
bin/console symine:migrate-from-redmine --source="postgresql://redmine:secret@old-db/redmine" --dry-run
```

The dry run connects to Redmine, counts the rows of every table and reports — **without writing anything**:

- the Redmine schema version (last entry of `schema_migrations`);
- plugin tables (not migrated) and Redmine 6+ OAuth tables (not supported by Symine);
- rows of plugin STI types (e.g. a plugin `users.type` or `enumerations.type`) that will be skipped;
- columns that exist in Redmine but not in Symine (ignored), columns missing in an old Redmine (defaults used);
- every serialized YAML value that cannot be decoded (exit code 2);
- dangling references (ids pointing to deleted rows) and how they will be fixed.

Source URL formats: `postgresql://`, `postgres://`, `mysql://`, `mysql2://`, `mariadb://`, `sqlite:///abs/path/redmine.db`,
`sqlserver://`. The URL can also be given with the `REDMINE_DATABASE_URL` environment variable.

## 4. Migrate

```bash
bin/console symine:migrate-from-redmine \
    --source="postgresql://redmine:secret@old-db/redmine" \
    --files=/var/lib/redmine/files \
    --files-target=/var/lib/symine/files \
    --cipher-key="$REDMINE_DATABASE_CIPHER_KEY" \
    --force
```

| Option | Meaning |
|---|---|
| `--source` | Redmine database URL (default `REDMINE_DATABASE_URL`). |
| `--files` | Redmine `files/` directory; attachments are copied (`YYYY/MM/<disk_filename>`) and their SHA256/MD5 digests verified (default `REDMINE_FILES_PATH`). |
| `--files-target` | Symine attachment directory (default `SYMINE_FILES_PATH`, `var/files`). |
| `--batch` | Rows fetched per query (default 1000). Memory use is bounded by this value. |
| `--only=t1,t2` | Re-migrate only these tables (they are purged and copied again, other tables are left untouched), e.g. `--only=issues,journals,journal_details`. |
| `--dry-run` | Validate and report only (see §3). |
| `--cipher-key` | Redmine `database_cipher_key` (default `REDMINE_CIPHER_KEY`), see §5. |
| `--force` | Do not ask for confirmation. |

What the command does:

1. creates/updates the Symine schema (no foreign keys, like Redmine);
2. in **one transaction** on the target: empties each core table and copies it from Redmine, ids preserved,
   streaming rows with keyset pagination (`WHERE id > ? ORDER BY id LIMIT n`) and multi-row INSERTs on a
   dedicated connection without query logging (300 000 rows ≈ 5 s, 36 MB peak);
3. converts values for the target engine:
   - Ruby YAML columns (`settings.value`, `roles.permissions`, `queries.filters/column_names/sort_criteria/options`,
     `user_preferences.others`, `custom_fields.possible_values/format_store`, `repositories.extra_info`,
     `imports.settings`, `webhooks.events`) → JSON. Handled: symbols (`:key`, `- :value`, `!ruby/symbol`),
     `HashWithIndifferentAccess`, `ActionController::Parameters` (queries saved from request params in old
     versions), `!binary` values and keys, Syck `!map:`/`!seq:` tags, `Set`, `TimeWithZone`;
   - booleans (`t`/`f`, `1`/`0`, `true`/`false`);
   - datetimes truncated to seconds (Redmine ≥ 7 stores microseconds; values are UTC in both applications);
   - binary data (`wiki_content_versions.data`, possibly zlib-compressed when `wiki_compression = gzip`) copied byte
     for byte (`bytea` on PostgreSQL, `BLOB` on MySQL/SQLite); Symine reads compressed versions transparently;
   - NULLs in NOT NULL columns and NOT NULL columns missing in old versions → neutral defaults (reported);
   - invalid UTF-8 (e.g. old `latin1` MySQL databases) converted from Windows-1252 (reported);
4. fixes dangling references (Redmine has no foreign keys): nullable references are cleared, mandatory user
   references are reassigned to the Anonymous user (what Redmine does when a user is deleted);
5. resets id generators so new records get fresh ids: PostgreSQL sequences/identities (`setval`),
   MySQL/MariaDB `AUTO_INCREMENT`, SQLite `sqlite_sequence`;
6. copies the attachment files and verifies digests;
7. compares the row counts table by table (exit code 0 when everything matches, 2 otherwise).

## 5. Encrypted values (`database_cipher_key`)

If Redmine's `configuration.yml` sets `database_cipher_key`, Redmine stores LDAP bind passwords
(`auth_sources.account_password`), SCM passwords (`repositories.password`) and two-factor TOTP keys
(`users.twofa_totp_key`) encrypted (`aes-256-cbc:...`). Pass the same key with `--cipher-key` (or
`REDMINE_CIPHER_KEY`): the values are decrypted during the copy (Symine stores them in clear, protect the database
accordingly). Without the key they are copied as-is and a warning is printed: re-enter LDAP/SCM passwords in the
administration and ask 2FA users to re-enroll.

## 6. Verify

```bash
bin/console symine:redmine:compare --source="postgresql://redmine:secret@old-db/redmine" --sample=100
```

The compare command checks, for every core table: row counts (minus the rows skipped on purpose), then a sample of
rows (first, last and evenly spread ids) column by column — YAML vs JSON compared structurally, booleans, dates,
floats and binary data (MD5) normalised — and, for each sampled issue, its journals, journal details, custom values,
watchers, attachments, relations and time entries. Intentional changes (dangling references fixed, decrypted
values) are listed as "expected changes". Exit code 0 when no difference was found, 1 otherwise.
`--tables=issues,journals` restricts the comparison.

Then log in with an existing account and check a few projects, issues, wiki pages and attachments.

## 7. Passwords, LDAP and sessions

- **Passwords keep working.** Redmine stores `SHA1(salt + SHA1(password))` (very old versions: unsalted
  `SHA1(password)`). Symine verifies these legacy hashes with `App\Security\RedminePasswordHasher` and, on the
  first successful login, transparently re-hashes the password with the modern `auto` algorithm (bcrypt/argon2)
  and clears the salt (`security.yaml`: `migrate_from: redmine_legacy`, `UserProvider::upgradePassword`).
  Users never need to reset their password.
- **LDAP users** (`users.auth_source_id`) keep their authentication source; `auth_sources` is migrated with all
  settings (see §5 for the bind password). They log in against the directory as before.
- **API keys, feeds keys** (`tokens`) are migrated: REST clients keep working with the same keys.
  Sessions and "autologin" cookies are not portable: users log in once.
- Locked/registered users keep their status.

## 8. What is not migrated

- **Plugin tables** and plugin columns (reported). Rows of core tables using a plugin STI type (e.g. a custom
  `Principal` subclass) are skipped (reported with their count).
- **OAuth** applications/grants/tokens (Redmine ≥ 6) and OpenID tables (Redmine < 4).
- Rails bookkeeping (`schema_migrations`, `ar_internal_metadata`).
- Repository working copies/caches: re-fetch changesets from the repositories after the switch if needed.
- Redmine's `config/configuration.yml` (SMTP, attachment path, ...): configure Symine through its `.env` and the
  administration settings (`settings` table values are migrated).

## 9. Re-running, incremental fixes and rollback

- The migration runs in a single transaction on the target: if anything fails, the Symine database is left
  unchanged (schema creation excepted).
- Run it as many times as needed (e.g. a rehearsal a week before, then the final run): each run replaces the data.
- `--only=...` re-imports a few tables after fixing them in Redmine.
- **Rollback**: the Redmine database and files are never modified, so going back means pointing users to Redmine
  again. Before migrating into an existing Symine database, back it up (`cp var/data.db`, `pg_dump`, `mysqldump`)
  if it holds data you want to keep.
- After switching, data created in Symine is not synchronised back to Redmine.

## 10. Engine notes and troubleshooting

- **MySQL source**: use the `charset=utf8mb4` default (added automatically). Tables declared `latin1` that actually
  contain UTF-8 ("double encoded") should be fixed in Redmine first; genuinely `latin1`/`cp1252` bytes are converted.
- **PostgreSQL target**: ids are `GENERATED BY DEFAULT AS IDENTITY`; the command resets them. If you load data
  by other means, run `SELECT setval(pg_get_serial_sequence('issues','id'), MAX(id)) FROM issues;` per table.
- **MySQL target**: `AUTO_INCREMENT` is reset after the copy (also after `--only`, which deletes rows first).
  DATETIME columns have no fractional seconds; Redmine's microseconds are truncated.
- **SQLite**: fine for small/medium installations; for large ones prefer PostgreSQL or MySQL.
- `Cannot connect to the source database`: check the URL, credentials and `serverVersion`; for PostgreSQL use
  `postgresql://user:pass@host:5432/db`.
- YAML errors in the dry run: the value is copied unchanged (Symine will treat it as a plain string); fix the
  record in Redmine (usually a corrupted saved query) and re-run with `--only=queries`.
- `MISMATCH` in the verification table: look at the warnings above it (skipped plugin rows are accounted for);
  `symine:redmine:compare` shows the differing rows.

## Appendix: verification performed during development

Reference data: `tests/fixtures/seed_redmine.rb` (plus extra edge cases: gzip wiki version, webhook, plugin table,
symbol-keyed preferences) loaded into Redmine 7.0.3 containers running on PostgreSQL 16 and MySQL 8.4.

| Source → target | Row counts | `symine:redmine:compare` | Id generators / new inserts |
|---|---|---|---|
| PostgreSQL 16 → SQLite | OK | no difference | OK |
| MySQL 8.4 → SQLite | OK | no difference | OK |
| PostgreSQL 16 → PostgreSQL 16 | OK | no difference | 46 sequences OK, ORM inserts OK |
| MySQL 8.4 → PostgreSQL 16 | OK | no difference | OK |
| PostgreSQL 16 → MySQL 8.4 | OK | no difference | AUTO_INCREMENT OK, ORM inserts OK |
| MySQL 8.4 → MySQL 8.4 | OK | no difference | OK |
| PostgreSQL 16 → MySQL 8.4, `--only=journals,journal_details,issue_statuses,watchers` re-run | OK | — | AUTO_INCREMENT moved back after the purge |
| PostgreSQL 16 → MariaDB 10.11 | OK | no difference | OK |
| SQLite → PostgreSQL / MySQL | OK | no difference | OK |
| PostgreSQL → MySQL/PostgreSQL with 300 000 extra `custom_values` | OK (4.5 s, 36 MB) | no difference | OK |

Automated tests: `tests/Migration/` (SQLite → SQLite, legacy schema, dirty data, YAML edge cases, `--only`,
`--dry-run`, compare, cipher, bounded memory).
