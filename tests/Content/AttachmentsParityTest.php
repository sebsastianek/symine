<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Journal;
use App\Entity\User;
use App\Event\IssueEvent;
use App\Service\AttachmentService;
use App\Service\Settings;

/**
 * Attachment viewers and side effects, compared with Redmine's AttachmentsController
 * (test/functional/attachments_controller_test.rb) and Issue#attachment_removed.
 */
final class AttachmentsParityTest extends ContentWebTestCase
{
    private const string DIFF = "Index: a.txt\n===================================================================\n--- a.txt\t(revision 1)\n+++ a.txt\t(revision 2)\n@@ -1,3 +1,3 @@\n line one\n-line two\n+line 2\n line three\n";

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
        parent::tearDown();
    }

    private function attach(string $type, int $id, string $filename, string $content, string $mime): Attachment
    {
        $service = static::getContainer()->get(AttachmentService::class);
        $author = $this->em->getRepository(User::class)->findOneBy(['login' => 'admin']);
        $attachment = $service->createFromContent($content, $filename, $author, $mime);
        $service->attachTo([$attachment], $type, $id);
        $this->em->flush();
        $this->files[] = $service->fullPath($attachment);

        return $attachment;
    }

    /** test_show_diff, test_show_diff_replace_cannot_convert_content, test_save_diff_type: .diff and .patch files get the diff viewer */
    public function testDiffAttachmentsAreDisplayedWithTheDiffViewer(): void
    {
        $this->login('jsmith');
        foreach (['change.diff', 'fix.PATCH'] as $filename) {
            $a = $this->attach('Issue', 13, $filename, self::DIFF, 'text/x-diff');
            $html = $this->get('/attachments/'.$a->id);
            self::assertSame(200, $this->httpStatus());
            self::assertStringContainsString('<table class="filecontent diffcontent">', $html, $filename);
            self::assertStringContainsString('<input type="radio" name="type" id="type_inline" value="inline" checked="checked"', $html);
            self::assertStringContainsString('<input type="radio" name="type" id="type_sbs" value="sbs"', $html);
            self::assertMatchesRegularExpression('#<td class="line-code diff_out">\s*<div>line <span>two</span></div>#', $html);
            self::assertMatchesRegularExpression('#<td class="line-code diff_in">\s*<div>line <span>2</span></div>#', $html);
            self::assertStringContainsString('<th colspan="3" class="filename">', $html);
        }

        // side by side, and the choice is remembered (User.current.pref[:diff_type])
        $html = $this->get('/attachments/'.$a->id.'?type=sbs');
        self::assertStringContainsString('<th colspan="4" class="filename">', $html);
        self::assertStringContainsString('id="type_sbs" value="sbs" checked="checked"', $html);
        $html = $this->get('/attachments/'.$a->id);
        self::assertStringContainsString('<th colspan="4" class="filename">', $html);
        $html = $this->get('/attachments/'.$a->id.'?type=bogus');
        self::assertStringContainsString('<th colspan="3" class="filename">', $html);

        // any other text file keeps the plain viewer
        $text = $this->attach('Issue', 13, 'notes2.txt', "plain\ntext\n", 'text/plain');
        $html = $this->get('/attachments/'.$text->id);
        self::assertStringContainsString('<table class="filecontent syntaxhl">', $html);
        self::assertStringNotContainsString('diffcontent', $html);
    }

    /** file.html.erb: a Markdown file is rendered as CommonMark and a Textile file as Textile, whatever the setting is */
    public function testMarkupPreviewsUseTheFormatOfTheFile(): void
    {
        $this->login('jsmith');
        $md = $this->attach('Issue', 13, 'readme.md', "# Title\n\nSome **bold** text and #13\n", 'text/markdown');
        $textile = $this->attach('Issue', 13, 'readme.textile', "h1. Title\n\nSome *bold* text\n", 'text/x-textile');
        foreach (['common_mark', 'textile'] as $setting) {
            static::getContainer()->get(Settings::class)->set('text_formatting', $setting);
            $html = $this->get('/attachments/'.$md->id);
            self::assertStringContainsString('Some <strong>bold</strong> text and <a href="/issues/13"', $html, $setting);
            self::assertMatchesRegularExpression('#<h1>Title<a href="\#Title" class="wiki-anchor">#', $html, $setting);
            $html = $this->get('/attachments/'.$textile->id);
            self::assertStringContainsString('Some <strong>bold</strong> text', $html, $setting);
            self::assertMatchesRegularExpression('#<h1>Title<a href="\#Title" class="wiki-anchor">#', $html, $setting);
        }
    }

    /**
     * Issue#attachment_removed (app/models/issue.rb) saves a journal: Journal#send_notification notifies it and the
     * issue.updated webhook is triggered, i.e. the same event as a normal update of the issue.
     */
    public function testDeletingAnIssueAttachmentIsJournalizedNotifiedAndTriggersTheIssueUpdatedEvent(): void
    {
        $this->login('jsmith');
        $a = $this->attach('Issue', 13, 'obsolete.txt', "bye\n", 'text/plain');
        $id = $a->id;
        $settings = static::getContainer()->get(Settings::class);
        $settings->set('notified_events', array_values(array_unique(array_merge($settings->array('notified_events'), ['issue_updated']))));
        /** @var list<IssueEvent> $events */
        $events = [];
        static::getContainer()->get('event_dispatcher')->addListener(IssueEvent::class, static function (IssueEvent $e) use (&$events): void {
            $events[] = $e;
        }, 100);

        self::assertSame(302, $this->submit('/attachments/'.$id, ['_method' => 'delete'], 'POST'));

        self::assertNull($this->em->getConnection()->fetchOne('SELECT id FROM attachments WHERE id = ?', [$id]) ?: null);
        self::assertCount(1, $events);
        self::assertSame(IssueEvent::UPDATED, $events[0]->action);
        self::assertSame(13, $events[0]->issue->id);
        self::assertTrue($events[0]->webhook);
        self::assertSame('jsmith', $events[0]->user->login);
        $journal = $events[0]->journal;
        self::assertInstanceOf(Journal::class, $journal);
        self::assertNotNull($journal->id);
        $detail = $journal->details->first();
        self::assertSame(['attachment', (string) $id, 'obsolete.txt', null], [$detail->property, $detail->propKey, $detail->oldValue, $detail->value]);
        // the journal is e-mailed like any other update
        $subjects = array_map(static fn ($m): string => (string) $m->getSubject(), array_filter(self::getMailerMessages(), static fn ($m): bool => $m instanceof \Symfony\Component\Mime\Email));
        self::assertNotSame([], $subjects);
        self::assertStringContainsString('#13', $subjects[array_key_first($subjects)]);

        // an attachment of another container deletes without issue event
        $events = [];
        $w = $this->attach('WikiPage', 1, 'page.txt', "x\n", 'text/plain');
        $this->login('admin');
        self::assertSame(302, $this->submit('/attachments/'.$w->id, ['_method' => 'delete'], 'POST'));
        self::assertSame([], $events);
    }

    /** test_download_all_with_maximum_bulk_download_size_larger_than_attachments: the limit is displayed as a size */
    public function testBulkDownloadLimitMessageUsesAHumanSize(): void
    {
        $this->login('jsmith');
        $this->attach('Issue', 13, 'big.bin', str_repeat('x', 3000), 'application/octet-stream');
        static::getContainer()->get(Settings::class)->set('bulk_download_max_size', '1');
        $this->get('/attachments/issues/13/download');
        self::assertSame(302, $this->httpStatus());
        $html = $this->get('/issues/13');
        self::assertStringContainsString('exceeds the maximum allowed size (1 KB)', $html);
    }

    /** Redmine::Reaction::Reactable: has_many :reactions, dependent: :delete_all */
    public function testDeletingANewsCommentDeletesItsReactions(): void
    {
        $user = $this->login('jsmith');
        $comment = $this->em->getRepository(Comment::class)->findOneBy(['commentedType' => 'News', 'commentedId' => 1]);
        self::assertNotNull($comment);
        $conn = $this->em->getConnection();
        foreach ([['Comment', (int) $comment->id], ['News', 1]] as [$type, $id]) {
            $conn->insert('reactions', ['reactable_type' => $type, 'reactable_id' => $id, 'user_id' => $user->id, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        }

        self::assertSame(302, $this->submit('/news/1/comments/'.$comment->id, ['_method' => 'delete'], 'POST'));
        self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM reactions WHERE reactable_type = 'Comment'"));
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM reactions WHERE reactable_type = 'News'"));
    }
}
