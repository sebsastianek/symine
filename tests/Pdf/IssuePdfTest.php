<?php

declare(strict_types=1);

namespace App\Tests\Pdf;

use App\Entity\Issue;
use App\Entity\User;
use App\Service\AttachmentService;

/** GET /issues/:id.pdf (IssuesController#show format.pdf, IssuesPdfHelper#issue_to_pdf) */
final class IssuePdfTest extends PdfWebTestCase
{
    public function testIssuePdfContent(): void
    {
        $this->login('admin');
        $pdf = $this->pdf('/issues/13.pdf', 'ecommerce-13.pdf');
        self::assertPdf($pdf, 1);
        [$w, $h] = self::pageSize($pdf);
        self::assertEqualsWithDelta(595.28, $w, 0.1, 'A4 portrait');
        self::assertEqualsWithDelta(841.89, $h, 0.1);
        self::assertStringContainsString('/Title (eCommerce - Bug #13)', str_replace(["\0", "\xFE\xFF"], '', $pdf));
        self::assertPdfContains($pdf,
            'eCommerce - Bug #13',
            'Cart total is wrong with discounts',
            '01:47 PM - John Smith',
            // attributes table and custom fields
            'Status:', 'In Progress', 'Priority:', 'High', 'Assignee:', 'Developers', 'Target version:', '1.0',
            'Start date:', '11/02/2026', '% Done:', '30%', 'Estimated time:', '8:00 hours', 'Spent time:', '2:30 hours',
            'Database:', 'PostgreSQL', 'Platforms:', 'Linux, macOS',
            // description rendered from text formatting
            'Description', 'Steps:', 'Add item', 'Apply coupon', 'Checkout flow',
            // relations
            'Related issues:', 'Related to Checkout - Bug #17: Payment fails on Safari', 'Blocks eCommerce - Support #19: Customer cannot login', 'Closed',
            // history with details and private notes
            'History', '#1 - 11/07/2026 01:47 PM - Dave Lopper (Private notes)', '- Status changed from New to In Progress', '- % Done changed from 0 to 30', 'Started working on it',
            // attachments
            'Files', 'notes.txt', '12 Bytes',
            // footer
            '1/1',
        );
        self::assertStringNotContainsString('Powered by TCPDF', $pdf);
    }

    public function testSubtasksAndAncestors(): void
    {
        $this->login('admin');
        $pdf = $this->pdf('/issues/14.pdf', 'ecommerce-14.pdf');
        self::assertPdfContains($pdf, 'Subtasks:', 'Feature # 15: Wishlist sharing', 'Feature # 16: Wishlist emails', 'Estimated time:', '0:00 hour', '- Subtask #15 added');
        $pdf = $this->pdf('/issues/15.pdf');
        self::assertPdfContains($pdf, 'Feature # 14 (New): Wishlist', 'Precedes (1 day) eCommerce - Feature #16: Wishlist emails');
    }

    public function testPrivateNotesHiddenWithoutPermission(): void
    {
        // rhill is Reporter in eCommerce: no view_private_notes
        $this->login('rhill');
        $pdf = $this->pdf('/issues/13.pdf');
        self::assertPdfContains($pdf, 'Cart total is wrong with discounts', 'notes.txt');
        self::assertPdfNotContains($pdf, 'History', 'Private notes', 'Started working on it');
    }

    public function testCommentsInReverseOrder(): void
    {
        $user = $this->login('admin');
        $pref = $user->getPref();
        $pref->set('comments_sorting', 'desc');
        if (null === $pref->id) {
            $this->em->persist($pref);
        }
        $this->em->flush();
        $pdf = self::pdfText($this->pdf('/issues/14.pdf'));
        self::assertLessThan(strpos($pdf, '#1 - '), strpos($pdf, '#2 - '));
    }

    public function testDescriptionImagesFromAttachmentsAreEmbedded(): void
    {
        $this->login('admin');
        /** @var AttachmentService $attachments */
        $attachments = static::getContainer()->get(AttachmentService::class);
        $img = imagecreatetruecolor(20, 10);
        imagefilledrectangle($img, 0, 0, 19, 9, (int) imagecolorallocate($img, 255, 0, 0));
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        $admin = $this->em->getRepository(User::class)->findOneBy(['login' => 'admin']);
        $a = $attachments->createFromContent($png, 'pdf-test-picture.png', $admin, 'image/png');
        $attachments->attachTo([$a], 'Issue', 13);
        $issue = $this->em->find(Issue::class, 13);
        $textile = 'textile' === static::getContainer()->get(\App\Service\Settings::class)->textFormatting();
        $issue->description = $textile
            ? "Screenshot:\n\n!pdf-test-picture.png!\n\n!http://example.invalid/remote.png!"
            : "Screenshot:\n\n![](pdf-test-picture.png)\n\n![](http://example.invalid/remote.png)";
        $this->em->flush();
        try {
            $pdf = $this->pdf('/issues/13.pdf');
            self::assertPdfContains($pdf, 'Screenshot:', 'pdf-test-picture.png');
            self::assertSame(1, preg_match_all('#/Subtype\s*/Image#', $pdf), 'only the attachment image is embedded');
        } finally {
            @unlink($attachments->fullPath($a));
        }
    }

    public function testPdfLinkOnIssuePage(): void
    {
        $this->login('jsmith');
        $html = (string) $this->fetch('/issues/13')->getContent();
        self::assertStringContainsString('<a class="pdf" rel="nofollow" href="/issues/13.pdf">PDF</a>', $html);

        $this->removePermission('Manager', 'export_issues');
        $html = (string) $this->fetch('/issues/13')->getContent();
        self::assertStringNotContainsString('/issues/13.pdf', $html);
    }

    public function testPermissions(): void
    {
        // anonymous: public project issue, private project issue (login), unknown issue
        self::assertSame(200, $this->fetch('/issues/13.pdf')->getStatusCode());
        self::assertSame(302, $this->fetch('/issues/18.pdf')->getStatusCode());
        self::assertSame($this->fetch('/issues/18')->getStatusCode(), $this->fetch('/issues/18.pdf')->getStatusCode());
        self::assertSame(404, $this->fetch('/issues/999.pdf')->getStatusCode());

        // logged in without export_issues: 403 (the HTML page is still visible)
        $this->login('jsmith');
        $this->removePermission('Manager', 'export_issues');
        self::assertSame(200, $this->fetch('/issues/13')->getStatusCode());
        self::assertSame(403, $this->fetch('/issues/13.pdf')->getStatusCode());
        // rhill is not a member of Internal Tools (private): same as the HTML page
        $this->login('rhill');
        self::assertSame($this->fetch('/issues/18')->getStatusCode(), $this->fetch('/issues/18.pdf')->getStatusCode());
        self::assertSame(403, $this->fetch('/issues/18.pdf')->getStatusCode());
    }

    public function testUserLanguageAndCjkFont(): void
    {
        $user = $this->login('admin');
        $user->language = 'ja';
        $this->em->flush();
        $pdf = $this->pdf('/issues/13.pdf');
        self::assertStringContainsString('KozMinPro-Regular', $pdf);

        $user->language = 'de';
        $this->em->flush();
        $pdf = $this->pdf('/issues/13.pdf');
        self::assertStringContainsString('FreeSans', $pdf);
        self::assertPdfContains($pdf, 'Status:', 'Beschreibung');
    }
}
