<?php

declare(strict_types=1);

namespace App\Tests\Notifications;

use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Document;
use App\Entity\Message;
use App\Entity\News;
use App\Entity\Token;
use App\Entity\WikiPage;
use App\Event\ContentEvent;
use App\Mailer\NotificationMailer;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ContentNotificationTest extends NotificationTestCase
{
    private function dispatch(ContentEvent $e): void
    {
        $this->service(EventDispatcherInterface::class)->dispatch($e);
    }

    private function enableAll(): void
    {
        $this->settings()->set('notified_events', ['issue_added', 'issue_updated', 'news_added', 'news_comment_added', 'message_posted', 'wiki_content_added', 'wiki_content_updated', 'document_added', 'file_added']);
        $this->em->flush();
    }

    public function testNewsAdded(): void
    {
        $this->enableAll();
        $news = $this->em->find(News::class, 1); // author jsmith (no_self_notified)
        $this->dispatch(new ContentEvent('news', 'created', $news, $this->user('jsmith')));
        $emails = $this->emailsByRecipient();
        self::assertSame(['dlopper@example.net', 'rhill@example.net'], array_keys($emails));
        $e = $emails['rhill@example.net'];
        self::assertSame('[eCommerce] News: '.$news->title, $e->getSubject());
        self::assertSame('ecommerce', $this->header($e, 'X-Redmine-Project'));
        self::assertMatchesRegularExpression('/^<redmine\.news-1\.\d{14}\.7@example\.net>$/', $this->header($e, 'Message-ID'));
        self::assertStringContainsString('http://localhost:3000/news/1', (string) $e->getTextBody());
    }

    public function testNewsAddedNotSentWhenEventDisabled(): void
    {
        $this->dispatch(new ContentEvent('news', 'created', $this->em->find(News::class, 1), $this->user('jsmith')));
        self::assertSame([], $this->emails());
    }

    public function testNewsCommentAdded(): void
    {
        $this->enableAll();
        $c = new Comment();
        $c->commentedType = 'News';
        $c->commentedId = 1;
        $c->author = $this->user('dlopper');
        $c->content = 'Great *news*';
        $this->em->persist($c);
        $this->em->flush();
        $this->dispatch(new ContentEvent('news_comment', 'created', $c, $this->user('dlopper')));
        $emails = $this->emailsByRecipient();
        self::assertSame(['jsmith@example.net', 'rhill@example.net'], array_keys($emails));
        self::assertStringStartsWith('Re: [eCommerce] News: ', $emails['jsmith@example.net']->getSubject());
        self::assertStringContainsString('Dave Lopper wrote:', (string) $emails['jsmith@example.net']->getTextBody());
        self::assertMatchesRegularExpression('/^<redmine\.news-1\.\d{14}\.5@example\.net>$/', $this->header($emails['jsmith@example.net'], 'References'));
    }

    public function testMessagePostedToRootWatchers(): void
    {
        $this->enableAll();
        $reply = $this->em->find(Message::class, 2); // reply of rhill to message 1 (watched by jsmith and rhill)
        $this->dispatch(new ContentEvent('message', 'created', $reply, $this->user('rhill')));
        $emails = $this->emailsByRecipient();
        self::assertSame(['jsmith@example.net'], array_keys($emails));
        $e = $emails['jsmith@example.net'];
        self::assertSame('[eCommerce - General - msg1] '.$reply->subject, $e->getSubject());
        self::assertSame('1', $this->header($e, 'X-Redmine-Topic-Id'));
        self::assertStringContainsString('/boards/1/topics/1?r=2#message-2', (string) $e->getTextBody());
    }

    public function testWikiContentUpdatedAndAdded(): void
    {
        $this->enableAll();
        $rhill = $this->user('rhill');
        $rhill->mailNotification = 'all';
        $this->em->flush();
        $page = $this->em->find(WikiPage::class, 2);
        $this->dispatch(new ContentEvent('wiki_page', 'updated', $page, $this->user('jsmith'), ['content' => $page->content]));
        $e = $this->emailsByRecipient()['rhill@example.net'];
        self::assertSame("[eCommerce] 'Checkout flow' wiki page has been updated", $e->getSubject());
        self::assertStringContainsString('/projects/ecommerce/wiki/Checkout_flow/diff?version='.$page->content->version, (string) $e->getTextBody());
        self::assertSame((string) $page->id, $this->header($e, 'X-Redmine-Wiki-Page-Id'));

        $this->clearEmails();
        $this->dispatch(new ContentEvent('wiki_page', 'created', $page, $this->user('jsmith')));
        self::assertSame("[eCommerce] 'Checkout flow' wiki page has been added", $this->emails()[0]->getSubject());

        // attachments only: no notification
        $this->clearEmails();
        $this->dispatch(new ContentEvent('wiki_page', 'updated', $page, $this->user('jsmith'), ['attachments' => []]));
        self::assertSame([], $this->emails());
    }

    public function testDocumentAndFilesAdded(): void
    {
        $this->enableAll();
        $rhill = $this->user('rhill');
        $rhill->mailNotification = 'all';
        $this->em->flush();
        $doc = $this->em->find(Document::class, 1);
        $this->dispatch(new ContentEvent('document', 'created', $doc, $this->user('jsmith')));
        $e = $this->emails()[0];
        self::assertSame('[eCommerce] New document: Specs', $e->getSubject());

        $this->clearEmails();
        $a = new Attachment();
        $a->filename = 'release.zip';
        $a->diskFilename = 'x_release.zip';
        $a->author = $this->user('jsmith');
        $a->containerType = 'Project';
        $a->containerId = 1;
        $a->filesize = 10;
        $this->em->persist($a);
        $this->em->flush();
        $project = $this->project('ecommerce');
        $this->dispatch(new ContentEvent('attachment', 'created', $a, $this->user('jsmith'), ['container' => $project, 'attachments' => [$a]]));
        $e = $this->emails()[0];
        self::assertSame('[eCommerce] New file: release.zip', $e->getSubject());
        self::assertStringContainsString("Project: eCommerce\n- release.zip", (string) $e->getTextBody());
        self::assertStringContainsString('/projects/ecommerce/files', (string) $e->getTextBody());
    }

    public function testSecurityNotificationAndAccountMails(): void
    {
        $mailer = $this->service(NotificationMailer::class);
        $jsmith = $this->user('jsmith');
        $mailer->deliverPasswordUpdated($jsmith, $jsmith, '10.0.0.1');
        $e = $this->emails()[0];
        self::assertSame('[Redmine Reference] Security notification', $e->getSubject());
        self::assertSame('jsmith', $this->header($e, 'X-Redmine-Sender'));
        self::assertSame('http://localhost:3000/my/password', $this->header($e, 'X-Redmine-Url'));
        self::assertStringContainsString('Your password has been changed.', (string) $e->getTextBody());
        self::assertStringContainsString('IP address: 10.0.0.1', (string) $e->getTextBody());
        self::assertSame('Redmine Reference', $e->getFrom()[0]->getName());

        $this->clearEmails();
        $token = new Token($jsmith, 'recovery');
        $token->value = 'abc123';
        $mailer->deliverLostPassword($jsmith, $token);
        $e = $this->emails()[0];
        self::assertSame('Your Redmine Reference password', $e->getSubject());
        self::assertStringContainsString('http://localhost:3000/account/lost_password?token=abc123', (string) $e->getTextBody());

        $this->clearEmails();
        $mailer->deliverSettingsUpdated($jsmith, ['login_required', 'rest_api_enabled'], '1.2.3.4');
        $e = $this->emails()[0];
        self::assertSame(['admin@dummy.invalid'], array_map(static fn ($a) => $a->getAddress(), $e->getTo()));
        self::assertStringContainsString('* Authentication required', (string) $e->getTextBody());
    }

    public function testRemindersCommand(): void
    {
        $tomorrow = (new \DateTimeImmutable('+2 days'))->format('Y-m-d');
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE issues SET due_date = NULL');
        $conn->executeStatement('UPDATE issues SET due_date = ? WHERE id IN (13, 17)', [$tomorrow]);

        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('symine:send-reminders'));
        $tester->execute(['--days' => '7']);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $emails = $this->emailsByRecipient();
        // #13 assigned to the group Developers (dlopper), #17 to jsmith
        self::assertSame(['dlopper@example.net', 'jsmith@example.net'], array_keys($emails));
        $e = $emails['dlopper@example.net'];
        self::assertSame('1 issue(s) due in the next 7 days', $e->getSubject());
        self::assertStringContainsString('* eCommerce - Bug #13: Cart total is wrong with discounts (Due in 2 days)', (string) $e->getTextBody());
        self::assertStringContainsString('View all issues', (string) $e->getTextBody());

        $this->clearEmails();
        $tester->execute(['--days' => '7', '--users' => '5', '--project' => 'checkout']);
        self::assertSame(['jsmith@example.net'], array_keys($this->emailsByRecipient()));
        $this->clearEmails();
        $tester->execute(['--days' => '1']);
        self::assertSame([], $this->emails());
    }
}
