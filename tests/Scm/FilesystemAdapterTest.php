<?php

declare(strict_types=1);

namespace App\Tests\Scm;

use App\Service\Scm\FilesystemAdapter;
use App\Service\Scm\ScmCommandFailed;
use PHPUnit\Framework\TestCase;

/** Port of test/unit/lib/redmine/scm/adapters/filesystem_adapter_test.rb */
final class FilesystemAdapterTest extends TestCase
{
    private FilesystemAdapter $adapter;

    protected function setUp(): void
    {
        ScmFixtures::requireBinary('tar');
        $this->adapter = new FilesystemAdapter(ScmFixtures::redmine('filesystem'));
    }

    public function testEntries(): void
    {
        self::assertSame(3, \count($this->adapter->entries()));
        self::assertSame(3, \count($this->adapter->entries('/')));
        self::assertSame(2, \count($this->adapter->entries('dir')));
        self::assertSame(2, \count($this->adapter->entries('dir/')));
        self::assertSame(['dir', 'japanese', 'test'], array_map(static fn ($e) => $e->name, $this->adapter->entries()));
        self::assertSame('dir/subdir', $this->adapter->entries('dir')[0]->path);
        self::assertTrue($this->adapter->entries()[0]->isDir());
        self::assertSame(9, $this->adapter->entries()[2]->size);
        self::assertNotNull($this->adapter->entries()[2]->lastrev->time);
        self::assertFalse($this->adapter->supportsAnnotate());
    }

    public function testCat(): void
    {
        self::assertSame("TEST CAT\n", $this->adapter->cat('/test'));
        self::assertSame("TEST CAT\n", $this->adapter->cat('test'));
    }

    public function testPathTraversalIsIgnored(): void
    {
        // ".." paths resolve to the root directory
        self::assertSame(3, \count($this->adapter->entries('../')));
        $this->expectException(ScmCommandFailed::class);
        $this->adapter->cat('missing');
    }
}
