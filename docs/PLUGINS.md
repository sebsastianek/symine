# Symine plugins

Redmine plugins are Ruby code and cannot run in Symine. Symine provides the PHP equivalent of Redmine's
plugin API (`Redmine::Plugin.register`, hooks, menus, access control, activity/search providers, field
formats, plugin settings, plugin migrations and assets) so that Symine can be extended without touching
core code.

A complete working example lives in [`plugins/symine-hello/`](../plugins/symine-hello): a "Greetings"
project module with a permission, a project menu tab, a plugin setting, view hooks, an activity provider,
a search provider, a custom field format, a migration and a stylesheet. Its tests are in `tests/Plugin/`.

## Contents

1. [Plugin = Symfony bundle](#1-plugin--symfony-bundle)
2. [Installation and discovery](#2-installation-and-discovery)
3. [Redmine DSL mapping](#3-redmine-dsl-mapping)
4. [Permissions and project modules](#4-permissions-and-project-modules)
5. [Menus](#5-menus)
6. [Hooks](#6-hooks)
7. [Activity and search providers](#7-activity-and-search-providers)
8. [Custom field formats](#8-custom-field-formats)
9. [Settings](#9-settings)
10. [Migrations](#10-migrations)
11. [Assets, templates, translations](#11-assets-templates-translations)
12. [Commands](#12-commands)
13. [Differences from Redmine and what is missing](#13-differences-from-redmine-and-what-is-missing)

## 1. Plugin = Symfony bundle

A plugin is a Symfony bundle implementing `App\Plugin\SyminePluginInterface`. You usually extend
`App\Plugin\AbstractSyminePlugin` (an `AbstractBundle`), which wires up the conventions listed below.

```
plugins/symine-hello/
├── composer.json            type "symine-plugin", PSR-4 autoload, extra.symine.bundle
├── src/
│   ├── HelloPlugin.php      the bundle: getPluginId() + register()
│   ├── Controller/          #[Route] controllers (routed automatically)
│   ├── Entity/              Doctrine entities (attribute mapping, added automatically)
│   ├── Hook/                hook listeners
│   ├── Activity/ Search/ FieldFormat/
│   └── ...                  every class in src/ is an autowired, autoconfigured service
├── config/services.yaml     optional extra service configuration
├── templates/               Twig namespace @HelloPlugin (= the bundle name)
├── translations/            messages.<locale>.yaml with Redmine-style keys
├── migrations/              Doctrine migrations, namespace Symine\Hello\Migrations
└── assets/                  published to public/plugin_assets/<plugin id>/
```

```php
namespace Symine\Hello;

use App\Plugin\AbstractSyminePlugin;
use App\Plugin\PluginDefinition;

final class HelloPlugin extends AbstractSyminePlugin
{
    public function getPluginId(): string
    {
        return 'symine_hello';            // Redmine plugin id: URLs, "plugin_<id>" setting, plugin_assets/<id>
    }

    public function register(PluginDefinition $plugin): void   // the Redmine::Plugin.register block
    {
        $plugin
            ->name('Symine Hello')
            ->author('Symine')
            ->authorUrl('https://github.com/symine/symine')
            ->description('Example plugin: greetings project module')
            ->url('https://github.com/symine/symine/tree/main/plugins/symine-hello')
            ->version('1.0.0')
            ->requiresSymine('0.1')                                   // requires_redmine
            ->requiresRedmine(['version_or_higher' => '7.0'])        // Symine implements Redmine 7.0's API
            ->settings(['greeting' => 'Hello', 'show_on_issues' => '1'], '@HelloPlugin/settings/_hello_settings.html.twig')
            ->projectModule('hello', static function (PluginDefinition $p): void {
                $p->permission('view_hello_greetings', ['hello_index'], ['read' => true]);
                $p->permission('add_hello_greetings', ['hello_create'], ['require' => 'loggedin']);
            })
            ->menu('project_menu', 'hello', 'hello_index', [
                'caption' => 'label_hello_greeting_plural', 'param' => 'project_id', 'after' => 'activity',
            ]);
    }
}
```

Metadata: `id` (getPluginId), `name`, `author`, `description`, `version`, `url`, `authorUrl`,
`requiresSymine`. They are shown on **Administration » Plugins** (`/admin/plugins`), with a *Configure* link
for configurable plugins.

`App\Plugin\PluginRegistry` is the equivalent of the class side of `Redmine::Plugin`: `all()` (sorted by
id), `find($id)` (throws `PluginNotFoundException`), `installed($id)`. A plugin that is not a bundle can be
registered at runtime with `PluginRegistry::add()`.

## 2. Installation and discovery

Plugins are registered as kernel bundles in two ways:

1. **Explicitly** in `config/bundles.php`:
   `Acme\Foo\FooPlugin::class => ['all' => true],` (the class must be autoloadable, e.g. installed by composer).
2. **Auto-discovery** (`App\Plugin\PluginLoader`, called at the end of `config/bundles.php`):
   - every `plugins/<dir>/composer.json` (drop-in directory, like Redmine's `plugins/`);
   - every composer package of type `symine-plugin` listed in `vendor/composer/installed.json`.

   The plugin's `composer.json` declares the bundle and the environments:

   ```json
   {
       "name": "symine/hello-plugin",
       "type": "symine-plugin",
       "autoload": {"psr-4": {"Symine\\Hello\\": "src/"}},
       "extra": {"symine": {"bundle": "Symine\\Hello\\HelloPlugin", "env": ["dev", "test"]}}
   }
   ```

   `env` defaults to `["all"]`. For plugins in `plugins/*` that composer does not know, the PSR-4 autoload
   is registered by `PluginLoader` itself, so a drop-in plugin works without `composer dump-autoload`.

**Composer path-repository convention** (recommended for production, gives a classmap-optimized
autoloader): add to the root `composer.json`

```json
"repositories": [{"type": "path", "url": "plugins/*"}],
"require": {"symine/hello-plugin": "*"}
```

then `composer update symine/hello-plugin`. Plugins published on Packagist or in a VCS repository are
installed with `composer require vendor/package` and discovered through `installed.json`.

Environment variables: `SYMINE_PLUGINS_DISCOVERY=0` disables discovery; `SYMINE_PLUGINS_DISABLED=a,b`
skips plugins by composer name or directory name.

After installing a plugin: `bin/console symine:plugins:migrate` (database), `bin/console cache:clear`
(the container is rebuilt automatically in debug mode when a `plugins/*/composer.json` appears or
disappears, once at least one plugin is installed), assets are published on cache warmup.

> The example plugin `plugins/symine-hello` is only enabled in the `dev` and `test` environments.

## 3. Redmine DSL mapping

| Redmine (`init.rb`) | Symine |
|---|---|
| `Redmine::Plugin.register :foo do ... end` | bundle implementing `SyminePluginInterface::register(PluginDefinition $plugin)` |
| `name`, `author`, `description`, `version`, `url`, `author_url` | `$plugin->name()`, `->author()`, `->description()`, `->version()`, `->url()`, `->authorUrl()` |
| `requires_redmine version_or_higher: '5.0'` | `->requiresSymine('0.1')` (Symine version) / `->requiresRedmine('7.0')` (API level) — `'x'`, `['version_or_higher' => 'x']`, `['version' => ['x', 'y']]`, `['version' => [from, to], 'range' => true]`; throws `PluginRequirementException` |
| `requires_redmine_plugin :bar, version_or_higher: '1.0'` | `->requiresSyminePlugin('bar', '1.0')` (checked once all plugins are registered) |
| `settings default: {...}, partial: 'settings/foo'` | `->settings([...], '@FooPlugin/settings/_foo.html.twig')` |
| `permission :view_foo, {foo: [:index]}, read: true` | `->permission('view_foo', ['foo_index'], ['read' => true])` (actions = route names) |
| `project_module :foo do ... end` | `->projectModule('foo', fn (PluginDefinition $p) => $p->permission(...))` |
| `menu :project_menu, :foo, {controller: 'foo', action: 'index'}, caption: :label_foo, after: :activity, param: :project_id` | `->menu('project_menu', 'foo', 'foo_index', ['caption' => 'label_foo', 'after' => 'activity', 'param' => 'project_id'])` |
| `delete_menu_item :top_menu, :help` | `->deleteMenuItem('top_menu', 'help')` (+ `->replaceMenuItem()`) |
| `activity_provider :foos, class_name: 'Foo', default: false` | service implementing `App\Plugin\Activity\ActivityProviderInterface` |
| `Redmine::Search.map {|s| s.register :foos}` | service implementing `App\Plugin\Search\SearchProviderInterface` |
| `Redmine::FieldFormat.add 'foo', FooFormat` | service implementing `App\Plugin\FieldFormat\FieldFormatInterface` |
| `class Hooks < Redmine::Hook::ViewListener; render_on :view_x, partial: '...'` | service extending `App\Plugin\Hook\ViewListener` with `RENDER_ON` |
| `def controller_issues_edit_after_save(context)` | method `controller_issues_edit_after_save(array $context)` on a `HookListenerInterface` service, or `#[AsEventListener(event: 'controller_issues_edit_after_save')]` receiving a `HookEvent` |
| `db/migrate/001_create_foos.rb`, `rake redmine:plugins:migrate NAME=foo VERSION=0` | `migrations/VersionYYYYMMDDHHMMSS.php`, `bin/console symine:plugins:migrate foo --target=0` |
| `assets/` → `public/plugin_assets/foo` | same; `plugin_stylesheet('foo', 'foo')` = `stylesheet_link_tag 'foo', plugin: 'foo'` |
| `config/locales/en.yml` | `translations/messages.en.yaml` |
| `app/views/...` | `templates/...` (namespace `@FooPlugin`) |
| `Setting.plugin_foo` | `$settings->hash('plugin_foo')` / Twig `setting('plugin_foo')` |

## 4. Permissions and project modules

`App\Security\AccessControl` keeps its static API (`AccessControl::moduleOf()`, `isPublic()`,
`setablePermissions()`, ...) and its `PERMISSIONS` / `MODULES` constants (Redmine's core); permissions
registered at runtime are merged in `AccessControl::permissions()` / `AccessControl::modules()`.
Registration goes through the `App\Security\AccessControlRegistry` service (plugins do it declaratively in
`register()`; the `PluginRegistry` pushes them when the kernel boots):

```php
$registry->projectModule('foo', function (ModulePermissionMapper $map): void {
    $map->permission('view_foos', ['foo_index'], ['read' => true]);
    $map->permission('manage_foos', ['foo_new', 'foo_create'], ['require' => 'member']);
});
$registry->permission('use_foo_globally', [], ['require' => 'loggedin']);   // no module
```

Options are Redmine's: `public`, `read` (allowed on closed projects), `require` (`member` | `loggedin`).
Plugin permissions and modules appear in the roles editor (one fieldset per module, label
`project_module_<module>`, permissions `permission_<name>`), in the permissions report, in the project
modules (project settings, *Default enabled modules for new projects*) and in every authorization check
(`Authorization::allowedTo()`, `allowedToCondition()`, `allowed_to()` in Twig): a permission of a module is
granted only when the module is enabled on the project.

Controllers check permissions explicitly, like the core controllers:

```php
#[Route('/projects/{project_id}/hello', name: 'hello_index', methods: ['GET'])]
public function index(string $project_id): Response
{
    $project = $this->findProject($project_id);
    $this->authorize('view_hello_greetings', $project);
    ...
}
```

The `actions` (route names) given to a permission are used to derive the permission of menu items
(`AccessControl::permissionForRoute()`), like Redmine's `allowed_to?(url)`.

## 5. Menus

Menus: `top_menu`, `account_menu`, `application_menu`, `project_menu`, `admin_menu`.
`App\Menu\MenuBuilder` builds the core items and then applies the operations of the plugins (bundle
order) and of `App\Menu\MenuRegistry` (runtime: `push()`, `delete()`, `replace()`).

```php
$plugin->menu('project_menu', 'hello', 'hello_index', ['param' => 'project_id', 'after' => 'activity']);
$plugin->menu('top_menu', 'docs', 'https://example.org/docs', ['last' => true, 'html' => ['target' => '_blank']]);
$plugin->menu('application_menu', 'reports', 'reports_index', ['if' => fn (?Project $p, User $u) => $u->isLogged()]);
$plugin->menu('admin_menu', 'hello_admin', 'hello_admin', ['icon' => 'plugins', 'caption' => 'label_hello']);
$plugin->menu('project_menu', 'new_greeting', 'hello_new', ['parent' => 'new_object', 'param' => 'project_id']);
$plugin->deleteMenuItem('top_menu', 'help');
$plugin->replaceMenuItem('top_menu', 'projects', 'my_projects');   // keeps the position
```

URL: a route name (with `['route' => ..., 'params' => [...]]` for extra parameters), a path / absolute URL,
or a `Closure(?Project): string`. In project menus the project identifier is passed in the route parameter
named by `param` (default `id`, as in Redmine). Items whose route does not exist are skipped.

Options (Redmine's `MenuItem`): `caption` (translation key, literal, or `Closure(?Project)`; default
`label_<name>` or the humanized name), `if` (`Closure(?Project, User): bool`), `permission` (checked in
project menus; `false` = no check; default: the permission whose actions include the route), `param`,
`html` (link attributes), `icon` (sprite icon, admin menu style), `class`, `first`, `last`, `before`,
`after`, `parent`, `children` (`Closure(?Project)` returning `[name, url, options]` lists).
Positions follow Redmine: without position an item goes before the "last" core items (`administration` and
`help` in the top menu, `settings` in the project menu, `plugins` and `info` in the admin menu). Pushing an
item with an existing name replaces it. Plugin controllers select their tab with
`'main_menu_selected' => 'hello'` (or `admin_menu_selected` in admin pages).

## 6. Hooks

`App\Plugin\Hook\Hooks::call($hook, $context)` is `Redmine::Hook.call_hook`: it calls the method named
after the hook on every `HookListenerInterface` service and dispatches a `HookEvent` named after the hook,
returning the responses.

**View hooks** - Twig: `{{ call_hook('view_issues_show_details_bottom', {issue: issue}) }}` (responses
joined with a space, output raw; `project` of the template, `request` and `hook_caller` are added to the
context like Redmine's default context).

```php
use App\Plugin\Hook\ViewListener;

final class HelloViewListener extends ViewListener
{
    // render_on :view_projects_show_right, partial: 'hello/project_greetings'
    protected const array RENDER_ON = [
        'view_projects_show_right' => '@HelloPlugin/hooks/_project_greetings.html.twig',
    ];

    public function view_layouts_base_html_head(array $context): string
    {
        return '<link rel="stylesheet" href="/plugin_assets/symine_hello/stylesheets/hello.css" />';
    }

    public function view_issues_show_details_bottom(array $context): string
    {
        return $this->render('@HelloPlugin/hooks/_issue_details.html.twig', ['issue' => $context['issue']]);
    }
}
```

**Controller and model hooks** are called with Redmine's names and context keys. Listen either with a
hook listener method or with a Symfony event listener:

```php
use App\Plugin\Hook\HookEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class IssueListener
{
    #[AsEventListener(event: 'controller_issues_edit_before_save')]
    public function beforeSave(HookEvent $event): void
    {
        $issue = $event->get('issue');      // or $event['issue']; $event['params'] = request parameters
        $issue->subject = trim($issue->subject);
    }
}
```

A plugin can define its own hooks with `Hooks::call()` (the example plugin calls
`controller_hello_create_before_save`); controllers extending `App\Controller\AbstractController` have
`$this->callHook($hook, $context)`.

### Hooks called by the core

View hooks (same places as Redmine's ERB):

| Hook | Template |
|---|---|
| `view_layouts_base_html_head`, `view_layouts_base_body_top`, `view_layouts_base_sidebar`, `view_layouts_base_content`, `view_layouts_base_body_bottom` | `base.html.twig` (a non-empty sidebar hook response displays the sidebar) |
| `view_welcome_index_left`, `view_welcome_index_right` | `welcome/index` |
| `view_account_login_top`, `view_account_login_bottom` | `account/login` |
| `view_my_account`, `view_my_account_preferences`, `view_my_account_contextual` | `my/account` |
| `view_my_page_contextual`, `view_my_page_splitcontent` | `my/page` |
| `view_account_left_bottom`, `view_account_right_bottom` | user profile (`admin/users/show`) |
| `view_users_form`, `view_users_form_preferences` | `admin/users/_form` |
| `view_projects_show_left`, `view_projects_show_right`, `view_projects_show_sidebar_bottom` | `projects/show` |
| `view_projects_form` | `projects/_form` |
| `view_projects_roadmap_version_bottom` | `versions/index` |
| `view_versions_show_contextual`, `view_versions_show_bottom` | `versions/show` |
| `view_issues_index_bottom` | `issues/index` |
| `view_issues_sidebar_issues_bottom`, `view_issues_sidebar_planning_bottom`, `view_issues_sidebar_queries_bottom` | `issues/_sidebar` |
| `view_issues_show_details_bottom`, `view_issues_show_description_bottom` | `issues/show` |
| `view_issues_new_top`, `view_issues_form_details_top`, `view_issues_form_details_bottom` | `issues/new`, `issues/_form` |
| `view_issues_edit_top`, `view_issues_edit_notes_bottom` | `issues/_edit` |
| `view_issues_history_journal_bottom` | `issues/tabs/_history` |
| `view_issues_context_menu_start`, `view_issues_context_menu_end` | `context_menus/issues` |
| `view_calendars_show_bottom` | `calendars/_show_content` |
| `view_timelog_edit_form_bottom` | `timelog/_form` |
| `view_wiki_show_sidebar_bottom` | `wiki/_sidebar` |
| `view_search_index_options_content_bottom` | `search/index` |
| `view_settings_general_form` | `admin/settings/_general` |
| `view_custom_fields_form_upper_box`, `view_custom_fields_form_<type>` (e.g. `view_custom_fields_form_issue_custom_field`) | `admin/custom_fields/_form` |
| `view_issue_statuses_form` | `admin/issue_statuses/_form` |

The `form` context key (Rails form builder) is always `null`: listeners render plain HTML inputs.

Controller and model hooks:

| Hook | Context |
|---|---|
| `controller_issues_new_before_save`, `controller_issues_new_after_save` | params, issue |
| `controller_issues_edit_before_save`, `controller_issues_edit_after_save` | params, issue, time_entry, journal (after save, when one was created) |
| `controller_issues_bulk_edit_before_save`, `controller_issues_bulk_edit_after_save` | params, issue (+ journal) |
| `controller_timelog_edit_before_save` | params, time_entry (new and edit) |
| `controller_time_entries_bulk_edit_before_save` | params, time_entry |
| `controller_wiki_show_before_render` | content, format |
| `controller_wiki_edit_after_save` | params, page |
| `controller_messages_new_after_save`, `controller_messages_reply_after_save` | params, message |
| `controller_custom_fields_new_after_save`, `controller_custom_fields_edit_after_save` | params, custom_field |
| `controller_journals_edit_post` | journal, params |
| `controller_account_success_authentication_after` | user (interactive login on the main firewall) |
| `model_project_copy_before_save` | source_project, destination_project |
| `model_changeset_scan_commit_for_issue_ids_pre_issue_update` | changeset, issue, action (changes made by listeners are saved) |

Controller hooks also get `controller`, `project`, `request` and `hook_caller`.

## 7. Activity and search providers

```php
use App\Plugin\Activity\ActivityProviderInterface;
use App\Plugin\Activity\ActivityScope;
use App\Service\Activity\ActivityEvent;

final class GreetingActivityProvider implements ActivityProviderInterface
{
    public function getEventType(): string { return 'hello_greetings'; }    // label: label_hello_greeting_plural
    public function isDefault(): bool { return false; }                     // :default option
    public function getPermission(): ?string { return 'view_hello_greetings'; }

    public function findEvents(ActivityScope $scope): array
    {
        $qb = $this->em->createQueryBuilder()->select('g', 'p')->from(Greeting::class, 'g')->join('g.project', 'p');
        // dates, author, limit, visibility (Project.allowed_to_condition) and project/subprojects scope
        $qb = $scope->apply($qb, 'g.createdOn', 'IDENTITY(g.author)', $scope->allowedToCondition('view_hello_greetings'), 'g.id');

        return null === $qb ? [] : array_map(fn (Greeting $g) => new ActivityEvent('hello-greeting', ...), $qb->getQuery()->getResult());
    }
}
```

The type appears in the activity sidebar (`show_hello_greetings`), the Atom feeds and the user profile
activity; `ActivityFetcher::availableEventTypes()` / `defaultTypes()` include the providers.

Search providers implement `App\Plugin\Search\SearchProviderInterface`: `getSearchType()` (plural type,
checkbox label `label_<singular>_plural`, single-project searches require `view_<type>` like Redmine),
`resultRanksAndIds($tokens, $user, $projects, $options)` returning `[timestamp, id]` pairs of visible
records, and `results($ids)` returning `ActivityEvent`s (title, url, datetime, description) indexed by id.
They are listed by `SearchService::types()` and appear in `/search` and the search API.

Both are registered automatically (autoconfiguration of the interfaces); registries:
`ActivityProviderRegistry`, `SearchProviderRegistry` (also `register()` at runtime).

## 8. Custom field formats

```php
use App\Plugin\FieldFormat\AbstractFieldFormat;

final class ColorFieldFormat extends AbstractFieldFormat
{
    public function getName(): string { return 'hello_color'; }             // custom_fields.field_format
    public function getLabel(): string { return 'label_hello_color'; }
    public function validateValue(CustomField $field, string $value): ?string
    {
        return preg_match('/\A#[0-9a-f]{6}\z/i', $value) ? null : 'activerecord.errors.messages.invalid';
    }
    public function formatValue(CustomField $field, string $value): string { return strtolower($value); }
}
```

Other options: `getCustomizedClasses()` (e.g. `['Issue', 'Project']`, null = all), `isMultipleSupported()`,
`isSearchable()`, `isFilterable()`. The format is offered in the custom field form, accepted on creation,
validated and formatted by `CustomFieldService`, and labelled in the custom fields list
(Twig `field_format_label()`).

## 9. Settings

`->settings($defaults, $partial)` defines the setting `plugin_<id>` (serialized hash, defaults = `$defaults`)
exactly like Redmine's `Setting.define_plugin_setting`. When a partial is given the plugin is configurable:
**Administration » Plugins » Configure** opens `GET /settings/plugin/<id>` (route `plugin_settings`, admins
only) which renders the partial inside the settings form with the `settings` variable (current values);
`POST` stores the `settings[...]` parameters in Setting `plugin_<id>` (flash *Successful update*).

```twig
{# @HelloPlugin/settings/_hello_settings.html.twig #}
<p><label for="settings_greeting">{{ l('field_hello_greeting') }}</label>
<input type="text" name="settings[greeting]" id="settings_greeting" value="{{ settings.greeting ?? '' }}" /></p>
```

Read the values with `$settings->hash('plugin_symine_hello')` (`App\Service\Settings`) or
`setting('plugin_symine_hello')` in Twig. `Settings::define($name, [...])` defines other settings at runtime.

## 10. Migrations

Plugin migrations are Doctrine migrations in `<plugin>/migrations/`, namespace `<PluginNamespace>\Migrations`
(override `AbstractSyminePlugin::getMigrationsNamespace()` to change it). Executed versions are stored in the
`doctrine_migration_versions` table; the fully qualified class name identifies the plugin (Redmine stores
`<version>-<plugin id>` in `schema_migrations`). The plugin paths are also added to the
`doctrine_migrations` configuration, so `doctrine:migrations:*` commands see them.

```
bin/console symine:plugins:migrate                          # all plugins, latest versions
bin/console symine:plugins:migrate symine_hello --target=0  # revert all migrations of a plugin
bin/console symine:plugins:migrate symine_hello --target=20261107000000
bin/console symine:plugins:migrate --status
```

Use the platform to generate portable DDL (see `plugins/symine-hello/migrations/`). Plugin entities live in
`src/Entity` and are mapped automatically; the core schema is Redmine's and is never changed by plugins.
Plugin code should tolerate a missing table until the migrations have been run (the example plugin checks
`HelloSchema::isInstalled()` in its providers and hooks), because e.g. the global search queries every
registered provider.

## 11. Assets, templates, translations

- **Assets**: `<plugin>/assets/**` is published to `public/plugin_assets/<id>/` (relative symlink, or copy)
  by a cache warmer and by `bin/console symine:plugins:assets [--copy]`. Twig: `plugin_asset_path(id, path)`,
  `plugin_stylesheet(id, 'name')` (`stylesheets/name.css`), `plugin_javascript(id, 'name')`
  (`javascripts/name.js`). Add them to every page with the `view_layouts_base_html_head` hook.
- **Templates**: `templates/` with namespace `@<BundleName>`; extend `base.html.twig` and follow the layout
  contract of `docs/ARCHITECTURE.md` (`project`, `main_menu_selected`, `page_title`, `body_css`, blocks
  `content` / `sidebar`).
- **Translations**: `translations/messages.<locale>.yaml`, Redmine-style keys (`l('label_...')`):
  `project_module_<module>`, `permission_<permission>`, `label_<event type singular>_plural`, pluralized keys
  as nested `zero/one/other`.
- **Controllers** extend `App\Controller\AbstractController` (`findProject()`, `authorize()`,
  `requireAdmin()`, `l()`, `callHook()`, ...); `#[Route]` attributes are routed automatically.

## 12. Commands

| Command | Purpose |
|---|---|
| `symine:plugins:list` | installed plugins, version, configurable, pending migrations |
| `symine:plugins:migrate [id] [--target=V] [--status]` | plugin migrations (`rake redmine:plugins:migrate`) |
| `symine:plugins:assets [--copy]` | publish assets to `public/plugin_assets` |

## 13. Differences from Redmine and what is missing

- Ruby monkey-patching of core classes (`Issue.send(:include, ...)`, `prepend`) has no equivalent: extend
  through hooks/events, Symfony service decoration (`#[AsDecorator]`) or Doctrine event listeners.
- Menu `before`/`after` positions are resolved against the items visible for the current user; Redmine
  resolves them against all registered items.
- The `:form` (Rails form builder) context key of view hooks is `null`.
- Plugin custom field formats are edited with a text field (Redmine `FieldFormat::Base#edit_tag`); there is
  no API yet for custom edit tags, format option partials in the custom field form, or query filter types.
- Not ported: `wiki_format_provider` (text formatters), `attachment_object_type`, `Redmine::MyPage` block
  registration, `Redmine::Views::ApiTemplateHandler` hooks, `helper_*` hooks
  (`helper_issues_show_detail_after_setting`, `helper_queries_column_content/value`), the "Check for
  updates" link of the plugins page.
- View hooks not inserted yet: `view_admin_projects_sidebar_queries_bottom`,
  `view_issues_bulk_edit_details_bottom`, `view_issues_history_changeset_bottom`,
  `view_issues_history_time_entry_bottom`, `view_journals_notes_form_after_notes`,
  `view_journals_update_js_bottom`, `view_projects_copy_only_items`, `view_projects_settings_members_table_header/row`,
  `view_projects_sidebar_queries_bottom`, `view_reports_issue_report_split_content_left/right`,
  `view_repositories_show_contextual`, `view_time_entries_bulk_edit_details_bottom`,
  `view_time_entries_context_menu_start/end`, `view_users_sidebar_queries_bottom`,
  `view_users/groups_memberships_table_header/row`.
