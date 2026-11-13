<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Entity\Changeset;
use App\Entity\Issue;
use App\Entity\Repository;
use App\Service\Scm\GitAdapter;
use App\Service\Scm\UnifiedDiff;
use Symfony\Component\Process\Process;

/** Repository browser (Git) */
final class RepositoryTest extends ContentWebTestCase
{
    private static ?string $repoPath = null;

    /** Creates a small git repository: main (2 commits referencing issues) + feature branch + tag */
    private static function gitRepo(): string
    {
        if (null !== self::$repoPath && is_dir(self::$repoPath)) {
            return self::$repoPath;
        }
        $dir = sys_get_temp_dir().'/symine-git-'.getmypid();
        if (is_dir($dir)) {
            (new Process(['rm', '-rf', $dir]))->mustRun();
        }
        mkdir($dir.'/src', 0o777, true);
        $run = static function (array $cmd, array $env = []) use ($dir): void {
            (new Process($cmd, $dir, $env + ['GIT_AUTHOR_NAME' => 'jsmith', 'GIT_AUTHOR_EMAIL' => 'jsmith@somenet.foo', 'GIT_COMMITTER_NAME' => 'jsmith', 'GIT_COMMITTER_EMAIL' => 'jsmith@somenet.foo']))->mustRun();
        };
        $run(['git', 'init', '-q', '-b', 'main']);
        file_put_contents($dir.'/README.md', "hello\nworld\n");
        file_put_contents($dir.'/src/a.php', "<?php echo 1;\n");
        $run(['git', 'add', '.']);
        $run(['git', 'commit', '-qm', 'Initial commit refs #13'], ['GIT_AUTHOR_DATE' => '2026-11-01T10:00:00Z', 'GIT_COMMITTER_DATE' => '2026-11-01T10:00:00Z']);
        file_put_contents($dir.'/README.md', "hello\nthere world\nnew line\n");
        file_put_contents($dir.'/src/b.txt', "doc\n");
        $run(['git', 'add', '.']);
        $run(['git', 'commit', '-qm', "Update readme, fixes #14\n\nLonger description."], ['GIT_AUTHOR_DATE' => '2026-11-02T10:00:00Z', 'GIT_COMMITTER_DATE' => '2026-11-02T10:00:00Z']);
        $run(['git', 'tag', 'v1.0']);
        $run(['git', 'checkout', '-qb', 'feature']);
        file_put_contents($dir.'/feature.txt', "x\n");
        $run(['git', 'add', '.']);
        $run(['git', 'commit', '-qm', 'feature work'], ['GIT_AUTHOR_DATE' => '2026-11-03T10:00:00Z', 'GIT_COMMITTER_DATE' => '2026-11-03T10:00:00Z']);
        $run(['git', 'checkout', '-q', 'main']);

        return self::$repoPath = $dir;
    }

    /** Logs in through the login form (more robust than loginUser with the session-based firewall) */
    private function signIn(string $login): void
    {
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/login');
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/login', ['username' => $login, 'password' => 'admin' === $login ? 'admin' : 'password123', '_csrf_token' => $token]);
        self::assertSame(302, $this->httpStatus(), 'login '.$login);
        self::assertStringNotContainsString('/login', $this->location(), 'login '.$login);
    }

    protected function setUp(): void
    {
        // Redmine 7: an SCM can only be used when scm_git_path_regexp is configured
        $_SERVER['SYMINE_SCM_GIT_PATH_REGEXP'] = '.+';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['SYMINE_SCM_GIT_PATH_REGEXP']);
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (null !== self::$repoPath && is_dir(self::$repoPath)) {
            (new Process(['rm', '-rf', self::$repoPath]))->run();
        }
    }

    private function createRepository(string $identifier = '', bool $default = true): Repository
    {
        $this->signIn('admin');
        $code = $this->submit('/projects/ecommerce/repositories', [
            'repository_scm' => 'Git',
            'repository' => ['identifier' => $identifier, 'url' => self::gitRepo(), 'is_default' => $default ? '1' : '0', 'report_last_commit' => '1'],
        ]);
        self::assertSame(302, $code, (string) $this->client->getResponse()->getContent());
        self::assertStringEndsWith('/projects/ecommerce/settings/repositories', $this->location());
        $this->em->clear();
        $repo = $this->em->getRepository(Repository::class)->findOneBy(['url' => self::gitRepo()], ['id' => 'DESC']);
        self::assertNotNull($repo);

        return $repo;
    }

    public function testAdapter(): void
    {
        $git = new GitAdapter(self::gitRepo());
        self::assertSame(['feature', 'main'], array_map('strval', $git->branches()));
        self::assertSame('main', $git->defaultBranch());
        self::assertSame(['v1.0'], $git->tags());
        $entries = $git->entries('', 'main');
        self::assertSame(['src', 'README.md'], array_map(static fn ($e) => $e->name, $entries));
        self::assertTrue($entries[0]->isDir());
        self::assertSame(27, $entries[1]->size);
        self::assertSame("hello\nthere world\nnew line\n", $git->cat('README.md', 'main'));
        self::assertNull($git->cat('nope.txt', 'main'));
        $revs = $git->revisions('', null, null, ['reverse' => true, 'includes' => ['main']]);
        self::assertCount(2, $revs);
        self::assertSame("Initial commit refs #13\n", $revs[0]->message);
        self::assertSame([['action' => 'A', 'path' => 'README.md'], ['action' => 'A', 'path' => 'src/a.php']], $revs[0]->paths);
        self::assertSame([$revs[0]->scmid], $revs[1]->parents);
        self::assertTrue($git->isValidName('feature'));
        self::assertFalse($git->isValidName('nope'));
        $annotate = $git->annotate('README.md', 'main');
        self::assertSame(['hello', 'there world', 'new line'], $annotate->lines);
        $diff = $git->diff('', $revs[1]->scmid);
        $ud = new UnifiedDiff($diff);
        self::assertCount(2, $ud->tables);
        self::assertSame('README.md', $ud->tables[0]->fileName);
    }

    public function testCrudFetchAndBrowse(): void
    {
        $repo = $this->createRepository();
        self::assertTrue($repo->isDefault);
        self::assertSame('Repository::Git', $repo->type);

        // show fetches changesets (autofetch_changesets default on)
        $this->assertPagesOk(['/projects/ecommerce/repository']);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="browser"', $html);
        self::assertStringContainsString('README.md', $html);
        self::assertStringContainsString('Initial commit', $html);
        $this->em->clear();
        $changesets = $this->em->getRepository(Changeset::class)->findBy(['repository' => $repo->id], ['committedOn' => 'ASC']);
        self::assertCount(3, $changesets, 'all branches fetched');
        self::assertSame('jsmith', $changesets[0]->user?->login, 'committer mapped to user by login');
        $repo = $this->em->find(Repository::class, $repo->id);
        self::assertCount(2, $repo->extraInfo['heads']);
        $changes = $this->em->getConnection()->fetchFirstColumn('SELECT path FROM changes WHERE changeset_id = ? ORDER BY path', [$changesets[1]->id]);
        self::assertSame(['README.md', 'src/b.txt'], $changes, 'git paths are stored without leading slash like Redmine');
        // issue references: "refs #13" linked; "fixes" is not a keyword while no update rule is configured
        self::assertSame([13], array_map(static fn (Issue $i) => $i->id, $changesets[0]->issues->toArray()));
        self::assertSame([], $changesets[1]->issues->toArray());

        $p = '/projects/ecommerce/repository/'.$repo->id;
        $sha = $changesets[1]->scmid;
        $this->assertPagesOk([
            $p, $p.'/show/src', $p.'/revisions', $p.'/revisions/'.$sha, $p.'/revision?rev='.substr($sha, 0, 8),
            $p.'/revisions/main/entry/README.md', $p.'/entry/README.md?rev=feature', $p.'/changes/README.md',
            $p.'/annotate/README.md', $p.'/revisions/'.$sha.'/diff', $p.'/revisions/'.$sha.'/diff?type=sbs',
            $p.'/diff?rev='.$sha.'&rev_to='.$changesets[0]->scmid, $p.'/statistics', $p.'/graph?graph=commits_per_month',
            $p.'/graph?graph=commits_per_author', $p.'/revisions/'.$sha.'/show/src', '/repositories/'.$repo->id.'/edit',
            '/repositories/'.$repo->id.'/committers', '/projects/ecommerce/repositories/new',
        ]);
        $this->get($p.'/revisions/'.$sha.'/diff');
        self::assertStringContainsString('diff_in', (string) $this->client->getResponse()->getContent());
        $this->get($p.'/revisions/'.$sha);
        self::assertStringContainsString('change-M', (string) $this->client->getResponse()->getContent());
        $this->get($p.'/revisions/main/entry/README.md');
        self::assertStringContainsString('there world', (string) $this->client->getResponse()->getContent());

        // raw download
        $this->client->request('GET', $p.'/raw/README.md');
        self::assertSame(200, $this->httpStatus());
        self::assertSame("hello\nthere world\nnew line\n", $this->client->getResponse()->getContent());
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        // unified diff download
        $this->client->request('GET', $p.'/revisions/'.$sha.'/diff?format=diff');
        self::assertStringStartsWith('text/x-patch', (string) $this->client->getResponse()->headers->get('Content-Type'));
        // atom
        $this->client->request('GET', $p.'/revisions.atom');
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('<feed', (string) $this->client->getResponse()->getContent());
        // not found
        $this->client->request('GET', $p.'/entry/missing.txt');
        self::assertSame(404, $this->httpStatus());
        $this->client->request('GET', $p.'/revisions/deadbeef');
        self::assertSame(404, $this->httpStatus());
        $this->client->request('GET', $p.'/show/src?rev=nobranch');
        self::assertSame(404, $this->httpStatus());

        // update + delete
        self::assertSame(302, $this->submit('/repositories/'.$repo->id, ['_method' => 'PUT', 'repository' => ['identifier' => 'main-repo', 'is_default' => '1']]));
        $this->em->clear();
        self::assertSame('main-repo', $this->em->find(Repository::class, $repo->id)->identifier);
        $this->assertPagesOk(['/projects/ecommerce/repository/main-repo/revisions']);
        self::assertSame(302, $this->submit('/repositories/'.$repo->id, ['_method' => 'DELETE']));
        $this->em->clear();
        self::assertNull($this->em->find(Repository::class, $repo->id));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM changesets'));
    }

    public function testValidationAndPermissions(): void
    {
        $this->signIn('admin');
        self::assertSame(422, $this->submit('/projects/ecommerce/repositories', ['repository_scm' => 'Git', 'repository' => ['identifier' => 'Bad Id', 'url' => '']]));
        self::assertSame(404, $this->submit('/projects/ecommerce/repositories', ['repository_scm' => 'Subversion', 'repository' => ['url' => '/tmp']]));
        $repo = $this->createRepository('code');

        // jsmith (manager of ecommerce) browses; anonymous redirected/denied on a private module? ecommerce is public
        $this->signIn('jsmith');
        $this->assertPagesOk(['/projects/ecommerce/repository/code', '/projects/ecommerce/repository/code/revisions']);
        // second repository with reserved identifier is rejected
        $this->signIn('admin');
        self::assertSame(422, $this->submit('/projects/ecommerce/repositories', ['repository_scm' => 'Git', 'repository' => ['identifier' => 'revisions', 'url' => self::gitRepo()]]));
        self::assertSame(422, $this->submit('/projects/ecommerce/repositories', ['repository_scm' => 'Git', 'repository' => ['identifier' => 'code', 'url' => self::gitRepo()]]));
        // dlopper is not allowed to manage repositories
        $this->signIn('dlopper');
        $this->client->request('GET', '/repositories/'.$repo->id.'/edit');
        self::assertSame(403, $this->httpStatus());
    }

    public function testFixKeywordUpdatesIssueStatusAndRelatedIssues(): void
    {
        $conn = $this->em->getConnection();
        $closed = (int) $conn->fetchOne('SELECT id FROM issue_statuses WHERE is_closed = 1 ORDER BY position LIMIT 1');
        $conn->executeStatement("UPDATE settings SET value = ? WHERE name = 'commit_update_keywords'", [json_encode([['keywords' => 'fixes,closes', 'status_id' => (string) $closed, 'done_ratio' => '100']])]);
        if (0 === (int) $conn->fetchOne("SELECT COUNT(*) FROM settings WHERE name = 'commit_update_keywords'")) {
            $conn->executeStatement("INSERT INTO settings (name, value, updated_on) VALUES ('commit_update_keywords', ?, datetime('now'))", [json_encode([['keywords' => 'fixes,closes', 'status_id' => (string) $closed, 'done_ratio' => '100']])]);
        }
        $repo = $this->createRepository();
        // commits older than the repository creation do not change issues (like Redmine)
        $conn->executeStatement("UPDATE repositories SET created_on = '2020-01-01 00:00:00' WHERE id = ?", [$repo->id]);
        $this->assertPagesOk(['/projects/ecommerce/repository']);
        $this->em->clear();
        self::assertSame([14], array_map(static fn (Issue $i) => $i->id, $this->em->getRepository(Changeset::class)->findOneBy(['repository' => $repo->id, 'comments' => "Update readme, fixes #14\n\nLonger description."])?->issues->toArray() ?? []));
        $issue = $this->em->find(Issue::class, 14);
        self::assertSame($closed, $issue->status->id, 'fixes #14 closed the issue');
        self::assertSame(100, $issue->doneRatio);
        $notes = $conn->fetchOne("SELECT notes FROM journals WHERE journalized_id = 14 AND journalized_type = 'Issue' ORDER BY id DESC LIMIT 1");
        self::assertStringContainsString('commit:', (string) $notes);

        // manage related issues on a changeset
        $cs = $this->em->getRepository(Changeset::class)->findOneBy(['repository' => $repo->id], ['committedOn' => 'ASC']);
        $base = '/projects/ecommerce/repository/'.$repo->id.'/revisions/'.$cs->scmid.'/issues';
        self::assertSame(302, $this->submit($base, ['issue_id' => '#15']));
        $this->em->clear();
        $ids = array_map(static fn (Issue $i) => $i->id, $this->em->find(Changeset::class, $cs->id)->issues->toArray());
        sort($ids);
        self::assertSame([13, 15], $ids);
        self::assertSame(302, $this->submit($base.'/15', ['_method' => 'DELETE']));
        $this->em->clear();
        self::assertSame([13], array_map(static fn (Issue $i) => $i->id, $this->em->find(Changeset::class, $cs->id)->issues->toArray()));
    }

    public function testSettingsTabPartial(): void
    {
        $repo = $this->createRepository('code');
        $this->em->clear();
        $container = static::getContainer();
        $container->get(\App\Security\CurrentUser::class)->set($this->em->getRepository(\App\Entity\User::class)->findOneBy(['login' => 'admin']));
        $html = $container->get('twig')->render('repositories/_settings_tab.html.twig', ['project' => $this->em->find(\App\Entity\Project::class, $repo->project->id)]);
        self::assertStringContainsString('/projects/ecommerce/repositories/new', $html);
        self::assertStringContainsString('/repositories/'.$repo->id.'/edit', $html);
        self::assertStringContainsString('/projects/ecommerce/repository/code', $html);
    }
}
