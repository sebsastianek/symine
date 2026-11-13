# Test coverage matrix (Redmine test suite → Symine)

Redmine's own test suite (`reference/redmine/test`) is the checklist for Symine's PHPUnit suite (`tests/`).
Every Redmine functional and integration test file, every REST API test file and the important unit tests are
listed below with the Symine tests that cover the same behaviour.

Symine runs on a single seeded dataset (`tests/fixtures/symine-seeded.db`, migrated from the reference Redmine
seeded by `tests/fixtures/seed_redmine.rb`), not on Redmine's YAML fixtures. Ported scenarios are therefore adapted
to that data (projects ecommerce/internal/checkout/legacy, users admin/jsmith/dlopper/rhill/anna/locked, issues #13-#19).

## Legend

| Status | Meaning |
|---|---|
| covered | most significant behaviours of the Redmine test file are tested in Symine |
| partial | some behaviours are tested; notable scenarios (listed in Notes) are not |
| missing | no Symine test exercises this area |
| n.a. | the feature does not exist in Symine (not a test gap) |

- **Status (before)**: the state of `tests/` when this coverage work started. It includes the suites added in parallel by
  the other work streams: `tests/Scm` (45 tests: Subversion, Mercurial, CVS, Bazaar, Filesystem, Git, sys API, syntax
  highlighting), `tests/Auth` (36: 2FA, OAuth2, TOTP, migration), `tests/Pdf` (30), `tests/Plugin` (37), `tests/Gaps`
  (40: project/user lists, reactions, help, health, robots) and `tests/Integration/RealServices` (17: LDAP, IMAP/POP3,
  SMTP, webhooks against real servers).
- **Status (after)**: after the API parity suite (`tests/Api`), the security suite (`tests/Security`) and the
  behavioural parity snapshots (`tests/Parity`).
- Test references use `File::Method` (without the `test` prefix); `A/B` = class `B` in directory `tests/A`.

## Functional tests (`test/functional`)

| Redmine test file | # | Symine tests | Before | After | Notes |
|---|---|---|---|---|---|
| account_controller_test.rb | 50 | Account/AccountTest (Register*, LostPasswordFlow, ExpiredRecoveryToken, PasswordComplexitySettings, ChangePassword, ForcedPasswordChange); Admin/AuthSourcesTest::OnTheFlyRegistrationAndLogin; Auth/TwofaTest::Login*; RealServices/LdapTest; Security/CsrfTest::LoginNeedsTheToken, LogoutNeedsTheToken | partial | partial | Missing: back_url sanitisation (open redirect) on login, autologin cookie, activation e-mail resend, lost password for locked/LDAP users |
| activities_controller_test.rb | 21 | Projects/ActivityTest (9); Parity/RedmineBehaviourParityTest::ActivityEvents (per user, :all/:default, global/ecommerce/internal); Security/PrivateDataVisibilityTest::PrivateIssueAndNotesInEveryOutput (activity HTML + Atom) | partial | covered | |
| admin_controller_test.rb | 16 | Admin/AdminTest (7); Gaps/ProjectsListTest | covered | covered | |
| attachments_controller_test.rb | 71 | Issues/IssueScreensTest::AttachmentDownloadAndPreview; Issues/JournalsRelationsWatchersTest::DeleteAttachmentIsJournalized; Gaps/RedmineGapsTest::AttachmentRoutesWithoutFilenameOrSize; Issues/IssuesApiTest::AttachmentApi; Content/DocumentsTest; Api/AttachmentsApiTest (18); Security/ContentVisibilityTest::AttachmentVisibilityFollowsTheContainer, AttachmentDeletionPermission | partial | partial | Missing: thumbnails, bulk edit/download (zip), edit_all, diff/image/text preview variants |
| attachments_visibility_test.rb | 2 | Security/ContentVisibilityTest::AttachmentVisibilityFollowsTheContainer (per container type × 6 users), AttachmentOfDocumentNeedsViewDocuments | missing | covered | |
| auth_sources_controller_test.rb | 15 | Admin/AuthSourcesTest (4); RealServices/LdapTest (11) | covered | covered | |
| auto_completes_controller_test.rb | 19 | Issues/IssueScreensTest::PreviewAndAutocomplete; Gaps/RedmineGapsTest::WikiPagesAutoComplete; Projects/ProjectsTest::Autocomplete; Security/PrivateDataVisibilityTest::PrivateIssueAndNotesInEveryOutput (autocomplete by subject and id) | partial | partial | Missing: status o/c filter, scope=all, excluding issue_id |
| avatars_controller_test.rb | 11 | — | n.a. | n.a. | Avatar upload is not implemented (Gravatar only) |
| boards_controller_test.rb | 20 | Content/ForumsTest::BoardCrudPositionsAndMoveTopic, BoardShowMarkup, PagesOkForAdminAndJsmith; Security/PermissionMatrixTest::ProjectAction; Security/CsrfTest::RequestNeedsTheAuthenticityToken | partial | partial | Missing: sorting topics, atom |
| calendars_controller_test.rb | 12 | Query/IssueListWebTest::PagesRenderForAdminAndJsmith (renders only) | partial | partial | Missing: issues/versions placed on the right days, custom query, week numbers, CSS classes |
| comments_controller_test.rb | 4 | Content/NewsTest::CreateUpdateCommentDelete | covered | covered | Not-commentable news only implicitly |
| context_menus/issues_controller_test.rb | 24 | Query/ReportsAndContextMenuTest::ContextMenu* (4) | partial | partial | Missing: per-field options (assignee, version, category, done ratio, custom fields), cross-project selection |
| context_menus/non_working_dates_controller_test.rb | 2 | — | n.a. | n.a. | Non-working dates calendar (unreleased Redmine master feature) |
| context_menus/projects_controller_test.rb | 5 | Gaps/ProjectsListTest::ContextMenuSingleProject, ContextMenuMultipleProjects | covered | covered | |
| context_menus/time_entries_controller_test.rb | 7 | Timelog/TimelogTest::ContextMenuAndFormRefresh | partial | partial | Missing: activity/custom field submenus, non-editable entries |
| context_menus/users_controller_test.rb | 8 | Gaps/UsersListTest::ContextMenu, ContextMenuAddToGroupLink | covered | covered | |
| custom_field_enumerations_controller_test.rb | 7 | Admin/CustomFieldsTest::EnumerationValues | partial | partial | Missing: destroy in-use value with reassignment form |
| custom_fields_controller_test.rb | 31 | Admin/CustomFieldsTest (9) | partial | partial | Missing: per-format option forms (link/user/version/attachment), copy, field_format change refused |
| documents_controller_test.rb | 17 | Content/DocumentsTest (3); Security/PermissionMatrixTest::ProjectAction, DisabledModule; Api/DocumentsApiTest | partial | partial | Missing: sorting/grouping of the index, notifications |
| email_addresses_controller_test.rb | 17 | Account/AccountTest::EmailAddresses; Admin/UsersTest::EmailAddresses; Notifications/ContentNotificationTest::SecurityNotificationAndAccountMails | partial | partial | Missing: disallowed domains, JS variants, other user's addresses denied |
| enumerations_controller_test.rb | 18 | Admin/EnumerationsTest (7) | covered | covered | |
| favicons_controller_test.rb | 1 | — | n.a. | n.a. | Dynamic favicon not implemented |
| files_controller_test.rb | 6 | Content/FilesTest (3) | covered | covered | |
| gantts_controller_test.rb | 19 | Query/GanttTest (4); Pdf/GanttExportTest (5) | partial | partial | Missing: HTML rendering checks (relations, versions, subjects), months/zoom params |
| groups_controller_test.rb | 35 | Admin/GroupsTest (5); Admin/UsersApiTest::GroupsApi | partial | partial | Missing: new_users autocomplete, memberships tab, validation errors |
| help_controller_test.rb | 7 | Gaps/RedmineGapsTest::WikiSyntaxHelp, WikiSyntaxHelpIsLocalized, AllWikiSyntaxTemplatesRender | covered | covered | |
| imports_controller_test.rb | 25 | Import/IssueImportTest (5); Import/TimeEntryAndUserImportTest (2) | partial | partial | Missing: encoding detection, malformed CSV, user import permissions |
| issue_categories_controller_test.rb | 14 | Projects/IssueCategoriesTest (5) | covered | covered | |
| issue_relations_controller_test.rb | 16 | Issues/JournalsRelationsWatchersTest::AddAndRemoveRelations; Integration/IssueWorkflowTest::RelationsAndCycles; Issues/IssuesApiTest::RelationsApi; Api/IssueRelationsApiTest (13: relations to invisible issues, create/destroy permissions) | partial | partial | Missing: multiple issue ids, invalid relation errors (XHR) |
| issue_statuses_controller_test.rb | 15 | Admin/IssueStatusesTest (7) | covered | covered | |
| issues_controller_test.rb | 493 | Issues/IssueFlowTest, IssueScreensTest, WorkflowRestrictionsTest, JournalsRelationsWatchersTest; Query/IssueListWebTest; Gaps/RedmineGapsTest (navigation, bulk edit); Pdf/IssuePdfTest, IssueListPdfTest; Security/PermissionMatrixTest, CsrfTest, PrivateDataVisibilityTest; Api/IssuesApiParityTest | partial | partial | Largest file. Now covered: action × role matrix, CSRF, private issue/notes in HTML/CSV/Atom/API/gantt/report. Missing: many index display options, copy with subtasks/attachments, bulk copy across projects |
| issues_controller_transaction_test.rb | 12 | Issues/IssueFlowTest::OptimisticLockingConflict | partial | partial | Missing: conflict resolution modes (overwrite / add notes / cancel) |
| issues_custom_fields_visibility_test.rb | 12 | Security/PrivateDataVisibilityTest::RestrictedCustomField (issue page, list column, filter, CSV, filters endpoint, query form, API, journal details, Atom; 5 users) | missing | covered | Notifications according to custom field visibility not checked |
| journals_controller_test.rb | 21 | Issues/JournalsRelationsWatchersTest (EditJournalNotes, DeleteNotes…, JournalEditDeniedWithoutPermission, DescriptionDiff, Quote); Issues/IssueScreensTest::PrivateNotesHiddenWithoutPermission; Api/JournalsApiTest (9); Security/PrivateDataVisibilityTest (`/issues/changes.atom`, QuotingPrivateNotes) | partial | covered | |
| mail_handler_controller_test.rb | 8 | MailHandler/MailHandlerEndpointTest (4) | covered | covered | |
| members_controller_test.rb | 41 | Projects/MembersTest (6); Security/PermissionMatrixTest::ProjectAction; Api/MembershipsApiTest | partial | partial | Missing: multiple users/roles in one request, role inheritance edit restrictions in the HTML form |
| messages_controller_test.rb | 26 | Content/ForumsTest (10); Security/PermissionMatrixTest::ProjectAction; Security/CsrfTest | partial | partial | Missing: quote, attachments, watchers, sticky/locked editing by non-managers |
| my_controller_test.rb | 56 | Account/MyPageTest (4); Account/AccountTest (MyAccountUpdate, Keys, DestroyOwnAccount, ChangePassword) | partial | partial | Missing: per-block content (issues assigned/reported/watched, timelog, news, documents, calendar), preferences validation |
| news_controller_test.rb | 23 | Content/NewsTest (6); Security/PermissionMatrixTest::ProjectAction; Api/NewsApiTest | partial | partial | Missing: global news form without projects, attachments in the HTML form |
| non_working_dates_controller_test.rb | 8 | — | n.a. | n.a. | Unreleased Redmine master feature |
| previews_controller_test.rb | 11 | Issues/IssueScreensTest::PreviewAndAutocomplete; Content/ForumsTest::Preview | partial | partial | Missing: news previews, attachment links/thumbnails in preview |
| principal_memberships_controller_test.rb | 22 | Admin/UsersTest::Memberships; Gaps/RedmineGapsTest::PrincipalMembershipVerbs | partial | partial | Missing: group memberships, multiple projects & roles, XHR failures |
| project_enumerations_controller_test.rb | 7 | Projects/ProjectsTest::ActivitiesOverride; Timelog/TimelogTest::ProjectActivityOverridesAndDefaultActivity | partial | partial | Missing: destroy reassigns time entries to system activity |
| projects_controller_test.rb | 124 | Projects/ProjectsTest (15); Gaps/ProjectsListTest (13); Security/PermissionMatrixTest (ProjectAction, DisabledModule, PrivateProject, ArchivedProject); Parity/RedmineBehaviourParityTest::VisibleProjects; Api/ProjectsApiParityTest | partial | partial | Missing: index display types and filters, settings tabs per permission, subproject creation rules, identifier rules |
| queries_controller_test.rb | 64 | Query/IssueListWebTest::SavedQueryCrud, FilterValuesEndpoint; Gaps/ProjectsListTest::SavedAdminQueries; Gaps/UsersListTest::SavedUserQuery | partial | partial | Missing: TimeEntryQuery saving, visibility roles, query with columns/totals/grouping options, non-admin public queries |
| reactions_controller_test.rb | 24 | Gaps/RedmineGapsTest (Reaction*, 5) | partial | partial | Missing: XHR rendering of each object type, disabled setting |
| reports_controller_test.rb | 15 | Query/ReportsAndContextMenuTest (IssueReportMatchesRedmine, IssueReportDetails, ReportCsvForAdmin, ReportRequiresViewIssues) | covered | covered | |
| repositories_bazaar_controller_test.rb | 15 | Scm/BazaarAdapterTest, BazaarRepositoryTest | partial | partial | Added by the SCM work stream |
| repositories_controller_test.rb | 34 | Content/RepositoryTest (5) | partial | partial | Missing: committers mapping, stats/graph, revision page with related issues |
| repositories_cvs_controller_test.rb | 15 | Scm/CvsAdapterTest, CvsRepositoryTest | partial | partial | Added by the SCM work stream |
| repositories_filesystem_controller_test.rb | 13 | Scm/FilesystemAdapterTest, FilesystemRepositoryTest | partial | partial | Added by the SCM work stream |
| repositories_git_controller_test.rb | 34 | Content/RepositoryTest; Scm/GitRepositoryTest | partial | partial | Missing: annotate/diff views, branches, entry with special chars |
| repositories_mercurial_controller_test.rb | 31 | Scm/MercurialAdapterTest, MercurialRepositoryTest | partial | partial | Added by the SCM work stream |
| repositories_subversion_controller_test.rb | 29 | Scm/SubversionAdapterTest, SubversionRepositoryTest | partial | partial | Added by the SCM work stream |
| roles_controller_test.rb | 27 | Admin/RolesTest (10) | covered | covered | |
| search_controller_test.rb | 37 | Projects/SearchTest (8); Plugin/ProvidersTest::SearchProvider; Parity/RedmineBehaviourParityTest::SearchResults (288 result lists: 16 questions × all/any/titles × 6 users); Security/PrivateDataVisibilityTest; Api/SearchApiTest | partial | covered | |
| search_custom_fields_visibility_test.rb | 1 | Security/ContentVisibilityTest::SearchIgnoresHiddenCustomFieldValues | missing | covered | |
| sessions_controller_test.rb | 11 | — | missing | missing | Session lifetime/timeout settings exist in the settings form but are not enforced (locked user sessions: Security/PermissionMatrixTest::LockedUserSessionIsRejected) |
| settings_controller_test.rb | 25 | Admin/SettingsTest (5) | partial | partial | Missing: per-tab specific settings (mail handler key generation, notified events, commit keywords) |
| sys_controller_test.rb | 13 | Scm/SysApiTest (3) | partial | partial | Missing: create with failure / disabled adapter, fetch by id/identifier |
| timelog_controller_test.rb | 124 | Timelog/TimelogTest (14); Parity/RedmineBehaviourParityTest::TimeEntryVisibilityAndTotals; Api/TimeEntriesApiTest; Security/PermissionMatrixTest | partial | partial | Missing: bulk edit across projects, many filters/columns |
| timelog_custom_fields_visibility_test.rb | 4 | Security/PrivateDataVisibilityTest::RestrictedCustomFieldInTimeEntries (list + CSV) | missing | covered | Edit form not checked |
| timelog_report_test.rb | 25 | Timelog/TimelogTest::CsvAndAtomAndReport | partial | partial | Missing: criteria combinations, custom field criteria, columns by year/month/week/day |
| trackers_controller_test.rb | 21 | Admin/TrackersTest (7) | covered | covered | |
| users_controller_test.rb | 89 | Admin/UsersTest (12); Gaps/UsersListTest (11); Security/PermissionMatrixTest::UserProfileVisibility, AdminPage; Api/UsersApiParityTest | partial | partial | Missing: preferences, generate password mail |
| versions_controller_test.rb | 28 | Projects/VersionsTest (9) | covered | covered | |
| versions_custom_fields_visibility_test.rb | 2 | Security/ContentVisibilityTest::VersionCustomFieldVisibility (HTML, roadmap, API) | missing | covered | |
| watchers_controller_test.rb | 62 | Issues/JournalsRelationsWatchersTest::WatchUnwatch, ManageWatchers; Issues/IssuesApiTest::WatchersApi; Security/ContentVisibilityTest::WatchersOfInvisibleObjects; Security/PrivateDataVisibilityTest::CannotWatchInvisibleIssue; Api/IssuesApiParityTest::AddAndRemoveWatcherXml, WatchersRequirePermission | partial | partial | Missing: watching news/boards/wiki pages through the HTML actions, autocomplete for watchers |
| webhooks_controller_test.rb | 22 | Admin/WebhooksTest (3) | partial | partial | Missing: edit/destroy by other users, project selection rules |
| welcome_controller_test.rb | 23 | Admin/SmokeBaseTest::HomeAsAdmin; Gaps/RedmineGapsTest::Robots; Api/* paging tests (offset/limit/total_count) | partial | partial | Missing: browser language negotiation, textarea font/warn on leaving preferences |
| wiki_controller_test.rb | 101 | Content/WikiTest (15); Pdf/WikiPdfTest; Security/PermissionMatrixTest::ProjectAction; Api/WikiPagesApiTest | partial | partial | Missing: history/annotate/diff views in detail, export HTML, parent page change |
| wikis_controller_test.rb | 3 | Content/WikiTest::DestroyWiki | covered | covered | |
| workflows_controller_test.rb | 29 | Admin/WorkflowsTest (8) | covered | covered | |

## Integration tests (`test/integration`)

| Redmine test file | # | Symine tests | Before | After | Notes |
|---|---|---|---|---|---|
| account_test.rb | 17 | Account/AccountTest; Auth/TwofaTest | partial | partial | Missing: autologin cookie flow, back_url handling after login |
| admin_test.rb | 2 | Admin/UsersTest::CreateUser | covered | covered | |
| application_test.rb | 8 | Api/AuthenticationApiTest::ApiRequestDoesNotUseTheSession, AnonymousOnLoginRequiredIs401; Security/PermissionMatrixTest::LoginRequired | missing | partial | Missing: localization from the browser, 4xx on missing templates |
| attachments_test.rb | 11 | Issues/IssueFlowTest::CreateWithAttachment; Issues/IssuesApiTest (uploads) | partial | partial | Missing: AJAX upload + preview of pending attachments |
| feeds_test.rb | 1 | Projects/ActivityTest::AtomFeed; Content/NewsTest::Atom | covered | covered | |
| issues_test.rb | 21 | Issues/IssueFlowTest; Issues/IssueScreensTest; Security/PrivateDataVisibilityTest (private issue through every output, DirectAccessToPrivateIssue); Api/IssuesApiParityTest | partial | covered | |
| layout_test.rb | 9 | (layout is checked indirectly by markup assertions) | partial | partial | Missing: top menu hidden with login required, search field target |
| projects_test.rb | 5 | Projects/ProjectsTest::CloseReopenArchiveUnarchive | covered | covered | |
| repositories_git_test.rb | 7 | Content/RepositoryTest; Scm/GitRepositoryTest | partial | partial | |
| sessions_test.rb | 12 | Security/ContentVisibilityTest::SessionsEndWhenTheAccountChanges; Api/MyAccountApiTest::ChangedMailRemovesSessionTokens; Api/UsersApiParityTest::AdminChangingOwnMailDoesNotCreateSessionToken | missing | partial | Password change / lock / mail change end other sessions; session lifetime/timeout settings are not enforced |
| sudo_mode_test.rb | 8 | — | n.a. | n.a. | Sudo mode is not implemented |
| twofa_test.rb | 10 | Auth/TwofaTest (16) | covered | covered | |
| users_test.rb | 1 | Admin/UsersTest::DestroyRequiresConfirmationAndAnonymizes; Security/CsrfTest::MethodOverrideIsIgnoredOnGet, RequestNeedsTheAuthenticityToken | partial | covered | |
| welcome_test.rb | 3 | Gaps/RedmineGapsTest::Robots | covered | covered | |
| wiki_test.rb | 1 | Content/WikiTest::RenameCreatesRedirect | covered | covered | |
| routing/ (49 files) | 75 | Api/* and Security/PermissionMatrixTest exercise the routes | missing | partial | No route-table assertions |
| lib/ (hook, menu_manager, themes, field_format/attachment_format) | 32 | Plugin/HooksTest, Plugin/MenuTest | partial | partial | Themes and attachment custom field format not covered |

## REST API tests (`test/integration/api_test`)

| Redmine test file | # | Symine tests | Before | After | Notes |
|---|---|---|---|---|---|
| api_routing_test.rb | 22 | Api/* (all API routes exercised) | missing | partial | No route-table test |
| api_test.rb | 5 | Api/AuthenticationApiTest (ApiWorksWithForgeryProtection, DatetimeFormat, NoContentResponsesHaveAnEmptyBody) | missing | covered | 406 for format-less API calls not ported |
| attachments_test.rb | 19 | Issues/IssuesApiTest::AttachmentApi, CreateUpdateDeleteLifecycle; Api/AttachmentsApiTest (18) | partial | covered | |
| authentication_test.rb | 22 | Api/AuthenticationApiTest; Auth/TwofaTest::ApiBasicAuthIsRefusedForTwofaUsers; RealServices/LdapTest::ApiBasicAuth | partial | covered | Also OAuth2 Bearer tokens: scopes, revoked/expired → 401, unknown → anonymous, ignored when the REST API is disabled |
| avatars_test.rb | 1 | — | n.a. | n.a. | |
| custom_fields_attribute_test.rb | 5 | Issues/IssuesApiTest::CreateUpdateDeleteLifecycle (custom_fields); Api/IssuesApiParityTest::UpdateJsonWithCustomFields, CreateWithWatchersParentAndCustomFields; Api/ReferenceDataApiTest::CustomFieldValuesAttributeOnGroups | partial | covered | |
| custom_fields_test.rb | 8 | Admin/CustomFieldsTest::ApiIndex; Api/ReferenceDataApiTest (CustomFieldsRequireAdmin, CustomFieldsXml, CustomFieldsProjectsAndRoles, EnumerationCustomFieldValuesAndLabels, DateCustomFieldWithOffsetDefault) | partial | covered | |
| disabled_rest_api_test.rb | 3 | Api/AuthenticationApiTest::DisabledRestApiReturns403 | missing | covered | |
| documents_test.rb | 26 | Content/DocumentsTest::Api; Api/DocumentsApiTest (12) | partial | covered | The reference container (Redmine 7.0.3-stable) has no documents API: not part of the differential suite |
| enumerations_test.rb | 2 | Admin/EnumerationsTest::Api | covered | covered | |
| files_test.rb | 6 | Api/FilesApiTest (7) | missing | covered | |
| groups_test.rb | 19 | Admin/UsersApiTest::GroupsApi; Api/GroupsApiTest (13) | partial | covered | |
| issue_categories_test.rb | 11 | Projects/IssueCategoriesTest::Api; Api/IssueCategoriesApiTest (9) | partial | covered | |
| issue_relations_test.rb | 7 | Issues/IssuesApiTest::RelationsApi; Api/IssueRelationsApiTest (13) | partial | covered | |
| issue_statuses_test.rb | 1 | Admin/IssueStatusesTest::Api | covered | covered | |
| issues_test.rb | 90 | Issues/IssuesApiTest (9); Query/IssueListWebTest::Api; Api/IssuesApiParityTest (53 methods, 66 cases); Api/IssueCreateAuthorizationApiTest (5) | partial | covered | |
| journals_test.rb | 4 | Issues/IssuesApiTest::JournalApiUpdate; Api/JournalsApiTest (9) | partial | covered | |
| jsonp_test.rb | 5 | Api/AuthenticationApiTest::JsonpCallback, JsonpCallbackIgnoredWhenDisabled | missing | covered | |
| memberships_test.rb | 13 | Projects/MembersTest::Api; Api/MembershipsApiTest (13) | partial | covered | |
| my_test.rb | 11 | Account/AccountTest::MyAccountApi; Api/MyAccountApiTest (7) | partial | covered | |
| news_test.rb | 25 | Content/NewsTest::Api; Api/NewsApiTest (16) | partial | covered | |
| projects_test.rb | 41 | Projects/ProjectsApiTest (5); Api/ProjectsApiParityTest (23) | partial | covered | Known unfixed divergences: `/projects.json?id=1,2` (Symine returns project 1, Redmine nothing), `/projects.json?status=*` (Symine all, Redmine none) |
| queries_test.rb | 1 | Query/IssueListWebTest::PagesRenderForAdminAndJsmith (/queries.json renders); Api/QueriesApiTest (5) | partial | covered | |
| repositories_test.rb | 8 | — | missing | missing | Adding/removing related issues of a changeset via the API |
| roles_test.rb | 3 | Admin/RolesTest::Api | covered | covered | |
| search_test.rb | 8 | Projects/SearchTest::Api; Api/SearchApiTest (10) | partial | covered | |
| time_entries_test.rb | 18 | Timelog/TimelogTest::ApiCrud; Api/TimeEntriesApiTest (22) | partial | covered | |
| trackers_test.rb | 1 | Admin/TrackersTest::Api | covered | covered | |
| users_test.rb | 37 | Admin/UsersApiTest (6); Gaps/UsersListTest::ApiCompatibility; Api/UsersApiParityTest (25) | partial | covered | |
| versions_test.rb | 9 | Projects/VersionsTest::Api; Api/VersionsApiTest (12) | partial | covered | |
| wiki_pages_test.rb | 14 | Content/WikiTest::Api; Api/WikiPagesApiTest (19) | partial | covered | |

Live differential checks are in `tests/Api/ReferenceDifferentialTest.php` (skipped unless `SYMINE_REFERENCE_URL` is set):
~84 read-only JSON GETs as anonymous/jsmith/dlopper/anna/rhill (switch user)/admin, 17 error statuses, 12 XML documents,
6 create/update/delete scenarios restored on the reference and 15 validation-error payloads.

## Unit tests (`test/unit`, selection)

| Redmine test file | # | Symine tests | Before | After | Notes |
|---|---|---|---|---|---|
| issue_test.rb | 309 | Integration/IssueWorkflowTest (9); Integration/RedmineParityTest (visibility, statuses, field rules, editability, assignable users); Issues/IssueFlowTest; Parity/RedmineBehaviourParityTest::NotifiedUsersAndWatchers (3 preference scenarios) | partial | partial | Still missing: copy rules, overdue/behind schedule helpers, many safe_attributes cases |
| issue_scopes_test.rb | 7 | Query/IssueQueryTest | partial | partial | |
| issue_subtasking_test.rb | 28 | Integration/IssueWorkflowTest::CreateSubtaskDerivesParentDatesAndRebuildsTree, ParentWithOpenChildrenCannotBeClosed | partial | partial | Missing: done ratio/priority/estimated derivation modes, independent mode |
| issue_nested_set_test.rb | 23 | Integration/IssueWorkflowTest::CreateSubtaskDerivesParentDatesAndRebuildsTree; Issues/IssueFlowTest::RemoveSubtaskLink | partial | partial | Missing: move between trees, destroy child, cross-project parent |
| issue_relation_test.rb | 19 | Integration/IssueWorkflowTest::RelationsAndCycles, BlockedIssueCannotBeClosed | partial | partial | Missing: precedes rescheduling with delay, reverse relation creation |
| issue_status_test.rb | 13 | Admin/IssueStatusesTest | partial | partial | |
| issue_priority_test.rb | 20 | Admin/EnumerationsTest::CreatePriorityRecomputesPositionNames | partial | partial | |
| issue_category_test.rb | 4 | Projects/IssueCategoriesTest::DestroyWithReassignment | covered | covered | |
| issue_custom_field_test.rb | 3 | Admin/CustomFieldsTest | partial | partial | |
| query_test.rb | 287 | Query/IssueQueryTest (10); Query/IssueQueryParityTest; Parity/RedmineBehaviourParityTest::IssueQueryTotals (count, totals, grouped totals per user) | partial | partial | Missing: many operator × field type combinations, watcher/updated_by filters |
| project_query_test.rb, project_admin_query_test.rb | 23 | Gaps/ProjectsListTest | partial | partial | |
| time_entry_query_test.rb | 15 | Timelog/TimelogTest::Filters; Parity/RedmineBehaviourParityTest::TimeEntryVisibilityAndTotals | partial | partial | Missing: filters on issue attributes and custom fields |
| user_query_test.rb | 21 | Gaps/UsersListTest | partial | partial | |
| project_test.rb | 105 | Projects/ProjectsTest; Integration/RedmineParityTest::ProjectPermissions; Parity/RedmineBehaviourParityTest::VisibleProjects, ProjectsAllowedTo | partial | partial | Missing: rollup helpers, shared versions per sharing mode |
| project_copy_test.rb | 28 | Projects/ProjectsTest::CopyProject | partial | partial | |
| project_members_inheritance_test.rb | 16 | Projects/MembersTest::InheritedMembersOnSubproject | partial | partial | |
| user_test.rb, principal_test.rb | 184 | Integration/RedmineParityTest::ProjectPermissions; Account/AccountTest; Gaps/UsersListTest; Security/PermissionMatrixTest::UserProfileVisibility; Parity/RedmineBehaviourParityTest::ProjectsAllowedTo | partial | partial | Missing: password validation rules, mail notification options |
| group_test.rb | 17 | Admin/GroupsTest | partial | partial | |
| member_test.rb | 31 | Projects/MembersTest | partial | partial | |
| role_test.rb | 30 | Admin/RolesTest; Integration/RedmineParityTest | partial | partial | |
| workflow_test.rb, workflow_transition_test.rb | 8 | Admin/WorkflowsTest (8); Integration/RedmineParityTest::StatusesAllowed | covered | covered | |
| mailer_test.rb | 86 | Notifications/IssueNotificationTest (15), ContentNotificationTest (8); Parity/RedmineBehaviourParityTest::NotifiedUsersAndWatchers | partial | partial | Missing: message-id/references threading details, reminders filters |
| mailer_localisation_test.rb | 13 | Notifications/IssueNotificationTest::RecipientLanguageAndPlainText | partial | partial | |
| mail_handler_test.rb | 113 | MailHandler/MailHandlerTest (14); RealServices/IncomingMailTest | partial | partial | Missing: many encoding / charset variants, CC watchers, default values per tracker |
| custom_field_test.rb, custom_value_test.rb | 51 | Admin/CustomFieldsTest | partial | partial | Missing: value validation per format (regexp, length, int/float ranges) |
| custom_field_user_format_test.rb, custom_field_version_format_test.rb | 14 | — | missing | missing | Possible values of user/version custom fields per project |
| time_entry_test.rb | 30 | Timelog/TimelogTest (CreateFromFormAndValidation, SettingsValidations, OwnVisibility); Parity/RedmineBehaviourParityTest::TimeEntryVisibilityAndTotals (default and own visibility) | partial | partial | Missing: validations on locked/closed projects, hours parsing variants |
| time_entry_activity_test.rb | 25 | Timelog/TimelogTest::ProjectActivityOverridesAndDefaultActivity | partial | partial | |
| wiki_test.rb, wiki_page_test.rb, wiki_content_test.rb, wiki_redirect_test.rb | 50 | Content/WikiTest (15) | partial | partial | |
| attachment_test.rb | 64 | Issues/IssueScreensTest; Gaps/RedmineGapsTest | partial | partial | Missing: digest/disk filename rules, extension allow/deny lists, max size |
| version_test.rb | 34 | Projects/VersionsTest | partial | partial | Missing: completed percent / estimated hours computations |
| journal_test.rb, journal_observer_test.rb | 41 | Integration/IssueWorkflowTest::UpdateCreatesJournalWithDetails; Notifications/IssueNotificationTest | partial | partial | |
| watcher_test.rb | 21 | Issues/JournalsRelationsWatchersTest | partial | partial | |
| search_test.rb | 14 | Projects/SearchTest (Tokenizer, HighlightTokens); Parity/RedmineBehaviourParityTest::SearchResults | partial | covered | |
| activity_test.rb | 16 | Projects/ActivityTest::FetcherVisibilityOfPrivateNotes; Parity/RedmineBehaviourParityTest::ActivityEvents | partial | covered | |
| news_test.rb | 9 | Content/NewsTest | partial | partial | |
| message_test.rb, board_test.rb | 18 | Content/ForumsTest::CreateTopicReplyEditDeleteMaintainsCounters | partial | partial | |
| document_test.rb | 6 | Content/DocumentsTest | partial | partial | |
| token_test.rb | 20 | Account/AccountTest::ExpiredRecoveryToken, Keys; Api/AuthenticationApiTest::ApiKeyUsageUpdatesTokenUpdatedOn, FailedAuthDoesNotUpdateTokens | partial | partial | Missing: token expiration/cleanup per action |
| setting_test.rb | 16 | Admin/SettingsTest | partial | partial | |
| webhook_test.rb, webhook_payload_test.rb | 50 | Notifications/WebhookTest (4); RealServices/WebhookTest (2) | partial | partial | |

## Summary

| Group | Rows | Before: covered / partial / missing / n.a. | After: covered / partial / missing / n.a. |
|---|---|---|---|
| Functional | 73 | 17 / 46 / 6 / 4 | 25 / 43 / 1 / 4 |
| Integration | 17 | 6 / 7 / 3 / 1 | 8 / 8 / 0 / 1 |
| REST API | 31 | 4 / 20 / 6 / 1 | 28 / 1 / 1 / 1 |
| Unit (selection) | 41 | 2 / 38 / 1 / 0 | 4 / 36 / 1 / 0 |
| **Total** | **162** | **29 / 111 / 16 / 6** | **65 / 88 / 3 / 6** |

## How to run

```
bin/test                        # full PHPUnit suite + summary per test directory
bin/test --api                  # REST API parity suite (tests/Api)
bin/test --security             # permission matrix, CSRF, private data visibility (tests/Security)
bin/test --parity               # oracle snapshots: tests/Parity + RedmineParityTest + IssueQueryParityTest
bin/test --differential         # live comparison with the reference Redmine (see below)
bin/test -- --filter Wiki tests/Content   # arguments after -- are passed to phpunit
```

Options can be combined (`bin/test --api --security`). Environment:

- `PHP`: PHP binary (default `/opt/homebrew/opt/php/bin/php` when present, else `php`).
- `TEST_TOKEN`: selects the test database `var/test$TEST_TOKEN.db` (default `bin`), a fresh copy of
  `tests/fixtures/symine-seeded.db` for every test, so several runs can share the working tree.
- `APP_CACHE_DIR`: Symfony cache directory (default `var/cache-test$TEST_TOKEN`).

`bin/test` wraps the plain command, which can always be used directly:

```
TEST_TOKEN=x APP_CACHE_DIR=var/cache-x php -d memory_limit=2G bin/phpunit
```

### Live differential suite

```
SYMINE_REFERENCE_URL=http://localhost:3300 bin/test --differential
```

- `SYMINE_REFERENCE_URL`: base URL of the reference Redmine (seeded with `tests/fixtures/seed_redmine.rb`).
  `bin/test --differential` defaults it to `http://localhost:3300`; in a plain phpunit run the differential tests are
  skipped without it.
- `SYMINE_REFERENCE_KEY`: API key used on both sides (default: jsmith's key `3de6a93a7c5f8921fa98e0fecd9ae25695aa7536`).
- `SYMINE_REFERENCE_ADMIN`: `login:password` for admin requests through HTTP Basic (default `admin:admin`).
- The same requests are sent to the reference and to Symine. JSON payloads are compared key by key (same keys, same
  JSON types, same values), ignoring timestamps and the host part of URLs. IDs are identical because the Symine
  database was migrated from the reference.
- The reference instance may be shared with other work: records that exist only in the reference are ignored in
  collections, and `total_count` is checked after discounting them.
- Mutating requests (POST/PUT/DELETE) are applied to both sides only when the reference can be restored afterwards
  (a created object is deleted again); otherwise only the shape of the responses is compared.
- Known version differences are ignored: the reference container runs Redmine 7.0.3-stable while Symine ports master.
  The reference has no documents API, so documents are not compared. The `export_issues` and `export_time_entries`
  permissions are stripped from role payloads.

### Regenerating the Redmine oracle

The oracle (`tests/fixtures/redmine-expectations.json`) is produced on a pristine copy of the seeded Redmine
database, not on the live reference (which other work may modify):

```
docker cp tests/fixtures/redmine-seeded.db symine-redmine-ref:/tmp/oracle.db
docker cp tests/fixtures/redmine_expectations.rb symine-redmine-ref:/tmp/
docker exec -e RAILS_ENV=production -e DATABASE_URL=sqlite3:/tmp/oracle.db symine-redmine-ref bin/rails runner /tmp/redmine_expectations.rb > tests/fixtures/redmine-expectations.json
```

## Remaining gaps

Not covered by tests (Redmine tests without a Symine equivalent):

- `api_test/repositories_test.rb`: the API for adding and removing a changeset's related issues.
- Session lifetime/timeout (`sessions_controller_test.rb`): the settings exist but are not enforced, so this is
  also a feature gap.
- Unit tests for user and version custom field formats (possible values per project).
- `issues_controller_transaction_test.rb`: the conflict resolution modes (overwrite / add notes / cancel).
- Calendar and Gantt HTML details: issues placed on the right days, relations, zoom and months.
- Context menu submenus: per-field options for issues and time entries.
- My page block contents.
- Mail handler encoding and charset variants.
- Many query operator × field type combinations.
- Issue subtasking derivation modes: done ratio, priority, estimated time, independent mode.
- Time entry report criteria combinations.

Known unfixed divergences from Redmine:

- `/projects.json?id=1,2`: Symine returns project 1; Redmine returns nothing.
- `/projects.json?status=*`: Symine returns all projects; Redmine returns none.
