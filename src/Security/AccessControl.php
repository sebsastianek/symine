<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Registry of permissions and project modules - a port of Redmine::AccessControl
 * as configured in Redmine's lib/redmine/preparation.rb.
 *
 * Each permission: [module|null, flags] where flags: public, read, require (loggedin|member).
 *
 * The constants hold Redmine's core permissions/modules. Plugins (and core code) add permissions and
 * modules at runtime through the AccessControlRegistry service (or register()); every static helper
 * below works on the merged list, so Authorization checks, the roles editor and the project modules
 * settings see them.
 */
final class AccessControl
{
    public const array MODULES = ['issue_tracking', 'time_tracking', 'news', 'documents', 'files', 'wiki', 'repository', 'boards', 'calendar', 'gantt'];

    /** @var array<string, array{module: ?string, public?: bool, read?: bool, require?: string}> */
    public const array PERMISSIONS = [
        'view_project' => ['module' => null, 'public' => true, 'read' => true],
        'search_project' => ['module' => null, 'public' => true, 'read' => true],
        'add_project' => ['module' => null, 'require' => 'loggedin'],
        'edit_project' => ['module' => null, 'require' => 'member'],
        'close_project' => ['module' => null, 'require' => 'member', 'read' => true],
        'delete_project' => ['module' => null, 'require' => 'member', 'read' => true],
        'select_project_publicity' => ['module' => null, 'require' => 'member'],
        'select_project_modules' => ['module' => null, 'require' => 'member'],
        'view_members' => ['module' => null, 'public' => true, 'read' => true],
        'manage_members' => ['module' => null, 'require' => 'member'],
        'manage_versions' => ['module' => null, 'require' => 'member'],
        'add_subprojects' => ['module' => null, 'require' => 'member'],
        'manage_public_queries' => ['module' => null, 'require' => 'member'],
        'save_queries' => ['module' => null, 'require' => 'loggedin'],
        'use_webhooks' => ['module' => null, 'require' => 'member'],
        // issue_tracking
        'view_issues' => ['module' => 'issue_tracking', 'read' => true],
        'add_issues' => ['module' => 'issue_tracking'],
        'edit_issues' => ['module' => 'issue_tracking'],
        'edit_own_issues' => ['module' => 'issue_tracking'],
        'copy_issues' => ['module' => 'issue_tracking'],
        'manage_issue_relations' => ['module' => 'issue_tracking'],
        'manage_subtasks' => ['module' => 'issue_tracking'],
        'set_issues_private' => ['module' => 'issue_tracking'],
        'set_own_issues_private' => ['module' => 'issue_tracking', 'require' => 'loggedin'],
        'add_issue_notes' => ['module' => 'issue_tracking'],
        'edit_issue_notes' => ['module' => 'issue_tracking', 'require' => 'loggedin'],
        'edit_own_issue_notes' => ['module' => 'issue_tracking', 'require' => 'loggedin'],
        'view_private_notes' => ['module' => 'issue_tracking', 'read' => true, 'require' => 'member'],
        'set_notes_private' => ['module' => 'issue_tracking', 'require' => 'member'],
        'delete_issues' => ['module' => 'issue_tracking', 'require' => 'member'],
        'view_issue_watchers' => ['module' => 'issue_tracking', 'read' => true],
        'add_issue_watchers' => ['module' => 'issue_tracking'],
        'delete_issue_watchers' => ['module' => 'issue_tracking'],
        'export_issues' => ['module' => 'issue_tracking', 'read' => true],
        'import_issues' => ['module' => 'issue_tracking'],
        'manage_categories' => ['module' => 'issue_tracking', 'require' => 'member'],
        // time_tracking
        'view_time_entries' => ['module' => 'time_tracking', 'read' => true],
        'log_time' => ['module' => 'time_tracking', 'require' => 'loggedin'],
        'edit_time_entries' => ['module' => 'time_tracking', 'require' => 'member'],
        'edit_own_time_entries' => ['module' => 'time_tracking', 'require' => 'loggedin'],
        'manage_project_activities' => ['module' => 'time_tracking', 'require' => 'member'],
        'log_time_for_other_users' => ['module' => 'time_tracking', 'require' => 'member'],
        'export_time_entries' => ['module' => 'time_tracking', 'read' => true],
        'import_time_entries' => ['module' => 'time_tracking'],
        // news
        'view_news' => ['module' => 'news', 'read' => true],
        'manage_news' => ['module' => 'news', 'require' => 'member'],
        'comment_news' => ['module' => 'news'],
        // documents
        'view_documents' => ['module' => 'documents', 'read' => true],
        'add_documents' => ['module' => 'documents', 'require' => 'loggedin'],
        'edit_documents' => ['module' => 'documents', 'require' => 'loggedin'],
        'delete_documents' => ['module' => 'documents', 'require' => 'loggedin'],
        // files
        'view_files' => ['module' => 'files', 'read' => true],
        'manage_files' => ['module' => 'files', 'require' => 'loggedin'],
        // wiki
        'view_wiki_pages' => ['module' => 'wiki', 'read' => true],
        'view_wiki_edits' => ['module' => 'wiki', 'read' => true],
        'export_wiki_pages' => ['module' => 'wiki', 'read' => true],
        'edit_wiki_pages' => ['module' => 'wiki'],
        'rename_wiki_pages' => ['module' => 'wiki', 'require' => 'member'],
        'delete_wiki_pages' => ['module' => 'wiki', 'require' => 'member'],
        'delete_wiki_pages_attachments' => ['module' => 'wiki'],
        'view_wiki_page_watchers' => ['module' => 'wiki', 'read' => true],
        'add_wiki_page_watchers' => ['module' => 'wiki'],
        'delete_wiki_page_watchers' => ['module' => 'wiki'],
        'protect_wiki_pages' => ['module' => 'wiki', 'require' => 'member'],
        'manage_wiki' => ['module' => 'wiki', 'require' => 'member'],
        // repository
        'view_changesets' => ['module' => 'repository', 'read' => true],
        'browse_repository' => ['module' => 'repository', 'read' => true],
        'commit_access' => ['module' => 'repository'],
        'manage_related_issues' => ['module' => 'repository'],
        'manage_repository' => ['module' => 'repository', 'require' => 'member'],
        // boards
        'view_messages' => ['module' => 'boards', 'read' => true],
        'add_messages' => ['module' => 'boards'],
        'edit_messages' => ['module' => 'boards', 'require' => 'member'],
        'edit_own_messages' => ['module' => 'boards', 'require' => 'loggedin'],
        'delete_messages' => ['module' => 'boards', 'require' => 'member'],
        'delete_own_messages' => ['module' => 'boards', 'require' => 'loggedin'],
        'view_message_watchers' => ['module' => 'boards', 'read' => true],
        'add_message_watchers' => ['module' => 'boards'],
        'delete_message_watchers' => ['module' => 'boards'],
        'manage_boards' => ['module' => 'boards', 'require' => 'member'],
        // calendar / gantt
        'view_calendar' => ['module' => 'calendar', 'read' => true],
        'view_gantt' => ['module' => 'gantt', 'read' => true],
    ];

    /**
     * Permissions registered at runtime (plugins, see AccessControlRegistry), same structure as PERMISSIONS
     * plus 'actions' (route names covered by the permission, like Redmine's {controller => actions}).
     *
     * @var array<string, array{module: ?string, public?: bool, read?: bool, require?: string, actions?: list<string>}>
     */
    private static array $registered = [];
    /** @var list<string> project modules registered at runtime */
    private static array $registeredModules = [];

    /**
     * All permissions: core ones (PERMISSIONS) followed by the registered ones (Redmine::AccessControl.permissions).
     *
     * @return array<string, array{module: ?string, public?: bool, read?: bool, require?: string, actions?: list<string>}>
     */
    public static function permissions(): array
    {
        return [] === self::$registered ? self::PERMISSIONS : array_merge(self::PERMISSIONS, self::$registered);
    }

    /**
     * Available project modules: core MODULES followed by the registered ones (Redmine::AccessControl.available_project_modules).
     *
     * @return list<string>
     */
    public static function modules(): array
    {
        return [] === self::$registeredModules ? self::MODULES : array_values(array_unique([...self::MODULES, ...self::$registeredModules]));
    }

    /**
     * Registers (or redefines) a permission - Redmine::AccessControl.map {|map| map.permission ...}.
     *
     * @param array{public?: bool, read?: bool, require?: string} $options
     * @param list<string>                                         $actions route names covered by the permission
     */
    public static function register(string $name, ?string $module = null, array $options = [], array $actions = []): void
    {
        if (isset($options['require']) && !\in_array($options['require'], ['member', 'loggedin'], true)) {
            throw new \InvalidArgumentException(\sprintf('Invalid require option "%s" for permission "%s" (member|loggedin).', $options['require'], $name));
        }
        $def = ['module' => $module];
        foreach (['public', 'read'] as $flag) {
            if (!empty($options[$flag])) {
                $def[$flag] = true;
            }
        }
        if (isset($options['require'])) {
            $def['require'] = $options['require'];
        }
        if ([] !== $actions) {
            $def['actions'] = array_values($actions);
        }
        self::$registered[$name] = $def;
        if (null !== $module) {
            self::registerModule($module);
        }
    }

    /** Registers a project module (map.project_module) */
    public static function registerModule(string $module): void
    {
        if (!\in_array($module, self::MODULES, true) && !\in_array($module, self::$registeredModules, true)) {
            self::$registeredModules[] = $module;
        }
    }

    /** Removes the registered permissions and modules (core ones are kept) */
    public static function resetRegistered(): void
    {
        self::$registered = [];
        self::$registeredModules = [];
    }

    /** @return list<string> route names covered by the permission (registered permissions only) */
    public static function actionsOf(string $permission): array
    {
        return self::$registered[$permission]['actions'] ?? [];
    }

    /**
     * Permission covering the given route (Redmine::AccessControl.allowed_actions / permission lookup by url).
     * Only registered permissions declare their actions.
     */
    public static function permissionForRoute(string $route): ?string
    {
        foreach (self::$registered as $name => $p) {
            if (\in_array($route, $p['actions'] ?? [], true)) {
                return $name;
            }
        }

        return null;
    }

    public static function exists(string $permission): bool
    {
        return isset(self::PERMISSIONS[$permission]) || isset(self::$registered[$permission]);
    }

    public static function moduleOf(string $permission): ?string
    {
        return (self::$registered[$permission] ?? self::PERMISSIONS[$permission] ?? null)['module'] ?? null;
    }

    public static function isPublic(string $permission): bool
    {
        return (self::$registered[$permission] ?? self::PERMISSIONS[$permission] ?? null)['public'] ?? false;
    }

    public static function isRead(string $permission): bool
    {
        return (self::$registered[$permission] ?? self::PERMISSIONS[$permission] ?? null)['read'] ?? false;
    }

    /** @return list<string> */
    public static function publicPermissions(): array
    {
        return array_keys(array_filter(self::permissions(), static fn ($p) => $p['public'] ?? false));
    }

    /** @return list<string> */
    public static function membersOnlyPermissions(): array
    {
        return array_keys(array_filter(self::permissions(), static fn ($p) => 'member' === ($p['require'] ?? null)));
    }

    /** @return list<string> */
    public static function loggedinOnlyPermissions(): array
    {
        return array_keys(array_filter(self::permissions(), static fn ($p) => \in_array($p['require'] ?? null, ['member', 'loggedin'], true)));
    }

    /**
     * Permissions available for the given enabled modules (non-module permissions are always included).
     *
     * @param list<string> $modules
     *
     * @return list<string>
     */
    public static function modulesPermissions(array $modules): array
    {
        return array_keys(array_filter(self::permissions(), static fn ($p) => null === $p['module'] || \in_array($p['module'], $modules, true)));
    }

    /** @return array<string, list<string>> permissions grouped by module ('' = project) */
    public static function permissionsByModule(): array
    {
        $out = [];
        foreach (self::permissions() as $name => $p) {
            $out[$p['module'] ?? ''][] = $name;
        }

        return $out;
    }

    /** Permissions a role can be given (builtin roles cannot get member/loggedin only permissions) */
    public static function setablePermissions(int $builtin): array
    {
        $perms = array_values(array_diff(array_keys(self::permissions()), self::publicPermissions()));
        if (1 === $builtin) {
            $perms = array_values(array_diff($perms, self::membersOnlyPermissions()));
        } elseif (2 === $builtin) {
            $perms = array_values(array_diff($perms, self::loggedinOnlyPermissions()));
        }

        return $perms;
    }
}
