<?php

declare(strict_types=1);

namespace App\Tests\Scm;

use App\Entity\Changeset;
use App\Entity\Project;
use App\Entity\Repository;
use App\Entity\TimeEntry;
use App\Service\Scm\RepositoryService;
use App\Service\Scm\ScmTwigExtension;

/**
 * Parity fixes for changesets, repositories and the sys API (expected results from Redmine's
 * changeset_test.rb, repository_test.rb, repositories_test.rb and sys_controller_test.rb).
 */
final class ChangesetParityFixesTest extends ScmWebTestCase
{
    private function repos(): RepositoryService
    {
        return static::getContainer()->get(RepositoryService::class);
    }

    /** A Git repository record of ecommerce that is never fetched (changesets are created by the tests) */
    private function repository(string $identifier = 'parity'): Repository
    {
        $repo = $this->repos()->build('Git');
        $repo->project = $this->em->getRepository(Project::class)->findOneBy(['identifier' => 'ecommerce']);
        $repo->url = '/nonexistent/parity.git';
        $repo->rootUrl = $repo->url;
        $repo->identifier = $identifier;
        $repo->isDefault = true;
        $repo->createdOn = new \DateTimeImmutable('2020-01-01');
        $this->em->persist($repo);
        $this->em->flush();

        return $repo;
    }

    /** @param list<array<string, mixed>> $paths */
    private function commit(Repository $repo, string $committer, string $comments, array $paths = []): Changeset
    {
        static $n = 0;
        $sha = sha1('parity'.(++$n).$comments);
        $cs = $this->repos()->createChangeset($repo, [
            'revision' => $sha, 'scmid' => $sha, 'committer' => $committer,
            'committed_on' => new \DateTimeImmutable('-1 day'), 'comments' => $comments,
        ], [], $paths);
        self::assertNotNull($cs);

        return $cs;
    }

    /** @return list<array<string, mixed>> */
    private function entriesOfIssue(int $issueId): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT user_id, author_id, project_id, activity_id, hours, comments FROM time_entries WHERE issue_id = ? AND comments LIKE ? ORDER BY id', [$issueId, 'Applied in changeset%']);
    }

    /** changeset_test.rb test_ref_keywords_any_with_timelog + TimeEntry validations (Changeset#log_time only saves valid entries) */
    public function testTimeLoggedFromCommitsIsValidated(): void
    {
        $this->settings()->setMany(['commit_ref_keywords' => '*', 'commit_logtime_enabled' => '1', 'commit_logtime_activity_id' => '9']);
        $repo = $this->repository();
        $anonymousId = (int) $this->em->getConnection()->fetchOne("SELECT id FROM users WHERE type = 'AnonymousUser'");

        // a member allowed to log time: the entry is created with the configured activity
        $cs = $this->commit($repo, 'dlopper <dlopper@example.net>', 'Worked on this issue #14 @3h15');
        $entries = $this->entriesOfIssue(14);
        self::assertCount(1, $entries);
        self::assertSame(6, (int) $entries[0]['user_id']);
        self::assertSame(1, (int) $entries[0]['project_id']);
        self::assertSame(9, (int) $entries[0]['activity_id']);
        self::assertEqualsWithDelta(3.25, (float) $entries[0]['hours'], 0.001);
        self::assertStringContainsString('commit:', (string) $entries[0]['comments']);
        self::assertSame($anonymousId, (int) $entries[0]['author_id'], 'set_author_if_nil: User.current');
        self::assertSame([14], array_map(static fn ($i) => $i->id, $cs->issues->toArray()));

        // anna is not a member of ecommerce: user_id is invalid, nothing is logged (the issue is still referenced)
        $cs = $this->commit($repo, 'anna <anna@example.net>', 'refs #14 @3h');
        self::assertSame(9, $cs->user?->id);
        self::assertCount(1, $this->entriesOfIssue(14));
        self::assertSame([14], array_map(static fn ($i) => $i->id, $cs->issues->toArray()));

        // maximum hours per day
        $this->settings()->setMany(['timelog_max_hours_per_day' => '8']);
        $this->commit($repo, 'dlopper <dlopper@example.net>', 'refs #14 @30h');
        self::assertCount(1, $this->entriesOfIssue(14));
        $this->settings()->setMany(['timelog_max_hours_per_day' => '999']);

        // closed issue (#19) when time cannot be logged on closed issues
        $this->settings()->setMany(['timelog_accept_closed_issues' => '0']);
        $this->commit($repo, 'dlopper <dlopper@example.net>', 'refs #19 @1h');
        self::assertCount(0, $this->entriesOfIssue(19));
        $this->settings()->setMany(['timelog_accept_closed_issues' => '1']);
        $this->commit($repo, 'dlopper <dlopper@example.net>', 'refs #19 @1h');
        self::assertCount(1, $this->entriesOfIssue(19));

        // "Default" activity: nothing is logged when there are several activities and none is the default one
        $this->settings()->setMany(['commit_logtime_activity_id' => '0']);
        $this->commit($repo, 'dlopper <dlopper@example.net>', 'refs #15 @2h');
        self::assertCount(0, $this->entriesOfIssue(15));
        $this->em->getConnection()->executeStatement('UPDATE enumerations SET is_default = 1 WHERE id = 8');
        $this->em->clear();
        $repo = $this->em->find(Repository::class, $repo->id);
        $this->commit($repo, 'dlopper <dlopper@example.net>', 'refs #15 @2h');
        $entries = $this->entriesOfIssue(15);
        self::assertCount(1, $entries);
        self::assertSame(8, (int) $entries[0]['activity_id']);

        // a time_entry.created webhook/notification event is only fired for saved entries: every row is a valid entry
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM time_entries WHERE hours > 8'));
        self::assertNotNull($this->em->getRepository(TimeEntry::class)->findOneBy(['comments' => $entries[0]['comments']]));
    }

    /** Repository#find_committer_user -> User.find_by_login falls back to a case-insensitive match */
    public function testCommitterLoginIsMatchedCaseInsensitively(): void
    {
        $this->settings()->setMany(['commit_ref_keywords' => '*', 'commit_logtime_enabled' => '1', 'commit_logtime_activity_id' => '9']);
        $repo = $this->repository();
        $cs = $this->commit($repo, 'JSMITH <caps@example.org>', 'refs #15 @45m');
        self::assertSame('jsmith', $cs->user?->login);
        $entries = $this->entriesOfIssue(15);
        self::assertCount(1, $entries);
        self::assertSame(5, (int) $entries[0]['user_id']);
        self::assertEqualsWithDelta(0.75, (float) $entries[0]['hours'], 0.001);
        // unknown committers stay unmapped
        self::assertNull($this->commit($repo, 'nobody <nobody@example.org>', 'refs #15')->user);
    }

    /** repository_test.rb test_2_repositories_with_blank_identifier(_and_one_as_default)_should_not_be_valid */
    public function testSecondRepositoryWithBlankIdentifierIsRejected(): void
    {
        $first = $this->repository('');
        self::assertSame('', (string) $first->identifier);
        $this->login('admin');
        foreach (['1', '0'] as $default) {
            $code = $this->submit('/projects/ecommerce/repositories', ['repository_scm' => 'Git', 'repository' => ['identifier' => '', 'url' => '/nonexistent/other.git', 'is_default' => $default]]);
            self::assertSame(422, $code);
            self::assertStringContainsString('Identifier has already been taken', $this->html());
        }
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM repositories WHERE project_id = 1'));
        // the existing repository can still be saved, and a named one can be added
        self::assertSame([], $this->repos()->validate($first, false));
        self::assertSame(302, $this->submit('/projects/ecommerce/repositories', ['repository_scm' => 'Git', 'repository' => ['identifier' => 'second', 'url' => '/nonexistent/other.git']]));
    }

    /** api_test/repositories_test.rb "POST .../issues.xml with invalid issue_id": errors rendered in the requested format */
    public function testRelatedIssueApiErrorsFollowTheRequestedFormat(): void
    {
        $repo = $this->repository();
        $cs = $this->commit($repo, 'jsmith <jsmith@example.net>', 'no reference');
        $base = '/projects/ecommerce/repository/parity/revisions/'.$cs->revision.'/issues';
        $this->client->request('POST', $base.'.xml', [], [], ['HTTP_X_REDMINE_API_KEY' => self::JSMITH_KEY, 'CONTENT_TYPE' => 'application/xml'], '<issue_id>9999</issue_id>');
        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('application/xml', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('<errors type="array"><error>Issue is invalid</error></errors>', $this->html());
        $this->client->request('POST', $base.'.json', [], [], ['HTTP_X_REDMINE_API_KEY' => self::JSMITH_KEY, 'CONTENT_TYPE' => 'application/json'], '{"issue_id": 9999}');
        self::assertSame(422, $this->httpStatus());
        self::assertSame(['errors' => ['Issue is invalid']], json_decode($this->html(), true));
        $this->client->request('POST', $base.'.json', [], [], ['HTTP_X_REDMINE_API_KEY' => self::JSMITH_KEY, 'CONTENT_TYPE' => 'application/json'], '{"issue_id": 15}');
        self::assertSame(204, $this->httpStatus());
    }

    /** sys_controller.rb fetch_changesets: "if params[:id]" - an empty id is not found, a missing id means all projects */
    public function testSysFetchChangesetsWithEmptyIdIsNotFound(): void
    {
        $this->settings()->setMany(['sys_api_enabled' => '1', 'sys_api_key' => 'k', 'autofetch_changesets' => '0']);
        $this->client->request('GET', '/sys/fetch_changesets?key=k&id=');
        self::assertSame(404, $this->httpStatus());
        $this->client->request('GET', '/sys/fetch_changesets?key=k&id=nope');
        self::assertSame(404, $this->httpStatus());
        $this->client->request('GET', '/sys/fetch_changesets?key=k');
        self::assertSame(200, $this->httpStatus());
    }

    private function tab(): string
    {
        $this->client->request('GET', '/issues/16/tab/changesets', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        return $this->html();
    }

    /** issues/tabs/_changesets.html.erb: "(diff)" link and format_changeset_comments; commit_logs_formatting off keeps #123 links */
    public function testAssociatedRevisionsTabAndUnformattedCommitMessages(): void
    {
        $this->settings()->setMany(['commit_ref_keywords' => '*', 'commit_logs_formatting' => '1']);
        $repo = $this->repository();
        $with = $this->commit($repo, 'jsmith <jsmith@example.net>', "refs #16 with **bold** text\n\n* item one", [['action' => 'M', 'path' => 'README']]);
        $without = $this->commit($repo, 'jsmith <jsmith@example.net>', 'refs #16 without changes');
        $this->login('jsmith');
        $html = $this->tab();
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('href="/projects/ecommerce/repository/parity/revisions/'.$with->revision.'/diff"', $html);
        self::assertStringNotContainsString('/revisions/'.$without->revision.'/diff', $html, 'no diff link without file changes');
        self::assertStringContainsString('<strong>bold</strong>', $html);

        // text formatting of commit messages disabled: escaped text, but issue links are kept (on every page)
        $this->settings()->setMany(['commit_logs_formatting' => '0']);
        $html = $this->tab();
        self::assertStringNotContainsString('<strong>bold</strong>', $html);
        self::assertStringContainsString('**bold**', $html);
        self::assertMatchesRegularExpression('#refs <a href="/issues/16"[^>]*>\#16</a> with#', $html);
        $ext = static::getContainer()->get(ScmTwigExtension::class);
        $this->em->clear();
        $formatted = $ext->formatChangesetComments($this->em->find(Changeset::class, $with->id));
        self::assertMatchesRegularExpression('#refs <a href="/issues/16"[^>]*>\#16</a> with \*\*bold\*\* text#', $formatted);

        // the diff link needs browse_repository
        $this->em->getConnection()->executeStatement("UPDATE roles SET permissions = REPLACE(permissions, '\"browse_repository\",', '') WHERE id = 3");
        $this->client->restart();
        static::getContainer()->get(\App\Security\Authorization::class)->reset();
        $this->login('jsmith');
        $html = $this->tab();
        self::assertStringNotContainsString('/diff"', $html);
    }

    /** The Repository tab is shown to roles allowed to repositories#show: browse_repository or view_changesets */
    public function testRepositoryMenuAndBrowsePermissions(): void
    {
        ScmFixtures::requireBinary('git');
        [$url] = ScmFixtures::small('git');
        $repo = $this->createRepository('Git', ['identifier' => 'small', 'url' => $url]);
        // Reporter (rhill): view_changesets without browse_repository
        $this->em->getConnection()->executeStatement("UPDATE roles SET permissions = REPLACE(permissions, '\"browse_repository\",', '') WHERE id = 5");
        $this->client->restart();
        $this->login('rhill');
        $html = $this->get('/projects/ecommerce');
        self::assertMatchesRegularExpression('#<a class="repository[^"]*" href="/projects/ecommerce/repository"#', $html);
        $this->assertPagesOk(['/projects/ecommerce/repository', '/projects/ecommerce/repository/small/revisions']);
        // repositories#browse is only allowed by browse_repository
        $this->get('/projects/ecommerce/repository/small/browse/src');
        self::assertSame(403, $this->httpStatus());
        $this->get('/projects/ecommerce/repository/small/entry/README');
        self::assertSame(403, $this->httpStatus());
        self::assertNotNull($repo->id);
    }

    /** repositories/show + revisions: Atom links carry the user's Atom key; the revision page has the issue autocomplete */
    public function testAtomLinksCarryTheKeyAndRelatedIssueAutocomplete(): void
    {
        ScmFixtures::requireBinary('git');
        [$url] = ScmFixtures::small('git');
        $this->createRepository('Git', ['identifier' => 'small', 'url' => $url]);
        $this->login('jsmith');
        $html = $this->get('/projects/ecommerce/repository/small');
        self::assertSame(200, $this->httpStatus());
        $key = (string) $this->em->getConnection()->fetchOne("SELECT value FROM tokens WHERE user_id = 5 AND action = 'feeds'");
        self::assertNotSame('', $key);
        self::assertSame(2, substr_count($html, '/projects/ecommerce/repository/small/revisions.atom?key='.$key));
        $html = $this->get('/projects/ecommerce/repository/small/revisions');
        self::assertSame(2, substr_count($html, '/projects/ecommerce/repository/small/revisions.atom?key='.$key));
        // the key authenticates the feed without a session
        $this->client->restart();
        $this->client->request('GET', '/projects/ecommerce/repository/small/revisions.atom?key='.$key);
        self::assertSame(200, $this->httpStatus());

        $this->login('jsmith');
        $rev = (string) $this->em->getConnection()->fetchOne('SELECT revision FROM changesets ORDER BY id LIMIT 1');
        $html = $this->get('/projects/ecommerce/repository/small/revisions/'.$rev);
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString("observeAutocompleteField('issue_id', '\\/issues\\/auto_complete\\u003Fproject_id\\u003Decommerce\\u0026scope\\u003Dall')", $html);
    }

    // ------------------------------------------------------------------------------------------
    // Second round
    // ------------------------------------------------------------------------------------------

    /** sys_controller.rb create_project_repository: repository.safe_attributes = params[:repository] (Git: report_last_commit) */
    public function testSysCreateRepositoryHonoursReportLastCommit(): void
    {
        ScmFixtures::requireBinary('git');
        [$url] = ScmFixtures::small('git');
        $this->settings()->setMany(['sys_api_enabled' => '1', 'sys_api_key' => 'k', 'autofetch_changesets' => '0']);
        $this->client->request('POST', '/sys/projects/ecommerce/repository?key=k', ['vendor' => 'Git', 'repository' => ['url' => $url, 'report_last_commit' => '0']]);
        self::assertSame(201, $this->httpStatus(), $this->html());
        $id = json_decode($this->html(), true)['repository-git']['id'];
        $this->em->clear();
        $repo = $this->em->find(Repository::class, $id);
        self::assertSame('0', (string) $repo->extraInfo['extra_report_last_commit']);
        self::assertFalse($this->repos()->reportLastCommit($repo));

        // create_project_repository does not look at the modules of the project
        $this->client->request('POST', '/sys/projects/internal/repository?key=k', ['vendor' => 'Git', 'repository' => ['url' => $url, 'report_last_commit' => '1']]);
        self::assertSame(201, $this->httpStatus(), $this->html());
        $this->em->clear();
        $repo = $this->em->find(Repository::class, json_decode($this->html(), true)['repository-git']['id']);
        self::assertTrue($this->repos()->reportLastCommit($repo));
    }

    /** repositories#revisions as Atom: the page of the list (per_page, page), cut to Setting.feeds_limit by render_feed */
    public function testRevisionsAtomFeedIsThePageOfTheList(): void
    {
        ScmFixtures::requireBinary('git');
        [$url] = ScmFixtures::small('git');
        $this->createRepository('Git', ['identifier' => 'small', 'url' => $url]);
        $this->login('jsmith');
        $this->assertPagesOk(['/projects/ecommerce/repository/small']);
        $total = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM changesets');
        self::assertSame(3, $total);
        $entries = fn (string $query) => substr_count($this->get('/projects/ecommerce/repository/small/revisions.atom'.$query), '<entry>');
        self::assertSame(3, $entries(''));
        $this->settings()->setMany(['per_page_options' => '2,25']);
        self::assertSame(2, $entries('?per_page=2'));
        $feed = $this->get('/projects/ecommerce/repository/small/revisions.atom?per_page=2&page=2');
        self::assertSame(1, substr_count($feed, '<entry>'));
        self::assertStringContainsString('Initial import', $feed, 'the oldest changeset is on the second page');
        $this->settings()->setMany(['per_page_options' => '25,50,100', 'feeds_limit' => '2']);
        self::assertSame(2, $entries(''));
    }

    /** show_error_not_found: "The entry or revision was not found in the repository." for unknown revisions */
    public function testUnknownRevisionsShowTheScmNotFoundMessage(): void
    {
        ScmFixtures::requireBinary('git');
        [$url] = ScmFixtures::small('git');
        $this->createRepository('Git', ['identifier' => 'small', 'url' => $url]);
        $this->login('jsmith');
        $this->assertPagesOk(['/projects/ecommerce/repository/small']);
        $message = 'The entry or revision was not found in the repository.';
        foreach ([
            '/projects/ecommerce/repository/small/show?rev=no-such-rev',
            '/projects/ecommerce/repository/small/revisions/deadbeef',
            '/projects/ecommerce/repository/small/revision?rev=no-such-rev',
            '/projects/ecommerce/repository/small/diff?rev=no-such-rev',
            '/projects/ecommerce/repository/small/entry/README?rev=no-such-rev',
            '/projects/ecommerce/repository/small/entry/no-such-file',
        ] as $path) {
            $html = $this->get($path);
            self::assertSame(404, $this->httpStatus(), $path);
            self::assertStringContainsString($message, $html, $path);
        }
        // find_changeset before add_related_issue / remove_related_issue
        self::assertSame(404, $this->submit('/projects/ecommerce/repository/small/revisions/deadbeef/issues', ['issue_id' => '13']));
        self::assertStringContainsString($message, $this->html());
        self::assertSame(404, $this->submit('/projects/ecommerce/repository/small/revisions/deadbeef/issues/13', ['_method' => 'DELETE']));
        self::assertStringContainsString($message, $this->html());
        // API: an empty 404
        $this->client->request('POST', '/projects/ecommerce/repository/small/revisions/deadbeef/issues.json', [], [], ['HTTP_X_REDMINE_API_KEY' => self::JSMITH_KEY, 'CONTENT_TYPE' => 'application/json'], '{"issue_id": 13}');
        self::assertSame(404, $this->httpStatus());
        self::assertStringNotContainsString('<html', $this->html());
        // an unknown repository or project is a plain 404
        $this->get('/projects/ecommerce/repository/nope');
        self::assertSame(404, $this->httpStatus());
        self::assertStringNotContainsString($message, $this->html());
    }

    /** @return list<string> "recipient: subject" of the captured e-mails, sorted */
    private function sent(): array
    {
        $out = [];
        foreach (self::getMailerMessages() as $m) {
            if ($m instanceof \Symfony\Component\Mime\Email) {
                $out[] = $m->getTo()[0]->getAddress().': '.$m->getSubject();
            }
        }
        sort($out);
        self::getContainer()->get('mailer.message_logger_listener')->reset();

        return $out;
    }

    /**
     * Changeset#fix_issue saves the issue with a journal of the committer: it is notified like any other update
     * (Journal#send_notification); referencing an issue or logging time on it sends nothing.
     */
    public function testIssuesFixedByCommitsAreNotified(): void
    {
        $this->settings()->setMany([
            'commit_ref_keywords' => 'refs', 'commit_logtime_enabled' => '1', 'commit_logtime_activity_id' => '9',
            'commit_update_keywords' => [['keywords' => 'fixes', 'status_id' => '5', 'done_ratio' => '100']],
            'notified_events' => ['issue_updated'],
        ]);
        $repo = $this->repository();
        // #14 is watched by jsmith (its author); dlopper is the committer
        $this->commit($repo, 'dlopper <dlopper@example.net>', 'This fixes #14 @1h and refs #15 @30m');
        $this->em->clear();
        self::assertSame(5, $this->em->find(\App\Entity\Issue::class, 14)->status->id);
        $sent = $this->sent();
        self::assertSame(['jsmith@example.net: [eCommerce - Feature #14] (Closed) Wishlist'], $sent);
        $journal = $this->em->getConnection()->fetchAssociative("SELECT user_id, notes FROM journals WHERE journalized_id = 14 AND journalized_type = 'Issue' ORDER BY id DESC LIMIT 1");
        self::assertSame(6, (int) $journal['user_id']);
        self::assertStringStartsWith('Applied in changeset', (string) $journal['notes']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM journals WHERE journalized_id = 15 AND journalized_type = 'Issue' AND notes LIKE 'Applied%'"), 'a reference does not change the issue');

        // the committer is not notified of his own change when he does not want to; he is otherwise
        $repo = $this->em->find(Repository::class, $repo->id);
        $this->em->getConnection()->executeStatement('UPDATE issues SET assigned_to_id = 6 WHERE id = 16');
        $this->commit($repo, 'dlopper <dlopper@example.net>', 'fixes #16');
        self::assertSame(['jsmith@example.net: [eCommerce - Feature #16] (Closed) Wishlist emails'], $this->sent());

        // an unknown committer: the journal is written by Anonymous and still notified
        $this->em->getConnection()->executeStatement('UPDATE issues SET status_id = 1 WHERE id = 16');
        $this->em->clear();
        $repo = $this->em->find(Repository::class, $repo->id);
        $this->commit($repo, 'somebody <somebody@unknown.example>', 'fixes #16');
        self::assertSame(['dlopper@example.net: [eCommerce - Feature #16] (Closed) Wishlist emails', 'jsmith@example.net: [eCommerce - Feature #16] (Closed) Wishlist emails'], $this->sent());

        // not notified when issue updates are not
        $this->settings()->setMany(['notified_events' => ['issue_added']]);
        $this->em->getConnection()->executeStatement('UPDATE issues SET status_id = 1 WHERE id = 16');
        $this->em->clear();
        $repo = $this->em->find(Repository::class, $repo->id);
        $this->commit($repo, 'dlopper <dlopper@example.net>', 'fixes #16');
        self::assertSame(5, (int) $this->em->getConnection()->fetchOne('SELECT status_id FROM issues WHERE id = 16'));
        self::assertSame([], $this->sent());
    }
}
