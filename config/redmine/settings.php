<?php

// Generated from Redmine config/settings.yml - default values of application settings
return array (
  'app_title' => 
  array (
    'default' => 'Redmine',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'welcome_text' => 
  array (
    'default' => 'Welcome to Redmine, an open-source, flexible project management software.

Note: You can modify this message in the "Welcome text" setting (Administration > Settings > General).',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'login_required' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => true,
  ),
  'self_registration' => 
  array (
    'default' => '2',
    'serialized' => false,
    'security_notifications' => true,
  ),
  'show_custom_fields_on_registration' => 
  array (
    'default' => 1,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'lost_password' => 
  array (
    'default' => 1,
    'serialized' => false,
    'security_notifications' => true,
  ),
  'twofa' => 
  array (
    'default' => 1,
    'serialized' => false,
    'security_notifications' => true,
  ),
  'unsubscribe' => 
  array (
    'default' => 1,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'password_required_char_classes' => 
  array (
    'default' => 
    array (
    ),
    'serialized' => true,
    'security_notifications' => true,
  ),
  'password_min_length' => 
  array (
    'default' => 8,
    'serialized' => false,
    'security_notifications' => true,
  ),
  'password_max_age' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => true,
  ),
  'max_additional_emails' => 
  array (
    'default' => 5,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'email_domains_allowed' => 
  array (
    'default' => NULL,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'email_domains_denied' => 
  array (
    'default' => NULL,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'session_lifetime' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => true,
  ),
  'session_timeout' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => true,
  ),
  'attachment_max_size' => 
  array (
    'default' => 5120,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'bulk_download_max_size' => 
  array (
    'default' => 102400,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'attachment_extensions_allowed' => 
  array (
    'default' => NULL,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'attachment_extensions_denied' => 
  array (
    'default' => NULL,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'issues_export_limit' => 
  array (
    'default' => 500,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'activity_days_default' => 
  array (
    'default' => 10,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'per_page_options' => 
  array (
    'default' => '25,50,100',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'search_results_per_page' => 
  array (
    'default' => 10,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'mail_from' => 
  array (
    'default' => 'redmine@example.net',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'plain_text_mail' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'text_formatting' => 
  array (
    'default' => 'common_mark',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'cache_formatted_text' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'wiki_compression' => 
  array (
    'default' => '',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'default_language' => 
  array (
    'default' => 'en',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'force_default_language_for_anonymous' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'force_default_language_for_loggedin' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'host_name' => 
  array (
    'default' => 'localhost:3000',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'protocol' => 
  array (
    'default' => 'http',
    'serialized' => false,
    'security_notifications' => true,
  ),
  'feeds_limit' => 
  array (
    'default' => 15,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'gantt_items_limit' => 
  array (
    'default' => 500,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'gantt_months_limit' => 
  array (
    'default' => 24,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'default_issue_query' => 
  array (
    'default' => '',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'file_max_size_displayed' => 
  array (
    'default' => 512,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'diff_max_lines_displayed' => 
  array (
    'default' => 1500,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'enabled_scm' => 
  array (
    'default' => 
    array (
      0 => 'Subversion',
      1 => 'Mercurial',
      2 => 'Cvs',
      3 => 'Bazaar',
      4 => 'Git',
    ),
    'serialized' => true,
    'security_notifications' => true,
  ),
  'autofetch_changesets' => 
  array (
    'default' => 1,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'sys_api_enabled' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => true,
  ),
  'sys_api_key' => 
  array (
    'default' => '',
    'serialized' => false,
    'security_notifications' => true,
  ),
  'commit_cross_project_ref' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'commit_ref_keywords' => 
  array (
    'default' => 'refs,references,IssueID',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'commit_update_keywords' => 
  array (
    'default' => 
    array (
    ),
    'serialized' => true,
    'security_notifications' => false,
  ),
  'commit_logtime_enabled' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'commit_logtime_activity_id' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'autologin' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'date_format' => 
  array (
    'default' => '',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'time_format' => 
  array (
    'default' => '',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'timespan_format' => 
  array (
    'default' => 'minutes',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'user_format' => 
  array (
    'default' => 'firstname_lastname',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'cross_project_issue_relations' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'cross_project_subtasks' => 
  array (
    'default' => 'tree',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'parent_issue_dates' => 
  array (
    'default' => 'derived',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'parent_issue_priority' => 
  array (
    'default' => 'derived',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'parent_issue_done_ratio' => 
  array (
    'default' => 'derived',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'link_copied_issue' => 
  array (
    'default' => 'ask',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'copy_attachments_on_issue_copy' => 
  array (
    'default' => 'ask',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'close_duplicate_issues' => 
  array (
    'default' => 1,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'issue_group_assignment' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'assignee_dropdown_display_format' => 
  array (
    'default' => 'users_then_groups',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'default_issue_start_date_to_creation_date' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'default_issue_due_date_offset' => 
  array (
    'default' => '',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'notified_events' => 
  array (
    'default' => 
    array (
      0 => 'issue_added',
      1 => 'issue_updated',
    ),
    'serialized' => true,
    'security_notifications' => false,
  ),
  'mail_handler_body_delimiters' => 
  array (
    'default' => '',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'mail_handler_enable_regex_delimiters' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'mail_handler_enable_regex_excluded_filenames' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'mail_handler_excluded_filenames' => 
  array (
    'default' => '',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'mail_handler_api_enabled' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => true,
  ),
  'mail_handler_api_key' => 
  array (
    'default' => NULL,
    'serialized' => false,
    'security_notifications' => true,
  ),
  'mail_handler_preferred_body_part' => 
  array (
    'default' => 'plain',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'issue_list_default_columns' => 
  array (
    'default' => 
    array (
      0 => 'tracker',
      1 => 'status',
      2 => 'priority',
      3 => 'subject',
      4 => 'assigned_to',
      5 => 'updated_on',
    ),
    'serialized' => true,
    'security_notifications' => false,
  ),
  'issue_list_default_totals' => 
  array (
    'default' => 
    array (
    ),
    'serialized' => true,
    'security_notifications' => false,
  ),
  'related_issues_default_columns' => 
  array (
    'default' => 
    array (
      0 => 'status',
      1 => 'assigned_to',
      2 => 'start_date',
      3 => 'due_date',
      4 => 'done_ratio',
    ),
    'serialized' => true,
    'security_notifications' => false,
  ),
  'display_related_issues_table_headers' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'display_subprojects_issues' => 
  array (
    'default' => 1,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'time_entry_list_defaults' => 
  array (
    'default' => 
    array (
      'column_names' => 
      array (
        0 => 'spent_on',
        1 => 'user',
        2 => 'activity',
        3 => 'issue',
        4 => 'comments',
        5 => 'hours',
      ),
      'totalable_names' => 
      array (
        0 => 'hours',
      ),
    ),
    'serialized' => true,
    'security_notifications' => false,
  ),
  'project_list_display_type' => 
  array (
    'default' => 'board',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'project_list_defaults' => 
  array (
    'default' => 
    array (
      'column_names' => 
      array (
        0 => 'name',
        1 => 'identifier',
        2 => 'short_description',
      ),
    ),
    'serialized' => true,
    'security_notifications' => false,
  ),
  'default_project_query' => 
  array (
    'default' => '',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'issue_done_ratio' => 
  array (
    'default' => 'issue_field',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'issue_done_ratio_interval' => 
  array (
    'default' => 10,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'default_projects_public' => 
  array (
    'default' => 1,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'default_projects_modules' => 
  array (
    'default' => 
    array (
      0 => 'issue_tracking',
      1 => 'time_tracking',
      2 => 'news',
      3 => 'documents',
      4 => 'files',
      5 => 'wiki',
      6 => 'repository',
      7 => 'boards',
      8 => 'calendar',
      9 => 'gantt',
    ),
    'serialized' => true,
    'security_notifications' => false,
  ),
  'default_projects_tracker_ids' => 
  array (
    'default' => NULL,
    'serialized' => true,
    'security_notifications' => false,
  ),
  'new_project_user_role_id' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'sequential_project_identifiers' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'default_users_hide_mail' => 
  array (
    'default' => 1,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'default_users_no_self_notified' => 
  array (
    'default' => 1,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'default_users_time_zone' => 
  array (
    'default' => '',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'default_users_auto_watch_on' => 
  array (
    'default' => 
    array (
      0 => 'issue_created',
      1 => 'issue_contributed_to',
    ),
    'serialized' => true,
    'security_notifications' => false,
  ),
  'repositories_encodings' => 
  array (
    'default' => '',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'commit_logs_encoding' => 
  array (
    'default' => 'UTF-8',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'commit_logs_formatting' => 
  array (
    'default' => 1,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'repository_log_display_limit' => 
  array (
    'default' => 100,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'ui_theme' => 
  array (
    'default' => '',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'favicon' => 
  array (
    'default' => 
    array (
    ),
    'serialized' => true,
    'security_notifications' => false,
  ),
  'emails_footer' => 
  array (
    'default' => 'You have received this notification because you have either subscribed to it, or are involved in it.
To change your notification preferences, please click here: http://hostname/my/account',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'avatar_enabled' => 
  array (
    'default' => 1,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'gravatar_enabled' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'gravatar_default' => 
  array (
    'default' => '',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'start_of_week' => 
  array (
    'default' => '',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'rest_api_enabled' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => true,
  ),
  'jsonp_enabled' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => true,
  ),
  'webhooks_enabled' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => true,
  ),
  'default_notification_option' => 
  array (
    'default' => 'only_assigned',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'emails_header' => 
  array (
    'default' => '',
    'serialized' => false,
    'security_notifications' => false,
  ),
  'thumbnails_enabled' => 
  array (
    'default' => 1,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'thumbnails_size' => 
  array (
    'default' => 100,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'non_working_week_days' => 
  array (
    'default' => 
    array (
      0 => '6',
      1 => '7',
    ),
    'serialized' => true,
    'security_notifications' => false,
  ),
  'new_item_menu_tab' => 
  array (
    'default' => 2,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'timelog_required_fields' => 
  array (
    'default' => 
    array (
    ),
    'serialized' => true,
    'security_notifications' => false,
  ),
  'timelog_accept_0_hours' => 
  array (
    'default' => 1,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'timelog_max_hours_per_day' => 
  array (
    'default' => 999,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'timelog_accept_future_dates' => 
  array (
    'default' => 1,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'timelog_accept_closed_issues' => 
  array (
    'default' => 1,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'show_status_changes_in_mail_subject' => 
  array (
    'default' => 1,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'wiki_tablesort_enabled' => 
  array (
    'default' => 0,
    'serialized' => false,
    'security_notifications' => false,
  ),
  'reactions_enabled' => 
  array (
    'default' => 1,
    'serialized' => false,
    'security_notifications' => false,
  ),
);
