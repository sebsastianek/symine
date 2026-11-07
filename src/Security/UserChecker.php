<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Principal;
use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }
        if (Principal::STATUS_LOCKED === $user->status) {
            throw new CustomUserMessageAccountStatusException('notice_account_locked');
        }
        if (Principal::STATUS_REGISTERED === $user->status) {
            throw new CustomUserMessageAccountStatusException('notice_account_not_activated_yet');
        }
        if (null !== $user->authSourceId && '' === $user->hashedPassword) {
            // LDAP users are authenticated by the LdapAuthenticator
            return;
        }
    }

    public function checkPostAuth(UserInterface $user, ?\Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token = null): void
    {
    }
}
