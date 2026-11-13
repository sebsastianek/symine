<?php

declare(strict_types=1);

namespace App\Tests\Scm;

use App\Entity\Repository;

/** Repository::Git with Redmine's git test repository (path_encoding ISO-8859-1, 8 branches) */
final class GitRepositoryTest extends ScmWebTestCase
{
    public const int NUM_REV = 29;
    public const int NUM_HEAD = 8;

    protected function setUp(): void
    {
        ScmFixtures::requireBinary('git', 'tar');
        parent::setUp();
    }

    public function testFetchAndBrowse(): void
    {
        $repo = $this->createRepository('Git', ['identifier' => 'git', 'url' => ScmFixtures::redmine('git'), 'path_encoding' => 'ISO-8859-1', 'report_last_commit' => '1']);
        $base = '/projects/ecommerce/repository/git';
        $this->assertPagesOk([$base]);
        self::assertSame(self::NUM_REV, $this->changesetCount($repo));
        self::assertSame(40, $this->changeCount($repo));
        $this->em->clear();
        $r = $this->em->find(Repository::class, $repo->id);
        self::assertCount(self::NUM_HEAD, $r->extraInfo['heads']);
        self::assertSame(1, $r->extraInfo['db_consistent']['ordering']);
        self::assertSame($r->url, $r->rootUrl);
        $cs = $this->em->getConnection()->fetchAssociative("SELECT id, comments FROM changesets WHERE repository_id = ? AND scmid = '7234cb2750b63f47bff735edc50a1c0a433c2518'", [$repo->id]);
        self::assertSame('Initial import.', substr((string) $cs['comments'], 0, 15));
        self::assertSame('README', $this->em->getConnection()->fetchOne('SELECT path FROM changes WHERE changeset_id = ? ORDER BY path LIMIT 1', [$cs['id']]));

        $this->assertPagesOk([
            $base.'/show/images', $base.'/revisions/master/entry/sources/watchers_controller.rb', $base.'/entry/README?rev=test_branch',
            $base.'/annotate/sources/watchers_controller.rb', $base.'/changes/sources/watchers_controller.rb', $base.'/revisions',
            $base.'/revisions/7234cb2750b63f47bff735edc50a1c0a433c2518', $base.'/revisions/7234cb27/diff', $base.'/show?branch=test_branch',
            $base.'/show?tag=tag00.lightweight', $base.'/browse/images', $base.'/revisions/master/browse/images', $base.'/revisions/master/raw/README',
            $base.'/statistics',
        ]);
        // latin-1 file name decoded with path_encoding
        $this->get($base.'/show/latin-1-dir?rev=latin-1-path-encoding');
        self::assertStringContainsString('test-Ü-2.txt', $this->html());
        $this->assertPagesOk([$base.'/entry/latin-1-dir/test-%C3%9C-2.txt?rev=latin-1-path-encoding']);
        // revision graph with the branch heads
        $this->get($base.'/revisions');
        preg_match('/getElementById\(\'holder\'\),\s*(\{.*\}),\s*(\d+)\s*\);/s', $this->html(), $m);
        $graph = json_decode($m[1], true);
        self::assertNotEmpty($graph);
        self::assertGreaterThan(0, (int) $m[2], 'several columns for the branches');
        $refs = array_filter(array_column($graph, 'refs'));
        self::assertNotEmpty($refs);
        foreach ($graph as $commit) {
            self::assertArrayHasKey('space', $commit);
            self::assertArrayHasKey('parent_scmids', $commit);
        }
        self::assertMatchesRegularExpression('/<td class="id" style="padding-left:\d+px">/', $this->html());
        // syntax highlighting
        $this->get($base.'/revisions/master/entry/sources/watchers_controller.rb');
        self::assertStringContainsString('<span class="k">class</span>', $this->html());
        // report last commit columns
        $this->get($base);
        self::assertStringContainsString('<th>Revision</th>', $this->html());
    }
}
