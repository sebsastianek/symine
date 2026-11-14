<?php

declare(strict_types=1);

namespace App\Service\Admin\Trackers;

use App\Entity\Project;
use App\Entity\Tracker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Loads the tracker an administration action works on, and what the tracker is linked to. */
final class TrackerFinder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** The tracker of the route (404 when there is none) */
    public function find(int $id): Tracker
    {
        return $this->em->find(Tracker::class, $id) ?? throw new NotFoundHttpException('Not Found');
    }

    /** @return list<int> */
    public function customFieldIds(Tracker $tracker): array
    {
        return null === $tracker->id ? [] : array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT custom_field_id FROM custom_fields_trackers WHERE tracker_id = ?', [$tracker->id]));
    }

    /** @return list<int> */
    public function projectIds(Tracker $tracker): array
    {
        return null === $tracker->id ? [] : array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT project_id FROM projects_trackers WHERE tracker_id = ?', [$tracker->id]));
    }

    public function hasIssues(Tracker $tracker): bool
    {
        return false !== $this->em->getConnection()->fetchOne('SELECT 1 FROM issues WHERE tracker_id = ? LIMIT 1', [$tracker->id]);
    }

    /** @return list<Project> the projects having issues of the tracker, in tree order */
    public function projectsWithIssues(Tracker $tracker): array
    {
        return $this->em->createQuery('SELECT DISTINCT p FROM App\Entity\Project p WHERE p.id IN (SELECT IDENTITY(i.project) FROM App\Entity\Issue i WHERE i.tracker = :t) ORDER BY p.lft')
            ->setParameter('t', $tracker)->getResult();
    }
}
