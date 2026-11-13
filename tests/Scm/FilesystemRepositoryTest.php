<?php

declare(strict_types=1);

namespace App\Tests\Scm;

/** Repository::Filesystem (port of test/unit/repository_filesystem_test.rb + functional browsing) */
final class FilesystemRepositoryTest extends ScmWebTestCase
{
    protected function setUp(): void
    {
        ScmFixtures::requireBinary('tar');
        parent::setUp();
    }

    public function testBlankRootDirectory(): void
    {
        $this->login('admin');
        self::assertSame(422, $this->submit('/projects/ecommerce/repositories', ['repository_scm' => 'Filesystem', 'repository' => ['identifier' => 'test', 'url' => '']]));
        self::assertStringContainsString('Root directory cannot be blank', $this->html());
    }

    public function testBrowse(): void
    {
        $repo = $this->createRepository('Filesystem', ['identifier' => 'fs', 'url' => ScmFixtures::redmine('filesystem')]);
        $base = '/projects/ecommerce/repository/fs';
        $this->assertPagesOk([$base, $base.'/show/dir', $base.'/entry/test', $base.'/raw/test', $base.'/entry/japanese/euc-jp.txt']);
        self::assertSame(0, $this->changesetCount($repo));
        $this->get($base.'/entry/test');
        self::assertStringContainsString('TEST CAT', $this->html());
        // no history, no annotate
        self::assertStringNotContainsString('tab-changes', $this->html());
        self::assertStringNotContainsString('tab-annotate', $this->html());
        self::assertStringNotContainsString('icon-stats', $this->html());
        $this->get($base);
        self::assertStringNotContainsString('label_view_all_revisions', $this->html());
        self::assertStringNotContainsString('/repository/fs/revisions"', $this->html());
        $this->client->request('GET', $base.'/entry/../../etc/passwd');
        self::assertNotSame(200, $this->httpStatus());
    }
}
