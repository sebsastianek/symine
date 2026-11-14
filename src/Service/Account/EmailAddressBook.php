<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\EmailAddress;
use App\Entity\User;
use App\I18n\Localizer;
use App\Service\AccountService;
use App\Service\Admin\SecurityNotifier;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The additional e-mail addresses of a user (Redmine's EmailAddressesController and the callbacks of EmailAddress):
 * adding one, switching its notifications, removing it; each change is announced by a security notification.
 */
final class EmailAddressBook
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly SecurityNotifier $security,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Adds an address, unless the user already has as many as the "max_additional_emails" setting allows.
     *
     * @return array{saved: bool, errors: list<string>} no error and not saved: the limit is reached
     */
    public function add(User $user, string $address): array
    {
        $count = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM email_addresses WHERE user_id = ?', [$user->id]);
        if ($count > $this->settings->int('max_additional_emails')) {
            return ['saved' => false, 'errors' => []];
        }
        $errors = $this->accounts->emailErrors($address, null, $this->i18n->l('field_address'));
        if ([] !== $errors) {
            return ['saved' => false, 'errors' => $errors];
        }
        $a = new EmailAddress();
        $a->user = $user;
        $a->address = $address;
        $a->isDefault = false;
        $user->emailAddresses->add($a);
        $this->em->persist($a);
        $this->em->flush();
        // EmailAddress#deliver_security_notification_create
        $this->security->emailAddressAdded($user, $address);

        return ['saved' => true, 'errors' => []];
    }

    /** params[:notify]: switches the notifications of an address on ("1") or off; nothing changes when it is absent */
    public function setNotify(User $user, EmailAddress $address, mixed $notify): void
    {
        if (null === $notify || '' === $notify) {
            return;
        }
        $wasNotify = (bool) $address->notify;
        $address->notify = \in_array((string) $notify, ['1', 'true'], true);
        $this->em->flush();
        if ($wasNotify !== $address->notify) {
            // EmailAddress#deliver_security_notification_update
            $this->security->emailNotifyChanged($user, (string) $address->address, $wasNotify);
        }
    }

    public function remove(User $user, EmailAddress $address): void
    {
        $removed = (string) $address->address;
        $user->emailAddresses->removeElement($address);
        $this->em->remove($address);
        $this->em->flush();
        // EmailAddress#destroy_tokens
        $this->accounts->deleteLoginTokens($user);
        // EmailAddress#deliver_security_notification_destroy
        $this->security->emailAddressRemoved($user, $removed);
    }

    /**
     * @param string|null  $address the address that could not be added, shown again in the form
     * @param list<string> $errors
     *
     * @return array{user: User, addresses: list<EmailAddress>, address: ?string, errors: list<string>} what the list shows
     */
    public function view(User $user, ?string $address = null, array $errors = []): array
    {
        return [
            'user' => $user,
            'addresses' => $this->em->getRepository(EmailAddress::class)->findBy(['user' => $user, 'isDefault' => false], ['id' => 'ASC']),
            'address' => $address,
            'errors' => $errors,
        ];
    }
}
