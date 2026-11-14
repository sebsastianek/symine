<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Project;
use App\I18n\Localizer;
use App\Query\ProjectQuery;
use App\Query\QueryColumn;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\ProjectManager;
use App\Service\TextFormatter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Port of ProjectsQueriesHelper (column_value / csv_value) and ProjectsHelper#grouped_project_list
 * for the ProjectQuery engine (admin projects list, CSV export).
 */
final class ProjectQueryExtension extends AbstractExtension
{
    public function __construct(
        private readonly Localizer $i18n,
        private readonly CurrentUser $currentUser,
        private readonly Authorization $auth,
        private readonly UrlGeneratorInterface $urls,
        private readonly ProjectManager $manager,
        private readonly TextFormatter $formatter,
        private readonly AppExtension $app,
        private readonly QueryExtension $queries,
    ) {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('project_query_column_content', $this->columnContent(...), $safe),
            new TwigFunction('grouped_project_list', $this->groupedProjectList(...)),
            // Hash#to_query (Rails): sorted keys, arrays as name[]=value
            new TwigFunction('rails_to_query', static fn (array $params): string => QueryExtension::toQuery($params)),
        ];
    }

    /** ProjectsQueriesHelper#column_value (HTML) */
    public function columnContent(ProjectQuery $query, QueryColumn $column, Project $item): string
    {
        $value = $query->columnValue($column, $item);
        if ($column->isCustomField() && null !== $column->customField) {
            return $this->queries->formatObject(['custom_field' => $column->customField, 'value' => $value, 'customized' => $item]);
        }
        $user = $this->currentUser->get();
        switch ($column->name) {
            case 'name':
                $html = $this->app->linkToProject($item);
                if ($this->auth->isMemberOf($user, $item)) {
                    $html .= '<span class="icon-only icon-user my-project">'.$this->app->spriteIcon('user', $this->i18n->l('label_my_projects'), 18, true).'</span>';
                }
                if (\in_array($item->id, $this->manager->bookmarkedProjectIds($user), true)) {
                    $html .= '<span class="icon-only icon-bookmarked-project">'.$this->app->spriteIcon('bookmarked', $this->i18n->l('label_my_bookmarks'), 18, true).'</span>';
                }

                return $html;
            case 'short_description':
                return null !== $item->description && '' !== trim($item->description)
                    ? '<div class="wiki">'.$this->formatter->format((string) $value, $item).'</div>' : '';
            case 'homepage':
                return null !== $item->homepage && '' !== trim($item->homepage)
                    ? '<div class="wiki">'.$this->formatter->format($item->homepage, $item).'</div>' : '';
            case 'status':
                return htmlspecialchars($this->statusLabel((int) $value));
            case 'parent_id':
                return null !== $item->parent ? $this->app->linkToProject($item->parent) : '';
            case 'last_activity_date':
                if (!$value instanceof \DateTimeInterface) {
                    return '';
                }
                $date = \DateTimeImmutable::createFromInterface($value)->setTimezone($this->i18n->userTimezone())->format('Y-m-d');
                $url = $this->urls->generate('project_activity', ['id' => $item->identifier, 'from' => $date]);

                return '<a href="'.htmlspecialchars($url).'">'.htmlspecialchars($this->i18n->formatTime($value)).'</a>';
            case 'created_on':
            case 'updated_on':
                return $value instanceof \DateTimeInterface ? htmlspecialchars($this->i18n->formatTime($value)) : '';
        }

        return $this->queries->formatObject($value);
    }

    /** ProjectsQueriesHelper#csv_value (+ QueriesHelper#csv_content); null = empty (unquoted) CSV field */
    public function csvValue(ProjectQuery $query, QueryColumn $column, Project $item): ?string
    {
        $value = $query->columnValue($column, $item);
        if ($column->isCustomField() && null !== $column->customField) {
            return html_entity_decode($this->queries->formatObject(['custom_field' => $column->customField, 'value' => $value], false), \ENT_QUOTES | \ENT_HTML5);
        }

        // format_object(nil) is '' (quoted ""), while status / parent_id give nil (empty field)
        return match ($column->name) {
            'status' => '' !== ($label = $this->statusLabel((int) $value)) ? $label : null,
            'parent_id' => $item->parent?->name,
            'created_on', 'updated_on', 'last_activity_date' => $value instanceof \DateTimeInterface ? $this->i18n->formatTime($value) : '',
            default => match (true) {
                null === $value => '',
                true === $value => $this->i18n->l('general_text_Yes'),
                false === $value => $this->i18n->l('general_text_No'),
                \is_float($value) => str_replace('.', $this->i18n->l('general_csv_decimal_separator'), \sprintf('%.2f', $value)),
                default => (string) $value,
            },
        };
    }

    /** ProjectsQueriesHelper#get_project_status_label (active / closed only) */
    private function statusLabel(int $status): string
    {
        return match ($status) {
            Project::STATUS_ACTIVE => $this->i18n->l('project_status_active'),
            Project::STATUS_CLOSED => $this->i18n->l('project_status_closed'),
            default => '',
        };
    }

    /**
     * ProjectsHelper#grouped_project_list + QueriesHelper#grouped_query_results.
     *
     * @param list<Project> $projects
     *
     * @return list<array{project: Project, level: int, group_name: string|null, group_count: int|null}>
     */
    public function groupedProjectList(array $projects, ProjectQuery $query): array
    {
        $counts = $query->isGrouped() ? $query->resultCountByGroup() : null;
        $column = $query->groupByColumn();
        $ancestors = [];
        $out = [];
        $previous = null;
        $first = true;
        foreach ($projects as $project) {
            while ([] !== $ancestors && !$project->isDescendantOf(end($ancestors))) {
                array_pop($ancestors);
            }
            $groupName = null;
            $groupCount = null;
            if (null !== $column) {
                $group = $query->groupValue($project);
                $key = ProjectQuery::groupKey($group);
                if ($first || $key !== $previous) {
                    if ((null === $group || '' === $group || [] === $group) && false !== $group) {
                        $groupName = '('.htmlspecialchars($this->i18n->l('label_blank_value')).')';
                    } elseif ($column->isCustomField() && null !== $column->customField) {
                        $groupName = $this->queries->formatObject(['custom_field' => $column->customField, 'value' => $group]);
                    } else {
                        $groupName = $this->queries->formatObject($group);
                    }
                    $groupCount = $counts[$key] ?? null;
                }
                $previous = $key;
                $first = false;
            }
            $out[] = ['project' => $project, 'level' => \count($ancestors), 'group_name' => $groupName, 'group_count' => $groupCount];
            if ($project->children->count() > 0) {
                $ancestors[] = $project;
            }
        }

        return $out;
    }
}
