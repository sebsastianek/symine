<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * Port of api_test/search_test.rb, plus visibility of private issues/projects/notes per user.
 */
final class SearchApiTest extends ApiTestCase
{
    public function testXmlAndJsonContent(): void
    {
        $xml = $this->xml('GET', '/search.xml', null, null);
        $this->assertXmlResponse();
        self::assertSame('results', $xml->getName());
        $data = $this->json('GET', '/search.json', null, null);
        $this->assertJsonResponse();
        self::assertIsArray($data['results']);
    }

    public function testWithoutQueryStringReturnsNothing(): void
    {
        $xml = $this->xml('GET', '/search.xml?q=&all_words=');
        $this->assertStatus(200);
        self::assertCount(0, $xml->result);
    }

    public function testResultsXml(): void
    {
        $xml = $this->xml('GET', '/search.xml?q=wishlist+emails&all_words=1');
        $this->assertStatus(200);
        self::assertSame('array', (string) $xml['type']);
        self::assertCount(1, $xml->result);
        $r = $xml->result[0];
        self::assertSame('16', (string) $r->id);
        self::assertSame('Feature #16 (New): Wishlist emails', (string) $r->title);
        self::assertSame('issue', (string) $r->type);
        self::assertSame('http://localhost/issues/16', (string) $r->url);
        self::assertSame('', (string) $r->description);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $r->datetime);
    }

    public function testResultsJson(): void
    {
        $data = $this->json('GET', '/search.json?q=wishlist');
        self::assertSame([16, 15, 14], self::ids($data['results']));
        $this->assertPaging($data, 3, 0, 25);
        self::assertSame(['id', 'title', 'type', 'url', 'description', 'datetime'], array_keys($data['results'][0]));
        self::assertNull($data['results'][0]['description']);

        $data = $this->json('GET', '/search.json?q=cart&issues=1');
        self::assertSame('Bug #13 (In Progress): Cart total is wrong with discounts', $data['results'][0]['title']);
        self::assertStringContainsString('Apply coupon', $data['results'][0]['description']);
    }

    public function testPaginate(): void
    {
        $data = $this->json('GET', '/search.json?q=wishlist&limit=1&offset=1');
        self::assertSame([15], self::ids($data['results']));
        $this->assertPaging($data, 3, 1, 1);
        $data = $this->json('GET', '/search.json?q=wishlist&limit=2&offset=2');
        self::assertSame([14], self::ids($data['results']));
        $this->assertPaging($data, 3, 2, 2);
    }

    public function testDoesNotQuickJumpToIssue(): void
    {
        $this->request('GET', '/search.xml?q=13');
        $this->assertStatus(200);
        $this->request('GET', '/search.json?q=%2313');
        $this->assertStatus(200);
    }

    public function testOtherObjectTypes(): void
    {
        $data = $this->json('GET', '/search.json?q=release&news=1');
        self::assertSame([['id' => 1, 'title' => 'Release 0.9', 'type' => 'news', 'url' => 'http://localhost/news/1', 'description' => 'Details...']], array_map(static fn ($r) => array_diff_key($r, ['datetime' => 1]), $data['results']));

        $data = $this->json('GET', '/search.json?q=hello&messages=1');
        self::assertSame([['reply', 2, 'General: RE: Hello', 'http://localhost/boards/1/topics/1?r=2#message-2'], ['message', 1, 'General: Hello', 'http://localhost/boards/1/topics/1']],
            array_map(static fn ($r) => [$r['type'], $r['id'], $r['title'], $r['url']], $data['results']));

        $data = $this->json('GET', '/search.json?q=checkout&wiki_pages=1');
        self::assertSame([['wiki-page', 2, 'Wiki: Checkout_flow'], ['wiki-page', 1, 'Wiki: Wiki']], array_map(static fn ($r) => [$r['type'], $r['id'], $r['title']], $data['results']));
        self::assertSame('http://localhost/projects/ecommerce/wiki/Checkout_flow', $data['results'][0]['url']);
    }

    public function testTitlesOnlyAndScopes(): void
    {
        self::assertSame([], $this->json('GET', '/search.json?q=coupon&titles_only=1')['results']);
        self::assertSame([13], self::ids($this->json('GET', '/search.json?q=coupon')['results']));
        self::assertSame([13], self::ids($this->json('GET', '/projects/ecommerce/search.json?q=coupon')['results']));
        self::assertSame([], $this->json('GET', '/projects/checkout/search.json?q=coupon&scope=subprojects')['results']);
        self::assertSame([], $this->json('GET', '/search.json?q=coupon&news=1')['results']);
    }

    /** @return iterable<string, array{0: ?string, 1: string, 2: list<int>}> */
    public static function privateProvider(): iterable
    {
        yield 'private project, anonymous' => [null, 'crash', []];
        yield 'private project, anna' => ['anna', 'crash', []];
        yield 'private project, rhill' => ['rhill', 'crash', []];
        yield 'private project, member' => ['jsmith', 'crash', [18]];
        yield 'private project, admin' => ['admin', 'crash', [18]];
        yield 'private issue, anonymous' => [null, 'safari', []];
        yield 'private issue, dlopper' => ['dlopper', 'safari', []];
        yield 'private issue, author' => ['rhill', 'safari', [17]];
        yield 'private issue, assignee' => ['jsmith', 'safari', [17]];
        yield 'private notes, anonymous' => [null, 'working', []];
        yield 'private notes, rhill' => ['rhill', 'working', []];
        yield 'private notes, dlopper' => ['dlopper', 'working', [13]];
        yield 'private notes, jsmith' => ['jsmith', 'working', [13]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('privateProvider')]
    public function testPrivateDataIsExcluded(?string $as, string $q, array $ids): void
    {
        $data = $this->json('GET', '/search.json?q='.$q, null, $as);
        $this->assertStatus(200);
        self::assertSame($ids, self::ids($data['results']));
        self::assertSame(\count($ids), $data['total_count']);
    }

    public function testPrivateProjectScope(): void
    {
        $this->request('GET', '/projects/internal/search.json?q=crash', null, null);
        $this->assertStatus(401);
        $this->request('GET', '/projects/internal/search.json?q=crash', null, 'anna');
        $this->assertStatus(403);
        $data = $this->json('GET', '/projects/internal/search.json?q=crash', null, 'jsmith');
        self::assertSame([18], self::ids($data['results']));
    }
}
