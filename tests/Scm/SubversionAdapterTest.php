<?php

declare(strict_types=1);

namespace App\Tests\Scm;

use App\Service\Scm\AbstractAdapter;
use App\Service\Scm\SubversionAdapter;
use App\Service\Scm\UnifiedDiff;
use PHPUnit\Framework\TestCase;

/** Port of test/unit/lib/redmine/scm/adapters/subversion_adapter_test.rb (+ browsing operations) */
final class SubversionAdapterTest extends TestCase
{
    private SubversionAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new SubversionAdapter(ScmFixtures::redmine('subversion'));
    }

    public function testScmVersion(): void
    {
        foreach (["svn, version 1.6.13 (r1002816)\n" => [1, 6, 13], "svn, versione 1.6.13 (r1002816)\n" => [1, 6, 13], "1.6.1\n1.7\n1.8" => [1, 6, 1], "1.6.2\r\n1.8.1\r\n1.9.1" => [1, 6, 2]] as $s => $v) {
            self::assertSame($v, AbstractAdapter::parseVersion($s));
        }
        self::assertTrue(SubversionAdapter::clientAvailable());
        self::assertNotSame([], SubversionAdapter::clientVersion());
    }

    public function testInfo(): void
    {
        $info = $this->adapter->info();
        self::assertNotNull($info);
        self::assertSame(ScmFixtures::redmine('subversion'), $info->rootUrl);
        self::assertSame('16', $info->lastrev->identifier);
        self::assertNull((new SubversionAdapter('file:///invalid/invalid/'))->info());
    }

    public function testEntries(): void
    {
        $entries = $this->adapter->entries('subversion_test');
        self::assertNotNull($entries);
        $names = array_map(static fn ($e) => $e->name, $entries);
        self::assertContains('[folder_with_brackets]', $names);
        self::assertContains('helloworld.c', $names);
        self::assertTrue($entries[0]->isDir(), 'directories first');
        $c = array_values(array_filter($entries, static fn ($e) => 'helloworld.c' === $e->name))[0];
        self::assertSame('subversion_test/helloworld.c', $c->path);
        self::assertSame('file', $c->kind);
        self::assertGreaterThan(0, $c->size);
        self::assertNotNull($c->lastrev->time);
        // square brackets in path
        $b = $this->adapter->entries('subversion_test/[folder_with_brackets]');
        self::assertSame(['README.txt'], array_map(static fn ($e) => $e->name, $b));
        self::assertNull($this->adapter->entries('nope'));
        // entries at a revision
        self::assertLessThan(\count($entries), \count($this->adapter->entries('subversion_test', '2')));
        self::assertSame('file', $this->adapter->entry('subversion_test/helloworld.c')->kind);
        self::assertSame('dir', $this->adapter->entry('')->kind);
    }

    public function testRevisionsDiffCatAnnotate(): void
    {
        $revs = $this->adapter->revisions('', '16', '1', ['with_paths' => true]);
        self::assertCount(16, $revs);
        self::assertSame('16', $revs[0]->identifier);
        $first = $revs[15];
        self::assertSame('Initial import.', $first->message);
        self::assertSame('jp', $first->author);
        self::assertSame('A', $first->paths[0]['action']);
        $limited = $this->adapter->revisions('subversion_test/folder', null, null, ['limit' => 3]);
        self::assertCount(3, $limited);

        $diff = $this->adapter->diff('', '3');
        self::assertNotEmpty($diff);
        self::assertSame(2, \count((new UnifiedDiff($diff))->tables));
        self::assertNotNull($this->adapter->diff('subversion_test/helloworld.rb', '3', '2'));

        self::assertStringContainsString('hello, world', (string) $this->adapter->cat('subversion_test/helloworld.c'));
        self::assertNull($this->adapter->cat('subversion_test/nope.c'));

        $ann = $this->adapter->annotate('subversion_test/helloworld.c');
        self::assertFalse($ann->isEmpty());
        self::assertSame('#include <stdio.h>', $ann->lines[0]);
        self::assertSame('4', $ann->revisions[0]->identifier);
        self::assertSame('jp', $ann->revisions[0]->author);
        self::assertSame('4', $ann->revisions[0]->formatIdentifier());
    }

    public function testProperties(): void
    {
        $props = $this->adapter->properties('subversion_test');
        self::assertIsArray($props);
    }

    public function testValidName(): void
    {
        self::assertTrue($this->adapter->isValidName('12'));
        self::assertTrue($this->adapter->isValidName(null));
        self::assertFalse($this->adapter->isValidName('HEAD'));
        self::assertFalse($this->adapter->isValidName('-r'));
    }
}
