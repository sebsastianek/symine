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

    /**
     * User.find_by_login: the exact login first, then a case-insensitive match. Only the login identifies a
     * user (User.try_to_login): an e-mail address is not a user name, for the login form as for the API.
     */
    public function findByLogin(string $login): ?User
    {
        if ('' === $login) {
            return null;
        }
        $users = $this->em->createQuery(
            'SELECT u FROM App\Entity\User u WHERE LOWER(u.login) = :l AND u NOT INSTANCE OF App\Entity\AnonymousUser ORDER BY u.id'
        )->setParameter('l', mb_strtolower($login))->getResult();
        foreach ($users as $u) {
            if ($u->login === $login) {
                return $u;
            }
        }

        return $users[0] ?? null;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException();
        }
        $fresh = $this->em->find(User::class, $user->id);
        // find_current_user: User.active.find(session[:user_id]) - locked/unactivated users lose their session
        if (!$fresh instanceof User || $fresh instanceof AnonymousUser || !$fresh->isActive()) {
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
