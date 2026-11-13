<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\AnonymousUser;
use App\Entity\GroupAnonymous;
use App\Entity\GroupNonMember;
use App\Entity\Principal;
use App\Entity\Role;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Database installation (schema + initial records created by Redmine's migrations). */
final class Installer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly DefaultDataLoader $loader,
    ) {
    }

    public function isInstalled(): bool
    {
        try {
            return $this->em->getConnection()->createSchemaManager()->tablesExist(['users']);
        } catch (\Throwable) {
            return false;
        }
    }

    public function createSchema(bool $drop = false): void
    {
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        if ($drop) {
            $tool->dropDatabase();
        }
        $tool->createSchema($metadata);
    }

    /** Admin account, anonymous user, builtin groups and builtin roles */
    public function createInitialData(string $adminPassword = 'admin'): void
    {
        $users = $this->em->getRepository(User::class);
        if (null === $users->findOneBy(['login' => 'admin'])) {
            $admin = new User();
            $admin->login = 'admin';
            $admin->firstname = 'Redmine';
            $admin->lastname = 'Admin';
            $admin->admin = true;
            $admin->status = Principal::STATUS_ACTIVE;
            $admin->mailNotification = 'all';
            $admin->language = 'en';
            $admin->mustChangePasswd = true;
            $admin->setMail('admin@example.net');
            $admin->hashedPassword = $this->hasher->hashPassword($admin, $adminPassword);
            $this->em->persist($admin);
        }
        if (null === $this->em->getRepository(AnonymousUser::class)->findOneBy([])) {
            $anon = new AnonymousUser();
            $anon->firstname = '';
            $anon->login = '';
            $this->em->persist($anon);
        }
        foreach ([GroupAnonymous::class, GroupNonMember::class] as $class) {
            if (null === $this->em->getRepository($class)->findOneBy([])) {
                $g = new $class();
                $g->lastname = GroupAnonymous::class === $class ? 'Anonymous users' : 'Non member users';
                $g->status = Principal::STATUS_ACTIVE;
                $this->em->persist($g);
            }
        }
        foreach ([Role::BUILTIN_NON_MEMBER => 'Non member', Role::BUILTIN_ANONYMOUS => 'Anonymous'] as $builtin => $name) {
            if (null === $this->em->getRepository(Role::class)->findOneBy(['builtin' => $builtin])) {
                $r = new Role();
                $r->name = $name;
                $r->builtin = $builtin;
                $r->position = $builtin;
                $r->setPermissions([]);
                $this->em->persist($r);
            }
        }
        $this->em->flush();
    }

    public function loadDefaultData(string $lang = 'en'): void
    {
        $this->loader->load($lang);
    }
}
