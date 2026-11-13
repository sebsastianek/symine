<?php

declare(strict_types=1);

namespace App\Tests\Issues;

/** The issue screens render for admin and project members, with Redmine's markup. */
final class IssueScreensTest extends IssuesWebTestCase
{
    /** @return iterable<array{string, string}> */
    public static function pages(): iterable
    {
        foreach (['admin', 'jsmith'] as $login) {
            foreach (['/issues/13', '/issues/14', '/issues/16', '/issues/13/edit', '/projects/ecommerce/issues/new', '/issues/new',
                '/projects/ecommerce/issues/13/copy', '/issues/bulk_edit?ids[]=13&ids[]=14', '/issues/bulk_edit?ids[]=13&copy=1',
                '/attachments/1', '/attachments/1/notes.txt', '/attachments/issues/13/edit', '/issues/13?tab=history', '/issues/13?tab=time_entries'] as $url) {
                yield "$login $url" => [$login, $url];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function testPageRenders(string $login, string $url): void
    {
        $this->login($login);
        $this->get($url);
        self::assertSame(200, $this->code(), $url.': '.substr((string) $this->client->getResponse()->getContent(), 0, 500));
    }

    public function testShowPageMarkup(): void
    {
        $this->login('jsmith');
        $crawler = $this->client->request('GET', '/issues/13');
        self::assertSame('Bug #13', trim($crawler->filter('#content h2')->first()->text()));
        self::assertSame('Cart total is wrong with discounts', $crawler->filter('.subject h3')->text());
        self::assertSame('In Progress', $crawler->filter('.attributes .status .value')->text());
        self::assertStringContainsString('PostgreSQL', $crawler->filter('.attributes .cf_1 .value')->text());
        self::assertCount(1, $crawler->filter('#issue_description_wiki ol'));
        self::assertCount(2, $crawler->filter('#relations tr.issue'), 'related issues');
        self::assertCount(1, $crawler->filter('#change-15'), 'private journal visible to the manager');
        self::assertCount(1, $crawler->filter('#update form#issue-form'), 'inline edit form');
        self::assertCount(1, $crawler->filter('#watchers'));
        self::assertCount(2, $crawler->filter('a.issue-13-watcher'), 'watch link in both action menus');
        self::assertCount(1, $crawler->filter('div.attachments a[href="/attachments/download/1/notes.txt"]'));
        self::assertStringContainsString('Status</strong> changed from <i>New</i> to <i>In Progress</i>', $crawler->filter('#change-15')->html());
    }

    public function testPrivateNotesHiddenWithoutPermission(): void
    {
        // rhill (Reporter) cannot view private notes written by others
        $this->login('rhill');
        $crawler = $this->client->request('GET', '/issues/13');
        self::assertSame(200, $this->code());
        self::assertCount(0, $crawler->filter('#change-15'));
    }

    public function testPrivateIssueNotVisibleToNonMember(): void
    {
        $this->login('dlopper');
        $this->get('/issues/17');
        self::assertSame(403, $this->code());
        $this->get('/issues/999');
        self::assertSame(404, $this->code());
    }

    public function testAnonymousIsRedirectedToLoginOnPrivateProject(): void
    {
        $this->get('/issues/18');
        self::assertTrue(\in_array($this->code(), [302, 401, 403], true));
    }

    public function testAtomFeed(): void
    {
        $this->login('jsmith');
        $xml = $this->get('/issues/13.atom');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('<feed xmlns="http://www.w3.org/2005/Atom">', $xml);
        self::assertStringContainsString('Bug #13: Cart total is wrong with discounts', $xml);
    }

    public function testAttachmentDownloadAndPreview(): void
    {
        $this->login('jsmith');
        $this->get('/attachments/download/1/notes.txt');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $html = $this->get('/attachments/1');
        self::assertStringContainsString('class="filecontent syntaxhl"', $html);
        $this->get('/attachments/1/wrong-name.txt');
        self::assertSame(404, $this->code());
    }

    public function testFormRefreshReturnsAttributesPartial(): void
    {
        $this->login('jsmith');
        $js = $this->xhr('POST', '/projects/ecommerce/issues/new.js', ['issue' => ['tracker_id' => '2', 'subject' => 'x'], 'form_update_triggered_by' => 'issue_tracker_id']);
        self::assertSame(200, $this->code());
        self::assertStringStartsWith('replaceIssueFormWith(', $js);
        self::assertStringContainsString('issue_status_id', $js);
        $js = $this->xhr('POST', '/issues/13/edit.js', ['issue' => ['status_id' => '3']]);
        self::assertStringStartsWith('replaceIssueFormWith(', $js);
        self::assertStringContainsString('#log_time', $js);
    }

    public function testPreviewAndAutocomplete(): void
    {
        $this->login('jsmith');
        $this->xhr('POST', '/issues/preview?project_id=ecommerce', ['text' => '**bold**']);
        self::assertStringContainsString('<strong>bold</strong>', (string) $this->client->getResponse()->getContent());
        $json = json_decode($this->get('/issues/auto_complete?q=wish&project_id=ecommerce'), true);
        self::assertSame([16, 15, 14], array_column($json, 'id'));
        self::assertSame('Feature #16: Wishlist emails', $json[0]['label']);
        $json = json_decode($this->get('/issues/auto_complete?q=%2313'), true);
        self::assertSame(13, $json[0]['id']);
    }
}
