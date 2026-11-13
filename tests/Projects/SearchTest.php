<?php

declare(strict_types=1);

namespace App\Tests\Projects;

use App\Service\Activity\SearchService;
use App\Twig\ActivityExtension;

final class SearchTest extends ProjectsWebTestCase
{
    public function testSearchPagesRespond(): void
    {
        foreach ([null, 'jsmith', 'admin'] as $login) {
            if ($login) {
                $this->login($login);
            }
            foreach (['/search', '/search?q=wish', '/projects/ecommerce/search?q=cart', '/search?q=checkout&titles_only=1', '/search?q=e&scope=all'] as $url) {
                $this->get($url);
                self::assertSame(200, $this->code(), ($login ?? 'anonymous').' '.$url);
            }
        }
    }

    public function testResultsAndHighlighting(): void
    {
        $html = $this->get('/search?q=wish');
        self::assertStringContainsString('<h3>Results (3)</h3>', $html);
        self::assertStringContainsString('<span class="highlight token-0">Wish</span>list emails', $html);
        self::assertStringContainsString('<span class="items">(1-3/3)</span>', $html);
    }

    public function testAnyWordAndProjectScope(): void
    {
        $html = $this->get('/projects/ecommerce/search?q=cart+wish&all_words=&scope=subprojects');
        self::assertStringContainsString('<h3>Results (4)</h3>', $html);
        self::assertStringContainsString('<option selected="selected" value="subprojects">', $html);
        $html = $this->get('/projects/ecommerce/search?q=cart+wish');
        self::assertStringContainsString('<h3>Results (0)</h3>', $html);
        // projects are not searched within a single project
        self::assertStringNotContainsString('name="projects"', $html);
    }

    public function testPrivateProjectNotFoundByAnonymous(): void
    {
        $this->get('/search?q=internal&scope=all');
        $anon = (string) $this->client->getResponse()->getContent();
        $this->login('admin');
        $admin = $this->get('/search?q=internal&scope=all');
        self::assertStringContainsString('href="/projects/internal"', $admin);
        self::assertStringNotContainsString('href="/projects/internal"', $anon);
    }

    public function testQuickJumpToIssue(): void
    {
        $this->get('/search?q=%2313');
        self::assertSame(302, $this->code());
        self::assertSame('/issues/13', $this->client->getResponse()->headers->get('Location'));
    }

    public function testApi(): void
    {
        $data = $this->api('GET', '/search.json?q=wish&limit=2');
        self::assertSame(200, $this->code());
        self::assertSame(3, $data['total_count']);
        self::assertSame(2, $data['limit']);
        self::assertCount(2, $data['results']);
        self::assertSame(['id', 'title', 'type', 'url', 'description', 'datetime'], array_keys($data['results'][0]));
        self::assertSame('issue', $data['results'][0]['type']);
        $this->client->request('GET', '/search.xml?q=wish&offset=2', [], [], ['HTTP_X_REDMINE_API_KEY' => '3de6a93a7c5f8921fa98e0fecd9ae25695aa7536']);
        self::assertStringContainsString('<results type="array" total_count="3" offset="2" limit="25">', (string) $this->client->getResponse()->getContent());
    }

    public function testTokenizer(): void
    {
        self::assertSame(['hello', 'bye bye'], SearchService::tokens('hello "bye bye" a'));
        self::assertSame(['a1', 'b2', 'c3', 'd4', 'e5'], SearchService::tokens('a1 b2 c3 d4 e5 f6'));
    }

    public function testHighlightTokens(): void
    {
        self::assertSame('<span class="highlight token-1">Wish</span>list &amp; <span class="highlight token-0">cart</span>', ActivityExtension::highlightTokens('Wishlist & cart', ['cart', 'wish']));
    }
}
