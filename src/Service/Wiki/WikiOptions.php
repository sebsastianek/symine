<?php

declare(strict_types=1);

namespace App\Service\Wiki;

use App\Entity\WikiPage;
use App\Security\Authorization;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The <option> lists of the wiki forms (WikiHelper): the pages of a wiki as a tree and the wikis a page can
 * be moved to.
 */
final class WikiOptions
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
    ) {
    }

    /**
     * wiki_page_options_for_select
     *
     * @param list<WikiPage> $pages
     * @param list<WikiPage> $excluded
     */
    public function pages(array $pages, ?WikiPage $selected, array $excluded = []): string
    {
        $pages = array_values(array_filter($pages, static fn (WikiPage $p) => !\in_array($p, $excluded, true)));
        $ids = [];
        foreach ($pages as $p) {
            $ids[$p->id] = true;
        }
        $byParent = [];
        foreach ($pages as $p) {
            $byParent[$p->parent && isset($ids[$p->parent->id]) ? $p->parent->id : 0][] = $p;
        }
        $out = '';
        $walk = function (int $parent, int $level) use (&$walk, &$out, $byParent, $selected): void {
            foreach ($byParent[$parent] ?? [] as $page) {
                $indent = $level > 0 ? str_repeat('&nbsp;', $level * 2).'&#187; ' : '';
                $out .= '<option value="'.$page->id.'"'.($selected && $selected->id === $page->id ? ' selected="selected"' : '').'>'.$indent.htmlspecialchars($page->prettyTitle()).'</option>';
                $walk((int) $page->id, $level + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    /** wiki_page_wiki_options_for_select: wikis of projects where the user can rename pages */
    public function wikis(WikiPage $page): string
    {
        $wikis = $this->em->createQuery('SELECT w, p FROM App\Entity\Wiki w JOIN w.project p ORDER BY p.lft ASC, p.name ASC')->getResult();
        $out = '';
        foreach ($wikis as $w) {
            if ($w->id !== $page->wiki->id && (!$this->auth->allowedTo('rename_wiki_pages', $w->project) || $w->project->isArchived())) {
                continue;
            }
            $level = \count($w->project->getAncestors());
            $indent = $level > 0 ? str_repeat('&nbsp;', $level * 2).'&#187; ' : '';
            $out .= '<option value="'.$w->id.'"'.($w->id === $page->wiki->id ? ' selected="selected"' : '').'>'.$indent.htmlspecialchars($w->project->name).'</option>';
        }

        return $out;
    }
}
