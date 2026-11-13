<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\AuthSource;
use App\Entity\EmailAddress;
use App\Entity\Import;
use App\Entity\ImportItem;
use App\Entity\Principal;
use App\Entity\User;
use App\Entity\UserCustomField;
use App\Event\ContentEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Port of app/models/user_import.rb with User/EmailAddress validations (login format & uniqueness,
 * names, e-mail format/uniqueness/allowed domains, password length & complexity, custom fields).
 */
final class UserImporter extends AbstractImporter
{
    public const array AUTO_MAPPABLE_FIELDS = [
        'login' => 'field_login',
        'firstname' => 'field_firstname',
        'lastname' => 'field_lastname',
        'mail' => 'field_mail',
        'language' => 'field_language',
        'admin' => 'field_admin',
        'auth_source' => 'field_auth_source',
        'password' => 'field_password',
        'must_change_passwd' => 'field_must_change_passwd',
        'status' => 'field_status',
        'mail_notification' => 'field_mail_notification',
    ];

    /** User::LABEL_BY_STATUS */
    public const array LABEL_BY_STATUS = [
        Principal::STATUS_ANONYMOUS => 'anon',
        Principal::STATUS_ACTIVE => 'active',
        Principal::STATUS_REGISTERED => 'registered',
        Principal::STATUS_LOCKED => 'locked',
    ];

    private const array PASSWORD_CHAR_CLASSES = [
        'uppercase' => '/[A-Z]/',
        'lowercase' => '/[a-z]/',
        'digits' => '/[0-9]/',
        'special_chars' => '/[!-\/:-@\[-`{-~]/',
    ];

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    public static function type(): string
    {
        return 'UserImport';
    }

    public static function partialPrefix(): string
    {
        return 'users';
    }

    public function autoMappableFields(): array
    {
        return self::AUTO_MAPPABLE_FIELDS;
    }

    public function menuItem(): ?string
    {
        return 'users';
    }

    public function layout(): string
    {
        return 'admin';
    }

    public function isAuthorized(User $user): bool
    {
        return $user->admin;
    }

    /** @return list<User> */
    public function savedObjects(Import $import): array
    {
        $ids = $this->savedObjectIds($import);

        return [] === $ids ? [] : $this->em->getRepository(User::class)->findBy(['id' => $ids], ['id' => 'ASC']);
    }

    public function mappableCustomFields(Import $import): array
    {
        return $this->em->getRepository(UserCustomField::class)->findBy([], ['position' => 'ASC']);
    }

    protected function importRow(Import $import, array $row, ImportItem $item): array
    {
        $errors = [];
        $login = $this->rowValue($import, $row, 'login');
        $firstname = $this->rowValue($import, $row, 'firstname');
        $lastname = $this->rowValue($import, $row, 'lastname');
        $mail = $this->rowValue($import, $row, 'mail');
        $mail = null === $mail ? null : trim($mail);

        $lang = null;
        if (null !== ($language = $this->rowValue($import, $row, 'language'))) {
            foreach ($this->i18n->availableLocales() as $l) {
                if (0 === strcasecmp($l, trim($language))) {
                    $lang = $l;
                }
            }
        }
        $lang ??= $this->settings->string('default_language');

        $admin = $this->yes($import, $this->rowValue($import, $row, 'admin'));
        $authSource = null;
        if (null !== ($authName = $this->rowValue($import, $row, 'auth_source'))) {
            $authSource = $this->em->getRepository(AuthSource::class)->findOneBy(['name' => $authName]);
        }
        $password = $this->rowValue($import, $row, 'password');
        $mustChange = $this->yes($import, $this->rowValue($import, $row, 'must_change_passwd'));
        $status = Principal::STATUS_ACTIVE;
        if (null !== ($statusName = $this->rowValue($import, $row, 'status'))) {
            $key = array_search($statusName, self::LABEL_BY_STATUS, true);
            if (false !== $key) {
                $status = $key;
            }
        }
        $mailNotification = $this->settings->string('default_notification_option') ?: 'only_my_events';
        if (null !== ($mn = $this->rowValue($import, $row, 'mail_notification')) && \in_array(trim($mn), User::MAIL_NOTIFICATION_OPTIONS, true)) {
            $mailNotification = trim($mn);
        }

        // --- validations (User + EmailAddress)
        if (null === $login) {
            $errors[] = $this->error('field_login', 'blank');
        } else {
            if (!preg_match('/\A[a-z0-9_\-@.]*\z/i', $login)) {
                $errors[] = $this->error('field_login', 'invalid');
            }
            if (mb_strlen($login) > 60) {
                $errors[] = $this->error('field_login', 'too_long', ['count' => 60]);
            }
            $taken = (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM users WHERE LOWER(login) = LOWER(?) AND type IN ('User', 'AnonymousUser')", [$login]);
            if ($taken > 0) {
                $errors[] = $this->error('field_login', 'taken');
            }
        }
        if (null === $firstname) {
            $errors[] = $this->error('field_firstname', 'blank');
        } elseif (mb_strlen($firstname) > 30) {
            $errors[] = $this->error('field_firstname', 'too_long', ['count' => 30]);
        }
        if (null === $lastname) {
            $errors[] = $this->error('field_lastname', 'blank');
        } elseif (mb_strlen($lastname) > 255) {
            $errors[] = $this->error('field_lastname', 'too_long', ['count' => 255]);
        }
        if (null === $mail || '' === $mail) {
            $errors[] = $this->error('field_mail', 'blank');
        } else {
            if (false === filter_var($mail, \FILTER_VALIDATE_EMAIL)) {
                $errors[] = $this->error('field_mail', 'invalid');
            } elseif (!$this->isDomainAllowed($mail)) {
                $errors[] = $this->error('field_mail', 'domain_not_allowed', ['domain' => substr($mail, (int) strrpos($mail, '@') + 1)]);
            }
            if (mb_strlen($mail) > 254) {
                $errors[] = $this->error('field_mail', 'too_long', ['count' => 254]);
            }
            $taken = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM email_addresses WHERE LOWER(address) = LOWER(?)', [$mail]);
            if ($taken > 0) {
                $errors[] = $this->error('field_mail', 'taken');
            }
        }
        if (null !== $password) {
            $min = $this->settings->int('password_min_length');
            if (mb_strlen($password) < $min) {
                $errors[] = $this->error('field_password', 'too_short', ['count' => $min]);
            }
            foreach ($this->settings->array('password_required_char_classes') as $class) {
                if (isset(self::PASSWORD_CHAR_CLASSES[$class]) && !preg_match(self::PASSWORD_CHAR_CLASSES[$class], $password)) {
                    $errors[] = $this->error('field_password', 'must_contain_'.$class);
                }
            }
        }
        $fields = $this->mappableCustomFields($import);
        $cfValues = $this->customFieldValues($import, $row, $fields, null);
        foreach ($fields as $cf) {
            if (false === $cf->editable && !$import->user->admin) {
                unset($cfValues[(string) $cf->id]);
                continue;
            }
            $v = $cfValues[(string) $cf->id] ?? $cf->defaultValue;
            array_push($errors, ...$this->customFields->validate($cf, $this->customFields->normalize($cf, $v), null));
        }
        if ([] !== $errors) {
            return [null, array_values(array_unique($errors))];
        }

        $user = new User();
        $user->login = (string) $login;
        $user->firstname = (string) $firstname;
        $user->lastname = (string) $lastname;
        $user->language = $lang;
        $user->admin = $admin;
        $user->authSourceId = $authSource?->id;
        $user->mustChangePasswd = $mustChange;
        $user->status = $status;
        $user->mailNotification = $mailNotification;
        if (null !== $password) {
            $user->hashedPassword = $this->hasher->hashPassword($user, $password);
            $user->salt = null;
            $user->passwdChangedOn = new \DateTimeImmutable();
        }
        $address = new EmailAddress();
        $address->user = $user;
        $address->address = (string) $mail;
        $address->isDefault = true;
        $user->emailAddresses->add($address);
        $this->em->persist($user);
        $this->em->flush();
        $this->customFields->saveValues('Principal', (int) $user->id, $fields, $cfValues + $this->customFields->defaultValues($fields));
        $this->em->flush();

        // kept for extend_object (account information e-mail)
        $this->passwords[$user->id] = $password;

        return [$user, []];
    }

    /** @var array<int, string|null> */
    private array $passwords = [];

    /** UserImport#extend_object: account information e-mail when notifications are enabled */
    protected function extendObject(Import $import, array $row, ImportItem $item, object $object): void
    {
        \assert($object instanceof User);
        if ($this->yes($import, (string) ($this->settingsOf($import)['notifications'] ?? '0'))) {
            $this->events->dispatch(new ContentEvent('user', 'created', $object, $import->user, [
                'account_information' => true,
                'password' => $this->passwords[$object->id] ?? null,
            ]));
        }
        unset($this->passwords[$object->id]);
    }

    private function isDomainAllowed(string $mail): bool
    {
        $domain = strtolower(substr($mail, (int) strrpos($mail, '@') + 1));
        if ('' === $domain) {
            return true;
        }
        $in = static function (string $list) use ($domain): bool {
            foreach (preg_split('/[\s,]+/', strtolower($list)) ?: [] as $s) {
                if ('' !== $s && (str_starts_with($s, '.') ? str_ends_with($domain, $s) : $domain === $s)) {
                    return true;
                }
            }

            return false;
        };
        $denied = $this->settings->string('email_domains_denied');
        $allowed = $this->settings->string('email_domains_allowed');
        if ('' !== trim($denied) && $in($denied)) {
            return false;
        }

        return '' === trim($allowed) || $in($allowed);
    }
}
