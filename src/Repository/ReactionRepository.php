<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Reaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for entity Reaction.
 * 
 * @extends ServiceEntityRepository<Reaction>
 */
class ReactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reaction::class);
    }

    /**
     * Finds an entity by its ID.
     */
    public function find($id, $lockMode = null, $lockVersion = null): ?Reaction
    {
        return parent::find($id, $lockMode, $lockVersion);
    }

    /**
     * Finds all entities.
     *
     * @return Reaction[]
     */
    public function findAll(): array
    {
        return parent::findAll();
    }

    /**
     * Finds entities by criteria.
     *
     * @return Reaction[]
     */
    public function findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null): array
    {
        return parent::findBy($criteria, $orderBy, $limit, $offset);
    }

    /**
     * Finds one entity by criteria.
     */
    public function findOneBy(array $criteria, array $orderBy = null): ?Reaction
    {
        return parent::findOneBy($criteria, $orderBy);
    }

    /**
     * Saves an entity.
     */
    public function save(Reaction $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Removes an entity.
     */
    public function remove(Reaction $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}