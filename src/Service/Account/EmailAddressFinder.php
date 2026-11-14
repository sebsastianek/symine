<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\EmailAddress;
use App\Entity\User;
use App\Security\AccessGuard;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Loads what EmailAddressesController works on: the user of the URL (find_user) and one of their additional addresses. */
final class EmailAddressFinder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessGuard $guard,
        private readonly CurrentUser $currentUser,
    ) {
    }

    /** The user whose addresses are managed: oneself, or anyone for an administrator (404 unless a real user) */
    public function user(int $id): User
    {
        $this->guard->requireLogin();
        $user = $this->em->find(User::class, $id);
        if (!$user instanceof User || !$user->isLogged()) {
            throw new NotFoundHttpException();
        }
        if ($user->id !== $this->currentUser->get()->id) {
            $this->guard->requireAdmin();
        }

        return $user;
    }

    /** An additional address of the user (the default address is not managed here) */
    public function address(User $user, int $id): EmailAddress
    {
        $a = $this->em->getRepository(EmailAddress::class)->findOneBy(['id' => $id, 'user' => $user, 'isDefault' => false]);
        if (null === $a) {
            throw new NotFoundHttpException();
        }

        return $a;
    }
}
