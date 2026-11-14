<?php

declare(strict_types=1);

namespace App\Service\Admin\Roles;

use App\Entity\Project;
use App\Entity\Role;
use App\Service\Admin\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Loads the role an administration action works on, and the roles and projects related to it. */
final class RoleFinder
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly WorkflowService $workflows)
    {
    }

    /** The role of the route (404 when there is none) */
    public function find(int $id): Role
    {
        return $this->em->find(Role::class, $id) ?? throw new NotFoundHttpException('Not Found');
    }

    /** @return list<Role> Role.givable: the roles that can be given to members, sorted */
    public function givable(): array
    {
        return array_values(array_filter($this->workflows->sortedRoles(), static fn (Role $r) => !$r->isBuiltin()));
    }

    public function nonMember(): ?Role
    {
        return $this->em->getRepository(Role::class)->findOneBy(['builtin' => Role::BUILTIN_NON_MEMBER]);
    }

    public function hasMembers(Role $role): bool
    {
        return false !== $this->em->getConnection()->fetchOne('SELECT 1 FROM member_roles WHERE role_id = ? LIMIT 1', [$role->id]);
    }

    /** @return list<Project> the projects having members with the role, in tree order */
    public function projectsWithMembers(Role $role): array
    {
        return $this->em->createQuery('SELECT DISTINCT p FROM App\Entity\Project p JOIN p.memberships m JOIN m.memberRoles mr WHERE mr.role = :r ORDER BY p.lft')
            ->setParameter('r', $role)->getResult();
    }
}
