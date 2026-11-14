<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The tree of all projects shown with a checkbox per project in the tracker and custom field forms
 * (port of ApplicationHelper#render_project_nested_lists).
 */
final class ProjectNestedList
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<Project> every project, in tree order */
    public function projects(): array
    {
        return $this->em->createQuery('SELECT p FROM App\Entity\Project p ORDER BY p.lft')->getResult();
    }

    /**
     * render_project_nested_lists with a "<name>[]" checkbox per project.
     *
     * @param list<Project> $projects sorted by lft
     * @param list<int>     $selected
     */
    public function checkboxes(array $projects, string $name, array $selected): string
    {
        $s = '';
        $ancestors = [];
        foreach ($projects as $project) {
            if ([] === $ancestors || self::isDescendant($project, end($ancestors))) {
                $s .= "<ul class='projects ".([] === $ancestors ? 'root' : '')."'>\n";
            } else {
                array_pop($ancestors);
                $s .= '</li>';
                while ([] !== $ancestors && !self::isDescendant($project, end($ancestors))) {
                    array_pop($ancestors);
                    $s .= "</ul></li>\n";
                }
            }
            $classes = [] === $ancestors ? 'root' : 'child';
            if ($project->isArchived()) {
                $classes .= ' archived';
            }
            $s .= "<li class='$classes'><div class='$classes'>";
            $s .= '<label><input type="checkbox" name="'.$name.'[]" value="'.$project->id.'"'.(\in_array($project->id, $selected, true) ? ' checked="checked"' : '').' /> '.htmlspecialchars($project->name).'</label>';
            $s .= "</div>\n";
            $ancestors[] = $project;
        }

        return $s.str_repeat("</li></ul>\n", \count($ancestors));
    }

    private static function isDescendant(Project $p, Project $ancestor): bool
    {
        return null !== $p->lft && null !== $ancestor->lft && $p->lft > $ancestor->lft && $p->rgt < $ancestor->rgt;
    }
}
