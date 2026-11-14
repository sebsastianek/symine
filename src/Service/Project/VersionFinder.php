<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Version;
use App\Security\AccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads the version an action works on (VersionsController#find_model_object + find_project_from_association):
 * 404 when there is no such version, then the checks of find_project on the project it belongs to.
 */
final class VersionFinder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessGuard $guard,
    ) {
    }

    /** The project of the returned version ($version->project) is visible by the current user */
    public function find(int $id): Version
    {
        $version = $this->em->find(Version::class, $id);
        if (null === $version) {
            throw new NotFoundHttpException();
        }
        $this->guard->findProject((int) $version->project->id);

        return $version;
    }
}
