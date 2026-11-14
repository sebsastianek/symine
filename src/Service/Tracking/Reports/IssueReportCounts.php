<?php

declare(strict_types=1);

namespace App\Service\Tracking\Reports;

use App\Entity\IssueStatus;
use App\Entity\Project;
use App\Entity\Role;
use App\Query\QueryContext;
use App\Security\Authorization;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The numbers of the issue summary report: issues counted by status and by one of their attributes, and the
 * statuses and trackers in use in a project and its subprojects.
 *
 * Data rows are Issue.count_and_group_by results:
 * ['status_id' => string, 'closed' => bool, <field> => string, 'total' => string].
 */
final class IssueReportCounts
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QueryContext $ctx,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
    ) {
    }

    /**
     * Issue.count_and_group_by.
     *
     * @return list<array<string, mixed>>
     */
    public function data(Project $project, string $field, bool $withSubprojects, bool $rejectProject): array
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
    public function statuses(Project $project): array
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
    public function trackerIds(Project $project, bool $withSubprojects, bool $visible): array
    {
        $conn = $this->em->getConnection();
        $scope = $withSubprojects ? 'p.lft >= '.(int) $project->lft.' AND p.rgt <= '.(int) $project->rgt : 'p.id = '.(int) $project->id;
        $ids = array_map('intval', $conn->fetchFirstColumn(
            "SELECT DISTINCT t.id FROM trackers t JOIN projects_trackers pt ON pt.tracker_id = t.id JOIN projects p ON p.id = pt.project_id
             JOIN enabled_modules em ON em.project_id = p.id AND em.name = 'issue_tracking' WHERE p.status <> 9 AND $scope"
        ));
        if ($visible && !$this->currentUser->get()->admin) {
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
}
