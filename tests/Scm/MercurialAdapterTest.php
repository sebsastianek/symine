<?php

declare(strict_types=1);

namespace App\Tests\Scm;

use App\Service\Scm\MercurialAdapter;
use App\Service\Scm\ScmCommandFailed;
use PHPUnit\Framework\TestCase;

/** Port of test/unit/lib/redmine/scm/adapters/mercurial_adapter_test.rb (main operations) */
final class MercurialAdapterTest extends TestCase
{
    private MercurialAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new MercurialAdapter(ScmFixtures::redmine('mercurial'));
    }

    public function testClientAndInfo(): void
    {
        self::assertTrue(MercurialAdapter::clientAvailable());
        $info = $this->adapter->info();
        self::assertSame(ScmFixtures::redmine('mercurial'), $info->rootUrl);
        self::assertSame('42', $info->lastrev->revision);
        self::assertSame('ba20ebce08dbd2f0320b93faf7bba7c86186a1f7', $info->lastrev->scmid);
    }

    public function testBranchesAndTags(): void
    {
        $tags = $this->adapter->tags();
        self::assertContains('tag_test.00', $tags);
        self::assertContains('tag-Ü-00', $tags);
        self::assertContains('double"quote"tag', $tags);
        $branches = array_map('strval', $this->adapter->branches());
        self::assertContains('default', $branches);
        self::assertContains('branch (1)[2]&,%.-3_4', $branches);
        self::assertSame('31eeee7395c8c78e66dd54c50addd078d10b2355', $this->adapter->branchmap()['default']);
        self::assertSame('6987191f453a5f6557018d522feea2c450d5588d', $this->adapter->tagmap()['tag_test.00']);
        self::assertNotEmpty($this->adapter->nodesInBranch('default', ['limit' => 3]));
    }

    public function testEntriesCatAnnotate(): void
    {
        $entries = $this->adapter->entries('');
        self::assertSame(['images', 'sources', '.hgtags', 'README'], array_map(static fn ($e) => $e->name, $entries));
        self::assertSame('dir', $entries[0]->kind);
        self::assertSame(783, $entries[3]->size);
        self::assertSame('31', $entries[3]->lastrev->revision);
        $old = $this->adapter->entries(null, '2');
        self::assertCount(3, $old);
        self::assertSame('1', $old[2]->lastrev->revision);
        self::assertSame('sources/watchers_controller.rb', $this->adapter->entry('sources/watchers_controller.rb', '0')->path);
        self::assertSame([], $this->adapter->entries('nope'));

        self::assertStringStartsWith('Mercurial test repository', (string) $this->adapter->cat('README', '2'));
        self::assertNull($this->adapter->cat('nope', '0'));

        $ann = $this->adapter->annotate('README', '2');
        self::assertSame('Mercurial test repository', $ann->lines[0]);
        self::assertSame('1', $ann->revisions[0]->revision);
        self::assertSame('1:9d5b5b004199', $ann->revisions[0]->formatIdentifier());
        self::assertTrue($this->adapter->annotate('nope')->isEmpty());
    }

    public function testRevisionsAndDiff(): void
    {
        $revs = $this->adapter->revisions('', '0', '2');
        self::assertCount(3, $revs);
        self::assertSame('0', $revs[0]->revision);
        self::assertSame("Initial import.\nThe repository contains 3 files.", $revs[0]->message);
        self::assertSame('jsmith <jsmith@foo.bar>', $revs[1]->author);
        self::assertSame(['0885933ad4f68d77c2649cd11f8311276e7ef7ce'], $revs[1]->parents);
        self::assertSame(['action' => 'M', 'path' => '/README', 'from_path' => null, 'from_revision' => null], $revs[1]->paths[0]);
        self::assertSame(\count($revs), \count($this->adapter->revisions('', '0', '2', ['limit' => 3])));

        $diff = $this->adapter->diff('', '2');
        self::assertNotEmpty($diff);
        self::assertStringContainsString('watchers_controller.rb', implode('', $diff));
        self::assertNotEmpty($this->adapter->diff('sources/watchers_controller.rb', '2', '1'));
        self::assertNull($this->adapter->diff('', 'nosuchrev'));
    }

    public function testMaliciousArguments(): void
    {
        self::assertNull($this->adapter->diff('sources/welcome_controller.rb', '--config=alias.rhdiff=!xterm'));
        $this->expectException(ScmCommandFailed::class);
        $this->adapter->entries('--debugger');
    }
}
