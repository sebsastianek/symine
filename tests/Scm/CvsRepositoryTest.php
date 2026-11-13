<?php

declare(strict_types=1);

namespace App\Tests\Scm;

use App\Service\Scm\RepositoryService;

/** Repository::Cvs (port of test/unit/repository_cvs_test.rb + functional browsing) */
final class CvsRepositoryTest extends ScmWebTestCase
{
    public const int CHANGESETS_NUM = 7;

    protected function setUp(): void
    {
        ScmFixtures::requireBinary('cvs');
        parent::setUp();
    }

    public function testValidation(): void
    {
        $this->login('admin');
        self::assertSame(422, $this->submit('/projects/ecommerce/repositories', ['repository_scm' => 'Cvs', 'repository' => ['identifier' => 'test', 'url' => '', 'root_url' => '']]));
        foreach (['Module cannot be blank', 'CVSROOT cannot be blank', 'Commit messages encoding cannot be blank'] as $msg) {
            self::assertStringContainsString($msg, $this->html());
        }
        // the path regexp applies to the CVSROOT
        $_SERVER['SYMINE_SCM_CVS_PATH_REGEXP'] = '/cvspath/[a-z]+';
        self::assertSame(422, $this->submit('/projects/ecommerce/repositories', ['repository_scm' => 'Cvs', 'repository' => ['identifier' => 'test', 'url' => 'foo', 'root_url' => '/wrong_path', 'log_encoding' => 'UTF-8']]));
        self::assertStringContainsString('CVSROOT is invalid', $this->html());
        self::assertSame(302, $this->submit('/projects/ecommerce/repositories', ['repository_scm' => 'Cvs', 'repository' => ['identifier' => 'test', 'url' => 'foo', 'root_url' => '/cvspath/foo', 'log_encoding' => 'UTF-8']]));
    }

    public function testFetchAndBrowse(): void
    {
        $repo = $this->createRepository('Cvs', ['identifier' => 'cvs', 'url' => 'test', 'root_url' => ScmFixtures::redmine('cvs'), 'log_encoding' => 'UTF-8']);
        $base = '/projects/ecommerce/repository/cvs';
        $this->assertPagesOk([$base]);
        self::assertSame(self::CHANGESETS_NUM, $this->changesetCount($repo));
        self::assertSame(16, $this->changeCount($repo));
        self::assertSame('v1-20071213-162510', $this->changeset($repo, '2')->scmid);
        $rev3 = $this->changeset($repo, '3');
        self::assertSame('HEAD-20071213-162722', $rev3->scmid);
        self::assertSame('LANG', $rev3->committer);
        self::assertSame('2007-12-13 16:27:22', $rev3->committedOn->format('Y-m-d H:i:s'));
        self::assertSame('3', RepositoryService::identifier($rev3));

        // incremental fetch
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM changes WHERE changeset_id IN (SELECT id FROM changesets WHERE repository_id = ? AND CAST(revision AS INTEGER) > 3)', [$repo->id]);
        $conn->executeStatement('DELETE FROM changesets WHERE repository_id = ? AND CAST(revision AS INTEGER) > 3', [$repo->id]);
        $this->assertPagesOk([$base]);
        self::assertSame(self::CHANGESETS_NUM, $this->changesetCount($repo));
        self::assertSame('HEAD-20071213-163001', $this->changeset($repo, '5')->scmid);

        $this->assertPagesOk([
            $base.'/show/sources', $base.'/entry/README', $base.'/revisions/1/entry/README', $base.'/annotate/README', $base.'/revisions/1/annotate/README',
            $base.'/changes/README', $base.'/revisions', $base.'/revisions/3', $base.'/revisions/3/diff', $base.'/revisions/3/show/sources', $base.'/raw/README',
        ]);
        $this->get($base.'/revisions/1/entry/README');
        self::assertStringContainsString('CVS test repository', $this->html());
        self::assertStringNotContainsString('with one change', $this->html());
        $this->get($base.'/revisions/3/diff');
        self::assertStringContainsString('with one change', $this->html());
        // deleted files are not listed
        $this->get($base.'/show/sources');
        self::assertStringContainsString('watchers_controller.rb', $this->html());
        self::assertStringNotContainsString('welcome_controller.rb', $this->html());
        // entries of revision 3: lastrev mapped to the changeset
        $this->get($base.'/revisions/3/show');
        self::assertStringContainsString('/repository/cvs/revisions/3"', $this->html());
        $this->client->request('GET', $base.'/revisions/123/entry/README');
        self::assertSame(404, $this->httpStatus());
    }

    public function testIssueReferences(): void
    {
        [$url, $root] = ScmFixtures::small('cvs');
        $repo = $this->createRepository('Cvs', ['identifier' => 'small', 'url' => $url, 'root_url' => $root, 'log_encoding' => 'UTF-8']);
        $this->assertPagesOk(['/projects/ecommerce/repository/small']);
        // cvs import: 1.1 "Initial revision" + vendor branch 1.1.1.1, then the commit
        self::assertSame(3, $this->changesetCount($repo));
        self::assertSame([13], $this->referencedIssues($repo, 'Initial import'));
    }
}
