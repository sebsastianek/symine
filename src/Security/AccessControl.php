<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Registry of permissions and project modules - a port of Redmine::AccessControl
 * as configured in Redmine's lib/redmine/preparation.rb.
 *
 * Each permission: [module|null, flags] where flags: public, read, require (loggedin|member).
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

    public static function exists(string $permission): bool
    {
        return isset(self::PERMISSIONS[$permission]);
    }

    public static function moduleOf(string $permission): ?string
    {
        return self::PERMISSIONS[$permission]['module'] ?? null;
    }

    public static function isPublic(string $permission): bool
    {
        return self::PERMISSIONS[$permission]['public'] ?? false;
    }

    public static function isRead(string $permission): bool
    {
        return self::PERMISSIONS[$permission]['read'] ?? false;
    }

    /** @return list<string> */
    public static function publicPermissions(): array
    {
        return array_keys(array_filter(self::PERMISSIONS, static fn ($p) => $p['public'] ?? false));
    }

    /** @return list<string> */
    public static function membersOnlyPermissions(): array
    {
        return array_keys(array_filter(self::PERMISSIONS, static fn ($p) => 'member' === ($p['require'] ?? null)));
    }

    /** @return list<string> */
    public static function loggedinOnlyPermissions(): array
    {
        return array_keys(array_filter(self::PERMISSIONS, static fn ($p) => \in_array($p['require'] ?? null, ['member', 'loggedin'], true)));
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
        return array_keys(array_filter(self::PERMISSIONS, static fn ($p) => null === $p['module'] || \in_array($p['module'], $modules, true)));
    }

    /** @return array<string, list<string>> permissions grouped by module ('' = project) */
    public static function permissionsByModule(): array
    {
        $out = [];
        foreach (self::PERMISSIONS as $name => $p) {
            $out[$p['module'] ?? ''][] = $name;
        }

        return $out;
    }

    /** Permissions a role can be given (builtin roles cannot get member/loggedin only permissions) */
    public static function setablePermissions(int $builtin): array
    {
        $perms = array_values(array_diff(array_keys(self::PERMISSIONS), self::publicPermissions()));
        if (1 === $builtin) {
            $perms = array_values(array_diff($perms, self::membersOnlyPermissions()));
        } elseif (2 === $builtin) {
            $perms = array_values(array_diff($perms, self::loggedinOnlyPermissions()));
        }

        return $perms;
    }
}
