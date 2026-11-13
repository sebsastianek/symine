<?php

declare(strict_types=1);

namespace App\Tests\Pdf;

use App\Pdf\IssuePdfExporter;

/** GET /issues.pdf and /projects/:id/issues.pdf (IssuesPdfHelper#issues_to_pdf) */
final class IssueListPdfTest extends PdfWebTestCase
{
    public function testProjectIssueList(): void
    {
        $this->login('jsmith');
        $pdf = $this->pdf('/projects/ecommerce/issues.pdf?set_filter=1', 'issues.pdf');
        self::assertPdf($pdf, 1);
        [$w, $h] = self::pageSize($pdf);
        self::assertGreaterThan($h, $w, 'landscape');
        self::assertPdfContains($pdf,
            'eCommerce - Issues',
            // default columns
            '#', 'Tracker', 'Status', 'Priority', 'Subject', 'Assignee', 'Updated',
            'Payment fails on Safari', 'Wishlist emails', 'Cart total is wrong with discounts', 'In Progress', 'Developers',
            '1/1',
        );
        // open issues only by default
        self::assertPdfNotContains($pdf, 'Customer cannot login');
    }

    public function testColumnsGroupingTotalsAndBlockColumns(): void
    {
        $this->login('admin');
        $url = '/issues.pdf?set_filter=1&f[]=status_id&op[status_id]=*&c[]=subject&c[]=is_private&c[]=relations&c[]=parent'
            .'&c[]=done_ratio&c[]=estimated_hours&c[]=spent_hours&c[]=start_date&c[]=attachments&c[]=cf_1&c[]=watcher_users'
            .'&c[]=description&c[]=last_notes&group_by=status&t[]=estimated_hours&t[]=spent_hours';
        $pdf = $this->pdf($url, 'issues.pdf');
        self::assertPdfContains($pdf,
            'Issues',
            // totals and totals by group (Ruby number formatting, like Redmine)
            'Estimated time: 14.0 Spent time: 2.5',
            'New (5)', 'Estimated time: 6.0 Spent time: ',
            'In Progress (1)', 'Estimated time: 8.0 Spent time: 2.5',
            'Closed (1)',
            // inline columns
            'Private', 'Related issues', 'Parent task', 'Watchers',
            'Related to #17, Blocks #19', 'Follows (1 day) #15', 'Feature #14:', 'notes.txt', 'PostgreSQL', '8.00', '2.50', '11/02/2026',
            'false', 'true',
            // block columns
            'Description', 'Steps:', 'Add item', 'Last notes', 'Started working on it', 'Resolved by resetting password',
        );
        $text = self::pdfText($pdf);
        self::assertLessThan(strpos($text, 'In Progress (1)'), strpos($text, 'New (5)'));
        self::assertStringContainsString('/Outlines', $pdf, 'group bookmarks');
    }

    public function testSavedQueryNameAndFilename(): void
    {
        $this->login('admin');
        $pdf = $this->pdf('/issues.pdf?set_filter=1&query_name=My%20open%20bugs', 'my_open_bugs.pdf');
        self::assertPdfContains($pdf, 'Issues');
    }

    public function testExportLimitAddsEllipsis(): void
    {
        static::getContainer()->get(\App\Service\Settings::class)->set('issues_export_limit', '2');
        $this->em->flush();
        $this->login('admin');
        $pdf = $this->pdf('/issues.pdf?set_filter=1&c[]=subject');
        self::assertSame(1, substr_count(self::pdfText($pdf), '|...'));
    }

    public function testInvalidQuery(): void
    {
        $this->login('admin');
        self::assertSame(422, $this->fetch('/issues.pdf?set_filter=1&f[]=start_date&op[start_date]=%3D&v[start_date][]=not-a-date')->getStatusCode());
    }

    public function testPermissionsAndLinks(): void
    {
        // anonymous on a public project
        self::assertSame(200, $this->fetch('/projects/ecommerce/issues.pdf')->getStatusCode());
        self::assertSame(302, $this->fetch('/projects/internal/issues.pdf')->getStatusCode());
        self::assertSame(404, $this->fetch('/projects/nope/issues.pdf')->getStatusCode());

        $this->login('jsmith');
        $html = (string) $this->fetch('/projects/ecommerce/issues?set_filter=1&page=1')->getContent();
        self::assertMatchesRegularExpression('#<a class="pdf" rel="nofollow" href="/projects/ecommerce/issues\.pdf\?set_filter=1">PDF</a>#', $html);

        // export_issues removed from Manager: no link, 403 (HTML list still allowed)
        $this->removePermission('Manager', 'export_issues');
        $html = (string) $this->fetch('/projects/checkout/issues')->getContent();
        self::assertStringNotContainsString('issues.pdf', $html);
        self::assertSame(200, $this->fetch('/projects/checkout/issues')->getStatusCode());
        self::assertSame(403, $this->fetch('/projects/checkout/issues.pdf')->getStatusCode());
    }

    public function testRubyNumber(): void
    {
        self::assertSame('14.0', IssuePdfExporter::rubyNumber(14.0));
        self::assertSame('2.5', IssuePdfExporter::rubyNumber(2.5));
        self::assertSame('3', IssuePdfExporter::rubyNumber(3));
        self::assertSame('', IssuePdfExporter::rubyNumber(null));
    }
}
