<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Principal;
use App\Entity\User;
use App\Mailer\NotificationMailer;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Security notifications that Redmine sends from model callbacks:
 * EmailAddress (after_create/update/destroy_commit), User#deliver_security_notification
 * (after_save / after_destroy), Setting.set_all_from_params and the mails of UsersController#update.
 *
 * The sender is always the current user (User.current); call the methods after the change was flushed.
 */
final class SecurityNotifier
{
    public function __construct(
        private readonly NotificationMailer $mailer,
        private readonly CurrentUser $currentUser,
        private readonly RequestStack $requests,
        private readonly EntityManagerInterface $em,
    ) {
    }

    // --- EmailAddress -------------------------------------------------------------------------

    /** EmailAddress#deliver_security_notification_create (not sent for the only address of a user) */
    public function emailAddressAdded(User $user, string $address): void
    {
        $mails = $this->em->getConnection()->fetchFirstColumn('SELECT address FROM email_addresses WHERE user_id = ?', [$user->id]);
        if ([$address] === array_map('strval', $mails)) {
            return;
        }
        $this->emailNotification($user, [
            'message' => 'mail_body_security_notification_add',
            'field' => 'field_mail',
            'value' => $address,
        ]);
    }

    /** EmailAddress#deliver_security_notification_update (address changed): the previous address is notified too */
    public function emailAddressChanged(User $user, string $previous, string $address): void
    {
        if ($previous === $address) {
            return;
        }
        $this->emailNotification($user, [
            'recipients' => '' !== $previous ? [$previous] : [],
            'message' => 'mail_body_security_notification_change_to',
            'field' => 'field_mail',
            'value' => $address,
        ]);
    }

    /** EmailAddress#deliver_security_notification_update (notify flag toggled) */
    public function emailNotifyChanged(User $user, string $address, bool $wasNotify): void
    {
        $this->emailNotification($user, [
            'recipients' => [$address],
            'message' => $wasNotify ? 'mail_body_security_notification_notify_disabled' : 'mail_body_security_notification_notify_enabled',
            'value' => $address,
        ]);
    }

    /** EmailAddress#deliver_security_notification_destroy */
    public function emailAddressRemoved(User $user, string $address): void
    {
        $this->emailNotification($user, [
            'recipients' => [$address],
            'message' => 'mail_body_security_notification_remove',
            'field' => 'field_mail',
            'value' => $address,
        ]);
    }

    /** @param array<string, mixed> $options */
    private function emailNotification(User $user, array $options): void
    {
        $this->mailer->deliverSecurityNotification([$user], $this->currentUser->get(), $options + [
            'title' => 'label_my_account',
            'url' => '/my/account',
            'remote_ip' => $this->remoteIp(),
        ]);
    }

    // --- User ---------------------------------------------------------------------------------

    /**
     * State to pass to userSaved() once the user was saved.
     *
     * @return array{admin: bool, status: int}|null null for a new record
     */
    public function snapshot(User $user): ?array
    {
        return null === $user->id ? null : ['admin' => (bool) $user->admin, 'status' => (int) $user->status];
    }

    /**
     * User#deliver_security_notification (after_save): all active administrators are told when an
     * administrator is created, admin rights are granted/revoked or an administrator is (un)locked.
     *
     * @param array{admin: bool, status: int}|null $before snapshot() taken before the change
     */
    public function userSaved(User $user, ?array $before): void
    {
        $created = null === $before;
        $admin = (bool) $user->admin;
        $active = Principal::STATUS_ACTIVE === $user->status;
        $adminChanged = !$created && $before['admin'] !== $admin;
        $statusChanged = !$created && $before['status'] !== (int) $user->status;

        if ($admin && $active && ($created || $adminChanged || $statusChanged)) {
            $message = 'mail_body_security_notification_add';
        } elseif ((!$admin && $adminChanged && $active) || ($admin && $statusChanged && !$active)) {
            $message = 'mail_body_security_notification_remove';
        } else {
            return;
        }
        $this->adminNotification((string) $user->login, $message);
    }

    /** User#deliver_security_notification (after_destroy): an active administrator was deleted */
    public function userDestroyed(string $login, bool $wasAdmin, bool $wasActive): void
    {
        if ($wasAdmin && $wasActive) {
            $this->adminNotification($login, 'mail_body_security_notification_remove');
        }
    }

    private function adminNotification(string $login, string $message): void
    {
        $this->mailer->deliverSecurityNotification($this->activeAdmins(), $this->currentUser->get(), [
            'field' => 'field_admin',
            'value' => $login,
            'title' => 'label_user_plural',
            'url' => '/users',
            'message' => $message,
            'remote_ip' => $this->remoteIp(),
        ]);
    }

    /** UsersController#update: Mailer.deliver_password_updated(@user, User.current) */
    public function passwordUpdated(User $user): void
    {
        $this->mailer->deliverPasswordUpdated($user, $this->currentUser->get(), $this->remoteIp());
    }

    /** UsersController#update: Mailer.deliver_account_activated(@user) */
    public function accountActivated(User $user): void
    {
        $this->mailer->deliverAccountActivated($user);
    }

    // --- Setting ------------------------------------------------------------------------------

    /**
     * Setting.set_all_from_params: Mailer.deliver_settings_updated(User.current, changes).
     *
     * @param list<string> $changes names of the changed settings flagged security_notifications
     */
    public function settingsUpdated(array $changes): void
    {
        if ([] !== $changes) {
            $this->mailer->deliverSettingsUpdated($this->currentUser->get(), array_values($changes), $this->remoteIp());
        }
    }

    /** @return list<User> User.active.where(admin: true) */
    private function activeAdmins(): array
    {
        return $this->em->getRepository(User::class)->findBy(['admin' => true, 'status' => Principal::STATUS_ACTIVE], ['id' => 'ASC']);
    }

    private function remoteIp(): ?string
    {
        return $this->requests->getMainRequest()?->getClientIp();
    }
}
