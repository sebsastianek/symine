<?php

declare(strict_types=1);

namespace App\Security\Twofa;

use App\Entity\User;
use App\Security\AccessGuard;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The before_actions of Redmine's 2FA controllers: who may reach the pairing, backup code and login step actions. */
final class TwofaAccess
{
    public function __construct(
        private readonly TwofaManager $twofa,
        private readonly AccessGuard $guard,
        private readonly CurrentUser $currentUser,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** TwofaHelper#require_active_twofa: Setting.twofa? ? true : deny_access */
    public function requireActiveTwofa(): void
    {
        if (!$this->twofa->enabled()) {
            $this->guard->deny();
        }
    }

    /** before_action :require_login, :require_active_twofa (+ @user = User.current) */
    public function user(): User
    {
        $this->guard->requireLogin();
        $this->requireActiveTwofa();

        return $this->currentUser->get();
    }

    /** TwofaController#admin_deactivate: the user whose scheme an administrator removes (404 unless a real user) */
    public function userToUnpair(int $id): User
    {
        $this->guard->requireLogin();
        $this->guard->requireAdmin();
        $this->requireActiveTwofa();
        $user = $this->em->find(User::class, $id);
        if (!$user instanceof User || !$user->isLogged()) {
            throw new NotFoundHttpException();
        }
        // do not allow administrators to unpair 2FA without confirmation for themselves
        if ($user->id === $this->currentUser->get()->id) {
            $this->guard->deny();
        }

        return $user;
    }
}
