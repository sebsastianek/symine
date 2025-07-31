<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Group;
use App\Entity\GroupAnonymous;
use App\Entity\GroupNonMember;
use App\Entity\User;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for Group entities
 * 
 * @extends ServiceEntityRepository<Group>
 */
class GroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Group::class);
    }

    /**
     * Find all regular groups (not built-in)
     */
    public function findRegularGroups(): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.type = :type')
            ->setParameter('type', 'Group')
            ->orderBy('g.lastname', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all built-in groups
     */
    public function findBuiltinGroups(): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.type IN (:types)')
            ->setParameter('types', ['GroupAnonymous', 'GroupNonMember'])
            ->orderBy('g.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find groups that a user belongs to
     */
    public function findGroupsForUser(User $user): array
    {
        return $this->createQueryBuilder('g')
            ->join('g.users', 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $user->getId())
            ->orderBy('g.lastname', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find groups with access to a specific project
     */
    public function findGroupsWithProjectAccess(Project $project): array
    {
        return $this->createQueryBuilder('g')
            ->join('g.members', 'm')
            ->where('m.project = :project')
            ->setParameter('project', $project)
            ->orderBy('g.lastname', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find anonymous group
     */
    public function findAnonymousGroup(): ?GroupAnonymous
    {
        return $this->createQueryBuilder('g')
            ->where('g.type = :type')
            ->setParameter('type', 'GroupAnonymous')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find non-member group
     */
    public function findNonMemberGroup(): ?GroupNonMember
    {
        return $this->createQueryBuilder('g')
            ->where('g.type = :type')
            ->setParameter('type', 'GroupNonMember')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Search groups by name
     */
    public function searchByName(string $name): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.lastname LIKE :name OR g.login LIKE :name')
            ->setParameter('name', '%' . $name . '%')
            ->orderBy('g.lastname', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get group statistics
     */
    public function getGroupStats(): array
    {
        $qb = $this->createQueryBuilder('g');

        $totalGroups = (clone $qb)
            ->select('COUNT(g.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $regularGroups = (clone $qb)
            ->select('COUNT(g.id)')
            ->where('g.type = :type')
            ->setParameter('type', 'Group')
            ->getQuery()
            ->getSingleScalarResult();

        $builtinGroups = (clone $qb)
            ->select('COUNT(g.id)')
            ->where('g.type IN (:types)')
            ->setParameter('types', ['GroupAnonymous', 'GroupNonMember'])
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total' => (int) $totalGroups,
            'regular' => (int) $regularGroups,
            'builtin' => (int) $builtinGroups
        ];
    }

    /**
     * Find or create anonymous group
     */
    public function getOrCreateAnonymousGroup(): GroupAnonymous
    {
        $group = $this->findAnonymousGroup();
        
        if (!$group) {
            $group = new GroupAnonymous();
            $this->getEntityManager()->persist($group);
            $this->getEntityManager()->flush();
        }

        return $group;
    }

    /**
     * Find or create non-member group
     */
    public function getOrCreateNonMemberGroup(): GroupNonMember
    {
        $group = $this->findNonMemberGroup();
        
        if (!$group) {
            $group = new GroupNonMember();
            $this->getEntityManager()->persist($group);
            $this->getEntityManager()->flush();
        }

        return $group;
    }

    /**
     * Create a new regular group
     */
    public function createGroup(string $name, string $login = null): Group
    {
        $group = new Group();
        $group->setLastname($name);
        $group->setLogin($login ?: strtolower(str_replace(' ', '_', $name)));
        
        $this->getEntityManager()->persist($group);
        $this->getEntityManager()->flush();

        return $group;
    }

    /**
     * Save group
     */
    public function save(Group $group, bool $flush = false): void
    {
        $this->getEntityManager()->persist($group);
        
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove group
     */
    public function remove(Group $group, bool $flush = false): void
    {
        // Built-in groups cannot be removed
        if ($group->isBuiltin()) {
            throw new \RuntimeException('Built-in groups cannot be deleted');
        }

        $this->getEntityManager()->remove($group);
        
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Check if group name is available
     */
    public function isNameAvailable(string $name, ?Group $excludeGroup = null): bool
    {
        $qb = $this->createQueryBuilder('g')
            ->where('g.lastname = :name OR g.login = :name')
            ->setParameter('name', $name);

        if ($excludeGroup) {
            $qb->andWhere('g.id != :excludeId')
               ->setParameter('excludeId', $excludeGroup->getId());
        }

        return $qb->getQuery()->getOneOrNullResult() === null;
    }
}