<?php

declare(strict_types=1);

namespace App\Service\Health;

use Doctrine\ORM\EntityManagerInterface;

/** Whether the application is able to serve requests (Rails::HealthController): the database answers. */
final class HealthCheck
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function isUp(): bool
    {
        try {
            $this->em->getConnection()->executeQuery('SELECT 1')->fetchOne();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
