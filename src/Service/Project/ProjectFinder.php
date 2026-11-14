<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Project;
use App\Service\ProjectService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads the project of the administration actions (archive, unarchive, copy): whatever its status and without the
 * visibility check of find_project, since archived projects are not visible.
 */
final class ProjectFinder
{
    public function __construct(private readonly ProjectService $projects)
    {
    }

    /** The project with this identifier or id, 404 when there is none */
    public function any(string|int $id): Project
    {
        return $this->projects->findByIdentifierOrId($id) ?? throw new NotFoundHttpException('Not Found');
    }
}
