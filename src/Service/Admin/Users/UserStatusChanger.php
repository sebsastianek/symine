<?php

declare(strict_types=1);

namespace App\Service\Admin\Users;

use App\Entity\Principal;
use App\Entity\User;
use App\Service\Admin\SecurityNotifier;
use App\Service\Admin\UserManager;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Locks and unlocks accounts from the users list (users#bulk_lock, #bulk_unlock, and "Lock" on the deletion
 * confirmation): the sessions of a locked account are closed and the administrators are told.
 */
final class UserStatusChanger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserManager $users,
        private readonly SecurityNotifier $security,
    ) {
    }

    public function lock(User $user): void
    {
        $before = $this->security->snapshot($user);
        $user->status = Principal::STATUS_LOCKED;
        $this->em->flush();
        $this->users->deleteLoginTokens($user);
        $this->security->userSaved($user, $before);
    }

    /**
     * Gives every account the status (Principal::STATUS_LOCKED or Principal::STATUS_ACTIVE).
     *
     * @param list<User> $users
     */
    public function change(array $users, int $status): void
    {
        $before = [];
        foreach ($users as $u) {
            $before[$u->id] = $this->security->snapshot($u);
            $u->status = $status;
            if (Principal::STATUS_ACTIVE !== $status) {
                $this->users->deleteLoginTokens($u);
            }
        }
        $this->em->flush();
        foreach ($users as $u) {
            $this->security->userSaved($u, $before[$u->id]);
        }
    }
}
