<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Permission matrix of the project controller actions (Redmine's map.permission, lib/redmine/preparation.rb).
 *
 * For every action the permission (and its alternatives) is removed from the Manager, Non member and
 * Anonymous roles, then the action is requested as:
 *   anonymous                   => redirect to the login page (require_login)
 *   anna (non member)           => 403
 *   jsmith (Manager) without it => 403
 *   jsmith with the permission  => 200
 *   admin                       => 200
 */
final class PermissionMatrixTest extends SecurityWebTestCase
{
    /** @return iterable<string, array{0: string, 1: list<string>}> */
    public static function projectActions(): iterable
    {
        $rows = [
            // issue tracking
            ['/projects/ecommerce/issues', ['view_issues']],
            ['/issues/13', ['view_issues']],
            ['/projects/ecommerce/issues/report', ['view_issues']],
            ['/projects/ecommerce/issues/report/tracker', ['view_issues']],
            ['/projects/ecommerce/issues/new', ['add_issues', 'copy_issues']],
            ['/issues/13/edit', ['edit_issues', 'edit_own_issues', 'add_issue_notes']],
            ['/projects/ecommerce/issues/13/copy', ['copy_issues', 'add_issues']],
            ['/projects/ecommerce/issues/gantt', ['view_gantt']],
            ['/projects/ecommerce/issues/calendar', ['view_calendar']],
            ['/projects/ecommerce/issues.csv', ['export_issues']],
            ['/projects/ecommerce/issue_categories/new', ['manage_categories']],
            ['/issue_categories/1/edit', ['manage_categories']],
            ['/projects/ecommerce/queries/new', ['save_queries', 'manage_public_queries']],
            ['/issues/imports/new?project_id=ecommerce', ['import_issues']],
            // versions
            ['/projects/ecommerce/roadmap', ['view_issues']],
            ['/versions/1', ['view_issues']],
            ['/projects/ecommerce/versions/new', ['manage_versions']],
            ['/versions/1/edit', ['manage_versions']],
            // time tracking
            ['/projects/ecommerce/time_entries', ['view_time_entries']],
            ['/projects/ecommerce/time_entries/report', ['view_time_entries']],
            ['/projects/ecommerce/time_entries.csv', ['export_time_entries']],
            ['/projects/ecommerce/time_entries/new', ['log_time']],
            ['/issues/13/time_entries/new', ['log_time']],
            ['/time_entries/1/edit', ['edit_time_entries', 'edit_own_time_entries']],
            // news
            ['/projects/ecommerce/news', ['view_news']],
            ['/news/1', ['view_news']],
            ['/projects/ecommerce/news/new', ['manage_news']],
            ['/news/1/edit', ['manage_news']],
            // documents
            ['/projects/ecommerce/documents', ['view_documents']],
            ['/documents/1', ['view_documents']],
            ['/projects/ecommerce/documents/new', ['add_documents']],
            ['/documents/1/edit', ['edit_documents']],
            // files
            ['/projects/ecommerce/files', ['view_files']],
            ['/projects/ecommerce/files/new', ['manage_files']],
            // wiki
            ['/projects/ecommerce/wiki', ['view_wiki_pages']],
            ['/projects/ecommerce/wiki/Checkout_flow', ['view_wiki_pages']],
            ['/projects/ecommerce/wiki/index', ['view_wiki_pages']],
            ['/projects/ecommerce/wiki/date_index', ['view_wiki_pages']],
            ['/projects/ecommerce/wiki/Checkout_flow/history', ['view_wiki_edits']],
            ['/projects/ecommerce/wiki/Checkout_flow/diff?version=2&version_from=1', ['view_wiki_edits']],
            ['/projects/ecommerce/wiki/Checkout_flow/2/annotate', ['view_wiki_edits']],
            ['/projects/ecommerce/wiki/Checkout_flow/edit', ['edit_wiki_pages']],
            ['/projects/ecommerce/wiki/new', ['edit_wiki_pages']],
            ['/projects/ecommerce/wiki/Checkout_flow/rename', ['rename_wiki_pages', 'manage_wiki']],
            ['/projects/ecommerce/wiki/export', ['export_wiki_pages']],
            // forums
            ['/projects/ecommerce/boards', ['view_messages']],
            ['/projects/ecommerce/boards/1', ['view_messages']],
            ['/boards/1/topics/1', ['view_messages']],
            ['/boards/1/topics/new', ['add_messages']],
            ['/boards/1/topics/1/edit', ['edit_messages', 'edit_own_messages']],
            ['/projects/ecommerce/boards/new', ['manage_boards']],
            ['/projects/ecommerce/boards/1/edit', ['manage_boards']],
            // project administration
            ['/projects/ecommerce/settings', ['edit_project', 'select_project_modules', 'manage_members', 'manage_versions', 'manage_categories', 'manage_repository', 'manage_boards', 'manage_project_activities', 'manage_wiki', 'use_webhooks']],
            ['/projects/ecommerce/memberships/new', ['manage_members']],
        ];
        foreach ($rows as [$url, $perms]) {
            yield $url => [$url, $perms];
        }
    }

    #[DataProvider('projectActions')]
    public function testProjectAction(string $url, array $permissions): void
    {
        foreach ([self::ROLE_MANAGER, self::ROLE_NON_MEMBER, self::ROLE_ANONYMOUS] as $role) {
            $this->rolePermissions($role, [], $permissions);
        }
        $actual = [];
        foreach (['anonymous' => null, 'anna' => 'anna', 'jsmith without permission' => 'jsmith'] as $label => $login) {
            $this->as($login);
            $this->get($url);
            $actual[$label] = $this->outcome();
        }
        $this->rolePermissions(self::ROLE_MANAGER, $permissions);
        foreach (['jsmith with permission' => 'jsmith', 'admin' => 'admin'] as $label => $login) {
            $this->as($login);
            $this->get($url);
            $actual[$label] = $this->outcome();
        }
        self::assertSame([
            'anonymous' => 'login',
            'anna' => '403',
            'jsmith without permission' => '403',
            'jsmith with permission' => 'ok',
            'admin' => 'ok',
        ], $actual, "GET $url (permission ".implode('|', $permissions).')');
    }

    /** Disabled project module: 403 even for the admin (Redmine: allowed_to? checks enabled modules) */
    public static function moduleActions(): iterable
    {
        yield 'issue_tracking' => ['issue_tracking', '/projects/ecommerce/issues'];
        yield 'time_tracking' => ['time_tracking', '/projects/ecommerce/time_entries'];
        yield 'news' => ['news', '/projects/ecommerce/news'];
        yield 'documents' => ['documents', '/projects/ecommerce/documents'];
        yield 'files' => ['files', '/projects/ecommerce/files'];
        yield 'wiki' => ['wiki', '/projects/ecommerce/wiki'];
        yield 'boards' => ['boards', '/projects/ecommerce/boards'];
        yield 'calendar' => ['calendar', '/projects/ecommerce/issues/calendar'];
        yield 'gantt' => ['gantt', '/projects/ecommerce/issues/gantt'];
    }

    #[DataProvider('moduleActions')]
    public function testDisabledModule(string $module, string $url): void
    {
        $this->as('admin');
        $this->get($url);
        self::assertSame('ok', $this->outcome(), "$url with $module enabled");
        $this->conn()->executeStatement('DELETE FROM enabled_modules WHERE project_id = 1 AND name = ?', [$module]);
        foreach (['admin', 'jsmith'] as $login) {
            $this->as($login);
            $this->get($url);
            self::assertSame('403', $this->outcome(), "$url with $module disabled as $login");
        }
    }

    /** Private project: anonymous => login, non member => 403, member => 200, admin => 200 */
    public static function privateProjectPages(): iterable
    {
        foreach (['/projects/internal', '/projects/internal/issues', '/issues/18', '/projects/internal/activity', '/projects/internal/time_entries',
            '/projects/internal/wiki', '/projects/internal/roadmap', '/projects/internal/time_entries/report', '/projects/internal/search?q=crash'] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('privateProjectPages')]
    public function testPrivateProject(string $url): void
    {
        $actual = [];
        foreach (['anonymous' => null, 'anna' => 'anna', 'rhill' => 'rhill', 'jsmith' => 'jsmith', 'admin' => 'admin'] as $label => $login) {
            $this->as($login);
            $this->get($url);
            $actual[$label] = $this->outcome();
        }
        self::assertSame(['anonymous' => 'login', 'anna' => '403', 'rhill' => '403', 'jsmith' => 'ok', 'admin' => 'ok'], $actual, $url);
    }

    /** Archived project: 403 for everybody but the admin (Redmine: "project is archived" message) */
    public function testArchivedProject(): void
    {
        foreach (['jsmith', 'anna'] as $login) {
            $this->as($login);
            $this->get('/projects/legacy');
            self::assertSame('403', $this->outcome(), "archived project as $login");
            $this->get('/projects/legacy/issues');
            self::assertSame('403', $this->outcome(), "archived project issues as $login");
        }
        $this->as('admin');
        $this->get('/projects/legacy');
        self::assertSame('403', $this->outcome());
        self::assertStringContainsString('archived', $this->body());
    }

    /** Admin pages: anonymous => login, users => 403, admin => 200 */
    public static function adminPages(): iterable
    {
        foreach (['/admin', '/admin/projects', '/users', '/users/new', '/users/5/edit', '/groups', '/groups/new', '/roles', '/roles/new',
            '/roles/permissions', '/trackers', '/trackers/new', '/issue_statuses', '/workflows', '/workflows/edit', '/workflows/permissions',
            '/custom_fields', '/custom_fields/new?type=IssueCustomField', '/enumerations', '/settings', '/auth_sources', '/admin/info',
            '/admin/plugins', '/projects/new?parent_id=', ] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('adminPages')]
    public function testAdminPage(string $url): void
    {
        if ('/projects/new?parent_id=' === $url) {
            // projects#new belongs to the add_project and add_subprojects permissions (authorize_global):
            // denied unless a role grants one of them
            $this->rolePermissions(self::ROLE_MANAGER, [], ['add_project', 'add_subprojects']);
            $this->rolePermissions(self::ROLE_NON_MEMBER, [], ['add_project']);
        }
        $actual = [];
        foreach (['anonymous' => null, 'jsmith' => 'jsmith', 'anna' => 'anna', 'admin' => 'admin'] as $label => $login) {
            $this->as($login);
            $this->get($url);
            $actual[$label] = $this->outcome();
        }
        self::assertSame(['anonymous' => 'login', 'jsmith' => '403', 'anna' => '403', 'admin' => 'ok'], $actual, $url);
    }

    /** Pages that only need a login */
    public static function loggedInPages(): iterable
    {
        foreach (['/my/page', '/my/account', '/my/password', '/my/api_key', '/users/5/email_addresses'] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('loggedInPages')]
    public function testLoggedInPage(string $url): void
    {
        $this->as(null);
        $this->get($url);
        self::assertSame('login', $this->outcome(), "$url as anonymous");
        $this->as('/users/5/email_addresses' === $url ? 'jsmith' : 'anna');
        $this->get($url);
        self::assertSame('ok', $this->outcome(), "$url as a logged user");
    }

    /** login_required: every page but the login/registration pages needs a login */
    public function testLoginRequired(): void
    {
        $this->setting('login_required', '1');
        $this->as(null);
        foreach (['/', '/projects', '/projects/ecommerce', '/issues', '/issues/13', '/activity', '/search?q=cart', '/news', '/users/5', '/projects/ecommerce/wiki'] as $url) {
            $this->get($url);
            self::assertSame('login', $this->outcome(), "$url with login_required");
        }
        foreach (['/login', '/account/lost_password'] as $url) {
            $this->get($url);
            self::assertSame('ok', $this->outcome(), "$url with login_required");
        }
        $this->as('anna');
        $this->get('/projects');
        self::assertSame('ok', $this->outcome());
    }

    /** Locked users lose their session (find_current_user only finds active users) */
    public function testLockedUserSessionIsRejected(): void
    {
        $this->as('jsmith');
        $this->get('/my/page');
        self::assertSame('ok', $this->outcome());
        $this->conn()->executeStatement("UPDATE users SET status = 3 WHERE login = 'jsmith'");
        $this->get('/my/page');
        self::assertSame('login', $this->outcome());
    }

    /** User profiles: users_visibility of the roles */
    public function testUserProfileVisibility(): void
    {
        // anna shares no project with jsmith: Non member role users_visibility = members_of_visible_projects
        $this->as('anna');
        $this->get('/users/5');
        self::assertSame('ok', $this->outcome(), 'jsmith is a member of a public project');
        $this->get('/users/8');
        self::assertSame('404', $this->outcome(), 'locked user');
        $this->as(null);
        $this->get('/users/5');
        self::assertSame('ok', $this->outcome());
        $this->conn()->executeStatement("UPDATE roles SET users_visibility = 'members_of_visible_projects'");
        $this->conn()->executeStatement('UPDATE projects SET is_public = 0');
        $this->as('anna');
        $this->get('/users/5');
        self::assertSame('404', $this->outcome(), 'jsmith is only a member of private projects');
        $this->as('rhill');
        $this->get('/users/5');
        self::assertSame('ok', $this->outcome(), 'rhill shares ecommerce with jsmith');
    }
}
