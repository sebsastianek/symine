<?php

declare(strict_types=1);

namespace App\Tests\Scm;

/** SCM availability (scm_xxx_command / scm_xxx_path_regexp), settings, admin info, repository form and URL forms */
final class ScmSettingsTest extends ScmWebTestCase
{
    public function testScmDisabledWithoutPathRegexp(): void
    {
        ScmFixtures::requireBinary('git');
        [$url] = ScmFixtures::small('git');
        $repo = $this->createRepository('Git', ['identifier' => 'small', 'url' => $url]);
        $this->assertPagesOk(['/projects/ecommerce/repository/small', '/projects/ecommerce/settings/repositories']);
        self::assertStringContainsString('/projects/ecommerce/repositories/new', (string) $this->client->getResponse()->getContent());
        // Redmine 7: an SCM without scm_git_path_regexp is not available
        unset($_SERVER['SYMINE_SCM_GIT_PATH_REGEXP']);
        $this->client->request('GET', '/projects/ecommerce/repository/small');
        self::assertSame(404, $this->httpStatus());
        self::assertSame(404, $this->submit('/projects/ecommerce/repositories', ['repository_scm' => 'Git', 'repository' => ['identifier' => 'x', 'url' => $url]]));
        foreach (self::SCMS as $scm) {
            unset($_SERVER['SYMINE_SCM_'.$scm.'_PATH_REGEXP']);
        }
        $this->get('/projects/ecommerce/settings/repositories');
        self::assertStringNotContainsString('/projects/ecommerce/repositories/new', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('/repositories/'.$repo->id.'/edit', (string) $this->client->getResponse()->getContent());
    }

    public function testAdminSettingsAndInfo(): void
    {
        $this->login('admin');
        $_SERVER['SYMINE_SCM_GIT_COMMAND'] = 'git';
        unset($_SERVER['SYMINE_SCM_MERCURIAL_PATH_REGEXP']);
        $html = $this->get('/settings?tab=repositories');
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('<code>.+</code>', $html);
        self::assertMatchesRegularExpression('#value="Mercurial"[^>]*disabled="disabled"#', $html, 'no path regexp: cannot be enabled');
        if (ScmFixtures::hasBinary('git')) {
            self::assertMatchesRegularExpression('#value="Git" checked="checked" />#', $html);
            self::assertMatchesRegularExpression('#<span class="icon icon-ok">\s*<svg[^>]*>.*?</svg><span class="icon-label">git</span>#s', $html);
        }
        $info = $this->get('/admin/info');
        self::assertStringContainsString('SCM:', $info);
        if (ScmFixtures::hasBinary('git')) {
            self::assertMatchesRegularExpression('/  Git\s+[\d.]+\s+Allowed Paths: \.\+/', $info);
        }
        unset($_SERVER['SYMINE_SCM_GIT_COMMAND']);
    }

    public function testNewRepositoryFormPerScm(): void
    {
        $this->login('admin');
        $html = $this->get('/projects/ecommerce/repositories/new?repository_scm=Filesystem');
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('<option selected="selected" value="Filesystem">Filesystem</option>', $html);
        self::assertStringContainsString('Root directory', $html);
        self::assertStringContainsString('data-remote="true"', $html);
        // the SCM select reloads the form (new.js.erb)
        $this->client->request('GET', '/projects/ecommerce/repositories/new', ['repository_scm' => 'Filesystem'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $js = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("$('#content').html('", $js);
        self::assertStringContainsString('Root directory', $js);
        if (ScmFixtures::hasBinary('cvs')) {
            $this->client->request('GET', '/projects/ecommerce/repositories/new', ['repository_scm' => 'Cvs'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
            $js = (string) $this->client->getResponse()->getContent();
            self::assertStringContainsString('repository[root_url]', $js);
            self::assertStringContainsString('CVSROOT', $js);
            self::assertStringContainsString('repository[log_encoding]', $js);
        } else {
            // SCM client not installed: not available
            $this->client->request('GET', '/projects/ecommerce/repositories/new?repository_scm=Cvs');
            self::assertSame(404, $this->httpStatus());
        }
        // unknown / disabled SCM
        $this->client->request('GET', '/projects/ecommerce/repositories/new?repository_scm=Darcs');
        self::assertSame(404, $this->httpStatus());
    }

    public function testEveryRepositoryUrlForm(): void
    {
        ScmFixtures::requireBinary('git');
        [$url] = ScmFixtures::small('git');
        $repo = $this->createRepository('Git', ['identifier' => 'small', 'url' => $url, 'is_default' => '1']);
        $this->assertPagesOk(['/projects/ecommerce/repository']);
        $sha = (string) $this->em->getConnection()->fetchOne("SELECT scmid FROM changesets WHERE repository_id = ? AND comments LIKE 'Update readme%'", [$repo->id]);
        foreach (['/projects/ecommerce/repository/small', '/projects/ecommerce/repository/'.$repo->id] as $p) {
            $this->assertPagesOk([
                $p, $p.'/show/src', $p.'/browse', $p.'/browse/src', $p.'/entry/README', $p.'/raw/README', $p.'/annotate/README', $p.'/changes/README',
                $p.'/diff?rev='.$sha, $p.'/diff/README?rev='.$sha, $p.'/revisions', $p.'/revision?rev='.$sha, $p.'/revisions/'.$sha,
                $p.'/revisions/'.$sha.'/show', $p.'/revisions/'.$sha.'/show/src', $p.'/revisions/'.$sha.'/browse/src', $p.'/revisions/'.$sha.'/entry/README',
                $p.'/revisions/'.$sha.'/raw/README', $p.'/revisions/'.$sha.'/annotate/README', $p.'/revisions/'.$sha.'/diff', $p.'/revisions/'.$sha.'/diff/README',
                $p.'/revisions/main/entry/src/hello.rb', $p.'/statistics', $p.'/graph?graph=commits_per_month',
            ]);
        }
        $this->client->request('GET', '/projects/ecommerce/repository/small/revisions/'.$sha.'/diff?format=diff');
        self::assertStringStartsWith('text/x-patch', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $this->client->request('GET', '/projects/ecommerce/repository/nope/browse');
        self::assertSame(404, $this->httpStatus());
        // merge commit: two parents, revision graph with two columns
        $html = $this->get('/projects/ecommerce/repository/small/revisions');
        preg_match('/getElementById\(\'holder\'\),\s*(\{.*\}),\s*(\d+)\s*\);/s', $html, $m);
        $graph = json_decode($m[1], true);
        self::assertCount(3, $graph);
        self::assertSame(1, (int) $m[2]);
        $merge = array_values(array_filter($graph, static fn ($c) => 2 === \count($c['parent_scmids'])))[0];
        self::assertSame('main', $merge['refs'], json_encode($graph));
    }
}
