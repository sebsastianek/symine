<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Version;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\IssueService;
use App\Service\TimeEntryService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the page of a version shows (VersionsController#show): the visible issues it targets and what the user
 * may see of the time spent on them.
 */
final class VersionView
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly IssueService $issues,
        private readonly TimeEntryService $timeEntries,
    ) {
    }

    /**
     * @param string $criteria the "issues by" grouping of the sidebar (status_by), '' for the default one
     *
     * @return array<string, mixed> variables of versions/show.html.twig
     */
    public function view(Version $version, string $criteria): array
    {
        $project = $version->project;
        $cond = $this->issues->visibleCondition($this->currentUser->get(), 'i');
        $issues = $this->em->createQuery(
            "SELECT i, t, s FROM App\Entity\Issue i JOIN i.tracker t JOIN i.status s WHERE i.fixedVersion = :v AND $cond ORDER BY t.position ASC, i.id ASC"
        )->setParameter('v', $version->id)->getResult();
        $hasFixedIssues = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM issues WHERE fixed_version_id = ?', [$version->id]) > 0;

        return [
            'project' => $project,
            'version' => $version,
            'issues' => $issues,
            'has_fixed_issues' => $hasFixedIssues,
            'criteria' => $criteria,
            'can_view_time_entries' => $this->auth->allowedTo('view_time_entries', $project),
            // User#allowed_to_view_all_time_entries?
            'can_view_all_time_entries' => $this->timeEntries->canViewAll($project),
        ];
    }
}
