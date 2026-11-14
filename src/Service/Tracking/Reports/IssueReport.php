<?php

declare(strict_types=1);

namespace App\Service\Tracking\Reports;

use App\Entity\IssueStatus;
use App\Entity\Project;
use App\I18n\Localizer;
use App\Service\Settings;

/**
 * The issue summary of a project (ReportsController): the issues counted by status for each tracker, version,
 * priority, category, assignee, author or subproject.
 */
final class IssueReport
{
    /** detail => [grouped column, label of the report] */
    private const array DETAILS = [
        'tracker' => ['tracker_id', 'field_tracker'],
        'version' => ['fixed_version_id', 'field_version'],
        'priority' => ['priority_id', 'field_priority'],
        'category' => ['category_id', 'field_category'],
        'assigned_to' => ['assigned_to_id', 'field_assigned_to'],
        'author' => ['author_id', 'field_author'],
        'subproject' => ['project_id', 'field_subproject'],
    ];

    public function __construct(
        private readonly IssueReportCounts $counts,
        private readonly IssueReportRows $rows,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
    ) {
    }

    public function hasDetail(string $detail): bool
    {
        return isset(self::DETAILS[$detail]);
    }

    /**
     * The summary page: one small report per detail.
     *
     * @return array{statuses: list<IssueStatus>, sections: array<string, array{field: string, rows: list<array>, data: list<array>}>, has_children: bool}
     */
    public function summary(Project $project): array
    {
        $statuses = $this->counts->statuses($project);
        $sections = [];
        foreach (array_keys(self::DETAILS) as $detail) {
            $sections[$detail] = $this->section($project, $detail);
        }

        return [
            'statuses' => $statuses,
            'sections' => $sections,
            'has_children' => ($project->rgt ?? 0) - ($project->lft ?? 0) > 1,
        ];
    }

    /**
     * The full report of one detail (which hasDetail() accepts).
     *
     * @return array{statuses: list<IssueStatus>, rows: list<array>, data: list<array>, field: string, detail: string, report_title: string}
     */
    public function details(Project $project, string $detail): array
    {
        $statuses = $this->counts->statuses($project);

        return ['statuses' => $statuses] + $this->section($project, $detail) + [
            'detail' => $detail,
            'report_title' => $this->i18n->l(self::DETAILS[$detail][1]),
        ];
    }

    /** @return array{field: string, rows: list<array>, data: list<array>} */
    private function section(Project $project, string $detail): array
    {
        $with = $this->settings->displaySubprojectsIssues();
        [$field] = self::DETAILS[$detail];

        return [
            'field' => $field,
            'rows' => $this->rows->rows($project, $detail, $with),
            'data' => $this->counts->data($project, $field, 'subproject' === $detail ? true : $with, 'subproject' === $detail),
        ];
    }
}
