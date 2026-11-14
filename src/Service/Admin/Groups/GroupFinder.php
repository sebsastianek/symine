<?php

declare(strict_types=1);

namespace App\Service\Admin\Groups;

use App\Entity\Group;
use App\Entity\Principal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Loads the group an action of the groups administration works on (GroupsController#find_group). */
final class GroupFinder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** The group with this id, builtin groups included: 404 when the id is not the one of a group */
    public function find(int $id): Group
    {
        $g = $this->em->find(Principal::class, $id);
        if (!$g instanceof Group) {
            throw new NotFoundHttpException();
        }

        return $g;
    }
}
