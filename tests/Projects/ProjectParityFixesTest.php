<?php

declare(strict_types=1);

namespace App\Tests\Projects;

use Symfony\Component\Mime\Email;

/**
 * Project behaviours aligned with Redmine: project copy (notifications, attachments, watchers, author),
 * subproject creation with add_subprojects, members page / CSV / filters, deletion e-mail, overview links,
 * unarchive button, activity of users that are not visible.
 */
final class ProjectParityFixesTest extends ProjectsWebTestCase
{
    private function sql(string $sql, array $params = []): mixed
    {
        return $this->em->getConnection()->fetchOne($sql, $params);
    }

    /** @return list<Email> */
    private function mails(): array
    {
        return array_values(array_filter(self::getMailerMessages(), static fn ($m) => $m instanceof Email));
    }

    private function removePermissions(int $roleId, array $remove): void
    {
        $perms = json_decode((string) $this->sql('SELECT permissions FROM roles WHERE id = ?', [$roleId]), true) ?: [];
        $this->em->getConnection()->executeStatement('UPDATE roles SET permissions = ? WHERE id = ?', [json_encode(array_values(array_diff($perms, $remove))), $roleId]);
    }

    private function copyParams(string $identifier, array $extra = []): array
    {
        return [
            'project' => ['name' => 'Copy '.$identifier, 'identifier' => $identifier, 'is_public' => '1', 'enabled_module_names' => ['issue_tracking'], 'tracker_ids' => ['1', '2', '3']],
            'only' => ['members', 'versions', 'issue_categories', 'issues'],
        ] + $extra;
    }

    // --- project copy ---------------------------------------------------------------------------------

    public function testCopyDoesNotSendNotificationsUnlessAsked(): void
    {
        $this->login('admin');
        self::assertSame(302, $this->submit('/projects/ecommerce/copy', $this->copyParams('quiet')));
        self::assertSame(5, (int) $this->sql("SELECT COUNT(*) FROM issues i JOIN projects p ON p.id = i.project_id WHERE p.identifier = 'quiet'"));
        self::assertSame([], array_map(static fn (Email $m) => $m->getSubject(), $this->mails()), 'Mailer.with_deliveries(false)');
    }

    public function testCopySendsNotificationsWhenAsked(): void
    {
        $this->login('admin');
        self::assertSame(302, $this->submit('/projects/ecommerce/copy', $this->copyParams('loud', ['notifications' => '1'])));
        $subjects = array_map(static fn (Email $m) => (string) $m->getSubject(), $this->mails());
        self::assertNotSame([], $subjects);
        self::assertNotSame([], array_filter($subjects, static fn (string $s) => str_starts_with($s, '[Copy loud - Bug #')), implode(' | ', $subjects));
    }

    public function testCopyIssuesKeepsAttachmentsAndWatchersAndSetsTheAuthor(): void
    {
        // issue #13: attachment notes.txt, watchers jsmith (5) and rhill (7), author jsmith
        self::assertSame('5,7', (string) $this->sql("SELECT GROUP_CONCAT(user_id) FROM (SELECT user_id FROM watchers WHERE watchable_type = 'Issue' AND watchable_id = 13 ORDER BY user_id)"));
        $this->login('admin');
        self::assertSame(302, $this->submit('/projects/ecommerce/copy', $this->copyParams('full')));
        $copy = (int) $this->sql("SELECT i.id FROM issues i JOIN projects p ON p.id = i.project_id WHERE p.identifier = 'full' AND i.subject = 'Cart total is wrong with discounts'");
        self::assertGreaterThan(0, $copy);

        // Issue#copy_from: attachments are copied
        self::assertSame('notes.txt', $this->sql("SELECT filename FROM attachments WHERE container_type = 'Issue' AND container_id = ?", [$copy]));
        self::assertSame(1, (int) $this->sql("SELECT COUNT(*) FROM attachments WHERE container_type = 'Issue' AND container_id = 13"), 'the source keeps its attachment');
        // ... and the watchers too (plus the author of the copy, who watches the issues he creates)
        $watchers = array_map('intval', $this->em->getConnection()->fetchFirstColumn("SELECT user_id FROM watchers WHERE watchable_type = 'Issue' AND watchable_id = ? ORDER BY user_id", [$copy]));
        self::assertContains(5, $watchers);
        self::assertContains(7, $watchers);
        // self.author = User.current
        self::assertSame(['1'], array_map('strval', $this->em->getConnection()->fetchFirstColumn("SELECT DISTINCT i.author_id FROM issues i JOIN projects p ON p.id = i.project_id WHERE p.identifier = 'full'")));
        // :keep_status => true, versions and categories mapped by name, subtasks kept
        self::assertSame((int) $this->sql('SELECT status_id FROM issues WHERE id = 13'), (int) $this->sql('SELECT status_id FROM issues WHERE id = ?', [$copy]));
        self::assertSame('1.0', $this->sql('SELECT v.name FROM issues i JOIN versions v ON v.id = i.fixed_version_id WHERE i.id = ?', [$copy]));
        self::assertSame(2, (int) $this->sql("SELECT COUNT(*) FROM issues i JOIN projects p ON p.id = i.project_id WHERE p.identifier = 'full' AND i.parent_id IS NOT NULL"));
    }

    // --- projects#new / #create with add_subprojects -----------------------------------------------------

    public function testSubprojectCanBeCreatedWithAddSubprojectsOnly(): void
    {
        $this->removePermissions(3, ['add_project']); // Manager keeps add_subprojects
        $this->removePermissions(1, ['add_project']);
        $this->login('jsmith');
        $html = $this->get('/projects/new?parent_id=ecommerce');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('name="project[parent_id]"', $html);

        self::assertSame(302, $this->submit('/projects', ['project' => ['name' => 'Sub only', 'identifier' => 'sub-only', 'parent_id' => '1']]));
        self::assertSame(1, (int) $this->sql("SELECT parent_id FROM projects WHERE identifier = 'sub-only'"));

        // a root project still needs add_project (Project#allowed_parents)
        self::assertSame(200, $this->submit('/projects', ['project' => ['name' => 'Root', 'identifier' => 'root-denied']]));
        self::assertStringContainsString('Subproject of is invalid', (string) $this->client->getResponse()->getContent());
        self::assertFalse($this->sql("SELECT id FROM projects WHERE identifier = 'root-denied'"));
    }

    public function testNewProjectIsDeniedWithoutAddProjectAndAddSubprojects(): void
    {
        $this->removePermissions(3, ['add_project']);
        $this->removePermissions(1, ['add_project']);
        foreach (['dlopper', 'anna'] as $login) {
            $this->login($login);
            $this->get('/projects/new?parent_id=ecommerce');
            self::assertSame(403, $this->code(), $login);
            $this->submit('/projects', ['project' => ['name' => 'Denied', 'identifier' => 'denied-'.$login, 'parent_id' => '1']]);
            self::assertSame(403, $this->code(), $login);
        }
        self::assertSame(0, (int) $this->sql("SELECT COUNT(*) FROM projects WHERE identifier LIKE 'denied-%'"));
    }

    // --- members: page, CSV, filters ----------------------------------------------------------------------

    public function testMembersPageForUsersWhoCanViewTheMembers(): void
    {
        $this->login('rhill'); // Reporter: view_members (public permission), not manage_members
        $html = $this->get('/projects/ecommerce/members');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('<h2>Members</h2>', $html);
        foreach (['John Smith', 'Dave Lopper', 'Robert Hill', 'Developers'] as $name) {
            self::assertStringContainsString($name, $html);
        }
        self::assertStringContainsString('id="members-filter-form"', $html);
        // no management action, no status filter, no export
        self::assertStringNotContainsString('icon-edit', $html);
        self::assertStringNotContainsString('memberships/new', $html);
        self::assertStringNotContainsString('name="member_status"', $html);
        self::assertStringNotContainsString('memberships.csv', $html);

        // /projects/:id/memberships is the same action
        $this->get('/projects/ecommerce/memberships');
        self::assertSame(200, $this->code());

        // linked from the overview
        self::assertStringContainsString('<a href="/projects/ecommerce/members">View all members</a>', $this->get('/projects/ecommerce'));
    }

    public function testMembersPageFollowsTheProjectVisibility(): void
    {
        $this->get('/projects/ecommerce/members');
        self::assertSame(200, $this->code(), 'anonymous, public project');
        $this->get('/projects/internal/members');
        self::assertSame(302, $this->code(), 'anonymous, private project');
        $this->login('anna');
        $this->get('/projects/internal/members');
        self::assertSame(403, $this->code());
        $this->login('jsmith');
        self::assertStringContainsString('John Smith', $this->get('/projects/internal/members'));
    }

    /** @return list<int> ids of the members listed in the page */
    private function listedMembers(string $html): array
    {
        preg_match_all('/<tr id="member-(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
    }

    public function testMembersFilters(): void
    {
        // seeded members of ecommerce: 1 jsmith (Manager), 2 dlopper (Developer), 3 rhill (Reporter), 6 group Developers (Developer)
        $this->em->getConnection()->executeStatement("INSERT INTO members (user_id, project_id, created_on, mail_notification) VALUES (8, 1, datetime('now'), 0)");
        $locked = (int) $this->em->getConnection()->lastInsertId();
        $this->em->getConnection()->executeStatement('INSERT INTO member_roles (member_id, role_id) VALUES (?, 5)', [$locked]);

        $this->login('jsmith');
        $base = '/projects/ecommerce/settings/members';
        self::assertEqualsCanonicalizing([1, 2, 3, 6, $locked], $this->listedMembers($this->get($base)), 'managers see every status by default');
        self::assertSame([2], $this->listedMembers($this->get($base.'?member_name=lopp')));
        self::assertSame([2], $this->listedMembers($this->get($base.'?member_name=dave+lopper')), 'every word in the names');
        self::assertSame([3], $this->listedMembers($this->get($base.'?member_name=rhill%40example')), 'e-mail address');
        self::assertSame([6], $this->listedMembers($this->get($base.'?member_name=developers')), 'group name');
        self::assertEqualsCanonicalizing([2, 6], $this->listedMembers($this->get($base.'?member_role_id=4')));
        self::assertEqualsCanonicalizing([3, $locked], $this->listedMembers($this->get($base.'?member_role_id=5')));
        self::assertSame([$locked], $this->listedMembers($this->get($base.'?member_status=3')));
        self::assertEqualsCanonicalizing([1, 2, 3, 6], $this->listedMembers($this->get($base.'?member_status=1')));
        self::assertSame([3], $this->listedMembers($this->get($base.'?member_status=1&member_role_id=5')));
        $html = $this->get($base.'?member_name=zzz');
        self::assertSame([], $this->listedMembers($html));
        self::assertStringContainsString('class="nodata"', $html);
        // the form keeps the filters
        $html = $this->get($base.'?member_name=lopp&member_role_id=4');
        self::assertStringContainsString('name="member_name" id="member_name" value="lopp"', $html);
        self::assertMatchesRegularExpression('/<option selected="selected" value="4">Developer/', $html);

        // users who cannot manage the members only see the active ones, whatever the status asked for
        $this->login('rhill');
        self::assertEqualsCanonicalizing([1, 2, 3, 6], $this->listedMembers($this->get('/projects/ecommerce/members')));
        self::assertEqualsCanonicalizing([1, 2, 3, 6], $this->listedMembers($this->get('/projects/ecommerce/members?member_status=3')));
        self::assertSame([2], $this->listedMembers($this->get('/projects/ecommerce/members?member_name=lopp')));
    }

    public function testMembersPaginationAndListParamsAreKept(): void
    {
        $this->em->getConnection()->executeStatement("UPDATE settings SET value = '2,25' WHERE name = 'per_page_options'");
        if (0 === (int) $this->sql("SELECT COUNT(*) FROM settings WHERE name = 'per_page_options'")) {
            $this->em->getConnection()->executeStatement("INSERT INTO settings (name, value, updated_on) VALUES ('per_page_options', '2,25', datetime('now'))");
        }
        $this->login('jsmith');
        $base = '/projects/ecommerce/settings/members';
        $page1 = $this->get($base);
        self::assertCount(2, $this->listedMembers($page1));
        self::assertStringContainsString('(1-2/4)', $page1);
        self::assertStringContainsString('members_page=2', $page1);
        $page2 = $this->get($base.'?members_page=2');
        self::assertCount(2, $this->listedMembers($page2));
        self::assertSame([], array_intersect($this->listedMembers($page1), $this->listedMembers($page2)));
        self::assertCount(4, $this->listedMembers($this->get($base.'?per_page=25')));

        // members_list_params are carried by the links and survive an update (redirect_to_settings_in_projects)
        $html = $this->get($base.'?members_page=2&member_role_id=4');
        self::assertStringContainsString('/projects/ecommerce/memberships/new?members_page=2&member_role_id=4', str_replace('&amp;', '&', $html));
        $html = $this->get($base.'?members_page=2');
        self::assertMatchesRegularExpression('#/memberships/\d+/edit\?members_page=2#', $html);
        $this->submit('/memberships/3?members_page=2&member_name=hill', ['_method' => 'put', 'membership' => ['role_ids' => ['5', '4']]]);
        self::assertSame(302, $this->code());
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/projects/ecommerce/settings/members', $location);
        self::assertStringContainsString('members_page=2', $location);
        self::assertStringContainsString('member_name=hill', $location);
    }

    public function testMembersCsvExport(): void
    {
        $this->login('jsmith');
        self::assertStringContainsString('/projects/ecommerce/memberships.csv', $this->get('/projects/ecommerce/settings/members'));
        $csv = $this->get('/projects/ecommerce/memberships.csv');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('ecommerce-members.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $lines = array_values(array_filter(array_map('trim', explode("\n", $csv))));
        self::assertSame('User or Group,Type,Role,Project', $lines[0]);
        self::assertContains('John Smith,User,Manager,eCommerce', $lines);
        self::assertContains('Dave Lopper,User,Developer,eCommerce', $lines);
        self::assertContains('Developers,Group,Developer,eCommerce', $lines);
        self::assertCount(5, $lines);

        // head :forbidden unless the user manages the members
        $this->login('rhill');
        $this->get('/projects/ecommerce/memberships.csv');
        self::assertSame(403, $this->code());
    }

    // --- project deletion e-mail (DestroyProjectJob) ------------------------------------------------------

    public function testDeletingAProjectNotifiesTheUser(): void
    {
        $this->login('admin');
        self::assertSame(302, $this->submit('/projects/checkout', ['_method' => 'delete', 'confirm' => 'checkout']));
        self::assertFalse($this->sql("SELECT id FROM projects WHERE identifier = 'checkout'"));
        $mails = $this->mails();
        $mail = end($mails);
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('[Redmine Reference] Security notification', $mail->getSubject());
        self::assertSame('admin@dummy.invalid', $mail->getTo()[0]->getAddress());
        self::assertStringContainsString('Project Checkout was deleted successfully.', (string) $mail->getTextBody());
        self::assertStringContainsString('/admin/projects', (string) $mail->getTextBody());
    }

    public function testDeletingAProjectWithSubprojectsNotifiesTheUser(): void
    {
        $this->login('admin');
        self::assertSame(302, $this->submit('/projects/bulk_destroy', ['_method' => 'delete', 'ids' => ['1'], 'confirm' => 'Yes']));
        self::assertSame(0, (int) $this->sql('SELECT COUNT(*) FROM projects WHERE id IN (1, 3)'));
        $bodies = array_map(static fn (Email $m) => (string) $m->getTextBody(), $this->mails());
        self::assertNotSame([], array_filter($bodies, static fn (string $b) => str_contains($b, 'Project eCommerce and its subprojects were deleted successfully.')), implode("\n---\n", $bodies));
    }

    // --- overview -----------------------------------------------------------------------------------------

    public function testOverviewLinks(): void
    {
        $this->login('jsmith');
        $html = $this->get('/projects/ecommerce');
        // issue summary and details (ReportsController)
        self::assertStringContainsString('href="/projects/ecommerce/issues/report"', $html);
        self::assertStringContainsString('href="/projects/ecommerce/issues/report/tracker"', $html);
        // the Atom auto-discovery link points to the feed
        self::assertMatchesRegularExpression('#<link rel="alternate" type="application/atom\+xml" title="ATOM" href="/projects/ecommerce/activity\.atom\?key=[0-9a-f]{40}"#', $html);
        preg_match('#href="(/projects/ecommerce/activity\.atom\?key=[0-9a-f]{40})"#', $html, $m);
        $this->client->restart();
        $this->get($m[1]);
        self::assertSame(200, $this->code());
        self::assertStringContainsString('application/atom+xml', (string) $this->client->getResponse()->headers->get('Content-Type'));
        foreach (['/projects/ecommerce/issues/report', '/projects/ecommerce/issues/report/tracker'] as $url) {
            $this->get($url);
            self::assertSame(200, $this->code(), $url);
        }
    }

    // --- archived project error page ----------------------------------------------------------------------

    public function testArchivedProjectErrorPageOffersUnarchiveToAdministrators(): void
    {
        $this->login('admin');
        foreach (['/projects/legacy', '/projects/legacy/issues'] as $url) {
            $html = $this->get($url);
            self::assertSame(403, $this->code());
            self::assertStringContainsString('has been archived', $html);
            self::assertMatchesRegularExpression('#<a class="icon icon-unlock"[^>]*data-method="post"[^>]*href="/projects/legacy/unarchive"#', $html, $url);
        }
        // other 403/404 pages do not show it
        self::assertStringNotContainsString('/unarchive', $this->get('/projects/does-not-exist'));

        self::assertSame(302, $this->submit('/projects/legacy/unarchive'));
        self::assertSame(1, (int) $this->sql("SELECT status FROM projects WHERE identifier = 'legacy'"));

        $this->em->getConnection()->executeStatement("UPDATE projects SET status = 9 WHERE identifier = 'legacy'");
        $this->login('jsmith');
        $html = $this->get('/projects/legacy');
        self::assertSame(403, $this->code());
        self::assertStringNotContainsString('/unarchive', $html);
    }

    // --- activity of a user (User.visible.active.find) ----------------------------------------------------

    public function testActivityOfAUserWhoIsNotVisibleIsNotFound(): void
    {
        // anna has the non member role (users visibility: members of visible projects); admin is a member of nothing
        $this->login('anna');
        $this->get('/users/1');
        self::assertSame(404, $this->code());
        $this->get('/activity?user_id=1');
        self::assertSame(404, $this->code());
        $this->get('/activity.atom?user_id=1');
        self::assertSame(404, $this->code());
        // visible users and oneself
        foreach ([5, 6, 9] as $id) {
            $this->get('/activity?user_id='.$id);
            self::assertSame(200, $this->code(), "user $id");
        }
        // locked and unknown users
        $this->get('/activity?user_id=8');
        self::assertSame(404, $this->code());
        $this->get('/activity?user_id=999');
        self::assertSame(404, $this->code());
        // a manager sees every active user (users visibility: all)
        $this->login('jsmith');
        $this->get('/activity?user_id=1');
        self::assertSame(200, $this->code());
        $this->login('admin');
        $this->get('/activity?user_id=9');
        self::assertSame(200, $this->code());
    }

    // --- built-in groups as project members (User#roles_for_project, Project#override_roles) -------------

    private const int GROUP_ANONYMOUS = 2;
    private const int GROUP_NON_MEMBER = 3;

    /** Outcome of a GET as the given user (null = anonymous): 200, 403, 404 or "login" */
    private function outcomeAs(?string $login, string $url): string
    {
        $this->client->restart();
        $this->em->clear();
        if (null !== $login) {
            $this->login($login);
        }
        $this->client->request('GET', $url);
        $response = $this->client->getResponse();
        if ($response->isRedirect()) {
            return str_contains((string) $response->headers->get('Location'), '/login') ? 'login' : 'redirect';
        }

        return (string) $response->getStatusCode();
    }

    private function addRole(string $name, array $permissions): int
    {
        $c = $this->em->getConnection();
        $c->executeStatement("INSERT INTO roles (name, position, assignable, builtin, permissions, issues_visibility, users_visibility, time_entries_visibility, all_roles_managed, settings) SELECT ?, 99, 1, 0, ?, 'default', 'members_of_visible_projects', 'all', 1, NULL", [$name, json_encode($permissions)]);

        return (int) $c->lastInsertId();
    }

    private function addGroupMember(string $project, int $groupId, array $roleIds): void
    {
        $this->client->restart();
        $this->login('admin');
        self::assertSame(302, $this->submit('/projects/'.$project.'/memberships', ['membership' => ['user_ids' => [(string) $groupId], 'role_ids' => array_map('strval', $roleIds)]]));
        self::assertSame(1, (int) $this->sql('SELECT COUNT(*) FROM members m JOIN projects p ON p.id = m.project_id WHERE p.identifier = ? AND m.user_id = ?', [$project, $groupId]), "group $groupId is a member of $project");
    }

    public function testBuiltinGroupsCanBeAddedListedAndRemoved(): void
    {
        $this->login('admin');
        $html = $this->get('/projects/ecommerce/memberships/new');
        self::assertMatchesRegularExpression('#name="membership\[user_ids\]\[\]" value="3" />.*?Non member users</label>#s', $html);
        self::assertMatchesRegularExpression('#name="membership\[user_ids\]\[\]" value="2" />.*?Anonymous users</label>#s', $html);

        $this->addGroupMember('ecommerce', self::GROUP_NON_MEMBER, [3]);
        $this->addGroupMember('ecommerce', self::GROUP_ANONYMOUS, [5]);

        // listed with the members, in the settings and on the members page
        foreach (['/projects/ecommerce/settings/members', '/projects/ecommerce/members'] as $url) {
            $html = $this->get($url);
            self::assertMatchesRegularExpression('#<a class="group" href="/groups/3">.*?Non member users</a>#s', $html, $url);
            self::assertMatchesRegularExpression('#<a class="group" href="/groups/2">.*?Anonymous users</a>#s', $html, $url);
        }
        // ... on the overview (Project#principals_by_role) ...
        $html = $this->get('/projects/ecommerce');
        self::assertMatchesRegularExpression('#<span class="label">Manager:</span>.*?Non member users#s', $html);
        self::assertMatchesRegularExpression('#<span class="label">Reporter:</span>.*?Anonymous users#s', $html);
        // ... and in the CSV export; they are not offered a second time
        self::assertStringContainsString('Non member users,Group,Manager,eCommerce', $this->get('/projects/ecommerce/memberships.csv'));
        $html = $this->get('/projects/ecommerce/memberships/new');
        self::assertStringNotContainsString('Non member users', $html);
        self::assertStringNotContainsString('Anonymous users', $html);
        // the name follows the language of the user
        $this->em->getConnection()->executeStatement("UPDATE users SET language = 'fr' WHERE login = 'admin'");
        $this->client->restart();
        $this->em->clear();
        $this->login('admin');
        self::assertStringContainsString('Utilisateurs non membres', $this->get('/projects/ecommerce/settings/members'));
        $this->em->getConnection()->executeStatement("UPDATE users SET language = 'en' WHERE login = 'admin'");

        // they are not users of the project: not assignable, not notified
        $this->client->restart();
        $this->em->clear();
        $this->login('jsmith');
        $html = $this->get('/projects/ecommerce/issues/new');
        preg_match('#<select[^>]*name="issue\[assigned_to_id\]".*?</select>#s', $html, $m);
        self::assertNotEmpty($m);
        self::assertStringNotContainsString('Non member users', $m[0]);
        self::assertStringNotContainsString('Anonymous users', $m[0]);

        // roles can be changed and the membership removed
        $member = (int) $this->sql('SELECT id FROM members WHERE project_id = 1 AND user_id = ?', [self::GROUP_NON_MEMBER]);
        self::assertSame(302, $this->submit('/memberships/'.$member, ['_method' => 'put', 'membership' => ['role_ids' => ['4']]]));
        self::assertSame('4', (string) $this->sql('SELECT GROUP_CONCAT(role_id) FROM member_roles WHERE member_id = ?', [$member]));
        self::assertSame(302, $this->submit('/memberships/'.$member, ['_method' => 'delete']));
        self::assertFalse($this->sql('SELECT id FROM members WHERE id = ?', [$member]));
    }

    public function testBuiltinGroupsInTheMembershipsApi(): void
    {
        $key = $this->adminKey();
        // create
        $created = $this->api('POST', '/projects/ecommerce/memberships.json', ['membership' => ['user_id' => self::GROUP_NON_MEMBER, 'role_ids' => [5]]], $key);
        self::assertSame(201, $this->code());
        self::assertSame(['id' => self::GROUP_NON_MEMBER, 'name' => 'Non member users'], $created['membership']['group_non_member']);
        self::assertArrayNotHasKey('group', $created['membership']);
        $this->api('POST', '/projects/ecommerce/memberships.json', ['membership' => ['user_id' => self::GROUP_ANONYMOUS, 'role_ids' => [5]]], $key);
        self::assertSame(201, $this->code());
        // index and show
        $list = $this->api('GET', '/projects/ecommerce/memberships.json', null, $key)['memberships'];
        $kinds = [];
        foreach ($list as $m) {
            foreach (['user', 'group', 'group_non_member', 'group_anonymous'] as $kind) {
                if (isset($m[$kind])) {
                    $kinds[$kind][] = $m[$kind]['name'];
                }
            }
        }
        self::assertSame(['Non member users'], $kinds['group_non_member']);
        self::assertSame(['Anonymous users'], $kinds['group_anonymous']);
        self::assertSame(['Developers'], $kinds['group']);
        self::assertCount(3, $kinds['user']);
        $shown = $this->api('GET', '/memberships/'.$created['membership']['id'].'.json', null, $key);
        self::assertSame('Non member users', $shown['membership']['group_non_member']['name']);
        self::assertSame([['id' => 5, 'name' => 'Reporter']], $shown['membership']['roles']);
        // update and delete
        $this->api('PUT', '/memberships/'.$created['membership']['id'].'.json', ['membership' => ['role_ids' => [4]]], $key);
        self::assertSame(204, $this->code());
        $this->api('DELETE', '/memberships/'.$created['membership']['id'].'.json', null, $key);
        self::assertSame(204, $this->code());
    }

    public function testRolesOfTheBuiltinGroupsReplaceTheBuiltinRolesOnAPublicProject(): void
    {
        // before: the Non member and Anonymous roles apply
        self::assertSame('403', $this->outcomeAs('anna', '/projects/ecommerce/settings'));
        self::assertSame('login', $this->outcomeAs(null, '/projects/ecommerce/issues/new'));
        self::assertSame('403', $this->outcomeAs('anna', '/projects/new'));

        $this->addGroupMember('ecommerce', self::GROUP_NON_MEMBER, [3]); // Manager for the non members
        $this->addGroupMember('ecommerce', self::GROUP_ANONYMOUS, [5]);  // Reporter for the anonymous users

        // non member: Manager of the project
        self::assertSame('200', $this->outcomeAs('anna', '/projects/ecommerce/settings'));
        self::assertSame('200', $this->outcomeAs('anna', '/projects/ecommerce/versions/new'));
        self::assertSame('200', $this->outcomeAs('anna', '/projects/ecommerce/issues/new'));
        // ... on that project only
        self::assertSame('403', $this->outcomeAs('anna', '/projects/checkout/settings'));
        // ... and globally, as she has no other role (User#roles): the Manager role allows to add projects
        self::assertSame('200', $this->outcomeAs('anna', '/projects/new'));

        // anonymous: Reporter of the project (the Anonymous role cannot add issues)
        self::assertSame('200', $this->outcomeAs(null, '/projects/ecommerce/issues/new'));
        self::assertSame('login', $this->outcomeAs(null, '/projects/ecommerce/settings'));
        self::assertSame('login', $this->outcomeAs(null, '/projects/checkout/issues/new'));

        // members keep the roles of their membership: rhill is Reporter, dlopper Developer
        self::assertSame('403', $this->outcomeAs('rhill', '/projects/ecommerce/settings'));
        self::assertSame('200', $this->outcomeAs('dlopper', '/projects/ecommerce/settings'), 'manage_versions of the Developer role');
        self::assertStringNotContainsString('id="tab-info"', (string) $this->client->getResponse()->getContent());
        // ... also globally: their own roles count, not the roles of the group
        self::assertSame('403', $this->outcomeAs('rhill', '/projects/new'));
        // administrators are not concerned
        self::assertSame('200', $this->outcomeAs('admin', '/projects/ecommerce/settings'));
    }

    public function testRolesOfTheBuiltinGroupsCanAlsoRestrictAPublicProject(): void
    {
        // checkout (public) has issue #17; the Non member and Anonymous roles can view issues
        $this->em->getConnection()->executeStatement('UPDATE issues SET is_private = 0 WHERE id = 17');
        foreach ([null, 'anna'] as $login) {
            self::assertSame('200', $this->outcomeAs($login, '/issues/17'));
            self::assertSame('200', $this->outcomeAs($login, '/projects/checkout/issues'));
        }
        $restricted = $this->addRole('Restricted', []);
        $this->addGroupMember('checkout', self::GROUP_NON_MEMBER, [$restricted]);

        // non member: the role of the group replaces the Non member role (no view_issues any more)
        self::assertSame('403', $this->outcomeAs('anna', '/issues/17'));
        self::assertSame('403', $this->outcomeAs('anna', '/projects/checkout/issues'));
        self::assertSame('200', $this->outcomeAs('anna', '/projects/checkout'), 'the project itself stays visible');
        // ... in the cross-project queries too (Project.allowed_to_condition)
        $this->outcomeAs('anna', '/issues?set_filter=1&status_id=*');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Payment fails on Safari', $html);
        self::assertStringContainsString('Cart total is wrong with discounts', $html, 'issues of the other public project');
        $this->outcomeAs('anna', '/search?q=Safari&issues=1');
        self::assertStringNotContainsString('Payment fails on Safari', (string) $this->client->getResponse()->getContent());
        $this->outcomeAs('anna', '/projects/checkout/activity');
        self::assertStringNotContainsString('Payment fails on Safari', (string) $this->client->getResponse()->getContent());
        // API
        $this->em->getConnection()->executeStatement("INSERT INTO tokens (user_id, action, value, created_on, updated_on) SELECT id, 'api', ?, datetime('now'), datetime('now') FROM users WHERE login = 'anna'", [str_repeat('e', 40)]);
        $this->client->restart();
        $list = $this->api('GET', '/issues.json?status_id=*', null, str_repeat('e', 40));
        self::assertNotContains(17, array_column($list['issues'], 'id'));
        self::assertContains(13, array_column($list['issues'], 'id'));
        $this->api('GET', '/issues/17.json', null, str_repeat('e', 40));
        self::assertSame(403, $this->code());

        // anonymous users are not concerned by the Non member group, members by neither
        self::assertSame('200', $this->outcomeAs(null, '/issues/17'));
        self::assertSame('200', $this->outcomeAs('jsmith', '/issues/17'));
        $this->outcomeAs(null, '/issues?set_filter=1&status_id=*');
        self::assertStringContainsString('Payment fails on Safari', (string) $this->client->getResponse()->getContent());

        // the same for the anonymous group
        $this->addGroupMember('checkout', self::GROUP_ANONYMOUS, [$restricted]);
        self::assertSame('login', $this->outcomeAs(null, '/issues/17'));
        self::assertSame('200', $this->outcomeAs(null, '/projects/checkout'));
        $this->outcomeAs(null, '/issues?set_filter=1&status_id=*');
        self::assertStringNotContainsString('Payment fails on Safari', (string) $this->client->getResponse()->getContent());
        self::assertSame('200', $this->outcomeAs('jsmith', '/issues/17'));
    }

    public function testRolesOfTheBuiltinGroupsGiveNothingOnAPrivateProject(): void
    {
        // internal is private: issue #18
        $this->addGroupMember('internal', self::GROUP_NON_MEMBER, [3]);
        $this->addGroupMember('internal', self::GROUP_ANONYMOUS, [3]);

        self::assertSame('login', $this->outcomeAs(null, '/projects/internal'));
        self::assertSame('login', $this->outcomeAs(null, '/issues/18'));
        self::assertSame('403', $this->outcomeAs('anna', '/projects/internal'));
        self::assertSame('403', $this->outcomeAs('anna', '/issues/18'));
        self::assertSame('403', $this->outcomeAs('anna', '/projects/internal/settings'));
        foreach ([null, 'anna'] as $login) {
            $this->outcomeAs($login, '/projects');
            self::assertStringNotContainsString('/projects/internal', (string) $this->client->getResponse()->getContent());
            $this->outcomeAs($login, '/issues?set_filter=1&status_id=*');
            self::assertStringNotContainsString('Internal tool crash', (string) $this->client->getResponse()->getContent());
        }
        // no global role either from a private project
        self::assertSame('403', $this->outcomeAs('anna', '/projects/new'));
        // the member of the project keeps his access
        self::assertSame('200', $this->outcomeAs('jsmith', '/issues/18'));

        // once the project is public the roles of the groups apply
        $this->em->getConnection()->executeStatement('UPDATE projects SET is_public = 1 WHERE id = 2');
        self::assertSame('200', $this->outcomeAs('anna', '/projects/internal/settings'));
        self::assertSame('200', $this->outcomeAs(null, '/issues/18'));
    }

    // --- project copy: notifications of documents and wiki pages -------------------------------------------

    private function notifyEverything(): void
    {
        $settings = static::getContainer()->get(\App\Service\Settings::class);
        $settings->set('notified_events', ['issue_added', 'issue_updated', 'document_added', 'wiki_content_added', 'wiki_content_updated', 'news_added', 'file_added']);
        $this->em->flush();
        // jsmith and rhill (members of the source, hence of the copy) want every notification
        $this->em->getConnection()->executeStatement("UPDATE users SET mail_notification = 'all' WHERE login IN ('jsmith', 'rhill')");
    }

    private function fullCopyParams(string $identifier, array $extra = []): array
    {
        return [
            'project' => ['name' => 'Copy '.$identifier, 'identifier' => $identifier, 'is_public' => '1', 'enabled_module_names' => ['issue_tracking', 'wiki', 'documents', 'boards', 'time_tracking'], 'tracker_ids' => ['1', '2', '3']],
            'only' => ['members', 'wiki', 'versions', 'issue_categories', 'issues', 'queries', 'boards', 'documents'],
        ] + $extra;
    }

    public function testCopySendsDocumentAndWikiNotificationsWhenAsked(): void
    {
        $this->notifyEverything();
        $this->login('admin');
        self::assertSame(302, $this->submit('/projects/ecommerce/copy', $this->fullCopyParams('noisy', ['notifications' => '1'])));
        $subjects = array_map(static fn (Email $m) => (string) $m->getSubject(), $this->mails());
        self::assertContains('[Copy noisy] New document: Specs', $subjects, implode(' | ', $subjects));
        self::assertNotSame([], array_filter($subjects, static fn (string $s) => str_contains($s, "'Wiki' wiki page has been added")), implode(' | ', $subjects));
        self::assertNotSame([], array_filter($subjects, static fn (string $s) => str_contains($s, "'Checkout flow' wiki page has been added")), implode(' | ', $subjects));
        self::assertNotSame([], array_filter($subjects, static fn (string $s) => str_starts_with($s, '[Copy noisy - Bug #')));
        $documentRecipients = [];
        foreach ($this->mails() as $mail) {
            if (str_contains((string) $mail->getSubject(), 'New document')) {
                array_push($documentRecipients, ...array_map(static fn ($a) => $a->getAddress(), array_merge($mail->getTo(), $mail->getBcc(), $mail->getCc())));
            }
        }
        self::assertContains('jsmith@example.net', $documentRecipients);
        self::assertContains('rhill@example.net', $documentRecipients);
    }

    public function testCopySendsNoDocumentOrWikiNotificationOtherwise(): void
    {
        $this->notifyEverything();
        $this->login('admin');
        self::assertSame(302, $this->submit('/projects/ecommerce/copy', $this->fullCopyParams('silent')));
        self::assertSame(1, (int) $this->sql("SELECT COUNT(*) FROM documents d JOIN projects p ON p.id = d.project_id WHERE p.identifier = 'silent'"));
        self::assertSame([], array_map(static fn (Email $m) => (string) $m->getSubject(), $this->mails()));
    }

    // --- project copy: what is copied (Project#copy, test/unit/project_copy_test.rb) -----------------------

    public function testCopyOfMembersVersionsCategoriesBoardsQueriesAndWiki(): void
    {
        $c = $this->em->getConnection();
        // source: a built-in group member, a locked member, a default version, a version file, sub-boards,
        // a query visible to roles and a spent time query
        $c->executeStatement("INSERT INTO members (user_id, project_id, created_on, mail_notification) VALUES (3, 1, datetime('now'), 0)");
        $c->executeStatement('INSERT INTO member_roles (member_id, role_id) VALUES (?, 5)', [(int) $c->lastInsertId()]);
        $c->executeStatement("INSERT INTO members (user_id, project_id, created_on, mail_notification) VALUES (8, 1, datetime('now'), 1)");
        $c->executeStatement('INSERT INTO member_roles (member_id, role_id) VALUES (?, 5)', [(int) $c->lastInsertId()]);
        $c->executeStatement('UPDATE projects SET default_version_id = 1 WHERE id = 1');
        $c->executeStatement("INSERT INTO attachments (container_id, container_type, filename, disk_filename, filesize, content_type, digest, downloads, author_id, created_on, description, disk_directory) SELECT 1, 'Version', 'release.txt', disk_filename, filesize, content_type, digest, 7, 7, '2020-02-03 04:05:06', 'Release notes', disk_directory FROM attachments WHERE id = 1");
        $c->executeStatement("INSERT INTO attachments (container_id, container_type, filename, disk_filename, filesize, content_type, digest, downloads, author_id, created_on, description, disk_directory) SELECT 2, 'WikiPage', 'flow.txt', disk_filename, filesize, content_type, digest, 3, 6, '2021-02-03 04:05:06', '', disk_directory FROM attachments WHERE id = 1");
        $c->executeStatement("INSERT INTO boards (project_id, name, description, position, topics_count, messages_count, parent_id) VALUES (1, 'Sub forum', 'Child', 1, 4, 9, 1)");
        $c->executeStatement("INSERT INTO queries (project_id, name, filters, user_id, column_names, sort_criteria, group_by, type, visibility, options, description) SELECT 1, 'For roles', filters, 6, column_names, sort_criteria, group_by, 'IssueQuery', 1, options, description FROM queries WHERE id = 8");
        $rolesQuery = (int) $c->lastInsertId();
        $c->executeStatement('INSERT INTO queries_roles (query_id, role_id) VALUES (?, 3), (?, 4)', [$rolesQuery, $rolesQuery]);
        $c->executeStatement("INSERT INTO queries (project_id, name, filters, user_id, column_names, sort_criteria, group_by, type, visibility, options, description) SELECT 1, 'Spent', filters, 5, NULL, NULL, NULL, 'TimeEntryQuery', 2, NULL, NULL FROM queries WHERE id = 7");
        $c->executeStatement('UPDATE projects SET default_issue_query_id = 8 WHERE id = 1');

        $this->login('admin');
        self::assertSame(302, $this->submit('/projects/ecommerce/copy', $this->fullCopyParams('twin')));
        $this->em->clear();
        $twin = (int) $this->sql("SELECT id FROM projects WHERE identifier = 'twin'");
        self::assertGreaterThan(0, $twin);

        // members: every membership (users first, then groups), non inherited roles, mail_notification
        $members = $c->fetchAllKeyValue("SELECT m.user_id, (SELECT GROUP_CONCAT(role_id || CASE WHEN inherited_from IS NULL THEN '' ELSE 'i' END) FROM (SELECT role_id, inherited_from FROM member_roles WHERE member_id = m.id ORDER BY role_id, inherited_from)) FROM members m WHERE m.project_id = ? ORDER BY m.user_id", [$twin]);
        self::assertSame([3 => '5', 5 => '3', 6 => '4,4i', 7 => '5', 8 => '5', 10 => '4'], array_map('strval', $members));
        self::assertSame(1, (int) $this->sql('SELECT mail_notification FROM members WHERE project_id = ? AND user_id = 8', [$twin]));

        // versions: attributes and files; the default version is not carried over
        $versions = $c->fetchAllAssociative('SELECT name, description, status, sharing, effective_date, wiki_page_title FROM versions WHERE project_id = ? ORDER BY name', [$twin]);
        self::assertSame($c->fetchAllAssociative('SELECT name, description, status, sharing, effective_date, wiki_page_title FROM versions WHERE project_id = 1 ORDER BY name'), $versions);
        self::assertNull($this->sql('SELECT default_version_id FROM projects WHERE id = ?', [$twin]));
        $file = $c->fetchAssociative("SELECT a.filename, a.description, a.author_id, a.created_on, a.downloads FROM attachments a JOIN versions v ON v.id = a.container_id WHERE a.container_type = 'Version' AND v.project_id = ?", [$twin]);
        self::assertSame(['filename' => 'release.txt', 'description' => 'Release notes', 'author_id' => 7, 'downloads' => 0], ['filename' => $file['filename'], 'description' => $file['description'], 'author_id' => (int) $file['author_id'], 'downloads' => (int) $file['downloads']]);
        self::assertStringStartsWith('2020-02-03 04:05:06', (string) $file['created_on'], 'Attachment#copy keeps the date');

        // issue categories
        self::assertSame($c->fetchAllAssociative('SELECT name, assigned_to_id FROM issue_categories WHERE project_id = 1 ORDER BY name'), $c->fetchAllAssociative('SELECT name, assigned_to_id FROM issue_categories WHERE project_id = ? ORDER BY name', [$twin]));

        // boards: name, description, position; the counters restart
        $boards = $c->fetchAllAssociative('SELECT name, description, position, topics_count, messages_count, last_message_id FROM boards WHERE project_id = ? ORDER BY id', [$twin]);
        self::assertSame(['name' => 'General', 'topics_count' => 0, 'messages_count' => 0, 'last_message_id' => null], ['name' => $boards[0]['name'], 'topics_count' => (int) $boards[0]['topics_count'], 'messages_count' => (int) $boards[0]['messages_count'], 'last_message_id' => $boards[0]['last_message_id']]);
        self::assertSame($this->sql('SELECT description FROM boards WHERE id = 1'), $boards[0]['description']);

        // queries: issue and spent time queries, owner kept, roles of the queries visible to roles, default query
        $queries = $c->fetchAllAssociative('SELECT id, name, type, user_id, visibility, filters FROM queries WHERE project_id = ? ORDER BY name', [$twin]);
        self::assertSame(['For roles', 'Open bugs', 'Spent'], array_column($queries, 'name'));
        self::assertSame(['IssueQuery', 'IssueQuery', 'TimeEntryQuery'], array_column($queries, 'type'));
        self::assertSame([6, 5, 5], array_map('intval', array_column($queries, 'user_id')));
        self::assertSame($this->sql('SELECT filters FROM queries WHERE id = 8'), $queries[1]['filters']);
        self::assertSame('3,4', (string) $this->sql('SELECT GROUP_CONCAT(role_id) FROM (SELECT role_id FROM queries_roles WHERE query_id = ? ORDER BY role_id)', [$queries[0]['id']]));
        self::assertSame(0, (int) $this->sql('SELECT COUNT(*) FROM queries_roles WHERE query_id = ?', [$queries[1]['id']]));
        self::assertSame((int) $queries[1]['id'], (int) $this->sql('SELECT default_issue_query_id FROM projects WHERE id = ?', [$twin]));

        // wiki: start page, pages with their protection and hierarchy, a content that starts at version 1 with
        // one history entry, attachments
        $wiki = (int) $this->sql('SELECT id FROM wikis WHERE project_id = ?', [$twin]);
        self::assertSame($this->sql('SELECT start_page FROM wikis WHERE project_id = 1'), $this->sql('SELECT start_page FROM wikis WHERE id = ?', [$wiki]));
        $pages = $c->fetchAllAssociativeIndexed('SELECT p.title, p.id, p.parent_id, p.protected, c.id AS content_id, c.version, c.text, c.author_id, c.comments FROM wiki_pages p JOIN wiki_contents c ON c.page_id = p.id WHERE p.wiki_id = ?', [$wiki]);
        self::assertEqualsCanonicalizing(['Wiki', 'Checkout_flow'], array_keys($pages));
        self::assertSame((int) $pages['Wiki']['id'], (int) $pages['Checkout_flow']['parent_id']);
        self::assertNull($pages['Wiki']['parent_id']);
        foreach (['Wiki' => 1, 'Checkout_flow' => 2] as $title => $sourceContent) {
            $source = $c->fetchAssociative('SELECT text, author_id, comments FROM wiki_contents WHERE id = ?', [$sourceContent]);
            self::assertSame($source['text'], $pages[$title]['text']);
            self::assertSame((int) $source['author_id'], (int) $pages[$title]['author_id']);
            self::assertSame($source['comments'], $pages[$title]['comments']);
            self::assertSame(1, (int) $pages[$title]['version'], 'WikiContent#initialize: a new content is version 1');
            self::assertSame('1', (string) $this->sql('SELECT GROUP_CONCAT(version) FROM wiki_content_versions WHERE wiki_content_id = ?', [$pages[$title]['content_id']]), 'WikiContent#create_version');
        }
        self::assertSame('flow.txt', $this->sql("SELECT filename FROM attachments WHERE container_type = 'WikiPage' AND container_id = ?", [$pages['Checkout_flow']['id']]));
        // the history of the copied page can be displayed
        $this->get('/projects/twin/wiki/Checkout_flow/history');
        self::assertSame(200, $this->code());
        $this->get('/projects/twin/wiki/Checkout_flow');
        self::assertSame(200, $this->code());

        // documents: category, title, description and date
        $columns = 'category_id, title, description, SUBSTR(created_on, 1, 19) AS created_on';
        self::assertSame($c->fetchAssociative("SELECT $columns FROM documents WHERE project_id = 1"), $c->fetchAssociative("SELECT $columns FROM documents WHERE project_id = ?", [$twin]));
    }

    public function testCopyReassignsVersionCustomFieldsToTheCopiedVersions(): void
    {
        $c = $this->em->getConnection();
        $c->executeStatement("INSERT INTO custom_fields (type, name, field_format, is_required, is_for_all, is_filter, position, searchable, editable, visible, multiple, format_store) VALUES ('IssueCustomField', 'Found in', 'version', 0, 1, 1, 20, 0, 1, 1, 1, NULL)");
        $field = (int) $c->lastInsertId();
        foreach ([1, 2, 3] as $tracker) {
            $c->executeStatement('INSERT INTO custom_fields_trackers (custom_field_id, tracker_id) VALUES (?, ?)', [$field, $tracker]);
        }
        // issue #13 was found in the versions 1.0 (id 1) and 0.9 (id 2) of the project
        foreach ([1, 2] as $version) {
            $c->executeStatement("INSERT INTO custom_values (customized_type, customized_id, custom_field_id, value) VALUES ('Issue', 13, ?, ?)", [$field, (string) $version]);
        }
        $this->login('admin');
        self::assertSame(302, $this->submit('/projects/ecommerce/copy', $this->fullCopyParams('fields')));
        $twin = (int) $this->sql("SELECT id FROM projects WHERE identifier = 'fields'");
        $copy = (int) $this->sql("SELECT id FROM issues WHERE project_id = ? AND subject = 'Cart total is wrong with discounts'", [$twin]);
        self::assertGreaterThan(0, $copy, 'the issue was copied');
        $expected = array_map('strval', $c->fetchFirstColumn('SELECT id FROM versions WHERE project_id = ? ORDER BY id', [$twin]));
        self::assertCount(2, $expected);
        $values = $c->fetchFirstColumn("SELECT value FROM custom_values WHERE customized_type = 'Issue' AND customized_id = ? AND custom_field_id = ? ORDER BY value", [$copy, $field]);
        self::assertEqualsCanonicalizing($expected, array_map('strval', $values));
        // the source issue is untouched
        self::assertEqualsCanonicalizing(['1', '2'], array_map('strval', $c->fetchFirstColumn("SELECT value FROM custom_values WHERE customized_type = 'Issue' AND customized_id = 13 AND custom_field_id = ?", [$field])));
    }
}
