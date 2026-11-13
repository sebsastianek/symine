# Symine architecture & conventions

Symine is a Symfony 7.4 LTS / PHP 8.5 port of Redmine (reference: Redmine 7.0, cloned in `reference/redmine`).
Goal: functional parity with Redmine, same URLs, same HTML markup/CSS, same REST API, same database schema.

## Ground rules
- **Database**: the physical schema is *identical* to Redmine's (same tables/columns, no FK constraints).
  Entities in `src/Entity` map those tables (public typed properties, snake_case columns via naming strategy).
  Never add/rename columns. Polymorphic columns (`container_type/id`, `watchable_*`, `customized_*`) stay scalar.
- **Serialized columns** (roles.permissions, settings.value, queries.filters, ...) use the `redmine_serialized`
  Doctrine type: reads Ruby YAML or JSON, writes JSON.
- **Behaviour**: port logic from `reference/redmine/app/models`, `app/controllers`, `app/helpers`, `lib/redmine`.
  When unsure, read the Ruby source. Behaviour can be verified against the running reference instance:
  `docker exec -e RAILS_ENV=production symine-redmine-ref bin/rails runner <script>` (Redmine on http://localhost:3300,
  admin/admin, jsmith/password123 ...; API key of jsmith in tests/fixtures/seed_redmine.rb output).
- **Views**: templates reproduce Redmine's ERB markup (`reference/redmine/app/views/**`) so Redmine's CSS/JS
  (copied into `public/assets` by `bin/sync-redmine-assets`) apply unchanged. Use the same element ids/classes.
- **URLs/routes**: same paths as `reference/redmine/config/routes.rb`. Route names below are the contract used by
  menus and helpers - keep them.
- **i18n**: use Redmine translation keys only: `l('label_issue')` in Twig, `$this->l('notice_successful_create')` in
  controllers, params without wrapper: `l('label_x_issues', {count: 3})`. `translations/*.yaml` are GENERATED
  (`bin/console symine:locales:import`) - do not edit them.
- **CSRF**: every non-GET browser request is checked globally (`CsrfSubscriber`). Forms: `{{ csrf_field() }}`.
  Links with `data-method="post|delete"` and `data-confirm` work via rails-ujs (meta csrf-token is in the layout).
  `_method` override (PATCH/PUT/DELETE) is enabled.
- **Auth**: `$this->user()` (never null; AnonymousUser when not logged), `$this->authorize('perm', $project)`,
  `$this->requireLogin()`, `$this->requireAdmin()`, `$this->findProject($idOrIdentifier)` (404/403 handled).
  Visibility in queries: `Authorization::allowedToCondition($permission, 'IDENTITY(x.project)', ...)` returns a DQL
  condition; issues: `IssueService::visibleCondition($user, 'i')`.
- **REST API**: same URL with `.json`/`.xml` suffix (routes declare `{_format}` requirement `json|xml` or duplicate
  route). Use `App\Api\ApiRenderer` (`render($request, ['issue' => [...]])`, `errors()`, `payload()`), and
  replicate the structure of `reference/redmine/app/views/**/*.api.rsb`. API auth (key/basic) is done by the
  `api` firewall; respond 401/403/404/422 like Redmine (ExceptionSubscriber already maps HttpExceptions to JSON).
- **Events**: dispatch `App\Event\IssueEvent` / `App\Event\ContentEvent` after changes; notifications & webhooks
  subscribe to them.

## Layout contract (templates/base.html.twig)
Variables: `project` (Project|null, enables project menu & header), `main_menu_selected` (menu item name, e.g.
`issues`), `page_title`, `body_css` (e.g. `controller-issues action-show`), `question`.
Blocks: `content`, `sidebar` (non-empty → collapsible sidebar), `header_tags`.
Admin pages extend `admin/layout.html.twig` and set `admin_menu_selected` (e.g. `users`).
Reusable partials: `common/_pagination.html.twig` (with `pager` from `AbstractController::paginate()`),
`common/_menu.html.twig`, `common/_flash.html.twig`.

Twig helpers (src/Twig/AppExtension.php): `l()`, `current_user()`, `setting()`, `settings.*`, `allowed_to(perm, project)`,
`sprite_icon(name, label)`, `avatar(user, size)`, `link_to_user()`, `link_to_issue(issue, {project:, subject:, tracker:, truncate:})`,
`link_to_project()`, `link_to_version()`, `issue_css_classes()`, `textilizable(text, project)`, `format_date()`, `format_time()`,
`format_hours()`, `time_tag()`, `authoring(created, author)`, `progress_bar()`, `number_to_human_size()`, `csrf_field()`, `path_exists(route)`.

## Services
| Service | Purpose |
|---|---|
| `Security\Authorization` | allowedTo / rolesForProject / allowedToCondition / visibleProjectIds (Redmine User#allowed_to?) |
| `Security\CurrentUser` | User.current (`get()`, `anonymous()`, `set()`) |
| `Security\AccessControl` | permission registry & project modules |
| `Service\Settings` | Setting.* (defaults from config/redmine/settings.php) |
| `I18n\Localizer` | l(), formatDate/Time/Hours, user time zone, today() |
| `Service\ProjectService` | visibleProjects, sharedVersions, assignableUsers, allowedTargetTrackers/Projects, rebuildTree |
| `Service\IssueService` | ticketing flow: visibility, workflow, safe attributes, validate, save (journals), relations, copy, destroy |
| `Service\CustomFieldService` | custom values for any object, validation, formatting, options |
| `Service\AttachmentService` | file storage (Redmine layout), uploads tokens, download responses |
| `Service\WatcherService` | generic watchers |
| `Service\TextFormatter` | textilizable (CommonMark/Textile + Redmine links + macros) |
| `Api\ApiRenderer` | JSON/XML API rendering |
| `Menu\MenuBuilder` | top/account/application/project/admin menus (items appear when their route exists) |
| `Plugin\PluginRegistry`, `Plugin\Hook\Hooks`, `Menu\MenuRegistry`, `Security\AccessControlRegistry` | plugin API (Redmine::Plugin, hooks, menus, permissions) - see docs/PLUGINS.md |

## Route names (contract)
home, signin, signout, register, lost_password, account_activate,
my_page, my_account, my_password, my_api_key,
projects_index, projects_autocomplete, project_new, project_show, project_settings, project_activity, admin_projects,
activity, search,
issues_index, project_issues, issue_new, issue_show, issue_edit, issues_auto_complete, issues_context_menu,
gantt, project_gantt, calendar, project_calendar, issues_report,
time_entries_index, project_time_entries, time_entry_new,
project_roadmap, version_show, version_new, issue_category_new,
news_index, project_news, news_show, news_new,
project_documents, document_show, document_new,
wiki_index, wiki_show, wiki_new, boards_index, board_message_show,
project_files, file_new, repository_show,
admin_index, users_index, user_show, groups_index, group_show, roles_index, trackers_index, issue_statuses_index,
workflows_edit, custom_fields_index, enumerations_index, settings_index, auth_sources_index, admin_webhooks,
admin_plugins, admin_info, attachment_show, attachment_download

## Development
- PHP 8.5 binary: `/opt/homebrew/opt/php/bin/php` (put it first in PATH).
- Seeded dev DB: `cp tests/fixtures/symine-seeded.db var/data.db` (migrated from the reference Redmine; users
  admin/admin, jsmith|dlopper|rhill|anna / password123).
- Run a server on your own port with your own DB/cache to avoid clashes:
  `APP_CACHE_DIR=var/cache-X DATABASE_URL="sqlite:///$PWD/var/X.db" php -d variables_order=EGPCS -S 127.0.0.1:81XX -t public`
- Tests: `TEST_TOKEN=X APP_CACHE_DIR=var/cache-X php bin/phpunit tests/...` (integration tests copy tests/fixtures/symine-seeded.db to var/testX.db).
- Parity data: tests/fixtures/redmine-expectations.json (regenerate with tests/fixtures/redmine_expectations.rb).

## Testing
Test pyramid (bottom to top):
- **PHPUnit unit/integration** (`tests/`, `php bin/phpunit`): services, queries, controllers via `WebTestCase`
  (functional, in-process kernel); integration tests copy `tests/fixtures/symine-seeded.db`.
- **Parity tests against the reference** (`tests/Integration/RedmineParityTest.php`, `tests/Query/IssueQueryParityTest.php`):
  compare Symine output with expectations recorded from the real Redmine on the same data
  (`tests/fixtures/redmine-expectations.json`).
- **Playwright e2e** (`e2e/`, `bin/test-e2e`): real Chrome against a PHP built-in server on a fresh copy of the seeded
  DB (`var/e2e.db`, restored before each spec file, e-mails captured by an SMTP sink). Covers user-visible flows with
  Redmine's real JS (attachments, context menus, query forms, modals, autocomplete). `@parity` specs also run against the
  reference Redmine (`E2E_BASE_URL=http://localhost:3300`). Conventions: `e2e/README.md`.

## Notifications, webhooks, incoming mail
- `Mailer\NotificationSubscriber` / `Webhook\WebhookDispatcher` subscribe to `IssueEvent` and `ContentEvent`; e-mails
  and webhook calls are delivered after the response (`Mailer\DeferredTasks`, immediately in CLI/tests).
- ContentEvent contract: `news/created` (News), `news_comment/created` (Comment), `message/created` (Message),
  `wiki_page/created|updated` (WikiPage, context `content` when a new version was saved; `previous_text` optional),
  `document/created` (Document), `attachment/created` (subject = Attachment with context `container` (Project|Version|Document)
  and `attachments` list), `time_entry|version|news|wiki_page/*` (webhooks), `user/created` with context
  `account_information: true, password` (account information mail). Context `notify: false` disables e-mails.
- `Mailer\NotificationMailer` is the Mailer API (deliverIssueAdd, deliverSecurityNotification, deliverPasswordUpdated,
  deliverSettingsUpdated, deliverLostPassword, deliverRegister, deliverAccountActivated, deliverAccountInformation,
  deliverAccountActivationRequest, deliverTestEmail, reminders). `NotificationSubscriber::suppress($issueId)` = Issue#notify=false.
- Incoming e-mail: `MailHandler\MailHandler::receive($raw, $options)`; `bin/console symine:receive-email|symine:receive-imap|symine:receive-pop3`,
  `POST /mail_handler` (rdm-mailhandler.rb). Reminders: `bin/console symine:send-reminders --days=7`.
- Version / issue deletions dispatch their webhook event *before* the deletion (payload built from the loaded object).
- Real services (LDAP, IMAP/POP3, SMTP, webhooks): `bin/test-real-services [--e2e]` starts the containers of
  `tests/Integration/RealServices/compose.yaml`, runs `tests/Integration/RealServices` (skipped without the
  `SYMINE_TEST_*` env vars) and the Playwright specs of `e2e/tests/real-services`, then tears everything down.
