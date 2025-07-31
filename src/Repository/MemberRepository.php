<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Member;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for entity Member.
 * 
 * @extends ServiceEntityRepository<Member>
 */
class MemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Member::class);
    }

    /**
     * Finds an entity by its ID.
     */
    public function find($id, $lockMode = null, $lockVersion = null): ?Member
    {
        return parent::find($id, $lockMode, $lockVersion);
    }

    /**
     * Finds all entities.
     *
     * @return Member[]
     */
    public function findAll(): array
    {
        return parent::findAll();
    }

    /**
     * Finds entities by criteria.
     *
     * @return Member[]
     */
    public function findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null): array
    {
        return parent::findBy($criteria, $orderBy, $limit, $offset);
    }

    /**
     * Finds one entity by criteria.
     */
    public function findOneBy(array $criteria, array $orderBy = null): ?Member
    {
        return parent::findOneBy($criteria, $orderBy);
    }

    /**
     * Saves an entity.
     */
    public function save(Member $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Removes an entity.
     */
    public function remove(Member $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}