<?php

declare(strict_types=1);

namespace App\Tests\Account;

use App\Entity\User;
use App\Service\MyPageService;
use App\Tests\Timelog\TimelogWebTestCase;

final class MyPageTest extends TimelogWebTestCase
{
    private function layout(string $login): array
    {
        $this->em->clear();

        return static::getContainer()->get(MyPageService::class)->layout($this->user($login));
    }

    public function testDefaultPage(): void
    {
        foreach (['admin', 'jsmith', 'dlopper', 'anna'] as $login) {
            $this->login($login);
            self::assertStringEndsWith('/my/page', $this->location(), $login);
            $html = $this->get('/my/page');
            self::assertSame(200, $this->code(), $login);
            self::assertStringContainsString('id="block-issuesassignedtome"', $html);
            self::assertStringContainsString('id="block-issuesreportedbyme"', $html);
        }
        $this->login('jsmith');
        $html = $this->get('/my');
        self::assertMatchesRegularExpression('/Issues assigned to me<\/a>\s*\(1\)/', $html);
        self::assertMatchesRegularExpression('/Reported issues<\/a>\s*\(4\)/', $html);
        self::assertStringContainsString('id="issue-17"', $html);
    }

    public function testAllBlocksRender(): void
    {
        $this->login('jsmith');
        foreach (['timelog', 'calendar', 'news', 'documents', 'activity', 'issueswatched', 'issuesupdatedbyme', 'issuequery'] as $block) {
            $this->submit('/my/add_block', ['block' => $block]);
            self::assertSame(302, $this->code(), $block);
        }
        $html = $this->get('/my/page');
        self::assertSame(200, $this->code());
        foreach (['timelog', 'calendar', 'news', 'documents', 'activity', 'issueswatched', 'issuesupdatedbyme', 'issuequery'] as $block) {
            self::assertStringContainsString('id="block-'.$block.'"', $html);
        }
        self::assertStringContainsString('Meeting', $html); // jsmith's time entry in the timelog block
        // issuequery block: query selection then a second instance
        self::assertStringContainsString('settings[issuequery][query_id]', $html);
        $this->submit('/my/add_block', ['block' => 'issuequery__1']);
        self::assertContains('issuequery__1', $this->layout('jsmith')['top']);
        // block already present: 422
        $this->submit('/my/add_block', ['block' => 'timelog']);
        self::assertSame(422, $this->code());
        $this->submit('/my/add_block', ['block' => 'unknown']);
        self::assertSame(422, $this->code());
    }

    public function testAddRemoveOrderAndSettings(): void
    {
        $this->login('jsmith');
        $this->submit('/my/add_block', ['block' => 'news'], 'POST', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertSame(200, $this->code());
        self::assertStringContainsString('$("#list-top").prepend(', $this->content());
        self::assertSame(['news'], $this->layout('jsmith')['top']);

        $this->submit('/my/order_blocks', ['group' => 'left', 'blocks' => ['news', 'issuesassignedtome']]);
        self::assertSame(200, $this->code());
        $layout = $this->layout('jsmith');
        self::assertSame([], $layout['top']);
        self::assertSame(['news', 'issuesassignedtome'], $layout['left']);

        $this->submit('/my/remove_block', ['block' => 'news']);
        self::assertSame(['issuesassignedtome'], $this->layout('jsmith')['left']);

        $this->submit('/my/add_block', ['block' => 'timelog']);
        $this->submit('/my/page', ['settings' => ['timelog' => ['days' => '30']]], 'POST', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertSame(200, $this->code());
        self::assertStringContainsString('$("#block-timelog").replaceWith(', $this->content());
        self::assertStringContainsString('last 30 days', $this->content());
        $this->submit('/my/page', ['settings' => ['issuesassignedtome' => ['columns' => ['project', 'priority', 'subject'], 'sort' => 'subject']]]);
        self::assertSame(302, $this->code());
        $this->em->clear();
        $settings = $this->user('jsmith')->getPref()->get('my_page_settings');
        self::assertSame(['days' => '30'], $settings['timelog']);
        self::assertSame(['project', 'priority', 'subject'], $settings['issuesassignedtome']['columns']);
        $html = $this->get('/my/page');
        self::assertStringContainsString('<th class="priority">', $html);
        // settings of removed blocks are cleared on save
        $this->submit('/my/remove_block', ['block' => 'timelog']);
        $this->em->clear();
        self::assertArrayNotHasKey('timelog', $this->user('jsmith')->getPref()->get('my_page_settings'));
    }

    public function testLoginRequired(): void
    {
        $this->client->request('GET', '/my/page');
        self::assertSame(302, $this->code());
        self::assertStringContainsString('/login', $this->location());
        self::assertInstanceOf(User::class, $this->user('jsmith'));
    }
}
