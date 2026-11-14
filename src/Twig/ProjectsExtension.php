<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Group;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\User;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\ProjectManager;
use App\Service\TextFormatter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Port of Redmine's ProjectsHelper / project tree helpers of ApplicationHelper. */
final class ProjectsExtension extends AbstractExtension
{
    public function __construct(
        private readonly Localizer $i18n,
        private readonly CurrentUser $currentUser,
        private readonly Authorization $auth,
        private readonly UrlGeneratorInterface $urls,
        private readonly ProjectManager $manager,
        private readonly TextFormatter $formatter,
        private readonly AppExtension $app,
        private readonly \App\Service\CustomFieldService $customFields,
        private readonly ?\App\Doctrine\BatchLoader $batch = null,
    ) {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('project_css_classes', $this->cssClasses(...)),
            new TwigFunction('render_project_hierarchy', $this->renderHierarchy(...), $safe),
            new TwigFunction('project_tree_options', $this->treeOptions(...), $safe),
            new TwigFunction('project_tree', $this->tree(...)),
            new TwigFunction('render_projects_for_jump_box', $this->jumpBox(...), $safe),
            new TwigFunction('principals_options', $this->principalsOptions(...), $safe),
            new TwigFunction('is_member_of', fn (Project $p) => $this->auth->isMemberOf($this->currentUser->get(), $p)),
            new TwigFunction('bookmarked_project_ids', fn () => $this->manager->bookmarkedProjectIds($this->currentUser->get())),
            new TwigFunction('project_short_description', self::shortDescription(...)),
            new TwigFunction('project_is_deletable', fn (Project $p) => $this->manager->isDeletable($p)),
            new TwigFunction('format_custom_value', fn ($field, $value) => $this->customFields->formatValue($field, $value)),
            new TwigFunction('custom_field_options', fn ($field, ?Project $project = null) => $this->customFields->possibleValuesOptions($field, $project)),
            new TwigFunction('project_jump_box_items', $this->jumpBoxItems(...), $safe),
        ];
    }

    public static function shortDescription(?string $description, int $length = 255): ?string
    {
        if (null === $description) {
            return null;
        }
        if (preg_match('/^(.{'.$length.'}[^\n\r]*).*$/su', $description, $m)) {
            return trim($m[1].'...');
        }

        return trim($description);
    }

    public function cssClasses(Project $p): string
    {
        $s = 'project';
        $s .= null === $p->parent ? ' root' : ' child';
        // asked for every project of the list: one query for all of them
        $this->batch?->collection($p, 'children');
        $s .= 0 === $p->children->count() ? ' leaf' : ' parent';
        if ($p->isPublic) {
            $s .= ' public';
        }
        if (!$p->isActive()) {
            $s .= $p->isArchived() ? ' archived' : ' closed';
        }

        return $s;
    }

    /**
     * Project.project_tree: yields [project, level] sorted by lft.
     *
     * @param iterable<Project> $projects
     *
     * @return list<array{0: Project, 1: int}>
     */
    public function tree(iterable $projects): array
    {
        $list = \is_array($projects) ? $projects : iterator_to_array($projects);
        usort($list, static fn (Project $a, Project $b) => $a->lft <=> $b->lft);
        $ancestors = [];
        $out = [];
        foreach ($list as $p) {
            while ([] !== $ancestors && !$p->isDescendantOf(end($ancestors))) {
                array_pop($ancestors);
            }
            $out[] = [$p, \count($ancestors)];
            $ancestors[] = $p;
        }

        return $out;
    }

    /** @param iterable<Project|null> $projects */
    public function treeOptions(iterable $projects, mixed $selected = null, bool|string $includeBlank = false): string
    {
        $s = '';
        if (false !== $includeBlank) {
            $s .= '<option value="">'.(true === $includeBlank ? '&nbsp;' : htmlspecialchars($includeBlank)).'</option>';
        }
        $selectedIds = [];
        foreach (\is_iterable($selected) ? $selected : [$selected] as $sel) {
            if ($sel instanceof Project) {
                $selectedIds[] = $sel->id;
            } elseif (null !== $sel && '' !== $sel) {
                $selectedIds[] = (int) $sel;
            }
        }
        $list = array_filter(\is_array($projects) ? $projects : iterator_to_array($projects), static fn ($p) => null !== $p);
        foreach ($this->tree($list) as [$p, $level]) {
            $prefix = $level > 0 ? str_repeat('&nbsp;', 2 * $level).'&#187; ' : '';
            $s .= '<option value="'.$p->id.'"'.(\in_array($p->id, $selectedIds, true) ? ' selected="selected"' : '').'>'.$prefix.htmlspecialchars($p->name).'</option>';
        }

        return $s;
    }

    /** render_project_hierarchy (board view of the projects list) @param list<Project> $projects */
    public function renderHierarchy(array $projects): string
    {
        if ([] === $projects) {
            return '';
        }
        $user = $this->currentUser->get();
        $bookmarks = $this->manager->bookmarkedProjectIds($user);
        usort($projects, static fn (Project $a, Project $b) => $a->lft <=> $b->lft);
        $s = '';
        $ancestors = [];
        foreach ($projects as $project) {
            if ([] === $ancestors || $project->isDescendantOf(end($ancestors))) {
                $s .= "<ul class='projects ".([] === $ancestors ? 'root' : '')."'>\n";
            } else {
                array_pop($ancestors);
                $s .= '</li>';
                while ([] !== $ancestors && !$project->isDescendantOf(end($ancestors))) {
                    array_pop($ancestors);
                    $s .= "</ul></li>\n";
                }
            }
            $classes = [] === $ancestors ? 'root' : 'child';
            if ($project->isArchived()) {
                $classes .= ' archived';
            }
            $s .= "<li class='$classes'><div class='$classes'>";
            $css = explode(' ', $this->cssClasses($project));
            $member = $this->auth->isMemberOf($user, $project);
            $bookmarked = \in_array($project->id, $bookmarks, true);
            if ($member) {
                array_push($css, 'icon', 'icon-user', 'my-project');
            }
            if ($bookmarked) {
                array_push($css, 'icon', 'icon-bookmarked-project');
            }
            $css = implode(' ', array_unique($css));
            $s .= $project->isArchived()
                ? htmlspecialchars($project->name)
                : '<a class="'.$css.'" href="'.htmlspecialchars($this->urls->generate('project_show', ['id' => $project->identifier])).'">'.htmlspecialchars($project->name).'</a>';
            if ($member) {
                $s .= '<span class="icon-only icon-user my-project">'.$this->app->spriteIcon('user', $this->i18n->l('label_my_projects'), 18, true).'</span>';
            }
            if ($bookmarked) {
                $s .= '<span class="icon-only icon-bookmarked-project">'.$this->app->spriteIcon('bookmarked', $this->i18n->l('label_my_bookmarks'), 18, true).'</span>';
            }
            if (null !== $project->description && '' !== trim($project->description)) {
                $s .= '<div class="wiki description">'.$this->formatter->format(self::shortDescription($project->description), $project).'</div>';
            }
            $s .= "</div>\n";
            $ancestors[] = $project;
        }
        $s .= str_repeat("</li></ul>\n", \count($ancestors));

        return $s;
    }

    /** render_projects_for_jump_box @param list<Project> $projects */
    public function jumpBox(array $projects, ?string $query = null, ?string $jump = null, ?Project $selected = null): string
    {
        $user = $this->currentUser->get();
        $groups = [];
        if (null === $query || '' === $query) {
            $visible = $this->auth->visibleProjectIds($user);
            $load = function (array $ids) use ($visible): array {
                $out = [];
                foreach ($ids as $id) {
                    if (\in_array($id, $visible, true) && ($p = $this->manager->findProject($id))) {
                        $out[] = $p;
                    }
                }

                return $out;
            };
            $groups[] = [$load($this->manager->bookmarkedProjectIds($user)), 'label_optgroup_bookmarks', true];
            $groups[] = [$load($this->manager->recentlyUsedProjectIds($user)), 'label_optgroup_recents', false];
            $groups[] = [$projects, 'label_project_all', true];
        } else {
            $groups[] = [$projects, 'label_result_plural', true];
        }
        $s = '';
        $link = function (Project $p, int $level) use ($jump, $selected): string {
            $params = ['id' => $p->identifier];
            if (null !== $jump && '' !== $jump) {
                $params['jump'] = $jump;
            }

            return '<a title="'.htmlspecialchars($p->name).'"'.($selected?->id === $p->id ? ' class="selected"' : '').' href="'.htmlspecialchars($this->urls->generate('project_show', $params)).'"><span style="padding-inline-start:'.($level * 16).'px;">'.htmlspecialchars($p->name).'</span></a>';
        };
        foreach ($groups as [$list, $label, $isTree]) {
            if ([] === $list) {
                continue;
            }
            $s .= '<strong>'.htmlspecialchars($this->i18n->l($label)).'</strong>';
            if ($isTree) {
                foreach ($this->tree($list) as [$p, $level]) {
                    $s .= $link($p, $level);
                }
            } else {
                foreach ($list as $p) {
                    $s .= $link($p, 0);
                }
            }
        }

        return $s;
    }

    /** Initial content of the project jump box (projects_for_jump_box: active projects the user is member of) */
    public function jumpBoxItems(?Project $selected = null, ?string $jump = null): string
    {
        $user = $this->currentUser->get();
        if (!$user->isLogged()) {
            return '';
        }
        $projects = [];
        foreach ($this->auth->membershipsOf($user) as $m) {
            if ($m->project->isActive()) {
                $projects[] = $m->project;
            }
        }

        return $this->jumpBox($projects, null, $jump, $selected);
    }

    /** principals_options_for_select: users then groups optgroups @param list<Principal> $principals */
    public function principalsOptions(array $principals, ?Principal $selected = null): string
    {
        $user = $this->currentUser->get();
        $s = '';
        $users = array_filter($principals, static fn ($p) => $p instanceof User);
        $groups = array_filter($principals, static fn ($p) => $p instanceof Group);
        if ($user->isLogged() && \in_array($user, $users, true)) {
            $s .= '<option value="'.$user->id.'">&lt;&lt; '.htmlspecialchars($this->i18n->l('label_me')).' &gt;&gt;</option>';
        }
        $opt = static fn (Principal $p) => '<option value="'.$p->id.'"'.($selected?->id === $p->id ? ' selected="selected"' : '').'>'.htmlspecialchars($p->getName()).'</option>';
        if ([] !== $users && [] !== $groups) {
            $s .= '<optgroup label="'.htmlspecialchars($this->i18n->l('label_user_plural')).'">'.implode('', array_map($opt, $users)).'</optgroup>';
            $s .= '<optgroup label="'.htmlspecialchars($this->i18n->l('label_group_plural')).'">'.implode('', array_map($opt, $groups)).'</optgroup>';
        } else {
            $s .= implode('', array_map($opt, $principals));
        }

        return $s;
    }
}
