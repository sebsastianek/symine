<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Group;
use App\Entity\GroupBuiltin;
use App\Entity\IssuePriority;
use App\Entity\IssueStatus;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Version;
use App\Query\QueryContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Port of ReportsController + ReportsHelper (issue summary report).
 *
 * Report rows are plain arrays ['id' => ?int, 'name' => string, 'project' => ?Project]; data rows are
 * Issue.count_and_group_by results: ['status_id' => string, 'closed' => bool, <field> => string, 'total' => string].
 */
final class ReportsController extends AbstractController
{
    private const array DETAILS = [
        'tracker' => ['tracker_id', 'field_tracker'],
        'version' => ['fixed_version_id', 'field_version'],
        'priority' => ['priority_id', 'field_priority'],
        'category' => ['category_id', 'field_category'],
        'assigned_to' => ['assigned_to_id', 'field_assigned_to'],
        'author' => ['author_id', 'field_author'],
        'subproject' => ['project_id', 'field_subproject'],
    ];

    public function __construct(private readonly QueryContext $ctx)
    {
    }

    #[Route('/projects/{id}/issues/report', name: 'issues_report', methods: ['GET'], priority: 10)]
    public function issueReport(string $id): Response
    {
        $project = $this->findProject($id);
        $this->authorize('view_issues', $project);
        $with = $this->settings->displaySubprojectsIssues();
        $statuses = $this->statuses($project);
        $report = function (string $detail) use ($project, $with): array {
            [$field] = self::DETAILS[$detail];

            return ['field' => $field, 'rows' => $this->rows($project, $detail, $with), 'data' => $this->data($project, $field, 'subproject' === $detail ? true : $with, 'subproject' === $detail)];
        };
        $sections = [];
        foreach (array_keys(self::DETAILS) as $detail) {
            $sections[$detail] = $report($detail);
        }

        return $this->render('reports/issue_report.html.twig', [
            'project' => $project,
            'statuses' => $statuses,
            'sections' => $sections,
            'has_children' => ($project->rgt ?? 0) - ($project->lft ?? 0) > 1,
            'main_menu_selected' => 'issues',
            'body_css' => 'controller-reports action-issue_report',
            'page_title' => $this->l('label_report_plural'),
        ]);
    }

    #[Route('/projects/{id}/issues/report/{detail}.{_format}', name: 'issues_report_details', requirements: ['_format' => 'html|csv'], defaults: ['_format' => 'html'], methods: ['GET'], priority: 10)]
    public function issueReportDetails(Request $request, string $id, string $detail, string $_format = 'html'): Response
    {
        $project = $this->findProject($id);
        $this->authorize('view_issues', $project);
        if (!isset(self::DETAILS[$detail])) {
            throw $this->createNotFoundException();
        }
        $with = $this->settings->displaySubprojectsIssues();
        $exportAllowed = $this->exportAllowed($project, $with);
        if ('csv' === $_format && !$exportAllowed) {
            $this->deny();
        }
        [$field, $title] = self::DETAILS[$detail];
        $statuses = $this->statuses($project);
        $rows = $this->rows($project, $detail, $with);
        $data = $this->data($project, $field, 'subproject' === $detail ? true : $with, 'subproject' === $detail);

        if ('csv' === $_format) {
            return $this->csv($request, $detail, $field, $statuses, $rows, $data);
        }

        return $this->render('reports/issue_report_details.html.twig', [
            'project' => $project,
            'statuses' => $statuses,
            'rows' => $rows,
            'data' => $data,
            'field' => $field,
            'detail' => $detail,
            'report_title' => $this->l($title),
            'export_allowed' => $exportAllowed,
            'main_menu_selected' => 'issues',
            'body_css' => 'controller-reports action-issue_report_details',
            'page_title' => $this->l('label_report_plural'),
        ]);
    }

    // ------------------------------------------------------------------------------------------

    /** ReportsHelper#aggregate */
    public static function aggregate(array $data, array $criteria): int
    {
        $a = 0;
        foreach ($data as $row) {
            $match = true;
            foreach ($criteria as $k => $v) {
                if ('closed' === $k) {
                    if ((bool) $row['closed'] !== (bool) $v) {
                        $match = false;
                    }
                } elseif ((string) ($row[$k] ?? '') !== (string) ($v ?? '')) {
                    $match = false;
                }
            }
            if ($match) {
                $a += (int) $row['total'];
            }
        }

        return $a;
    }

    /** Issue.count_and_group_by */
    private function data(Project $project, string $field, bool $withSubprojects, bool $rejectProject): array
    {
        $scope = $withSubprojects ? 'projects.lft >= '.(int) $project->lft.' AND projects.rgt <= '.(int) $project->rgt : 'projects.id = '.(int) $project->id;
        $sql = "SELECT issues.status_id AS status_id, issue_statuses.is_closed AS closed, issues.$field AS f, COUNT(*) AS total"
            .' FROM issues INNER JOIN projects ON projects.id = issues.project_id INNER JOIN issue_statuses ON issue_statuses.id = issues.status_id'
            .' WHERE '.$this->ctx->issueVisibleSql()." AND ($scope)"
            ." GROUP BY issues.status_id, issue_statuses.is_closed, issues.$field";
        $out = [];
        foreach ($this->em->getConnection()->fetchAllAssociative($sql) as $r) {
            if ($rejectProject && (string) $r['f'] === (string) $project->id) {
                continue;
            }
            $out[] = [
                'status_id' => (string) $r['status_id'],
                'closed' => \in_array((string) $r['closed'], ['1', 't', 'true'], true),
                $field => null === $r['f'] ? '' : (string) $r['f'],
                'total' => (string) $r['total'],
            ];
        }

        return $out;
    }

    /** @return list<IssueStatus> Project#rolled_up_statuses */
    private function statuses(Project $project): array
    {
        $trackerIds = $this->trackerIds($project, true, false);
        if ([] === $trackerIds) {
            return [];
        }
        $in = implode(',', $trackerIds);
        $ids = $this->em->getConnection()->fetchFirstColumn(
            "SELECT old_status_id FROM workflows WHERE type = 'WorkflowTransition' AND tracker_id IN ($in) AND old_status_id <> new_status_id"
            ." UNION SELECT new_status_id FROM workflows WHERE type = 'WorkflowTransition' AND tracker_id IN ($in) AND old_status_id <> new_status_id"
        );

        return [] === $ids ? [] : $this->em->getRepository(IssueStatus::class)->findBy(['id' => array_map('intval', $ids)], ['position' => 'ASC']);
    }

    /** @return list<int> Project#rolled_up_trackers(with_subprojects)(.visible) */
    private function trackerIds(Project $project, bool $withSubprojects, bool $visible): array
    {
        $conn = $this->em->getConnection();
        $scope = $withSubprojects ? 'p.lft >= '.(int) $project->lft.' AND p.rgt <= '.(int) $project->rgt : 'p.id = '.(int) $project->id;
        $ids = array_map('intval', $conn->fetchFirstColumn(
            "SELECT DISTINCT t.id FROM trackers t JOIN projects_trackers pt ON pt.tracker_id = t.id JOIN projects p ON p.id = pt.project_id
             JOIN enabled_modules em ON em.project_id = p.id AND em.name = 'issue_tracking' WHERE p.status <> 9 AND $scope"
        ));
        if ($visible && !$this->user()->admin) {
            $cond = $this->auth->allowedToCondition('view_issues', 'p.id', null, static function (Role $role) {
                if (!$role->permissionsAllTrackers('view_issues')) {
                    $t = $role->permissionsTrackerIds('view_issues');

                    return [] !== $t ? 't.id IN ('.implode(',', array_map('intval', $t)).')' : '1=0';
                }

                return null;
            });
            $visibleIds = array_map('intval', $conn->fetchFirstColumn("SELECT DISTINCT t.id FROM trackers t JOIN projects_trackers pt ON pt.tracker_id = t.id JOIN projects p ON p.id = pt.project_id WHERE $cond"));
            $ids = array_values(array_intersect($ids, $visibleIds));
        }

        return $ids;
    }

    /** @return list<array{id: ?int, name: string, project?: Project}> */
    private function rows(Project $project, string $detail, bool $with): array
    {
        $none = '['.$this->l('label_none').']';
        $ref = static fn (?int $id, string $name, ?Project $p = null) => ['id' => $id, 'name' => $name, 'project' => $p];
        switch ($detail) {
            case 'tracker':
                $ids = $this->trackerIds($project, $with, true);
                $trackers = [] === $ids ? [] : $this->em->getRepository(Tracker::class)->findBy(['id' => $ids], ['position' => 'ASC']);

                return array_map(static fn (Tracker $t) => $ref($t->id, $t->name), $trackers);
            case 'version':
                $versions = $this->projects->sharedVersions($project);
                usort($versions, static function (Version $a, Version $b): int {
                    return [null === $a->effectiveDate ? 1 : 0, $a->effectiveDate?->format('Y-m-d') ?? '', (string) $a->name, $a->id]
                        <=> [null === $b->effectiveDate ? 1 : 0, $b->effectiveDate?->format('Y-m-d') ?? '', (string) $b->name, $b->id];
                });
                $rows = array_map(static fn (Version $v) => $ref($v->id, (string) $v->name), $versions);
                $rows[] = $ref(null, $none);

                return $rows;
            case 'priority':
                $priorities = $this->em->getRepository(IssuePriority::class)->findBy([], ['position' => 'DESC']);

                return array_map(static fn (IssuePriority $p) => $ref($p->id, $p->name), $priorities);
            case 'category':
                $rows = array_map(static fn ($c) => $ref($c->id, $c->name), $project->issueCategories->toArray());
                $rows[] = $ref(null, $none);

                return $rows;
            case 'assigned_to':
                $rows = array_map(static fn (Principal $p) => $ref($p->id, $p->getName()), $this->members($project, $this->settings->bool('issue_group_assignment')));
                $rows[] = $ref(null, $none.' ');

                return $rows;
            case 'author':
                return array_map(static fn (Principal $p) => $ref($p->id, $p->getName()), $this->members($project, false));
            case 'subproject':
                $visible = $this->auth->visibleProjectIds();
                $subs = $this->em->createQuery('SELECT p FROM App\Entity\Project p WHERE p.lft > :l AND p.rgt < :r ORDER BY p.lft')
                    ->setParameters(['l' => $project->lft, 'r' => $project->rgt])->getResult();

                return array_values(array_map(static fn (Project $p) => $ref($p->id, $p->name, $p), array_filter($subs, static fn (Project $p) => \in_array($p->id, $visible, true))));
        }

        return [];
    }

    /** @return list<Principal> Project#users / Project#principals, sorted (users first) */
    private function members(Project $project, bool $withGroups): array
    {
        $list = $this->em->createQuery('SELECT DISTINCT pr FROM App\Entity\Principal pr JOIN pr.memberships m WHERE m.project = :p AND pr.status = 1')
            ->setParameter('p', $project->id)->getResult();
        $list = array_values(array_filter($list, static fn ($p) => !$p instanceof GroupBuiltin && ($p instanceof User || ($withGroups && $p instanceof Group))));
        usort($list, static fn (Principal $a, Principal $b) => [$a instanceof Group ? 1 : 0, $a->firstname ?? '', $a->lastname ?? '', $a->id] <=> [$b instanceof Group ? 1 : 0, $b->firstname ?? '', $b->lastname ?? '', $b->id]);

        return $list;
    }

    /** ApplicationController#export_allowed? on the report scope */
    private function exportAllowed(Project $project, bool $with): bool
    {
        $user = $this->user();
        if ($user->admin) {
            return true;
        }
        if (!$this->auth->allowedTo('export_issues', $project)) {
            return false;
        }
        if (($project->rgt ?? 0) - ($project->lft ?? 0) <= 1) {
            return true;
        }
        $scope = $with ? 'projects.lft >= '.(int) $project->lft.' AND projects.rgt <= '.(int) $project->rgt : 'projects.id = '.(int) $project->id;
        $allowed = $this->auth->allowedToCondition('export_issues', 'projects.id');
        $n = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM issues INNER JOIN projects ON projects.id = issues.project_id WHERE '
            .$this->ctx->issueVisibleSql()." AND ($scope) AND NOT ($allowed)");

        return 0 === (int) $n;
    }

    private function csv(Request $request, string $detail, string $field, array $statuses, array $rows, array $data): Response
    {
        $sep = (string) ($request->query->get('field_separator') ?: $this->l('general_csv_separator'));
        $encoding = (string) ($request->query->get('encoding') ?: $this->l('general_csv_encoding'));
        $lines = [array_merge([''], array_map(static fn (IssueStatus $s) => $s->name, $statuses), [$this->l('label_open_issues_plural'), $this->l('label_closed_issues_plural'), $this->l('label_total')])];
        foreach ($rows as $row) {
            $line = [$row['name']];
            foreach ($statuses as $s) {
                $line[] = (string) self::aggregate($data, [$field => $row['id'], 'status_id' => $s->id]);
            }
            $line[] = (string) self::aggregate($data, [$field => $row['id'], 'closed' => false]);
            $line[] = (string) self::aggregate($data, [$field => $row['id'], 'closed' => true]);
            $line[] = (string) self::aggregate($data, [$field => $row['id']]);
            $lines[] = $line;
        }
        $csv = '';
        foreach ($lines as $line) {
            $csv .= implode($sep, array_map(static function (string $v) use ($sep) {
                return '' === $v || str_contains($v, $sep) || str_contains($v, '"') || str_contains($v, "\n") ? '"'.str_replace('"', '""', $v).'"' : $v;
            }, $line))."\n";
        }
        if (0 === strcasecmp($encoding, 'UTF-8')) {
            $csv = "\xEF\xBB\xBF".$csv;
        } else {
            $converted = @mb_convert_encoding($csv, $encoding, 'UTF-8');
            $csv = false === $converted ? $csv : $converted;
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; header=present',
            'Content-Disposition' => 'attachment; filename="report-'.$detail.'.csv"',
        ]);
    }
}
