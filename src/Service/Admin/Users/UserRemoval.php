<?php

declare(strict_types=1);

namespace App\Service\Admin\Users;

use App\Entity\User;
use App\I18n\Localizer;
use App\Security\ApiRequestMatcher;
use App\Security\CurrentUser;
use App\Service\Admin\UserManager;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Deletes user accounts from the administration (users#destroy, users#bulk_destroy): which account may be
 * deleted, the confirmation that has to be typed first, and the deletion itself.
 */
final class UserRemoval
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserManager $users,
        private readonly UserStatusChanger $status,
        private readonly CurrentUser $currentUser,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
    ) {
    }

    /** Whether the current user may delete the account: its own one only when User#own_account_deletable? */
    public function isAllowed(User $user): bool
    {
        return $user->id !== $this->currentUser->get()->id || $this->ownAccountDeletable($user);
    }

    /**
     * Deletes the account once its login was typed on the confirmation page (the API needs no confirmation),
     * or locks it when "Lock" was chosen there.
     */
    public function remove(User $user, Request $request): RemovalOutcome
    {
        $lock = $request->request->has('lock');
        if (!ApiRequestMatcher::isApiRequest($request) && !$lock && (string) $request->request->get('confirm') !== $user->login) {
            return RemovalOutcome::Unconfirmed;
        }
        if ($lock) {
            $this->status->lock($user);

            return RemovalOutcome::Locked;
        }
        $this->users->destroyUser($user);

        return RemovalOutcome::Deleted;
    }

    /**
     * Deletes the accounts once "Yes" was typed on the confirmation page; false when it was not.
     *
     * @param list<User> $users
     */
    public function removeAll(array $users, Request $request): bool
    {
        $params = array_merge($request->query->all(), $request->request->all());
        if ((string) ($params['confirm'] ?? '') !== $this->i18n->l('general_text_Yes')) {
            return false;
        }
        foreach ($users as $u) {
            $this->users->destroyUser($u);
        }

        return true;
    }

    /**
     * Variables of the confirmation page of a bulk deletion.
     *
     * @param list<User> $users
     *
     * @return array<string, mixed>
     */
    public function bulkConfirmation(array $users): array
    {
        return [
            'users' => $users,
            'ids' => array_map(static fn (User $u) => (int) $u->id, $users),
        ];
    }

    private function ownAccountDeletable(User $user): bool
    {
        if (!$this->settings->bool('unsubscribe')) {
            return false;
        }
        if (!$user->admin) {
            return true;
        }

        return (int) $this->em->createQuery('SELECT COUNT(u.id) FROM App\Entity\User u WHERE u.admin = true AND u.status = 1 AND u.id <> :id')->setParameter('id', $user->id)->getSingleScalarResult() > 0;
    }
}
