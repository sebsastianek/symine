<?php

declare(strict_types=1);

namespace App\Security;

class Permission
{
    // Project permissions
    public const PROJECT_VIEW = 'project_view';
    public const PROJECT_EDIT = 'project_edit';
    public const PROJECT_MANAGE_MEMBERS = 'project_manage_members';
    public const PROJECT_MANAGE_VERSIONS = 'project_manage_versions';
    public const PROJECT_DELETE = 'project_delete';
    public const PROJECT_CLOSE = 'project_close';
    public const PROJECT_REOPEN = 'project_reopen';
    public const PROJECT_ARCHIVE = 'project_archive';
    public const PROJECT_UNARCHIVE = 'project_unarchive';
    public const PROJECT_MANAGE_WIKI = 'project_manage_wiki';
    public const PROJECT_MANAGE_DOCUMENTS = 'project_manage_documents';
    public const PROJECT_MANAGE_FILES = 'project_manage_files';
    public const PROJECT_MANAGE_REPOSITORY = 'project_manage_repository';
    public const PROJECT_MANAGE_BOARDS = 'project_manage_boards';
    public const PROJECT_MANAGE_CATEGORIES = 'project_manage_categories';
    public const PROJECT_MANAGE_WORKFLOWS = 'project_manage_workflows';
    
    // Issue permissions
    public const ISSUE_VIEW = 'issue_view';
    public const ISSUE_CREATE = 'issue_create';
    public const ISSUE_EDIT = 'issue_edit';
    public const ISSUE_DELETE = 'issue_delete';
    public const ISSUE_MANAGE_PRIVATE = 'issue_manage_private';
    public const ISSUE_MANAGE_SUBTASKS = 'issue_manage_subtasks';
    public const ISSUE_MANAGE_RELATIONS = 'issue_manage_relations';
    public const ISSUE_MANAGE_WATCHERS = 'issue_manage_watchers';
    public const ISSUE_COMMENT = 'issue_comment';
    public const ISSUE_EDIT_NOTES = 'issue_edit_notes';
    public const ISSUE_VIEW_PRIVATE_NOTES = 'issue_view_private_notes';
    public const ISSUE_MANAGE_CATEGORIES = 'issue_manage_categories';
    
    // Time entry permissions
    public const TIME_ENTRY_VIEW = 'time_entry_view';
    public const TIME_ENTRY_LOG = 'time_entry_log';
    public const TIME_ENTRY_EDIT = 'time_entry_edit';
    public const TIME_ENTRY_DELETE = 'time_entry_delete';
    public const TIME_ENTRY_MANAGE_ALL = 'time_entry_manage_all';
    public const TIME_ENTRY_IMPORT = 'time_entry_import';
    
    // News permissions
    public const NEWS_VIEW = 'news_view';
    public const NEWS_MANAGE = 'news_manage';
    public const NEWS_COMMENT = 'news_comment';
    
    // Document permissions
    public const DOCUMENT_VIEW = 'document_view';
    public const DOCUMENT_MANAGE = 'document_manage';
    
    // File permissions
    public const FILE_VIEW = 'file_view';
    public const FILE_MANAGE = 'file_manage';
    
    // Wiki permissions
    public const WIKI_VIEW = 'wiki_view';
    public const WIKI_EDIT = 'wiki_edit';
    public const WIKI_MANAGE = 'wiki_manage';
    public const WIKI_RENAME = 'wiki_rename';
    public const WIKI_DELETE = 'wiki_delete';
    public const WIKI_PROTECT = 'wiki_protect';
    
    // Repository permissions
    public const REPOSITORY_VIEW = 'repository_view';
    public const REPOSITORY_BROWSE = 'repository_browse';
    public const REPOSITORY_COMMIT_ACCESS = 'repository_commit_access';
    public const REPOSITORY_MANAGE_CHANGESETS = 'repository_manage_changesets';
    
    // Board permissions
    public const BOARD_VIEW = 'board_view';
    public const BOARD_MANAGE = 'board_manage';
    public const BOARD_ADD_MESSAGES = 'board_add_messages';
    public const BOARD_EDIT_MESSAGES = 'board_edit_messages';
    public const BOARD_DELETE_MESSAGES = 'board_delete_messages';
    public const BOARD_EDIT_OWN_MESSAGES = 'board_edit_own_messages';
    public const BOARD_DELETE_OWN_MESSAGES = 'board_delete_own_messages';
    
    // Calendar permissions
    public const CALENDAR_VIEW = 'calendar_view';
    public const GANTT_VIEW = 'gantt_view';
    
    // Query permissions
    public const QUERY_MANAGE_PUBLIC = 'query_manage_public';
    public const QUERY_SAVE = 'query_save';
    
    // Global permissions
    public const GLOBAL_PROJECT_CREATE = 'global_project_create';
    public const GLOBAL_USER_MANAGE = 'global_user_manage';
    public const GLOBAL_GROUP_MANAGE = 'global_group_manage';
    public const GLOBAL_ROLE_MANAGE = 'global_role_manage';
    public const GLOBAL_TRACKER_MANAGE = 'global_tracker_manage';
    public const GLOBAL_ISSUE_STATUS_MANAGE = 'global_issue_status_manage';
    public const GLOBAL_WORKFLOW_MANAGE = 'global_workflow_manage';
    public const GLOBAL_ENUMERATION_MANAGE = 'global_enumeration_manage';
    public const GLOBAL_SETTINGS_MANAGE = 'global_settings_manage';
    public const GLOBAL_PLUGIN_MANAGE = 'global_plugin_manage';
    public const GLOBAL_SYSTEM_INFO_VIEW = 'global_system_info_view';
    
    // Visibility permissions
    public const VISIBILITY_ALL_ISSUES = 'visibility_all_issues';
    public const VISIBILITY_DEFAULT_ISSUES = 'visibility_default_issues';
    public const VISIBILITY_OWN_ISSUES = 'visibility_own_issues';
    
    public const VISIBILITY_ALL_TIME_ENTRIES = 'visibility_all_time_entries';
    public const VISIBILITY_OWN_TIME_ENTRIES = 'visibility_own_time_entries';
    
    public const VISIBILITY_ALL_USERS = 'visibility_all_users';
    public const VISIBILITY_MEMBERS_OF_VISIBLE_PROJECTS = 'visibility_members_of_visible_projects';
    
    /**
     * Get all project-level permissions
     */
    public static function getProjectPermissions(): array
    {
        return [
            self::PROJECT_VIEW,
            self::PROJECT_EDIT,
            self::PROJECT_MANAGE_MEMBERS,
            self::PROJECT_MANAGE_VERSIONS,
            self::PROJECT_DELETE,
            self::PROJECT_CLOSE,
            self::PROJECT_REOPEN,
            self::PROJECT_ARCHIVE,
            self::PROJECT_UNARCHIVE,
            self::PROJECT_MANAGE_WIKI,
            self::PROJECT_MANAGE_DOCUMENTS,
            self::PROJECT_MANAGE_FILES,
            self::PROJECT_MANAGE_REPOSITORY,
            self::PROJECT_MANAGE_BOARDS,
            self::PROJECT_MANAGE_CATEGORIES,
            self::PROJECT_MANAGE_WORKFLOWS,
            self::ISSUE_VIEW,
            self::ISSUE_CREATE,
            self::ISSUE_EDIT,
            self::ISSUE_DELETE,
            self::ISSUE_MANAGE_PRIVATE,
            self::ISSUE_MANAGE_SUBTASKS,
            self::ISSUE_MANAGE_RELATIONS,
            self::ISSUE_MANAGE_WATCHERS,
            self::ISSUE_COMMENT,
            self::ISSUE_EDIT_NOTES,
            self::ISSUE_VIEW_PRIVATE_NOTES,
            self::ISSUE_MANAGE_CATEGORIES,
            self::TIME_ENTRY_VIEW,
            self::TIME_ENTRY_LOG,
            self::TIME_ENTRY_EDIT,
            self::TIME_ENTRY_DELETE,
            self::TIME_ENTRY_MANAGE_ALL,
            self::TIME_ENTRY_IMPORT,
            self::NEWS_VIEW,
            self::NEWS_MANAGE,
            self::NEWS_COMMENT,
            self::DOCUMENT_VIEW,
            self::DOCUMENT_MANAGE,
            self::FILE_VIEW,
            self::FILE_MANAGE,
            self::WIKI_VIEW,
            self::WIKI_EDIT,
            self::WIKI_MANAGE,
            self::WIKI_RENAME,
            self::WIKI_DELETE,
            self::WIKI_PROTECT,
            self::REPOSITORY_VIEW,
            self::REPOSITORY_BROWSE,
            self::REPOSITORY_COMMIT_ACCESS,
            self::REPOSITORY_MANAGE_CHANGESETS,
            self::BOARD_VIEW,
            self::BOARD_MANAGE,
            self::BOARD_ADD_MESSAGES,
            self::BOARD_EDIT_MESSAGES,
            self::BOARD_DELETE_MESSAGES,
            self::BOARD_EDIT_OWN_MESSAGES,
            self::BOARD_DELETE_OWN_MESSAGES,
            self::CALENDAR_VIEW,
            self::GANTT_VIEW,
            self::QUERY_MANAGE_PUBLIC,
            self::QUERY_SAVE,
        ];
    }
    
    /**
     * Get all global permissions
     */
    public static function getGlobalPermissions(): array
    {
        return [
            self::GLOBAL_PROJECT_CREATE,
            self::GLOBAL_USER_MANAGE,
            self::GLOBAL_GROUP_MANAGE,
            self::GLOBAL_ROLE_MANAGE,
            self::GLOBAL_TRACKER_MANAGE,
            self::GLOBAL_ISSUE_STATUS_MANAGE,
            self::GLOBAL_WORKFLOW_MANAGE,
            self::GLOBAL_ENUMERATION_MANAGE,
            self::GLOBAL_SETTINGS_MANAGE,
            self::GLOBAL_PLUGIN_MANAGE,
            self::GLOBAL_SYSTEM_INFO_VIEW,
        ];
    }
    
    /**
     * Get all visibility permissions
     */
    public static function getVisibilityPermissions(): array
    {
        return [
            self::VISIBILITY_ALL_ISSUES,
            self::VISIBILITY_DEFAULT_ISSUES,
            self::VISIBILITY_OWN_ISSUES,
            self::VISIBILITY_ALL_TIME_ENTRIES,
            self::VISIBILITY_OWN_TIME_ENTRIES,
            self::VISIBILITY_ALL_USERS,
            self::VISIBILITY_MEMBERS_OF_VISIBLE_PROJECTS,
        ];
    }
}