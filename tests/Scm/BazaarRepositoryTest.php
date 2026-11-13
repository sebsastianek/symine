<?php

declare(strict_types=1);

namespace App\Tests\Scm;

/** Repository::Bazaar (port of test/unit/repository_bazaar_test.rb + functional browsing) */
final class BazaarRepositoryTest extends ScmWebTestCase
{
    public const int NUM_REV = 4;

    protected function setUp(): void
    {
        ScmFixtures::requireBinary('bzr');
        parent::setUp();
    }

    public function testValidation(): void
    {
        $this->login('admin');
        self::assertSame(422, $this->submit('/projects/ecommerce/repositories', ['repository_scm' => 'Bazaar', 'repository' => ['identifier' => 'test', 'url' => '']]));
        self::assertStringContainsString('Path to repository cannot be blank', $this->html());
        self::assertStringContainsString('Commit messages encoding cannot be blank', $this->html());
    }

    public function testFetchAndBrowse(): void
    {
        $repo = $this->createRepository('Bazaar', ['identifier' => 'bzr', 'url' => ScmFixtures::redmine('bazaar').'/trunk', 'log_encoding' => 'UTF-8']);
        $base = '/projects/ecommerce/repository/bzr';
        $this->assertPagesOk([$base]);
        self::assertSame(self::NUM_REV, $this->changesetCount($repo));
        self::assertSame(9, $this->changeCount($repo));
        self::assertSame('Initial import', $this->changeset($repo, '1')->comments);
        // incremental
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM changes WHERE changeset_id IN (SELECT id FROM changesets WHERE repository_id = ? AND CAST(revision AS INTEGER) > 2)', [$repo->id]);
        $conn->executeStatement('DELETE FROM changesets WHERE repository_id = ? AND CAST(revision AS INTEGER) > 2', [$repo->id]);
        $this->assertPagesOk([$base]);
        self::assertSame(self::NUM_REV, $this->changesetCount($repo));

        $this->assertPagesOk([
            $base.'/show/directory', $base.'/entry/doc-mkdir.txt', $base.'/revisions/2/entry/doc-mkdir.txt', $base.'/annotate/doc-mkdir.txt',
            $base.'/changes/doc-mkdir.txt', $base.'/revisions', $base.'/revisions/2', $base.'/revisions/3/diff', $base.'/raw/doc-mkdir.txt',
        ]);
        $this->get($base);
        self::assertStringContainsString('doc-mkdir.txt', $this->html());
        // last revision of the entries mapped through the changes
        self::assertStringContainsString('/repository/bzr/revisions/', $this->html());
        $this->get($base.'/annotate/doc-mkdir.txt');
        self::assertStringContainsString('/repository/bzr/revisions/1"', $this->html());
    }

    public function testIssueReferencesAndCommitter(): void
    {
        [$url] = ScmFixtures::small('bazaar');
        $repo = $this->createRepository('Bazaar', ['identifier' => 'small', 'url' => $url, 'log_encoding' => 'UTF-8']);
        $this->assertPagesOk(['/projects/ecommerce/repository/small']);
        self::assertSame(2, $this->changesetCount($repo));
        self::assertSame([13], $this->referencedIssues($repo, 'Initial import'));
        self::assertSame('jsmith', $this->changeset($repo, '1')->user?->login);
    }
}
