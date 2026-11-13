<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Entity\Wiki;
use App\Entity\WikiContentVersion;
use App\Entity\WikiPage;
use App\Entity\WikiRedirect;
use App\Service\Wiki\WikiDiff;
use App\Service\WikiService;

/** Wiki module (WikiController / WikisController), checked against the seeded ecommerce wiki. */
final class WikiTest extends ContentWebTestCase
{
    private function page(string $title, int $wikiId = 1): ?WikiPage
    {
        $this->em->clear();

        return $this->em->getRepository(WikiPage::class)->findOneBy(['title' => $title, 'wiki' => $wikiId]);
    }

    public function testPagesReturn200ForAdminAndJsmith(): void
    {
        foreach (['admin', 'jsmith'] as $login) {
            $this->login($login);
            $this->assertPagesOk([
                '/projects/ecommerce/wiki',
                '/projects/ecommerce/wiki/Wiki',
                '/projects/ecommerce/wiki/Checkout_flow',
                '/projects/ecommerce/wiki/Checkout_flow/1',
                '/projects/ecommerce/wiki/index',
                '/projects/ecommerce/wiki/date_index',
                '/projects/ecommerce/wiki/Checkout_flow/history',
                '/projects/ecommerce/wiki/Checkout_flow/diff',
                '/projects/ecommerce/wiki/Checkout_flow/diff?version=2&version_from=1',
                '/projects/ecommerce/wiki/Checkout_flow/2/annotate',
                '/projects/ecommerce/wiki/Checkout_flow/edit',
                '/projects/ecommerce/wiki/Checkout_flow/rename',
                '/projects/ecommerce/wiki/new',
                '/projects/ecommerce/wiki/export',
                '/projects/ecommerce/wiki/Checkout_flow.txt',
                '/projects/ecommerce/wiki/Checkout_flow.html',
            ]);
        }
    }

    public function testShowMarkup(): void
    {
        $this->login('jsmith');
        $html = $this->get('/projects/ecommerce/wiki/Checkout_flow');
        self::assertStringContainsString('<p class="breadcrumb"><a href="/projects/ecommerce/wiki/Wiki">Wiki</a> » </p>', $html);
        self::assertStringContainsString('<div class="wiki wiki-page">', $html);
        self::assertStringContainsString('Version 2 with more text', $html);
        self::assertStringContainsString('2 revisions', $html);
        self::assertStringContainsString('href="/projects/ecommerce/wiki/Checkout_flow/history"', $html);
        // start page renders wiki links, including the existing page link
        $html = $this->get('/projects/ecommerce/wiki');
        self::assertMatchesRegularExpression('#<a href="/projects/ecommerce/wiki/Checkout_flow" class="wiki-page">Checkout flow</a>#', $html);
        // index hierarchy
        $html = $this->get('/projects/ecommerce/wiki/index');
        self::assertStringContainsString('<ul class="pages-hierarchy">', $html);
        self::assertMatchesRegularExpression('#href="/projects/ecommerce/wiki/Wiki">Wiki</a>\s*<ul class="pages-hierarchy">\s*<li><a title="Updated [^"]+" href="/projects/ecommerce/wiki/Checkout_flow">Checkout flow</a></li>#', $html);
        // old version
        $html = $this->get('/projects/ecommerce/wiki/Checkout_flow/1');
        self::assertStringContainsString('Revision 1/2', $html);
        self::assertStringContainsString('Version 1', $html);
    }

    public function testAnonymousAndNonMemberPermissions(): void
    {
        $this->client->request('GET', '/projects/ecommerce/wiki/Checkout_flow');
        $code = $this->httpStatus();
        self::assertContains($code, [200, 302, 401, 403]);
        // a non existing page without edit permission is a 404 for anonymous (or login redirect)
        $this->client->request('GET', '/projects/ecommerce/wiki/Does_not_exist');
        self::assertContains($this->httpStatus(), [302, 401, 404]);
    }

    public function testCreateAndUpdatePageWithVersions(): void
    {
        $this->login('jsmith');
        // new page dialog redirects to the page
        self::assertSame(302, $this->submit('/projects/ecommerce/wiki/new', ['title' => 'Release notes', 'parent' => 'Wiki']));
        self::assertStringEndsWith('/projects/ecommerce/wiki/Release_notes?parent=Wiki', $this->location());
        // non-existing page shows the edit form
        $html = $this->get('/projects/ecommerce/wiki/Release_notes?parent=Wiki');
        self::assertStringContainsString('id="wiki_form"', $html);
        self::assertStringContainsString('# Release notes', $html);

        self::assertSame(302, $this->submit('/projects/ecommerce/wiki/Release_notes', [
            '_method' => 'PUT',
            'content' => ['text' => "# Release notes\n\nFirst", 'comments' => 'created'],
            'wiki_page' => ['parent_id' => '1'],
        ]));
        $page = $this->page('Release_notes');
        self::assertNotNull($page);
        self::assertSame(1, $page->content->version);
        self::assertSame('Wiki', $page->parent?->title);
        $versions = $this->em->getRepository(WikiContentVersion::class)->findBy(['pageId' => $page->id]);
        self::assertCount(1, $versions);
        self::assertSame("# Release notes\n\nFirst", $versions[0]->getText());
        self::assertSame('created', $versions[0]->comments);

        // update with correct version
        self::assertSame(302, $this->submit('/projects/ecommerce/wiki/Release_notes', ['_method' => 'PUT', 'content' => ['text' => "# Release notes\n\nSecond", 'version' => '1', 'comments' => '']]));
        $page = $this->page('Release_notes');
        self::assertSame(2, $page->content->version);
        self::assertCount(2, $this->em->getRepository(WikiContentVersion::class)->findBy(['pageId' => $page->id]));

        // stale version => conflict
        $this->submit('/projects/ecommerce/wiki/Release_notes', ['_method' => 'PUT', 'content' => ['text' => 'Conflicting', 'version' => '1']]);
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('flash error', (string) $this->client->getResponse()->getContent());
        self::assertSame(2, $this->page('Release_notes')->content->version);

        // same text => no new version
        $this->submit('/projects/ecommerce/wiki/Release_notes', ['_method' => 'PUT', 'content' => ['text' => "# Release notes\n\nSecond", 'version' => '2']]);
        self::assertSame(2, $this->page('Release_notes')->content->version);

        // diff and annotate
        $html = $this->get('/projects/ecommerce/wiki/Release_notes/diff');
        self::assertStringContainsString('<span class="diff_in">Second</span> <span class="diff_out">First</span>', $html);
        $html = $this->get('/projects/ecommerce/wiki/Release_notes/2/annotate');
        self::assertStringContainsString('<table class="filecontent annotate">', $html);
    }

    public function testSectionEdit(): void
    {
        $this->login('jsmith');
        $this->submit('/projects/ecommerce/wiki/Sections', ['_method' => 'PUT', 'content' => ['text' => "# Title\n\nintro\n\n## A\n\ntext a\n\n## B\n\ntext b"]]);
        $html = $this->get('/projects/ecommerce/wiki/Sections');
        self::assertStringContainsString('id="section-2"', $html);
        self::assertStringContainsString('/projects/ecommerce/wiki/Sections/edit?section=3', $html);
        $crawler = $this->client->request('GET', '/projects/ecommerce/wiki/Sections/edit?section=2');
        self::assertSame("## A\n\ntext a", trim($crawler->filter('#content_text')->text(null, false)));
        $hash = $crawler->filter('#section_hash')->attr('value');
        self::assertSame(302, $this->submit('/projects/ecommerce/wiki/Sections', ['_method' => 'PUT', 'section' => '2', 'section_hash' => $hash, 'content' => ['text' => "## A\n\nchanged a", 'version' => '1']]));
        self::assertStringEndsWith('#section-2', $this->location());
        self::assertSame("# Title\n\nintro\n\n## A\n\nchanged a\n\n## B\n\ntext b", $this->page('Sections')->content->text);
        // stale section hash
        $this->submit('/projects/ecommerce/wiki/Sections', ['_method' => 'PUT', 'section' => '2', 'section_hash' => $hash, 'content' => ['text' => 'x']]);
        self::assertStringContainsString('flash error', (string) $this->client->getResponse()->getContent());
    }

    public function testRenameCreatesRedirect(): void
    {
        $this->login('jsmith');
        self::assertSame(302, $this->submit('/projects/ecommerce/wiki/Checkout_flow/rename', ['wiki_page' => ['title' => 'Checkout process', 'redirect_existing_links' => '1', 'parent_id' => '1']]));
        self::assertStringEndsWith('/projects/ecommerce/wiki/Checkout_process', $this->location());
        self::assertNotNull($this->page('Checkout_process'));
        $r = $this->em->getRepository(WikiRedirect::class)->findOneBy(['title' => 'Checkout_flow']);
        self::assertNotNull($r);
        self::assertSame('Checkout_process', $r->redirectsTo);
        // old title redirects
        $this->client->request('GET', '/projects/ecommerce/wiki/Checkout_flow');
        self::assertSame(302, $this->httpStatus());
        self::assertStringEndsWith('/projects/ecommerce/wiki/Checkout_process', $this->location());
        // rename back removes the self redirect
        $this->submit('/projects/ecommerce/wiki/Checkout_process/rename', ['wiki_page' => ['title' => 'Checkout_flow', 'redirect_existing_links' => '1']]);
        $this->em->clear();
        self::assertNull($this->em->getRepository(WikiRedirect::class)->findOneBy(['title' => 'Checkout_flow', 'redirectsTo' => 'Checkout_flow']));
        self::assertNotNull($this->em->getRepository(WikiRedirect::class)->findOneBy(['title' => 'Checkout_process', 'redirectsTo' => 'Checkout_flow']));
    }

    public function testRenameWithoutRedirectAndStartPage(): void
    {
        $this->login('admin');
        $this->submit('/projects/ecommerce/wiki/Checkout_flow/rename', ['wiki_page' => ['title' => 'Flow', 'redirect_existing_links' => '0', 'is_start_page' => '1']]);
        self::assertSame(302, $this->httpStatus());
        $this->em->clear();
        self::assertNull($this->em->getRepository(WikiRedirect::class)->findOneBy(['title' => 'Checkout_flow']));
        self::assertSame('Flow', $this->em->find(Wiki::class, 1)->startPage);
    }

    public function testProtectAndDestroy(): void
    {
        $this->login('jsmith');
        $this->submit('/projects/ecommerce/wiki/Checkout_flow/protect', ['protected' => '1']);
        self::assertTrue($this->page('Checkout_flow')->protected);
        $this->submit('/projects/ecommerce/wiki/Checkout_flow/protect', ['protected' => '0']);
        self::assertFalse($this->page('Checkout_flow')->protected);

        // destroying a page with children asks what to do
        $this->submit('/projects/ecommerce/wiki/Wiki', ['_method' => 'DELETE']);
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('name="todo"', (string) $this->client->getResponse()->getContent());
        self::assertSame(302, $this->submit('/projects/ecommerce/wiki/Wiki', ['_method' => 'DELETE', 'todo' => 'nullify']));
        self::assertNull($this->page('Wiki'));
        $child = $this->page('Checkout_flow');
        self::assertNull($child->parent);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wiki_content_versions WHERE page_id = 1'));
    }

    public function testDestroyVersion(): void
    {
        $this->login('jsmith');
        $this->submit('/projects/ecommerce/wiki/Checkout_flow/2', ['_method' => 'DELETE']);
        self::assertSame(302, $this->httpStatus());
        $page = $this->page('Checkout_flow');
        self::assertSame(1, $page->content->version);
        self::assertSame('Version 1', $page->content->text);
    }

    public function testApi(): void
    {
        $data = $this->api('GET', '/projects/ecommerce/wiki/index.json');
        self::assertSame(200, $this->httpStatus());
        $titles = array_column($data['wiki_pages'], 'title');
        self::assertSame(['Checkout_flow', 'Wiki'], $titles);
        self::assertSame(['title' => 'Wiki'], $data['wiki_pages'][0]['parent']);

        $data = $this->api('GET', '/projects/ecommerce/wiki/Checkout_flow.json?include=attachments');
        self::assertSame('Version 2 with more text', $data['wiki_page']['text']);
        self::assertSame(2, $data['wiki_page']['version']);
        self::assertSame([], $data['wiki_page']['attachments']);
        $data = $this->api('GET', '/projects/ecommerce/wiki/Checkout_flow/1.json');
        self::assertSame('Version 1', $data['wiki_page']['text']);

        // create
        $data = $this->api('PUT', '/projects/ecommerce/wiki/Api_page.json', ['wiki_page' => ['text' => 'API text', 'comments' => 'via api']]);
        self::assertSame(201, $this->httpStatus());
        self::assertSame('Api_page', $data['wiki_page']['title']);
        // update
        $this->api('PUT', '/projects/ecommerce/wiki/Api_page.json', ['wiki_page' => ['text' => 'API text 2', 'version' => 1]]);
        self::assertSame(204, $this->httpStatus());
        // conflict
        $this->api('PUT', '/projects/ecommerce/wiki/Api_page.json', ['wiki_page' => ['text' => 'API text 3', 'version' => 1]]);
        self::assertSame(409, $this->httpStatus());
        // xml
        $this->client->request('GET', '/projects/ecommerce/wiki/Api_page.xml', [], [], ['HTTP_X_REDMINE_API_KEY' => self::JSMITH_KEY]);
        self::assertStringContainsString('<wiki_page><title>Api_page</title>', (string) $this->client->getResponse()->getContent());
        // delete
        $this->api('DELETE', '/projects/ecommerce/wiki/Api_page.json');
        self::assertSame(204, $this->httpStatus());
        self::assertNull($this->page('Api_page'));
        $this->api('GET', '/projects/ecommerce/wiki/Api_page.json');
        self::assertSame(404, $this->httpStatus());
    }

    public function testExport(): void
    {
        $this->login('jsmith');
        $html = $this->get('/projects/ecommerce/wiki/export');
        self::assertStringContainsString('<a name="Checkout_flow" />', $html);
        self::assertStringContainsString('href="#Checkout_flow"', $html);
        $this->client->request('GET', '/projects/ecommerce/wiki/export.zip');
        self::assertSame('application/zip', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testMacrosAndToc(): void
    {
        $this->login('jsmith');
        $this->submit('/projects/ecommerce/wiki/Macros', ['_method' => 'PUT', 'content' => ['text' => "{{toc}}\n\n# Main\n\n## Sub\n\n{{child_pages(Wiki)}}\n\n{{include(Checkout_flow)}}\n\n{{recent_pages}}\n\n{{include(Nope)}}"]]);
        $html = $this->get('/projects/ecommerce/wiki/Macros');
        self::assertStringContainsString('<ul class="toc"><li><strong>Table of contents</strong></li><li><a href="#Main">Main</a><ul><li><a href="#Sub">Sub</a></li></ul></li></ul>', $html);
        self::assertStringContainsString('<a name="Main"></a>', $html);
        self::assertStringContainsString('<a href="/projects/ecommerce/wiki/Checkout_flow">Checkout flow</a>', $html);
        self::assertStringContainsString('Version 2 with more text', $html);
        self::assertStringContainsString('Error executing the <strong>include</strong> macro (Page not found)', $html);
    }

    public function testDiffHelperMatchesRedmine(): void
    {
        self::assertSame("a b <span class=\"diff_in\">X</span> <span class=\"diff_out\">c</span> d \n <span class=\"diff_in\">new</span> <span class=\"diff_out\">old</span> line <span class=\"diff_in\">here</span>", WikiDiff::html("a b X d\nnew line here", "a b c d\nold line"));
        self::assertSame('<span class="diff_out">zero</span> one <span class="diff_in">two</span> three <span class="diff_out">four</span>', WikiDiff::html('one two three', 'zero one three four'));
    }

    public function testGzipCompressedVersionsAreReadable(): void
    {
        $svc = static::getContainer()->get(WikiService::class);
        $v = new WikiContentVersion();
        $v->data = gzcompress('compressed text', 9);
        $v->compression = 'gzip';
        self::assertSame('compressed text', $svc->versionText($v));
    }

    public function testDestroyWiki(): void
    {
        $this->login('admin');
        self::assertSame(200, $this->submit('/projects/ecommerce/wiki/destroy', [], 'GET'));
        self::assertSame(302, $this->submit('/projects/ecommerce/wiki/destroy', ['confirm' => '1']));
        $this->em->clear();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wiki_pages WHERE wiki_id = 1'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wikis WHERE project_id = 1'));
    }
}
