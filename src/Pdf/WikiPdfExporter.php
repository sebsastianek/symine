<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Entity\Project;
use App\Entity\Wiki;
use App\Entity\WikiPage;
use App\Service\WikiService;

/** Port of Redmine::Export::PDF::WikiPdfHelper. */
final class WikiPdfExporter
{
    public function __construct(
        private readonly PdfSupport $pdf,
        private readonly WikiService $wikis,
        private readonly IssuePdfExporter $issues,
    ) {
    }

    /** Returns a PDF string of a set of wiki pages */
    public function wikiPagesToPdf(Wiki $wiki, Project $project): string
    {
        $pdf = $this->pdf->newPdf();
        $pdf->setTitle($project->name);
        $pdf->AddPage();
        $pdf->setFontStyle('B', 11);
        $pdf->rdmMultiCell(190, 5, $project->name);
        $pdf->Ln();
        // Set resize image scale
        $pdf->setImageScale(1.6);
        $pdf->setFontStyle('', 9);
        $byParent = [];
        foreach ($this->wikis->pages($wiki) as $page) {
            $byParent[$page->parent?->id ?? 0][] = $page;
        }
        $this->writePageHierarchy($pdf, $byParent);

        return $pdf->render();
    }

    /**
     * Returns a PDF string of a single wiki page. Like Redmine, the current content of the page is
     * exported (the version parameter is only checked by the controller).
     */
    public function wikiPageToPdf(WikiPage $page, Project $project): string
    {
        $pdf = $this->pdf->newPdf();
        $pdf->setTitle($project->name.' - '.$page->title);
        $pdf->AddPage();
        $pdf->setFontStyle('B', 11);
        $pdf->rdmMultiCell(190, 5, $project->name.' - '.$page->title.' - # '.$page->content?->version);
        $pdf->Ln();
        // Set resize image scale
        $pdf->setImageScale(1.6);
        $pdf->setFontStyle('', 9);
        $this->writeWikiPage($pdf, $page);

        return $pdf->render();
    }

    /** @param array<int, list<WikiPage>> $pages pages grouped by parent id (0 = root) */
    private function writePageHierarchy(ITCPDF $pdf, array $pages, int $node = 0, int $level = 0): void
    {
        foreach ($pages[$node] ?? [] as $i => $page) {
            if (!(0 === $level && 0 === $i)) {
                $pdf->AddPage();
            }
            $pdf->Bookmark($page->title, $level);
            $this->writeWikiPage($pdf, $page);
            if (isset($pages[$page->id])) {
                $this->writePageHierarchy($pdf, $pages, (int) $page->id, $level + 1);
            }
        }
    }

    private function writeWikiPage(ITCPDF $pdf, WikiPage $page): void
    {
        $attachments = $this->wikis->attachmentsOf($page);
        $text = $this->pdf->formatText($page->content?->text, $page->wiki->project, ['object' => $page, 'attachments' => $attachments]);
        $pdf->rdmWriteFormattedCell(190, 5, '', '', $text, $attachments, 0);
        if ([] !== $attachments) {
            $pdf->Ln(5);
            $pdf->setFontStyle('B', 9);
            $pdf->rdmCell(190, 5, $this->pdf->l('label_attachment_plural'), 'B');
            $pdf->Ln();
            foreach ($attachments as $attachment) {
                $this->issues->attachmentRow($pdf, $attachment);
            }
        }
    }
}
