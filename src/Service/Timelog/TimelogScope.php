<?php

declare(strict_types=1);

namespace App\Service\Timelog;

use App\Entity\Issue;
use App\Entity\Project;
use App\Security\AccessGuard;
use App\Security\Authorization;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Finds the project or the issue an action of the time log works in (TimelogController#find_optional_project and
 * #find_optional_issue): the one of the route, or of params[:project_id] / params[:issue_id], or none at all.
 */
final class TimelogScope
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly Authorization $auth,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** A scalar, non blank query string parameter (params[:project_id], params[:issue_id]) */
    public static function param(Request $request, string $name): ?string
    {
        $value = $request->query->all()[$name] ?? null;

        return \is_scalar($value) && '' !== (string) $value ? (string) $value : null;
    }

    /** The project of the route or of the query string; the permission is checked globally when there is none */
    public function project(Request $request, ?string $projectId, string $permission): ?Project
    {
        return $this->optionalProject($projectId ?? self::param($request, 'project_id'), $permission);
    }

    public function optionalProject(?string $projectId, string $permission): ?Project
    {
        $project = null !== $projectId && '' !== $projectId ? $this->guard->findProject($projectId) : null;
        if (!$this->auth->allowedTo($permission, $project, null, null === $project)) {
            $this->guard->deny();
        }

        return $project;
    }

    /**
     * find_optional_issue (log_time on the issue's project) or find_optional_project
     *
     * @return array{0: ?Project, 1: ?Issue}
     */
    public function issueOrProject(?string $projectId, ?int $issueId): array
    {
        if (null !== $issueId) {
            $issue = $this->em->find(Issue::class, $issueId);
            if (null === $issue) {
                throw new NotFoundHttpException();
            }
            $this->guard->authorize('log_time', $issue->project);

            return [$issue->project, $issue];
        }

        return [$this->optionalProject($projectId, 'log_time'), null];
    }

    /** @return list<Project> Project.allowed_to(:log_time) */
    public function logTimeProjects(): array
    {
        $cond = $this->auth->allowedToCondition('log_time', 'p.id');

        return $this->em->createQuery("SELECT p FROM App\Entity\Project p WHERE $cond ORDER BY p.lft")->getResult();
    }
}
