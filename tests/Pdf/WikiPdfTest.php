<?php

declare(strict_types=1);

namespace App\Tests\Pdf;

/** GET /projects/:id/wiki/:page.pdf and /projects/:id/wiki/export.pdf (WikiPdfHelper) */
final class WikiPdfTest extends PdfWebTestCase
{
    public function testWikiPagePdf(): void
    {
        $this->login('jsmith');
        $pdf = $this->pdf('/projects/ecommerce/wiki/Checkout_flow.pdf', 'Checkout_flow.pdf');
        self::assertPdf($pdf, 1);
        self::assertPdfContains($pdf, 'eCommerce - Checkout_flow - # 2', 'Version 2 with more text', '1/1');

        // like Redmine, the version parameter is checked (view_wiki_edits, existence) and the current content exported
        $pdf = $this->pdf('/projects/ecommerce/wiki/Checkout_flow.pdf?version=1');
        self::assertPdfContains($pdf, 'eCommerce - Checkout_flow - # 2');
        self::assertSame(404, $this->fetch('/projects/ecommerce/wiki/Checkout_flow.pdf?version=9')->getStatusCode());
        self::assertSame(404, $this->fetch('/projects/ecommerce/wiki/Unknown_page.pdf')->getStatusCode());
    }

    public function testWikiExportPdf(): void
    {
        $this->login('jsmith');
        $pdf = $this->pdf('/projects/ecommerce/wiki/export.pdf', 'ecommerce.pdf');
        // one page per wiki page (Checkout_flow is a child of Wiki)
        self::assertPdf($pdf, 2);
        self::assertPdfContains($pdf, 'eCommerce', 'Home', 'See', 'Checkout flow', 'Version 2 with more text', '1/2', '2/2');
        self::assertStringContainsString('/Outlines', $pdf, 'page bookmarks');
    }

    public function testAttachmentsListAndEmbeddedImages(): void
    {
        $this->login('jsmith');
        $attachments = static::getContainer()->get(\App\Service\AttachmentService::class);
        $img = imagecreatetruecolor(16, 16);
        ob_start();
        imagejpeg($img);
        $jpeg = (string) ob_get_clean();
        $author = $this->em->getRepository(\App\Entity\User::class)->findOneBy(['login' => 'jsmith']);
        $a = $attachments->createFromContent($jpeg, 'pdf-wiki-diagram.jpg', $author, 'image/jpeg');
        $page = $this->em->getRepository(\App\Entity\WikiPage::class)->findOneBy(['title' => 'Checkout_flow']);
        $attachments->attachTo([$a], 'WikiPage', (int) $page->id);
        $textile = 'textile' === static::getContainer()->get(\App\Service\Settings::class)->textFormatting();
        $page->content->text = $textile ? "Flow:\n\n!pdf-wiki-diagram.jpg!" : "Flow:\n\n![](pdf-wiki-diagram.jpg)";
        $this->em->flush();
        try {
            $pdf = $this->pdf('/projects/ecommerce/wiki/Checkout_flow.pdf');
            self::assertPdfContains($pdf, 'Flow:', 'Files', 'pdf-wiki-diagram.jpg', 'John Smith');
            self::assertSame(1, preg_match_all('#/Subtype\s*/Image#', $pdf));
            $pdf = $this->pdf('/projects/ecommerce/wiki/export.pdf');
            self::assertSame(1, preg_match_all('#/Subtype\s*/Image#', $pdf));
        } finally {
            @unlink($attachments->fullPath($a));
        }
    }

    public function testExportLinks(): void
    {
        $this->login('jsmith');
        $html = (string) $this->fetch('/projects/ecommerce/wiki/Checkout_flow')->getContent();
        self::assertStringContainsString('<a class="pdf" rel="nofollow" href="/projects/ecommerce/wiki/Checkout_flow.pdf">PDF</a>', $html);
        $html = (string) $this->fetch('/projects/ecommerce/wiki/index')->getContent();
        self::assertStringContainsString('<a class="pdf" rel="nofollow" href="/projects/ecommerce/wiki/export.pdf">PDF</a>', $html);

        // dlopper (Developer) has no export_wiki_pages
        $this->login('dlopper');
        $html = (string) $this->fetch('/projects/ecommerce/wiki/Checkout_flow')->getContent();
        self::assertStringNotContainsString('.pdf', $html);
    }

    public function testPermissions(): void
    {
        // anonymous can view but not export: page export is not an available format, full export needs login
        self::assertSame(200, $this->fetch('/projects/ecommerce/wiki/Wiki')->getStatusCode());
        self::assertSame(406, $this->fetch('/projects/ecommerce/wiki/Wiki.pdf')->getStatusCode());
        self::assertSame(406, $this->fetch('/projects/ecommerce/wiki/Checkout_flow.pdf?version=1')->getStatusCode());
        self::assertSame(302, $this->fetch('/projects/ecommerce/wiki/export.pdf')->getStatusCode());

        $this->login('dlopper');
        self::assertSame(406, $this->fetch('/projects/ecommerce/wiki/Wiki.pdf')->getStatusCode());
        self::assertSame(403, $this->fetch('/projects/ecommerce/wiki/export.pdf')->getStatusCode());
        self::assertSame(404, $this->fetch('/projects/nope/wiki/export.pdf')->getStatusCode());
    }
}
