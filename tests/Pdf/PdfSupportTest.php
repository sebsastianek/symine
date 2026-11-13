<?php

declare(strict_types=1);

namespace App\Tests\Pdf;

use App\Entity\Attachment;
use App\Pdf\ITCPDF;
use App\Pdf\PdfImageResolver;
use App\Pdf\PdfSupport;
use App\Service\AttachmentService;
use App\Tests\Integration\SymineTestCase;

/** ITCPDF (fonts per locale, footer) and helpers */
final class PdfSupportTest extends SymineTestCase
{
    use PdfAssertions;

    public function testFontsFallBackToDejaVu(): void
    {
        new ITCPDF(['fontname' => 'freesans', 'monospaced' => 'freemono', 'rtl' => false, 'language' => 'en']);
        self::assertSame('freesans', ITCPDF::availableFont('freesans'));
        self::assertSame('dejavusans', ITCPDF::availableFont('DejaVuSans'));
        self::assertSame('kozminproregular', ITCPDF::availableFont('kozminproregular'));
        self::assertSame('dejavusans', ITCPDF::availableFont('NotInstalledFont'));
        self::assertSame('dejavusansmono', ITCPDF::availableFont('../../etc/passwd', 'dejavusansmono'));
    }

    public function testDocumentWithFooterAndPageNumbers(): void
    {
        PdfSupport::$compress = false;
        try {
            $this->actAs($this->user('admin'));
            $pdf = $this->service(PdfSupport::class)->newPdf();
            $pdf->AddPage();
            $pdf->setFontStyle('B', 11);
            $pdf->rdmMultiCell(190, 5, 'Héllo wörld – ünicode ✓');
            $pdf->AddPage();
            $out = $pdf->render();
        } finally {
            PdfSupport::$compress = true;
        }
        self::assertPdf($out, 2);
        self::assertPdfContains($out, 'Héllo wörld – ünicode ✓', '1/2', '2/2');
        self::assertStringContainsString('/Creator', $out);
        self::assertStringContainsString('FreeSans', $out);
    }

    public function testRtlLanguage(): void
    {
        $pdf = new ITCPDF(['fontname' => 'DejaVuSans', 'monospaced' => 'DejaVuSansMono', 'rtl' => true, 'language' => 'he']);
        self::assertTrue($pdf->getRTL());
        self::assertTrue($pdf->isRtl());
    }

    public function testHelpers(): void
    {
        self::assertSame('12 Bytes', PdfSupport::humanSize(12));
        self::assertSame('1 Byte', PdfSupport::humanSize(1));
        self::assertSame('1.5 KB', PdfSupport::humanSize(1536));
        self::assertSame('2 MB', PdfSupport::humanSize(2 * 1024 * 1024));
        self::assertSame('abcdefg...', PdfSupport::truncate('abcdefghijklmnop', 10));
        self::assertSame('short', PdfSupport::truncate('short', 10));
        $this->actAs($this->user('admin'));
        $support = $this->service(PdfSupport::class);
        self::assertStringEndsWith(' hour', $support->lHours(null));
        self::assertStringEndsWith(' hour', $support->lHours(1.5));
        self::assertStringEndsWith(' hours', $support->lHours(8.0));
    }

    public function testImageResolver(): void
    {
        $resolver = new PdfImageResolver($this->service(AttachmentService::class));
        $a = new Attachment();
        $a->id = 999;
        $a->filename = 'missing.png';
        $a->diskDirectory = '2000/01';
        $a->diskFilename = 'does_not_exist.png';
        // unreadable / unknown / remote images are dropped
        self::assertSame('<p>x</p>', $resolver->embed('<p><img src="missing.png" alt="">x</p>', [$a]));
        self::assertSame('<p></p>', $resolver->embed('<p><img src="http://example.invalid/a.png"></p>', [$a]));
        self::assertNull($resolver->imageFile('/attachments/download/999/missing.png', [$a]));
        self::assertNull($resolver->imageFile('../../secret.png', [$a]));

        $readable = $this->em->find(Attachment::class, 1); // notes.txt: not an image
        self::assertNotNull($readable);
        self::assertNull($resolver->imageFile('/attachments/download/1/notes.txt', [$readable]));
    }
}
