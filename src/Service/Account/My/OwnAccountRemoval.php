<?php

declare(strict_types=1);

namespace App\Service\Account\My;

use App\Entity\User;
use App\Event\ContentEvent;
use App\Service\AccountService;
use App\Service\Admin\SecurityNotifier;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/** A user deletes their own account (MyController#destroy), when the "unsubscribe" setting allows it. */
final class OwnAccountRemoval
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly SecurityNotifier $securityNotifier,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly Security $security,
    ) {
    }

    public function isAllowed(User $user): bool
    {
        return $this->accounts->ownAccountDeletable($user);
    }

    /** Deletes the account and ends the session of the user */
    public function remove(User $user, SessionInterface $session): void
    {
        $this->dispatcher->dispatch(new ContentEvent('user', 'deleted', $user, $user));
        $login = (string) $user->login;
        $wasAdmin = (bool) $user->admin;
        $wasActive = $user->isActive();
        $this->accounts->destroyUser($user);
        // User#deliver_security_notification (after_destroy): an administrator deleted his account
        $this->securityNotifier->userDestroyed($login, $wasAdmin, $wasActive);
        $this->security->logout(false);
        $session->invalidate();
    }
}
