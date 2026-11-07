<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\User;
use App\Security\Authorization;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests run against var/test.db, a copy of a database migrated from the seeded
 * reference Redmine (see tests/fixtures/seed_redmine.rb and tests/fixtures/redmine-seeded.db).
 */
abstract class SymineTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $project = \dirname(__DIR__, 2);
        copy($project.'/tests/fixtures/symine-seeded.db', $project.'/var/test'.($_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN') ?: '').'.db');
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function user(string $login): User
    {
        $u = $this->em->getRepository(User::class)->findOneBy(['login' => $login]);
        self::assertNotNull($u, "user $login");

        return $u;
    }

    protected function actAs(?User $user): void
    {
        self::getContainer()->get(CurrentUser::class)->set($user);
        self::getContainer()->get(Authorization::class)->reset();
    }

    /** @template T @param class-string<T> $id @return T */
    protected function service(string $id): object
    {
        return self::getContainer()->get($id);
    }
}
