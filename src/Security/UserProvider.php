<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AnonymousUser;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads users by login (case-insensitive, like Redmine) or e-mail address.
 *
 * @implements UserProviderInterface<User>
 */
final class UserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->findByLogin($identifier);
        if (null === $user) {
            throw new UserNotFoundException(\sprintf('User "%s" not found.', $identifier));
        }

        return $user;
    }

    public function findByLogin(string $login): ?User
    {
        $users = $this->em->createQuery(
            'SELECT u FROM App\Entity\User u WHERE LOWER(u.login) = :l AND u NOT INSTANCE OF App\Entity\AnonymousUser'
        )->setParameter('l', mb_strtolower($login))->getResult();
        foreach ($users as $u) {
            if ($u->login === $login) {
                return $u;
            }
        }
        if ($users) {
            return $users[0];
        }
        if (str_contains($login, '@')) {
            $u = $this->em->createQuery(
                'SELECT u FROM App\Entity\User u JOIN u.emailAddresses e WHERE LOWER(e.address) = :m'
            )->setParameter('m', mb_strtolower($login))->setMaxResults(1)->getOneOrNullResult();
            if ($u instanceof User && !$u instanceof AnonymousUser) {
                return $u;
            }
        }

        return null;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException();
        }
        $fresh = $this->em->find(User::class, $user->id);
        if (!$fresh instanceof User || $fresh instanceof AnonymousUser) {
            throw new UserNotFoundException();
        }

        return $fresh;
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            return;
        }
        $user->hashedPassword = $newHashedPassword;
        $user->salt = null;
        $this->em->flush();
    }
}
