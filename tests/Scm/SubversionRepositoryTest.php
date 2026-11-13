<?php

declare(strict_types=1);

namespace App\Tests\Scm;

use App\Entity\Repository;

/** Repository::Subversion (port of test/unit/repository_subversion_test.rb + functional browsing) */
final class SubversionRepositoryTest extends ScmWebTestCase
{
    protected function setUp(): void
    {
        ScmFixtures::requireBinary('svn', 'svnadmin');
        parent::setUp();
    }

    public const int NUM_REV = 16;

    public function testUrlValidation(): void
    {
        $this->login('admin');
        foreach (['invalid', 'http://', 'svn://', 'file://', "http://valid\nfoo"] as $url) {
            self::assertSame(422, $this->submit('/projects/ecommerce/repositories', ['repository_scm' => 'Subversion', 'repository' => ['identifier' => 'test', 'url' => $url]]), $url);
            self::assertStringContainsString('URL is invalid', $this->html());
        }
        // path regexp of the configuration
        $_SERVER['SYMINE_SCM_SUBVERSION_PATH_REGEXP'] = 'file:///svnpath/%project%(\.[a-z]+)?';
        self::assertSame(422, $this->submit('/projects/ecommerce/repositories', ['repository_scm' => 'Subversion', 'repository' => ['identifier' => 'test', 'url' => 'file:///svnpath/foo']]));
        self::assertSame(302, $this->submit('/projects/ecommerce/repositories', ['repository_scm' => 'Subversion', 'repository' => ['identifier' => 'test', 'url' => 'file:///svnpath/ecommerce.x']]));
    }

    public function testFetchAndBrowse(): void
    {
        $url = ScmFixtures::redmine('subversion');
        $repo = $this->createRepository('Subversion', ['identifier' => 'svn', 'url' => $url, 'is_default' => '1', 'login' => '', 'password' => '']);
        $base = '/projects/ecommerce/repository/svn';
        $this->assertPagesOk([$base]);
        self::assertSame(self::NUM_REV, $this->changesetCount($repo));
        self::assertSame(26, $this->changeCount($repo));
        self::assertSame('Initial import.', $this->changeset($repo, '1')->comments);
        self::assertSame($url, $this->em->find(Repository::class, $repo->id)->rootUrl, 'root_url retrieved from svn info');
        // incremental fetch
        $this->em->getConnection()->executeStatement('DELETE FROM changes WHERE changeset_id IN (SELECT id FROM changesets WHERE repository_id = ? AND CAST(revision AS INTEGER) > 5)', [$repo->id]);
        $this->em->getConnection()->executeStatement('DELETE FROM changesets WHERE repository_id = ? AND CAST(revision AS INTEGER) > 5', [$repo->id]);
        self::assertSame(5, $this->changesetCount($repo));
        $this->assertPagesOk([$base]);
        self::assertSame(self::NUM_REV, $this->changesetCount($repo));

        $this->assertPagesOk([
            $base.'/show/subversion_test', $base.'/browse/subversion_test', $base.'/revisions/3/show/subversion_test',
            $base.'/revisions/3/browse/subversion_test', $base.'/entry/subversion_test/helloworld.c', $base.'/revisions/4/entry/subversion_test/helloworld.c',
            $base.'/annotate/subversion_test/helloworld.c', $base.'/revisions/4/annotate/subversion_test/helloworld.c',
            $base.'/changes/subversion_test/helloworld.c', $base.'/revisions', $base.'/revisions/3', $base.'/revision?rev=3',
            $base.'/revisions/3/diff', $base.'/revisions/3/diff/subversion_test/helloworld.rb', $base.'/diff/subversion_test/helloworld.rb?rev=3&rev_to=2',
            $base.'/statistics', $base.'/show/subversion_test/%5Bfolder_with_brackets%5D', $base.'/revisions.atom',
        ]);
        $this->get($base.'/show/subversion_test');
        self::assertStringContainsString('helloworld.c', $this->html());
        $this->get($base.'/changes/subversion_test/folder/helloworld.rb');
        self::assertStringContainsString('<li><b>svn:eol-style</b>: <span>native</span></li>', $this->html(), 'svn properties');
        // syntax highlighting of the C file
        $this->get($base.'/entry/subversion_test/helloworld.c');
        self::assertMatchesRegularExpression('#<span class="cp">\#include#', $this->html());
        $this->get($base.'/annotate/subversion_test/helloworld.c');
        self::assertStringContainsString('href="/projects/ecommerce/repository/svn/revisions/4"', $this->html());
        // latest changesets of a directory
        $this->get($base.'/changes/subversion_test/folder');
        foreach (['13', '12', '10', '9', '7', '6', '5', '2'] as $r) {
            self::assertStringContainsString('/repository/svn/revisions/'.$r.'"', $this->html());
        }
        $this->get($base.'/revisions/3');
        self::assertStringContainsString('change-M', $this->html());
        $this->get($base.'/revisions/3/diff');
        self::assertStringContainsString('table class="filecontent diffcontent"', $this->html());
        self::assertStringContainsString('subversion_test/helloworld.rb', $this->html());
        $this->client->request('GET', $base.'/raw/subversion_test/helloworld.c');
        self::assertStringContainsString('hello, world', $this->html());
        // revisions are numbers only
        $this->client->request('GET', $base.'/revisions/3/entry/subversion_test/helloworld.c?rev_to=HEAD');
        self::assertSame(404, $this->httpStatus());
        $this->client->request('GET', $base.'/entry/subversion_test/missing.c');
        self::assertSame(404, $this->httpStatus());
    }

    public function testIssueReferencesAndCommitter(): void
    {
        [$url] = ScmFixtures::small('subversion');
        $repo = $this->createRepository('Subversion', ['identifier' => 'small', 'url' => $url]);
        $this->assertPagesOk(['/projects/ecommerce/repository/small']);
        self::assertSame(2, $this->changesetCount($repo));
        self::assertSame([13], $this->referencedIssues($repo, 'Initial import'));
        self::assertSame('jsmith', $this->changeset($repo, '1')->user?->login);
        $this->get('/projects/ecommerce/repository/small/revisions/1');
        self::assertStringContainsString('/issues/13', $this->html());
    }
}
