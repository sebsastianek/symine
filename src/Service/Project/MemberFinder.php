<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Member;
use App\Security\AccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Loads the membership an action works on (MembersController#find_model_object + find_project_from_association). */
final class MemberFinder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessGuard $guard,
    ) {
    }

    /** 404 when there is no such membership, then the checks of find_project on its project (404 / 403) */
    public function find(string|int $id): Member
    {
        $member = $this->em->find(Member::class, (int) $id);
        if (null === $member) {
            throw new NotFoundHttpException('Not Found');
        }
        $this->guard->findProject((string) $member->project->id);

        return $member;
    }
}
