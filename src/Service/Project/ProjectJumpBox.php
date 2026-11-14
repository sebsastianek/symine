<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Project;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Twig\ProjectsExtension;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The project jump box of the header: the projects its search field finds and the scripts that refresh its
 * content (projects/autocomplete.js.erb, and the bookmark link of a project).
 */
final class ProjectJumpBox
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly ProjectsExtension $helper,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * Project.visible.like(q) when something was typed, otherwise the active projects of the user.
     *
     * @return list<Project>
     */
    public function projects(string $q): array
    {
        if ('' !== $q) {
            $like = '%'.mb_strtolower(addcslashes($q, '%_\\')).'%';

            return $this->em->createQuery('SELECT p FROM App\Entity\Project p WHERE '.Authorization::in('p.id', $this->auth->visibleProjectIds()).' AND (LOWER(p.identifier) LIKE :q OR LOWER(p.name) LIKE :q) ORDER BY p.lft')
                ->setParameter('q', $like)->getResult();
        }
        $ids = array_keys($this->auth->membershipsOf($this->currentUser->get()));

        return [] === $ids ? [] : $this->em->createQuery('SELECT p FROM App\Entity\Project p WHERE p.id IN (:ids) AND p.status <> 9 AND p.status <> 10 ORDER BY p.lft')->setParameter('ids', $ids)->getResult();
    }

    /** projects/autocomplete.js.erb: replaces the projects of the box with those matching what was typed */
    public function autocompleteScript(string $q, string $jump): string
    {
        $projects = $this->projects($q);
        $html = '';
        if ([] !== $projects) {
            $html = $this->helper->jumpBox($projects, $q, $jump ?: 'overview'); // params[:jump].presence || current_menu_item
        } elseif ('' !== $q) {
            $html = '<span>'.htmlspecialchars($this->i18n->l('label_no_data')).'</span>';
        }

        return "$('#project-jump .drdn-items.projects').html('".self::escapeJavascript($html)."');\n";
    }

    /**
     * projects/bookmark.js.erb: the box lists the bookmarked projects first, and the link of the page toggles.
     *
     * @param string $bookmarkLink the rendered projects/_bookmark_link partial
     */
    public function bookmarkScript(Project $project, string $bookmarkLink): string
    {
        $items = $this->helper->jumpBoxItems($project);

        return "$('#project-jump div.drdn-items.projects').html(".json_encode($items, \JSON_HEX_TAG).");\n$('.contextual a.icon.bookmark').replaceWith(".json_encode(trim($bookmarkLink), \JSON_HEX_TAG).');';
    }

    /** ActionView::Helpers::JavaScriptHelper#escape_javascript */
    private static function escapeJavascript(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '</' => '<\/', "\r\n" => '\n', "\n" => '\n', "\r" => '\n', '"' => '\\"', "'" => "\\'", '`' => '\\`', '$' => '\\$', "\u{2028}" => '&#x2028;', "\u{2029}" => '&#x2029;']);
    }
}
