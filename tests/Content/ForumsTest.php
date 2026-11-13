<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Entity\Board;
use App\Entity\Message;
use App\Event\ContentEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/** Forums (boards + messages): pages, counters, CRUD flows, permissions */
final class ForumsTest extends ContentWebTestCase
{
    private function board(int $id): array
    {
        return $this->em->getConnection()->fetchAssociative('SELECT * FROM boards WHERE id = ?', [$id]) ?: [];
    }

    private function message(int $id): array|false
    {
        return $this->em->getConnection()->fetchAssociative('SELECT * FROM messages WHERE id = ?', [$id]);
    }

    public function testPagesOkForAdminAndJsmith(): void
    {
        foreach (['admin', 'jsmith'] as $login) {
            $this->login($login);
            $this->assertPagesOk([
                '/projects/ecommerce/boards',
                '/projects/ecommerce/boards/1',
                '/projects/ecommerce/boards/1?sort=replies:desc',
                '/projects/ecommerce/boards/1.atom',
                '/boards/1/topics/1',
                '/boards/1/topics/1?r=2',
                '/boards/1/topics/new',
                '/boards/1/topics/1/edit',
                '/boards/1/topics/2/edit',
                '/projects/ecommerce/boards/new',
                '/projects/ecommerce/boards/1/edit',
            ]);
        }
    }

    public function testBoardShowMarkup(): void
    {
        $this->login('jsmith');
        $html = $this->get('/projects/ecommerce/boards/1');
        self::assertStringContainsString('<table class="list messages">', $html);
        self::assertStringContainsString('<tr id="message-1" class="message', $html);
        self::assertStringContainsString('href="/boards/1/topics/1?r=2#message-2"', $html);
        self::assertStringContainsString('<td class="reply-count">1</td>', $html);
        $html = $this->get('/boards/1/topics/1');
        self::assertStringContainsString('<div id="message_topic_wiki" class="wiki"', $html);
        self::assertStringContainsString('id="message-2"', $html);
        self::assertStringContainsString('action="/boards/1/topics/1/replies"', $html);
    }

    public function testAnonymousCannotPostButCanRead(): void
    {
        $this->client->request('GET', '/boards/1/topics/1');
        self::assertSame(200, $this->httpStatus());
        $this->client->request('GET', '/boards/1/topics/1/edit');
        self::assertSame(302, $this->httpStatus());
        $this->client->request('GET', '/projects/ecommerce/boards/new');
        self::assertSame(302, $this->httpStatus());
    }

    public function testNonEditorCannotEditOthersMessage(): void
    {
        $this->login('rhill'); // Reporter: edit_own_messages only
        $this->client->request('GET', '/boards/1/topics/1/edit'); // jsmith's topic
        self::assertSame(403, $this->httpStatus());
        $this->client->request('GET', '/boards/1/topics/2/edit'); // own reply
        self::assertSame(200, $this->httpStatus());
    }

    public function testCreateTopicReplyEditDeleteMaintainsCounters(): void
    {
        $events = [];
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(ContentEvent::class, static function (ContentEvent $e) use (&$events) {
            $events[] = $e->type.':'.$e->action;
        });
        $this->login('jsmith');
        $code = $this->submit('/boards/1/topics/new', ['message' => ['subject' => 'New topic', 'content' => 'Body', 'sticky' => '1', 'locked' => '0']]);
        self::assertSame(302, $code);
        $topicId = (int) $this->em->getConnection()->fetchOne("SELECT id FROM messages WHERE subject = 'New topic'");
        self::assertStringEndsWith('/boards/1/topics/'.$topicId, $this->location());
        $b = $this->board(1);
        self::assertSame(2, (int) $b['topics_count']);
        self::assertSame(3, (int) $b['messages_count']);
        self::assertSame($topicId, (int) $b['last_message_id']);
        self::assertSame(1, (int) $this->message($topicId)['sticky']);
        // author added as watcher of the topic
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM watchers WHERE watchable_type = 'Message' AND watchable_id = ? AND user_id = 5", [$topicId]));

        // sticky topic listed first
        $html = $this->get('/projects/ecommerce/boards/1?sort=created_on');
        self::assertLessThan(strpos($html, 'id="message-1"'), strpos($html, 'id="message-'.$topicId.'"'));

        // reply
        $code = $this->submit('/boards/1/topics/'.$topicId.'/replies', ['reply' => ['subject' => 'RE: New topic', 'content' => 'A reply']]);
        self::assertSame(302, $code);
        $replyId = (int) $this->em->getConnection()->fetchOne("SELECT id FROM messages WHERE content = 'A reply'");
        self::assertStringContainsString('r='.$replyId, $this->location());
        $t = $this->message($topicId);
        self::assertSame(1, (int) $t['replies_count']);
        self::assertSame($replyId, (int) $t['last_reply_id']);
        $b = $this->board(1);
        self::assertSame(2, (int) $b['topics_count']);
        self::assertSame(4, (int) $b['messages_count']);
        self::assertSame($replyId, (int) $b['last_message_id']);

        // edit the reply
        $code = $this->submit('/boards/1/topics/'.$replyId.'/edit', ['message' => ['subject' => 'RE: edited', 'content' => 'Edited reply']]);
        self::assertSame(302, $code);
        self::assertSame('Edited reply', $this->message($replyId)['content']);

        // quote
        $this->client->request('POST', '/boards/1/topics/quote/'.$replyId.'.js', ['_token' => $this->csrf()]);
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('wrote in message#'.$replyId, (string) $this->client->getResponse()->getContent());

        // delete the reply
        $code = $this->submit('/boards/1/topics/'.$replyId.'/destroy');
        self::assertSame(302, $code);
        self::assertFalse($this->message($replyId));
        $t = $this->message($topicId);
        self::assertSame(0, (int) $t['replies_count']);
        self::assertNull($t['last_reply_id']);
        self::assertSame(3, (int) $this->board(1)['messages_count']);

        // delete topic 1 with its reply
        $code = $this->submit('/boards/1/topics/1/destroy');
        self::assertSame(302, $code);
        self::assertStringEndsWith('/projects/ecommerce/boards/1', $this->location());
        self::assertFalse($this->message(1));
        self::assertFalse($this->message(2));
        $b = $this->board(1);
        self::assertSame(1, (int) $b['topics_count']);
        self::assertSame(1, (int) $b['messages_count']);
        self::assertSame($topicId, (int) $b['last_message_id']);

        self::assertContains('message:created', $events);
        self::assertContains('message:updated', $events);
        self::assertContains('message:deleted', $events);
    }

    public function testReplyToLockedTopicIsRejected(): void
    {
        $this->em->getConnection()->executeStatement('UPDATE messages SET locked = 1 WHERE id = 1');
        $this->login('jsmith');
        $html = $this->get('/boards/1/topics/1');
        self::assertStringNotContainsString('id="reply"', $html);
        $this->submit('/boards/1/topics/1/replies', ['reply' => ['subject' => 'RE', 'content' => 'locked?']]);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM messages WHERE content = 'locked?'"));
    }

    public function testInvalidTopicShowsErrors(): void
    {
        $this->login('jsmith');
        $code = $this->submit('/boards/1/topics/new', ['message' => ['subject' => '', 'content' => '']]);
        self::assertSame(422, $code);
        self::assertStringContainsString('errorExplanation', (string) $this->client->getResponse()->getContent());
    }

    public function testBoardCrudPositionsAndMoveTopic(): void
    {
        $this->login('jsmith');
        self::assertSame(302, $this->submit('/projects/ecommerce/boards', ['board' => ['name' => 'Second', 'description' => 'Second board']]));
        self::assertSame(302, $this->submit('/projects/ecommerce/boards', ['board' => ['name' => 'Third', 'description' => 'Third board']]));
        $conn = $this->em->getConnection();
        $second = (int) $conn->fetchOne("SELECT id FROM boards WHERE name = 'Second'");
        $third = (int) $conn->fetchOne("SELECT id FROM boards WHERE name = 'Third'");
        self::assertSame(2, (int) $this->board($second)['position']);
        self::assertSame(3, (int) $this->board($third)['position']);

        // invalid board
        self::assertSame(422, $this->submit('/projects/ecommerce/boards', ['board' => ['name' => '', 'description' => '']]));

        // reorder: move Third to first position
        $this->client->request('PUT', '/projects/ecommerce/boards/'.$third, ['board' => ['position' => '1'], '_token' => $this->csrf()], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(1, (int) $this->board($third)['position']);
        self::assertSame(2, (int) $this->board(1)['position']);
        self::assertSame(3, (int) $this->board($second)['position']);

        // parent board
        self::assertSame(302, $this->submit('/projects/ecommerce/boards/'.$second, ['board' => ['parent_id' => (string) $third]], 'PATCH'));
        self::assertSame($third, (int) $this->board($second)['parent_id']);
        // a board cannot be its own descendant's child
        self::assertSame(422, $this->submit('/projects/ecommerce/boards/'.$third, ['board' => ['parent_id' => (string) $second]], 'PATCH'));

        // index lists the tree
        $html = $this->get('/projects/ecommerce/boards');
        self::assertStringContainsString('board idnt idnt-1', $html);

        // move topic 1 (with its reply) to board Second
        self::assertSame(302, $this->submit('/boards/1/topics/1/edit', ['message' => ['subject' => 'Hello', 'content' => 'First post', 'board_id' => (string) $second]]));
        self::assertSame($second, (int) $this->message(2)['board_id']);
        $b = $this->board($second);
        self::assertSame(1, (int) $b['topics_count']);
        self::assertSame(2, (int) $b['messages_count']);
        self::assertSame(2, (int) $b['last_message_id']);
        $b1 = $this->board(1);
        self::assertSame(0, (int) $b1['topics_count']);
        self::assertNull($b1['last_message_id']);

        // destroy board Third: children nullified
        self::assertSame(302, $this->submit('/projects/ecommerce/boards/'.$third, [], 'DELETE'));
        self::assertEmpty($this->board($third));
        self::assertNull($this->board($second)['parent_id']);
        // destroy board Second with its messages
        self::assertSame(302, $this->submit('/projects/ecommerce/boards/'.$second, [], 'DELETE'));
        self::assertFalse($this->message(1));
        self::assertSame(1, (int) $this->board(1)['position']);
        self::assertNotNull($this->em->find(Board::class, 1));
        self::assertNull($this->em->getRepository(Message::class)->find(2));
    }

    public function testPreview(): void
    {
        $this->login('jsmith');
        $this->client->request('POST', '/boards/1/topics/preview', ['text' => '**bold**', '_token' => $this->csrf()]);
        self::assertStringContainsString('<strong>bold</strong>', (string) $this->client->getResponse()->getContent());
    }

    public function testSettingsTabPartialRenders(): void
    {
        $this->login('jsmith');
        $this->submit('/projects/ecommerce/boards', ['board' => ['name' => 'Child', 'description' => 'Child board', 'parent_id' => '1']]);
        $this->get('/projects/ecommerce/boards/1'); // sets up the request context / current user
        $project = $this->em->getRepository(\App\Entity\Project::class)->findOneBy(['identifier' => 'ecommerce']);
        $html = static::getContainer()->get('twig')->render('boards/_settings_tab.html.twig', ['project' => $project]);
        self::assertStringContainsString('<div class="table-list boards">', $html);
        self::assertStringContainsString('padding-left: 18px', $html);
        self::assertStringContainsString('data-reorder-param="board"', $html);
    }
}
