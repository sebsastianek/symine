<?php

declare(strict_types=1);

namespace App\Service\Wiki;

use App\Entity\Wiki;
use App\Entity\WikiPage;
use App\I18n\Localizer;
use App\Service\TextFormatter;
use App\Service\WikiService;

/**
 * What the lists of the pages of a wiki show: the index by title (a tree), the index by date and the export
 * of all the pages in one HTML file.
 */
final class WikiPages
{
    public function __construct(
        private readonly WikiService $wikis,
        private readonly WikiLayout $layout,
        private readonly TextFormatter $formatter,
        private readonly Localizer $i18n,
    ) {
    }

    /** @return array<string, mixed> */
    public function index(Wiki $wiki): array
    {
        $pages = $this->wikis->pages($wiki);

        return [
            'pages' => $pages,
            'hierarchy' => $this->hierarchy($pages, true),
        ] + $this->layout->vars($wiki);
    }

    /** @return array<string, mixed> */
    public function dateIndex(Wiki $wiki): array
    {
        $pages = $this->wikis->pages($wiki);
        $byDate = [];
        foreach ($pages as $p) {
            $d = $p->content?->updatedOn;
            $key = $d ? $d->setTimezone($this->i18n->userTimezone())->format('Y-m-d') : '';
            $byDate[$key][] = $p;
        }
        krsort($byDate);

        return [
            'pages' => $pages,
            'pages_by_date' => $byDate,
        ] + $this->layout->vars($wiki);
    }

    /**
     * The file of all the pages: its table of contents links to the pages further down the file.
     *
     * @return array<string, mixed>
     */
    public function export(Wiki $wiki): array
    {
        $pages = $this->wikis->pages($wiki);

        return [
            'project' => $wiki->project,
            'wiki' => $wiki,
            'pages' => $pages,
            'hierarchy' => $this->hierarchy($pages, true, static fn (WikiPage $p) => '#'.$p->title),
        ];
    }

    /**
     * Pages grouped by parent id rendered as nested lists (render_page_hierarchy).
     *
     * @param list<WikiPage> $pages
     */
    private function hierarchy(array $pages, bool $timestamp, ?callable $href = null): string
    {
        $byParent = [];
        $ids = [];
        foreach ($pages as $p) {
            $ids[$p->id] = true;
        }
        foreach ($pages as $p) {
            $parentId = $p->parent && isset($ids[$p->parent->id]) ? $p->parent->id : 0;
            $byParent[$parentId][] = $p;
        }
        $title = $timestamp ? fn (WikiPage $p) => $p->content?->updatedOn ? $this->i18n->l('label_updated_time', ['value' => $this->i18n->distanceOfTimeInWords($p->content->updatedOn)]) : null : null;

        return $this->formatter->pageHierarchy($byParent, 0, $title, $href);
    }
}
