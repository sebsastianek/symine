<?php

declare(strict_types=1);

namespace App\Tests\Scm;

use App\Service\Scm\BazaarAdapter;
use PHPUnit\Framework\TestCase;

/** Port of test/unit/lib/redmine/scm/adapters/bazaar_adapter_test.rb */
final class BazaarAdapterTest extends TestCase
{
    private BazaarAdapter $adapter;

    protected function setUp(): void
    {
        ScmFixtures::requireBinary('bzr');
        $this->adapter = new BazaarAdapter(ScmFixtures::redmine('bazaar').'/trunk');
    }

    public function testInfoAndEntries(): void
    {
        self::assertTrue(BazaarAdapter::clientAvailable());
        self::assertSame('4', $this->adapter->info()->lastrev->identifier);
        $entries = $this->adapter->entries();
        self::assertCount(2, $entries);
        self::assertSame(['dir', 'directory', 'directory'], [$entries[0]->kind, $entries[0]->name, $entries[0]->path]);
        self::assertSame(['file', 'doc-mkdir.txt', 'doc-mkdir.txt'], [$entries[1]->kind, $entries[1]->name, $entries[1]->path]);
        $sub = $this->adapter->entries('directory');
        self::assertCount(3, $sub);
        self::assertSame('directory/document.txt', $sub[1]->path);
        self::assertSame([], $this->adapter->entries('missing'), 'bzr ls succeeds for a missing path (like Redmine)');
    }

    public function testRevisionsCatAnnotateDiff(): void
    {
        $revs = $this->adapter->revisions('', '4', '1');
        self::assertCount(4, $revs);
        $r2 = array_values(array_filter($revs, static fn ($r) => '2' === $r->identifier))[0];
        self::assertSame('jsmith <jsmith@foo.bar>', $r2->author);
        self::assertSame("Added a directory with 2 files\n", $r2->message);
        self::assertSame('jsmith@foo.bar-20071203175224-v0eog5d5wrgdrshg', $r2->scmid);
        self::assertSame('2007-12-03 17:52:24', $r2->time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertSame(['action' => 'A', 'path' => '/directory/', 'revision' => 'directory-20071203175209-idcordrd5wudsk75-1'], $r2->paths[0]);

        self::assertStringContainsString('mkdir', (string) $this->adapter->cat('doc-mkdir.txt'));
        self::assertNull($this->adapter->cat('missing.txt'));
        $ann = $this->adapter->annotate('doc-mkdir.txt');
        self::assertNotEmpty($ann->lines);
        self::assertSame('1', $ann->revisions[0]->identifier);
        self::assertStringContainsString('jsmith', (string) $ann->revisions[0]->author);
        self::assertNotEmpty($this->adapter->diff('', '3'));
    }

    public function testBranchConfPath(): void
    {
        self::assertSame('/var/bzr/repo/.bzr/branch/branch.conf', BazaarAdapter::branchConfPath('/var/bzr/repo'));
        self::assertSame('/var/bzr/repo/.bzr/branch/branch.conf', BazaarAdapter::branchConfPath('/var/bzr/repo/'));
        self::assertSame('/var/bzr/repo/.bzr/branch/branch.conf', BazaarAdapter::branchConfPath('/var/bzr/repo/.bzr'));
    }
}
