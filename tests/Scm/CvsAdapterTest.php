<?php

declare(strict_types=1);

namespace App\Tests\Scm;

use App\Service\Scm\CvsAdapter;
use App\Service\Scm\CvsRevisionHelper;
use App\Service\Scm\ScmCommandFailed;
use PHPUnit\Framework\TestCase;

/** Port of test/unit/lib/redmine/scm/adapters/cvs_adapter_test.rb */
final class CvsAdapterTest extends TestCase
{
    private CvsAdapter $adapter;

    protected function setUp(): void
    {
        ScmFixtures::requireBinary('cvs');
        $this->adapter = new CvsAdapter('test', ScmFixtures::redmine('cvs'));
    }

    public function testRootUrlRequired(): void
    {
        $this->expectException(ScmCommandFailed::class);
        new CvsAdapter('test', '');
    }

    public function testClient(): void
    {
        self::assertTrue(CvsAdapter::clientAvailable());
        self::assertSame([1, 12, 13], \array_slice(CvsAdapter::parseVersion("\nConcurrent Versions System (CVS) 1.12.13 (client/server)\n"), 0, 3));
    }

    public function testRevisions(): void
    {
        $revs = $this->adapter->revisions('');
        self::assertGreaterThanOrEqual(16, \count($revs));
        $readme = array_values(array_filter($revs, static fn ($r) => 'README' === $r->paths[0]['path'] && '1.2' === $r->paths[0]['revision']))[0];
        self::assertSame('LANG', $readme->author);
        self::assertSame('Two files changed', $readme->message);
        self::assertSame('2007-12-13 16:27:22', $readme->time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertSame('Exp', $readme->paths[0]['action']);
        self::assertSame('HEAD', $readme->paths[0]['branch']);
        $branch = array_values(array_filter($revs, static fn ($r) => '1.2.2.1' === $r->paths[0]['revision']))[0];
        self::assertSame('branch00', $branch->paths[0]['branch']);
        // since a date
        self::assertLessThan(\count($revs), \count($this->adapter->revisions('', '2011-03-01 00:00:00 UTC')));
    }

    public function testEntriesCatAnnotateDiff(): void
    {
        $entries = $this->adapter->entries('');
        self::assertSame(['images', 'sources', 'README'], array_map(static fn ($e) => $e->name, $entries));
        self::assertSame('1.3', $entries[2]->lastrev->revision);
        self::assertSame('2011-03-10 15:15:39', $entries[2]->lastrev->time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertNull($this->adapter->entries('missing'));
        $old = $this->adapter->entries('', '2007-12-13 16:27:23 UTC');
        self::assertSame('1.2', $old[2]->lastrev->revision);

        self::assertSame("CVS test repository\n", $this->adapter->cat('README', '2007-12-13 16:26:00 UTC'));
        self::assertStringContainsString('with one change', (string) $this->adapter->cat('README'));

        $ann = $this->adapter->annotate('README');
        self::assertCount(3, $ann->lines);
        self::assertSame('1.2', $ann->revisions[1]->revision);
        self::assertSame('LANG', $ann->revisions[1]->author);
        self::assertSame('with one change', $ann->lines[1]);

        $diff = $this->adapter->diff('README', '1.2', '1.1');
        self::assertStringContainsString('+with one change', implode('', $diff));
    }

    public function testRevisionHelper(): void
    {
        self::assertSame('1.1', (new CvsRevisionHelper('1.2'))->prevRev());
        self::assertSame('1.0', (new CvsRevisionHelper('1.1'))->prevRev());
        self::assertSame('1.2', (new CvsRevisionHelper('1.2.2.1'))->prevRev());
        self::assertTrue((new CvsRevisionHelper('1.2.2.1'))->isInBranchWithSymbol('1.2.0.2'));
        self::assertFalse((new CvsRevisionHelper('1.3'))->isInBranchWithSymbol('1.2.0.2'));
    }
}
