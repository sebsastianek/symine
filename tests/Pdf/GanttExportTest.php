<?php

declare(strict_types=1);

namespace App\Tests\Pdf;

/** GET /issues/gantt.pdf|png and /projects/:id/issues/gantt.pdf|png (Gantt#to_pdf / #to_image) */
final class GanttExportTest extends PdfWebTestCase
{
    private const string PARAMS = 'year=2026&month=11&months=6&zoom=2&set_filter=1&f[]=status_id&op[status_id]=*';

    public function testProjectGanttPdf(): void
    {
        $this->login('jsmith');
        $pdf = $this->pdf('/projects/ecommerce/issues/gantt.pdf?'.self::PARAMS, 'ecommerce-gantt.pdf');
        self::assertPdf($pdf, 1);
        [$w, $h] = self::pageSize($pdf);
        self::assertGreaterThan($h, $w, 'landscape');
        self::assertPdfContains($pdf,
            'eCommerce',
            // months and weeks headers
            '2026-11', '2026-12', '2027-4', '45', '53',
            // subjects (project, issues, version with project name, subproject)
            'Wishlist', 'Wishlist sharing', 'eCommerce - 1.0', 'Cart total is wrong with discounts', 'Checkout',
            // bar labels
            'New 50%', '1.0 30%', 'In Progress 30%',
        );
    }

    public function testDayHeadersAndPagination(): void
    {
        $this->login('admin');
        // 1 month: weeks, day numbers and day letters
        $pdf = $this->pdf('/issues/gantt.pdf?year=2026&month=11&months=1&set_filter=1&f[]=status_id&op[status_id]=*', 'gantt.pdf');
        self::assertPdfContains($pdf, '2026-11', '30', 'Internal Tools', 'Internal tool crash');
    }

    public function testGanttPng(): void
    {
        $this->login('jsmith');
        $response = $this->fetch('/issues/gantt.png?'.self::PARAMS);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('inline; filename="gantt.png"; filename*=UTF-8\'\'gantt.png', $response->headers->get('Content-Disposition'));
        $png = (string) $response->getContent();
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $info = getimagesizefromstring($png);
        self::assertNotFalse($info);
        // subject width 400 + 181 days * (zoom 2 * 2) + 1
        self::assertSame(400 + 181 * 4 + 1, $info[0]);
        $img = imagecreatefromstring($png);
        self::assertNotFalse($img);
        // headers: 2 * 18; rows: 20 * n + 30
        self::assertSame(0, ($info[1] - 36 - 30) % 20);
        self::assertGreaterThan(5, ($info[1] - 36 - 30) / 20);
        // today line (red) is drawn when today is in the chart, task bars in gray/green
        $colors = [];
        for ($x = 400; $x < $info[0]; $x += 1) {
            for ($y = 40; $y < $info[1]; $y += 2) {
                $colors[imagecolorat($img, $x, $y)] = true;
            }
        }
        self::assertArrayHasKey(0xADB5BD, $colors, 'task bars');
        self::assertArrayHasKey(0x40C057, $colors, 'progress');
        self::assertArrayHasKey(0x364FC7, $colors, 'markers');

        $response = $this->fetch('/projects/ecommerce/issues/gantt.png?'.self::PARAMS);
        self::assertSame('inline; filename="ecommerce-gantt.png"; filename*=UTF-8\'\'ecommerce-gantt.png', $response->headers->get('Content-Disposition'));
    }

    public function testExportLinksAndPermissions(): void
    {
        $this->login('jsmith');
        $html = (string) $this->fetch('/projects/ecommerce/issues/gantt?year=2026&month=11&months=6')->getContent();
        self::assertMatchesRegularExpression('#<a class="pdf" rel="nofollow" href="/projects/ecommerce/issues/gantt\.pdf\?[^"]*months=6[^"]*">PDF</a>#', $html);
        self::assertMatchesRegularExpression('#<a class="png" rel="nofollow" href="/projects/ecommerce/issues/gantt\.png\?[^"]*">PNG</a>#', $html);

        $this->removePermission('Manager', 'export_issues');
        $html = (string) $this->fetch('/projects/ecommerce/issues/gantt')->getContent();
        self::assertStringNotContainsString('gantt.pdf', $html);
        self::assertSame(403, $this->fetch('/projects/ecommerce/issues/gantt.pdf')->getStatusCode());
        self::assertSame(403, $this->fetch('/projects/ecommerce/issues/gantt.png')->getStatusCode());

        $this->removePermission('Manager', 'view_gantt');
        self::assertSame(403, $this->fetch('/projects/ecommerce/issues/gantt.pdf')->getStatusCode());
    }

    public function testAnonymous(): void
    {
        self::assertSame(200, $this->fetch('/projects/ecommerce/issues/gantt.pdf')->getStatusCode());
        self::assertSame(302, $this->fetch('/projects/internal/issues/gantt.pdf')->getStatusCode());
        self::assertSame(404, $this->fetch('/projects/nope/issues/gantt.png')->getStatusCode());
    }
}
