<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Entity\Comment;
use App\Entity\News;
use App\Entity\Watcher;

final class NewsTest extends ContentWebTestCase
{
    public function testPagesForAdminAndJsmith(): void
    {
        foreach (['admin', 'jsmith'] as $login) {
            $this->login($login);
            $this->assertPagesOk(['/projects/ecommerce/news', '/news/1', '/news/1/edit', '/projects/ecommerce/news/new', '/news/new', '/news', '/news.atom', '/projects/ecommerce/news.atom']);
        }
        $html = $this->get('/news/1');
        self::assertStringContainsString('Release 0.9', $html);
        self::assertStringContainsString('Great!', $html);
        self::assertStringContainsString('id="add_comment_form"', $html);
    }

    public function testAnonymousCannotManage(): void
    {
        $this->client->request('GET', '/news/1/edit');
        self::assertSame(302, $this->httpStatus());
        $this->client->request('GET', '/projects/ecommerce/news');
        self::assertSame(200, $this->httpStatus());
        self::assertStringNotContainsString('add-news-link', (string) $this->client->getResponse()->getContent());
    }

    public function testCreateUpdateCommentDelete(): void
    {
        $jsmith = $this->login('jsmith');
        // validation error
        self::assertSame(422, $this->submit('/projects/ecommerce/news', ['news' => ['title' => '', 'description' => '']]));
        self::assertStringContainsString('errorExplanation', (string) $this->client->getResponse()->getContent());

        self::assertSame(302, $this->submit('/projects/ecommerce/news', ['news' => ['title' => 'Big release', 'summary' => 'Sum', 'description' => 'Body *text*']]));
        self::assertSame('/projects/ecommerce/news', $this->location());
        $news = $this->em->getRepository(News::class)->findOneBy(['title' => 'Big release']);
        self::assertNotNull($news);
        self::assertSame($jsmith->id, $news->author->id);
        self::assertNotNull($this->em->getRepository(Watcher::class)->findOneBy(['watchableType' => 'News', 'watchableId' => $news->id]), 'author watches the news');

        self::assertSame(302, $this->submit('/news/'.$news->id, ['_method' => 'PUT', 'news' => ['title' => 'Bigger release']]));
        $this->em->clear();
        self::assertSame('Bigger release', $this->em->find(News::class, $news->id)->title);

        // comments
        self::assertSame(302, $this->submit('/news/'.$news->id.'/comments', ['comment' => ['comments' => 'Nice one']]));
        $this->em->clear();
        self::assertSame(1, $this->em->find(News::class, $news->id)->commentsCount);
        $comment = $this->em->getRepository(Comment::class)->findOneBy(['commentedId' => $news->id, 'commentedType' => 'News']);
        self::assertSame('Nice one', $comment->content);
        self::assertSame(302, $this->submit('/news/'.$news->id.'/comments/'.$comment->id, ['_method' => 'DELETE']));
        $this->em->clear();
        self::assertSame(0, $this->em->find(News::class, $news->id)->commentsCount);

        self::assertSame(302, $this->submit('/news/'.$news->id, ['_method' => 'DELETE']));
        $this->em->clear();
        self::assertNull($this->em->find(News::class, $news->id));
    }

    public function testCrossProjectCreate(): void
    {
        $this->login('admin');
        self::assertSame(302, $this->submit('/news', ['project_id' => '1', 'cross_project' => '1', 'news' => ['title' => 'Global', 'description' => 'x']]));
        self::assertSame('/news', $this->location());
    }

    public function testAtom(): void
    {
        $this->login('jsmith');
        $this->client->request('GET', '/projects/ecommerce/news.atom');
        $xml = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<feed xmlns="http://www.w3.org/2005/Atom">', $xml);
        self::assertStringContainsString('<title>Release 0.9</title>', $xml);
        self::assertStringContainsString('eCommerce: News', $xml);
    }

    public function testApi(): void
    {
        $data = $this->api('GET', '/news.json');
        self::assertSame(1, $data['total_count']);
        self::assertSame('Release 0.9', $data['news'][0]['title']);

        $data = $this->api('GET', '/news/1.json?include=comments');
        self::assertSame('Great!', $data['news']['comments'][0]['content']);

        $this->api('POST', '/projects/ecommerce/news.json', ['news' => ['title' => 'API news', 'description' => 'From API']]);
        self::assertSame(204, $this->httpStatus());
        $news = $this->em->getRepository(News::class)->findOneBy(['title' => 'API news']);
        self::assertNotNull($news);

        $data = $this->api('POST', '/projects/ecommerce/news.json', ['news' => ['title' => '']]);
        self::assertSame(422, $this->httpStatus());
        self::assertNotEmpty($data['errors']);

        $this->api('PUT', '/news/'.$news->id.'.json', ['news' => ['summary' => 'Updated']]);
        self::assertSame(204, $this->httpStatus());
        $this->api('DELETE', '/news/'.$news->id.'.json');
        self::assertSame(204, $this->httpStatus());

        $this->client->request('GET', '/news.xml', [], [], ['HTTP_X_REDMINE_API_KEY' => self::JSMITH_KEY]);
        self::assertStringContainsString('<news type="array"', (string) $this->client->getResponse()->getContent());
    }
}
