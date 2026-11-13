<?php

declare(strict_types=1);

namespace App\Tests\Scm;

/** Repository::Mercurial (port of test/unit/repository_mercurial_test.rb + functional browsing) */
final class MercurialRepositoryTest extends ScmWebTestCase
{
    protected function setUp(): void
    {
        ScmFixtures::requireBinary('hg');
        parent::setUp();
    }

    public const int NUM_REV = 43;

    public function testFetchAndBrowse(): void
    {
        $repo = $this->createRepository('Mercurial', ['identifier' => 'hg', 'url' => ScmFixtures::redmine('mercurial'), 'path_encoding' => '']);
        $base = '/projects/ecommerce/repository/hg';
        $this->assertPagesOk([$base]);
        self::assertSame(self::NUM_REV, $this->changesetCount($repo));
        self::assertSame(47, $this->changeCount($repo));
        $rev0 = $this->changeset($repo, '0');
        self::assertSame("Initial import.\nThe repository contains 3 files.", $rev0->comments);
        self::assertSame('0885933ad4f68d77c2649cd11f8311276e7ef7ce', $rev0->scmid);
        $rev2 = $this->changeset($repo, '2');
        self::assertSame(['9d5b5b00419901478496242e0768deba1ce8c51e'], array_map(static fn ($p) => $p->scmid, $rev2->parents->toArray()));

        $this->assertPagesOk([
            $base.'/show/sources', $base.'/entry/sources/watchers_controller.rb', $base.'/annotate/README', $base.'/changes/README',
            $base.'/revisions', $base.'/revisions/2', $base.'/revisions/400bb8672109', $base.'/revision?rev=2',
            $base.'/revisions/400bb86721098697c7d17b3724c794c57636de70/diff', $base.'/revisions/2/entry/sources/watchers_controller.rb',
            $base.'/show?rev=default', $base.'/show/sources?rev=tag_test.00', $base.'/changes/sources?rev=default',
            $base.'/diff?rev=2&rev_to=1', $base.'/raw/README',
        ]);
        // revision title: "rev:short scmid"
        $this->get($base.'/revisions/2');
        self::assertStringContainsString('2:400bb8672109', $this->html());
        // revision graph
        $this->get($base.'/revisions');
        self::assertStringContainsString('<div id="holder" class="revision-graph"></div>', $this->html());
        self::assertStringContainsString('/assets/revision_graph.js', $this->html());
        self::assertMatchesRegularExpression('/drawRevisionGraph\(\s*document.getElementById\(\'holder\'\),\s*\{"/', $this->html());
        preg_match('/getElementById\(\'holder\'\),\s*(\{.*\}),\s*(\d+)\s*\);/s', $this->html(), $m);
        $graph = json_decode($m[1], true);
        self::assertIsArray($graph);
        $tip = $graph['ba20ebce08dbd2f0320b93faf7bba7c86186a1f7'] ?? null;
        self::assertNotNull($tip, 'latest revision of the page is in the graph');
        self::assertSame('branch-empty-message', $tip['refs']);
        self::assertSame(0, $tip['space']);
        self::assertStringContainsString('/repository/hg/revisions/ba20ebce08dbd2f0320b93faf7bba7c86186a1f7', $tip['href']);
        // syntax highlighting of a ruby file
        $this->get($base.'/entry/sources/watchers_controller.rb');
        self::assertStringContainsString('<span class="k">class</span>', $this->html());
        // annotate with revision links
        $this->get($base.'/annotate/README');
        self::assertStringContainsString('class="filecontent annotate syntaxhl"', $this->html());
        // branch selector
        $this->get($base);
        self::assertStringContainsString('<option value="default">default</option>', $this->html());
        $this->client->request('GET', $base.'/entry/missing');
        self::assertSame(404, $this->httpStatus());
    }

    public function testIssueReferencesAndCommitter(): void
    {
        [$url] = ScmFixtures::small('mercurial');
        $repo = $this->createRepository('Mercurial', ['identifier' => 'small', 'url' => $url]);
        $this->assertPagesOk(['/projects/ecommerce/repository/small']);
        self::assertSame(2, $this->changesetCount($repo));
        self::assertSame([13], $this->referencedIssues($repo, 'Initial import'));
        self::assertSame('jsmith', $this->changeset($repo, '0')->user?->login);
    }
}
