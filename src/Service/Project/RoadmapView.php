<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\Version;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\IssueService;
use App\Service\ProjectService;
use App\Service\Settings;
use App\Service\VersionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the roadmap of a project shows (VersionsController#index): its versions, those shared with it and those
 * of its subprojects, with the visible issues of the selected trackers each one targets.
 */
final class RoadmapView
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly Settings $settings,
        private readonly ProjectService $projects,
        private readonly VersionService $versions,
        private readonly IssueService $issues,
        private readonly ?\App\Doctrine\BatchLoader $batch = null,
        private readonly ?\App\Service\CustomFieldService $customFields = null,
    ) {
    }

    /**
     * Parameters: tracker_ids[] (or "1/2"), with_subprojects (0/1, the setting when absent), completed
     * (present: completed versions are listed with the others).
     *
     * @return array<string, mixed> variables of versions/index.html.twig
     */
    public function view(Project $project, Request $request): array
    {
        $trackers = $project->trackers->toArray();
        usort($trackers, static fn (Tracker $a, Tracker $b) => $a->position <=> $b->position);
        $selectedTrackerIds = $this->selectedTrackerIds($request, $trackers);
        $withSubprojects = $request->query->has('with_subprojects')
            ? '1' === $request->query->get('with_subprojects')
            : $this->settings->displaySubprojectsIssues();
        $this->batch?->descendants($project, 'children');
        $projectIds = $withSubprojects ? array_map(static fn (Project $p) => $p->id, $project->getSelfAndDescendants()) : [$project->id];

        $versions = $this->projects->sharedVersions($project);
        if ($withSubprojects) {
            foreach ($this->projects->rolledUpVersions($project) as $v) {
                if ($this->auth->allowedTo('view_issues', $v->project)) {
                    $versions[] = $v;
                }
            }
        }
        $versions = $this->sortVersions($this->uniqueVersions($versions));
        $completedVersions = [];
        if (!$request->query->has('completed')) {
            $completedVersions = array_reverse(array_values(array_filter($versions, $this->versions->isCompleted(...))));
            $versions = array_values(array_filter($versions, fn (Version $v) => !$this->versions->isCompleted($v)));
        }

        // each version shows its custom fields: the values of all of them come in one query
        $this->customFields?->preloadValues('Version', array_map(static fn (Version $v) => (int) $v->id, [...$versions, ...$completedVersions]));

        $issuesByVersion = [];
        if ([] !== $selectedTrackerIds && [] !== $versions) {
            $cond = $this->issues->visibleCondition($this->currentUser->get(), 'i');
            $list = $this->em->createQuery(
                "SELECT i, p, t FROM App\Entity\Issue i JOIN i.project p JOIN i.tracker t
                 WHERE $cond AND t.id IN (:trackers) AND p.id IN (:projects) AND IDENTITY(i.fixedVersion) IN (:versions)
                 ORDER BY p.lft ASC, t.position ASC, i.id ASC"
            )->setParameters([
                'trackers' => array_map('intval', $selectedTrackerIds),
                'projects' => $projectIds,
                'versions' => array_map(static fn (Version $v) => $v->id, $versions),
            ])->getResult();
            foreach ($list as $issue) {
                $issuesByVersion[$issue->fixedVersion->id][] = $issue;
            }
        }
        $versions = array_values(array_filter($versions, static fn (Version $v) => \in_array($v->project->id, $projectIds, true) || !empty($issuesByVersion[$v->id])));

        $hasSubprojectsWithIssues = false;
        foreach ($project->getSelfAndDescendants() as $d) {
            if ($d !== $project && $this->auth->allowedTo('view_issues', $d)) {
                $hasSubprojectsWithIssues = true;
                break;
            }
        }

        return [
            'project' => $project,
            'versions' => $versions,
            'completed_versions' => $completedVersions,
            'issues_by_version' => $issuesByVersion,
            'trackers' => $trackers,
            'selected_tracker_ids' => $selectedTrackerIds,
            'with_subprojects' => $withSubprojects,
            'show_completed' => $request->query->has('completed'),
            'show_subprojects_option' => $hasSubprojectsWithIssues,
        ];
    }

    /** @param list<Tracker> $trackers @return list<string> */
    private function selectedTrackerIds(Request $request, array $trackers): array
    {
        $all = $request->query->all();
        if (\array_key_exists('tracker_ids', $all)) {
            $ids = $all['tracker_ids'];
            $ids = \is_array($ids) ? $ids : explode('/', (string) $ids);

            return array_values(array_map(static fn ($id) => (string) (int) $id, $ids));
        }

        return array_values(array_map(static fn (Tracker $t) => (string) $t->id, array_filter($trackers, static fn (Tracker $t) => $t->isInRoadmap)));
    }

    /** @param list<Version> $versions @return list<Version> */
    private function uniqueVersions(array $versions): array
    {
        $out = [];
        foreach ($versions as $v) {
            $out[$v->id] = $v;
        }

        return array_values($out);
    }

    /** Version#<=> @param list<Version> $versions @return list<Version> */
    private function sortVersions(array $versions): array
    {
        usort($versions, static function (Version $a, Version $b): int {
            if ($a->effectiveDate && $b->effectiveDate) {
                if ($a->effectiveDate == $b->effectiveDate) {
                    return [$a->name, $a->id] <=> [$b->name, $b->id];
                }

                return $a->effectiveDate <=> $b->effectiveDate;
            }
            if ($a->effectiveDate) {
                return -1;
            }
            if ($b->effectiveDate) {
                return 1;
            }

            return [$a->name, $a->id] <=> [$b->name, $b->id];
        });

        return $versions;
    }
}
