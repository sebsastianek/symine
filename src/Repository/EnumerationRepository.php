<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enumeration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Enumeration>
 */
class EnumerationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Enumeration::class);
    }
}
