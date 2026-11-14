<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\AccountService;
use App\Service\CustomFieldService;
use App\Service\IssueService;
use App\Service\MembersManager;
use App\Service\ProjectManager;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the overview of a project (projects#show) shows: issue counts by tracker, spent time, latest news,
 * subprojects, members by role and custom fields, as far as the user may see them.
 */
final class ProjectOverview
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly Settings $settings,
        private readonly IssueService $issues,
        private readonly ProjectManager $manager,
        private readonly CustomFieldService $customFields,
        private readonly MembersManager $members,
        private readonly AccountService $accounts,
    ) {
    }

    /**
     * Variables of projects/show.html.twig.
     *
     * @return array<string, mixed>
     */
    public function view(Project $project): array
    {
        $user = $this->currentUser->get();
        $withSubprojects = $this->settings->displaySubprojectsIssues();
        $projectCond = $withSubprojects
            ? 'pp.lft >= '.(int) $project->lft.' AND pp.rgt <= '.(int) $project->rgt
            : 'pp.id = '.(int) $project->id;

        // trackers (rolled_up_trackers.visible)
        $trackerDql = $withSubprojects
            ? 'SELECT DISTINCT t FROM App\Entity\Tracker t JOIN t.projects pp WHERE pp.lft >= :l AND pp.rgt <= :r AND pp.status <> 9 AND pp.status <> 10 ORDER BY t.position'
            : 'SELECT t FROM App\Entity\Tracker t JOIN t.projects pp WHERE pp.id = :id ORDER BY t.position';
        $tq = $this->em->createQuery($trackerDql);
        $withSubprojects ? $tq->setParameters(['l' => $project->lft, 'r' => $project->rgt]) : $tq->setParameter('id', $project->id);
        $trackers = array_values(array_filter($tq->getResult(), fn (Tracker $t) => $this->trackerVisible($t)));

        $visible = $this->issues->visibleCondition($user, 'i');
        $open = [];
        $total = [];
        foreach ($this->em->createQuery("SELECT IDENTITY(i.tracker) AS t, COUNT(i.id) AS c FROM App\Entity\Issue i JOIN i.project pp JOIN i.status s WHERE $visible AND $projectCond AND s.isClosed = false GROUP BY i.tracker")->getResult() as $row) {
            $open[(int) $row['t']] = (int) $row['c'];
        }
        foreach ($this->em->createQuery("SELECT IDENTITY(i.tracker) AS t, COUNT(i.id) AS c FROM App\Entity\Issue i JOIN i.project pp WHERE $visible AND $projectCond GROUP BY i.tracker")->getResult() as $row) {
            $total[(int) $row['t']] = (int) $row['c'];
        }
        $totalHours = null;
        $totalEstimated = null;
        if ($this->allowedToViewAllTimeEntries($project)) {
            $teCond = $this->auth->allowedToCondition('view_time_entries', 'IDENTITY(te.project)', $user, function ($role, User $u) {
                return 'all' === $role->timeEntriesVisibility ? null : 'IDENTITY(te.user) = '.(int) $u->id;
            });
            $totalHours = (float) $this->em->createQuery("SELECT COALESCE(SUM(te.hours), 0) FROM App\Entity\TimeEntry te JOIN te.project pp WHERE $teCond AND $projectCond")->getSingleScalarResult();
            $totalEstimated = (float) $this->em->createQuery("SELECT COALESCE(SUM(i.estimatedHours), 0) FROM App\Entity\Issue i JOIN i.project pp WHERE $visible AND $projectCond")->getSingleScalarResult();
        }
        $news = $this->em->createQuery('SELECT n FROM App\Entity\News n WHERE n.project = :p ORDER BY n.createdOn DESC')->setParameter('p', $project)->setMaxResults(5)->getResult();
        $subprojects = array_values(array_filter($project->children->toArray(), fn (Project $c) => !$c->isArchived() && $this->auth->isProjectVisible($c)));
        $customValues = $this->manager->customValues($project);
        $cfDisplay = [];
        foreach ($this->manager->visibleCustomFields($project) as $f) {
            $formatted = $this->customFields->formatValue($f, $customValues[$f->id] ?? null);
            if ('' !== $formatted) {
                $cfDisplay[] = ['field' => $f, 'value' => $formatted, 'raw' => $customValues[$f->id] ?? null];
            }
        }

        return [
            'project' => $project,
            'principals_by_role' => $this->manager->principalsByRole($project),
            'members_manager' => $this->members,
            'subprojects' => $subprojects,
            'news' => $news,
            'trackers' => $trackers,
            'open_by_tracker' => $open,
            'total_by_tracker' => $total,
            'total_hours' => $totalHours,
            'total_estimated_hours' => $totalEstimated,
            'custom_field_values' => $cfDisplay,
            'deletable' => $this->manager->isDeletable($project),
            'bookmarked' => \in_array($project->id, $this->manager->bookmarkedProjectIds($user), true),
            'atom_key' => $user->isLogged() ? $this->accounts->atomKey($user) : null,
        ];
    }

    /** Tracker.visible: the user has a role that may view the issues of the tracker */
    private function trackerVisible(Tracker $tracker): bool
    {
        $user = $this->currentUser->get();
        if ($user->admin) {
            return true;
        }
        foreach ($this->auth->allRoles($user) as $role) {
            if ($this->auth->roleAllowedTo($role, 'view_issues') && ($role->permissionsAllTrackers('view_issues') || \in_array($tracker->id, $role->permissionsTrackerIds('view_issues'), true))) {
                return true;
            }
        }

        return false;
    }

    /** User#allowed_to_view_all_time_entries? */
    private function allowedToViewAllTimeEntries(Project $project): bool
    {
        return $this->auth->allowedTo('view_time_entries', $project, null, false, static fn ($role) => 'all' === $role->timeEntriesVisibility);
    }
}
