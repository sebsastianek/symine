<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailAddress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for entity EmailAddresse.
 *
 * @extends ServiceEntityRepository<EmailAddress>
 */
class EmailAddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailAddress::class);
    }

    /**
     * Finds an entity by its ID.
     */
    public function find($id, $lockMode = null, $lockVersion = null): ?EmailAddress
    {
        return parent::find($id, $lockMode, $lockVersion);
    }

    /**
     * Finds all entities.
     *
     * @return EmailAddress[]
     */
    public function findAll(): array
    {
        return parent::findAll();
    }

    /**
     * Finds entities by criteria.
     *
     * @return EmailAddress[]
     */
    public function findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null): array
    {
        return parent::findBy($criteria, $orderBy, $limit, $offset);
    }

    /**
     * Finds one entity by criteria.
     */
    public function findOneBy(array $criteria, array $orderBy = null): ?EmailAddress
    {
        return parent::findOneBy($criteria, $orderBy);
    }

    /**
     * Saves an entity.
     */
    public function save(EmailAddress $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Removes an entity.
     */
    public function remove(EmailAddress $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
