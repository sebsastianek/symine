<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AnonymousUser;
use App\Entity\EmailAddress;
use App\Entity\Member;
use App\Entity\Principal;
use App\Entity\Token;
use App\Entity\User;
use App\Entity\UserCustomField;
use App\Entity\UserPreference;
use App\I18n\Localizer;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Account related logic ported from Redmine's User, Token, EmailAddress and UserPreference models:
 * password policy, tokens (api/feeds/recovery/register), user validation, notified projects,
 * own account deletion.
 */
final class AccountService
{
    /** Same as Setting::PASSWORD_CHAR_CLASSES */
    public const array PASSWORD_CHAR_CLASSES = [
        'uppercase' => '/[A-Z]/',
        'lowercase' => '/[a-z]/',
        'digits' => '/[0-9]/',
        'special_chars' => '/[\x21-\x2F\x3A-\x40\x5B-\x60\x7B-\x7E]/',
    ];

    /** Token actions: [max_instances, validity in seconds (null = never expires; 'autologin' => setting)] */
    public const array TOKEN_ACTIONS = [
        'api' => [1, null],
        'autologin' => [10, 'autologin'],
        'feeds' => [1, null],
        'recovery' => [1, 86400],
        'register' => [1, 86400],
        'session' => [10, null],
        'twofa_backup_code' => [10, null],
    ];

    public const string EMAIL_REGEXP = '/\A[a-zA-Z0-9.!\#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*\z/';

    public const array TEXTAREA_FONT_OPTIONS = ['monospace', 'proportional'];
    public const array DEFAULT_TOOLBAR_LANGUAGE_OPTIONS = ['c', 'cpp', 'csharp', 'css', 'diff', 'go', 'groovy', 'html', 'java', 'javascript', 'objc', 'perl', 'php', 'python', 'r', 'ruby', 'sass', 'scala', 'shell', 'sql', 'swift', 'xml', 'yaml'];
    public const array AUTO_WATCH_ON_OPTIONS = ['issue_created', 'issue_contributed_to', 'issue_assigned_to_me'];

    /** @var array<string, string>|null */
    private static ?array $languageOptions = null;

    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly CurrentUser $currentUser,
        private readonly CustomFieldService $customFields,
    ) {
    }

    /**
     * Language options (locale => general_lang_name) read directly from the translation files, so that
     * the 50 catalogues don't have to be loaded in memory (lang_options_for_select).
     *
     * @return array<string, string>
     */
    public function languageOptions(): array
    {
        if (null === self::$languageOptions) {
            $out = [];
            foreach (glob($this->projectDir.'/translations/messages.*.yaml') ?: [] as $f) {
                $code = substr(basename($f, '.yaml'), \strlen('messages.'));
                $fh = fopen($f, 'r');
                $name = $code;
                while ($fh && false !== ($line = fgets($fh))) {
                    if (str_starts_with($line, 'general_lang_name:')) {
                        $name = trim(substr($line, \strlen('general_lang_name:')));
                        if (preg_match("/^'(.*)'$/", $name, $m)) {
                            $name = str_replace("''", "'", $m[1]);
                        } elseif (preg_match('/^"(.*)"$/', $name, $m)) {
                            $name = stripcslashes($m[1]);
                        }
                        break;
                    }
                }
                if ($fh) {
                    fclose($fh);
                }
                $out[$code] = $name;
            }
            asort($out);
            self::$languageOptions = $out;
        }

        return self::$languageOptions;
    }

    // --- Tokens ---------------------------------------------------------------

    public function tokenOf(User $user, string $action): ?Token
    {
        if (null === $user->id) {
            return null;
        }

        return $this->em->getRepository(Token::class)->findOneBy(['user' => $user, 'action' => $action], ['id' => 'DESC']);
    }

    /** Creates a token, deleting previous tokens of the same action beyond max_instances (Token#delete_previous_tokens) */
    public function createToken(User $user, string $action): Token
    {
        $max = self::TOKEN_ACTIONS[$action][0] ?? 1;
        $conn = $this->em->getConnection();
        if (null !== $user->id) {
            if ($max > 1) {
                $ids = $conn->fetchFirstColumn('SELECT id FROM tokens WHERE user_id = ? AND action = ? ORDER BY updated_on DESC', [$user->id, $action]);
                $ids = \array_slice($ids, $max - 1);
                if ([] !== $ids) {
                    $conn->executeStatement('DELETE FROM tokens WHERE id IN ('.implode(',', array_map('intval', $ids)).')');
                }
            } else {
                $conn->executeStatement('DELETE FROM tokens WHERE user_id = ? AND action = ?', [$user->id, $action]);
            }
        }
        $token = new Token($user, $action);
        $this->em->persist($token);
        $this->em->flush();

        return $token;
    }

    public function apiKey(User $user): string
    {
        return ($this->tokenOf($user, 'api') ?? $this->createToken($user, 'api'))->value;
    }

    public function atomKey(User $user): string
    {
        return ($this->tokenOf($user, 'feeds') ?? $this->createToken($user, 'feeds'))->value;
    }

    public function isTokenExpired(Token $token): bool
    {
        $limit = $this->invalidWhenCreatedBefore($token->action);

        return null !== $limit && $token->createdOn < $limit;
    }

    public function isTokenUsed(Token $token): bool
    {
        return null !== $token->updatedOn && $token->updatedOn > $token->createdOn;
    }

    private function invalidWhenCreatedBefore(string $action): ?\DateTimeImmutable
    {
        $validity = \array_key_exists($action, self::TOKEN_ACTIONS) ? self::TOKEN_ACTIONS[$action][1] : 86400;
        if ('autologin' === $validity) {
            $validity = $this->settings->int('autologin') * 86400;
        }
        if (null === $validity) {
            return null;
        }

        return new \DateTimeImmutable('-'.(int) $validity.' seconds');
    }

    /** Token.find_token */
    public function findToken(string $action, string $value): ?Token
    {
        if ('' === $action || !preg_match('/\A[a-z0-9]+\z/i', $value)) {
            return null;
        }
        $token = $this->em->getRepository(Token::class)->findOneBy(['action' => $action, 'value' => $value]);
        if (null === $token || !hash_equals($token->value, $value)) {
            return null;
        }

        return $token;
    }

    public function deleteLoginTokens(User $user): void
    {
        if (null !== $user->id) {
            $this->em->getConnection()->executeStatement("DELETE FROM tokens WHERE user_id = ? AND action IN ('recovery', 'autologin', 'session')", [$user->id]);
        }
    }

    // --- Passwords ------------------------------------------------------------

    public function checkPassword(User $user, string $plain): bool
    {
        if ('' === $plain || '' === $user->hashedPassword) {
            return false;
        }
        try {
            return $this->hasher->isPasswordValid($user, $plain);
        } catch (\Throwable) {
            return false;
        }
    }

    /** User#change_password_allowed? (LDAP sources don't allow password changes) */
    public function changePasswordAllowed(User $user): bool
    {
        return null === $user->authSourceId;
    }

    public function isPasswordExpired(User $user): bool
    {
        $period = $this->settings->int('password_max_age');
        if (0 === $period) {
            return false;
        }
        $changed = $user->passwdChangedOn ?? new \DateTimeImmutable('@0');

        return $changed < new \DateTimeImmutable('-'.$period.' days');
    }

    public function mustChangePassword(User $user): bool
    {
        return ($user->mustChangePasswd || $this->isPasswordExpired($user)) && $this->changePasswordAllowed($user);
    }

    /**
     * Password validations of the User model (length, char classes, complexity, confirmation).
     *
     * @return list<string> full error messages
     */
    public function passwordErrors(User $user, ?string $password, ?string $confirmation = null): array
    {
        if (null === $password) {
            return [];
        }
        $label = $this->i18n->l('field_password');
        $errors = [];
        foreach (self::PASSWORD_CHAR_CLASSES as $class => $regexp) {
            if ('' !== $password && \in_array($class, $this->settings->array('password_required_char_classes'), true) && !preg_match($regexp, $password)) {
                $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.must_contain_'.$class);
            }
        }
        $min = $this->settings->int('password_min_length');
        if (mb_strlen($password) < $min) {
            $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.too_short', ['count' => $min]);
        }
        $bad = [$user->login, $user->firstname, $user->lastname];
        foreach ($user->emailAddresses as $a) {
            $bad[] = $a->address;
        }
        foreach ($bad as $b) {
            if ('' !== (string) $b && 0 === strcasecmp($password, (string) $b)) {
                $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.too_simple');
                break;
            }
        }
        if (null !== $confirmation && $password !== $confirmation) {
            $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.confirmation');
        }

        return $errors;
    }

    /** Sets a new password (hash with the current algorithm), like User#salt_password + destroy_tokens */
    public function setPassword(User $user, string $plain): void
    {
        $user->salt = null;
        $user->hashedPassword = '';
        $user->hashedPassword = $this->hasher->hashPassword($user, $plain);
        $user->passwdChangedOn = new \DateTimeImmutable('now');
        $this->deleteLoginTokens($user);
    }

    /** User#random_password */
    public function randomPassword(int $length = 40): string
    {
        $lists = [range('A', 'Z'), range('a', 'z'), range('0', '9')];
        if (\in_array('special_chars', $this->settings->array('password_required_char_classes'), true)) {
            $special = [];
            for ($c = 0x20; $c <= 0x7E; ++$c) {
                if (preg_match(self::PASSWORD_CHAR_CLASSES['special_chars'], \chr($c))) {
                    $special[] = \chr($c);
                }
            }
            $lists[] = $special;
        }
        foreach ($lists as &$l) {
            $l = array_values(array_filter($l, static fn ($c) => !str_contains("0O1l|'\"`*", $c)));
        }
        unset($l);
        $pw = [];
        foreach ($lists as $l) {
            $pw[] = $l[random_int(0, \count($l) - 1)];
            --$length;
        }
        $all = array_merge(...$lists);
        for ($i = 0; $i < $length; ++$i) {
            $pw[] = $all[random_int(0, \count($all) - 1)];
        }
        shuffle($pw);

        return implode('', $pw);
    }

    // --- Users ----------------------------------------------------------------

    public function findByMail(string $mail): ?User
    {
        $mail = mb_strtolower(trim($mail));
        if ('' === $mail) {
            return null;
        }
        $u = $this->em->createQuery('SELECT u FROM App\Entity\User u JOIN u.emailAddresses e WHERE LOWER(e.address) = :m')
            ->setParameter('m', $mail)->setMaxResults(1)->getOneOrNullResult();

        return $u instanceof User && !$u instanceof AnonymousUser ? $u : null;
    }

    /** New user with default preferences (UserPreference#initialize) */
    public function newUser(): User
    {
        $user = new User();
        $user->status = Principal::STATUS_REGISTERED;
        $user->language = $this->settings->string('default_language');
        $user->mailNotification = $this->settings->string('default_notification_option') ?: 'only_my_events';
        $pref = $user->getPref();
        $this->applyDefaultPreferences($pref);

        return $user;
    }

    public function applyDefaultPreferences(UserPreference $pref): void
    {
        $pref->hideMail = $this->settings->bool('default_users_hide_mail');
        $pref->timeZone = $this->settings->string('default_users_time_zone');
        $pref->set('no_self_notified', $this->settings->bool('default_users_no_self_notified') ? '1' : '0');
        $auto = $this->settings->get('default_users_auto_watch_on');
        $pref->set('auto_watch_on', null === $auto ? self::AUTO_WATCH_ON_OPTIONS : array_values((array) $auto));
    }

    public static function isValidEmail(string $address): bool
    {
        return (bool) preg_match(self::EMAIL_REGEXP, $address);
    }

    /** EmailAddress.valid_domain? */
    public function isValidEmailDomain(string $address): bool
    {
        $domain = mb_strtolower((string) substr(strrchr($address, '@') ?: '@'.$address, 1));
        $denied = $this->settings->string('email_domains_denied');
        $allowed = $this->settings->string('email_domains_allowed');
        if ('' !== trim($denied) && self::domainIn($domain, $denied)) {
            return false;
        }
        if ('' !== trim($allowed) && !self::domainIn($domain, $allowed)) {
            return false;
        }

        return true;
    }

    private static function domainIn(string $domain, string $list): bool
    {
        foreach (preg_split('/[\s,]+/', mb_strtolower($list)) ?: [] as $s) {
            if ('' === $s) {
                continue;
            }
            if (str_starts_with($s, '.') ? str_ends_with($domain, $s) : $domain === $s) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validation errors for an email address (EmailAddress validations).
     *
     * @return list<string>
     */
    public function emailErrors(string $address, ?int $exceptAddressId = null, ?string $label = null): array
    {
        $label ??= $this->i18n->l('field_mail');
        $address = trim($address);
        if ('' === $address) {
            return [$label.' '.$this->i18n->l('activerecord.errors.messages.blank')];
        }
        $errors = [];
        if (!self::isValidEmail($address)) {
            $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.invalid');
        }
        if (mb_strlen($address) > 254) {
            $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 254]);
        }
        $existing = $this->em->getConnection()->fetchOne('SELECT id FROM email_addresses WHERE LOWER(address) = ?'.(null !== $exceptAddressId ? ' AND id <> '.(int) $exceptAddressId : ''), [mb_strtolower($address)]);
        if (false !== $existing) {
            $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.taken');
        }
        if ([] === $errors && !$this->isValidEmailDomain($address)) {
            $domain = substr((string) strrchr($address, '@'), 1);
            $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.domain_not_allowed', ['domain' => $domain]);
        }

        return $errors;
    }

    /**
     * User model validations (login, names, mail, mail_notification).
     *
     * @return list<string>
     */
    public function userErrors(User $user, bool $loginChanged = false, bool $mailChanged = false): array
    {
        $errors = [];
        $blank = $this->i18n->l('activerecord.errors.messages.blank');
        foreach (['login' => 'field_login', 'firstname' => 'field_firstname', 'lastname' => 'field_lastname'] as $prop => $key) {
            if ('' === trim((string) $user->{$prop})) {
                $errors[] = $this->i18n->l($key).' '.$blank;
            }
        }
        $loginLabel = $this->i18n->l('field_login');
        if ('' !== $user->login) {
            if ($loginChanged || null === $user->id) {
                $exists = $this->em->getConnection()->fetchOne("SELECT id FROM users WHERE LOWER(login) = ? AND type IN ('User', 'AnonymousUser')".($user->id ? ' AND id <> '.(int) $user->id : ''), [mb_strtolower($user->login)]);
                if (false !== $exists) {
                    $errors[] = $loginLabel.' '.$this->i18n->l('activerecord.errors.messages.taken');
                }
            }
            if (!preg_match('/\A[a-z0-9_\-@.]*\z/i', $user->login)) {
                $errors[] = $loginLabel.' '.$this->i18n->l('activerecord.errors.messages.invalid');
            }
            if (mb_strlen($user->login) > 60) {
                $errors[] = $loginLabel.' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 60]);
            }
        }
        if (mb_strlen($user->firstname) > 30) {
            $errors[] = $this->i18n->l('field_firstname').' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 30]);
        }
        if (mb_strlen($user->lastname) > 255) {
            $errors[] = $this->i18n->l('field_lastname').' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 255]);
        }
        if ('' !== $user->mailNotification && !\in_array($user->mailNotification, [...User::MAIL_NOTIFICATION_OPTIONS, 'only_my_watches'], true)) {
            $errors[] = $this->i18n->l('field_mail_notification').' '.$this->i18n->l('activerecord.errors.messages.inclusion');
        }
        $default = null;
        foreach ($user->emailAddresses as $a) {
            if ($a->isDefault) {
                $default = $a;
            }
        }
        if (null === $default || '' === trim($default->address)) {
            $errors[] = $this->i18n->l('field_mail').' '.$blank;
        } elseif ($mailChanged || null === $user->id) {
            $errors = array_merge($errors, $this->emailErrors($default->address, $default->id));
        }

        return $errors;
    }

    /**
     * Validates custom field values for a user (only editable ones unless $all).
     *
     * @param list<\App\Entity\CustomField> $fields
     * @param array<int|string, mixed>      $values
     *
     * @return list<string>
     */
    public function customFieldErrors(array $fields, array $values): array
    {
        $errors = [];
        foreach ($fields as $f) {
            $errors = array_merge($errors, $this->customFields->validate($f, $values[$f->id] ?? $values[(string) $f->id] ?? null));
        }

        return $errors;
    }

    /** @return list<UserCustomField> custom fields editable by the user himself */
    public function editableUserCustomFields(User $user, bool $registration = false): array
    {
        $out = [];
        foreach ($this->customFields->fieldsOf(UserCustomField::class) as $f) {
            $editable = ($f->editable ?? true) && $f->visible;
            if ($registration) {
                if (($this->settings->bool('show_custom_fields_on_registration') && $editable) || $f->isRequired) {
                    $out[] = $f;
                }
            } elseif ($editable || $this->currentUser->get()->admin) {
                $out[] = $f;
            }
        }

        return $out;
    }

    /** Ids of projects for which the user has explicitly turned notifications on */
    public function notifiedProjectIds(User $user): array
    {
        if (null === $user->id) {
            return [];
        }

        return array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT project_id FROM members WHERE user_id = ? AND mail_notification = ?', [$user->id, 1]));
    }

    /** @param list<int|string> $ids */
    public function updateNotifiedProjectIds(User $user, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if ('selected' !== $user->mailNotification) {
            $ids = [];
        }
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE members SET mail_notification = ? WHERE user_id = ?', [0, $user->id]);
        if ([] !== $ids) {
            $conn->executeStatement('UPDATE members SET mail_notification = ? WHERE user_id = ? AND project_id IN ('.implode(',', $ids).')', [1, $user->id]);
        }
    }

    /** @return list<array{0: string, 1: string}> [value, label] */
    public function mailNotificationOptions(User $user): array
    {
        $hasMemberships = null !== $user->id && (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM members WHERE user_id = ?', [$user->id]) > 0;
        $labels = [
            'all' => 'label_user_mail_option_all',
            'selected' => 'label_user_mail_option_selected',
            'only_my_events' => 'label_user_mail_option_only_my_events',
            'only_assigned' => 'label_user_mail_option_only_assigned',
            'only_owner' => 'label_user_mail_option_only_owner',
            'only_my_watches' => 'label_user_mail_option_only_my_watches',
            'none' => 'label_user_mail_option_none',
        ];
        $out = [];
        foreach ($labels as $v => $k) {
            if ('selected' === $v && !$hasMemberships) {
                continue;
            }
            $out[] = [$v, $this->i18n->l($k)];
        }

        return $out;
    }

    public function ownAccountDeletable(User $user): bool
    {
        if (!$this->settings->bool('unsubscribe')) {
            return false;
        }
        if (!$user->admin) {
            return true;
        }

        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM users WHERE type = 'User' AND admin = ? AND status = ? AND id <> ?", [1, Principal::STATUS_ACTIVE, $user->id]) > 0;
    }

    /** User#destroy with remove_references_before_destroy */
    public function destroyUser(User $user): void
    {
        $id = (int) $user->id;
        $anon = $this->currentUser->anonymous();
        $sub = (int) $anon->id;
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $updates = [
                'UPDATE attachments SET author_id = :s WHERE author_id = :id',
                'UPDATE comments SET author_id = :s WHERE author_id = :id',
                'UPDATE issues SET author_id = :s WHERE author_id = :id',
                'UPDATE issues SET assigned_to_id = NULL WHERE assigned_to_id = :id',
                'UPDATE journals SET user_id = :s WHERE user_id = :id',
                'UPDATE journals SET updated_by_id = :s WHERE updated_by_id = :id',
                "UPDATE journal_details SET old_value = :ss WHERE property = 'attr' AND prop_key = 'assigned_to_id' AND old_value = :ids",
                "UPDATE journal_details SET value = :ss WHERE property = 'attr' AND prop_key = 'assigned_to_id' AND value = :ids",
                'UPDATE messages SET author_id = :s WHERE author_id = :id',
                'UPDATE news SET author_id = :s WHERE author_id = :id',
                'DELETE FROM queries_roles WHERE query_id IN (SELECT id FROM queries WHERE user_id = :id AND visibility = 0)',
                'DELETE FROM queries WHERE user_id = :id AND visibility = 0',
                'UPDATE queries SET user_id = :s WHERE user_id = :id',
                'UPDATE time_entries SET user_id = :s WHERE user_id = :id',
                'DELETE FROM tokens WHERE user_id = :id',
                'DELETE FROM watchers WHERE user_id = :id',
                'UPDATE wiki_contents SET author_id = :s WHERE author_id = :id',
                'UPDATE wiki_content_versions SET author_id = :s WHERE author_id = :id',
                "UPDATE custom_values SET value = '' WHERE value = :ids AND custom_field_id IN (SELECT id FROM custom_fields WHERE field_format = 'user')",
                'DELETE FROM member_roles WHERE member_id IN (SELECT id FROM members WHERE user_id = :id)',
                'DELETE FROM members WHERE user_id = :id',
                'DELETE FROM groups_users WHERE user_id = :id',
                'DELETE FROM email_addresses WHERE user_id = :id',
                'DELETE FROM user_preferences WHERE user_id = :id',
                "DELETE FROM custom_values WHERE customized_type = 'Principal' AND customized_id = :id",
                'DELETE FROM reactions WHERE user_id = :id',
                'DELETE FROM users WHERE id = :id',
            ];
            foreach ($updates as $sql) {
                $params = [];
                foreach (['s' => $sub, 'id' => $id, 'ss' => (string) $sub, 'ids' => (string) $id] as $k => $v) {
                    if (preg_match('/:'.$k.'\b/', $sql)) {
                        $params[$k] = $v;
                    }
                }
                try {
                    $conn->executeStatement($sql, $params);
                } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
                    // optional table
                }
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
        $this->em->detach($user);
    }

    /** Default email address object of a user (creates it when missing) */
    public function defaultEmailAddress(User $user): EmailAddress
    {
        foreach ($user->emailAddresses as $a) {
            if ($a->isDefault) {
                return $a;
            }
        }
        $user->setMail('');
        foreach ($user->emailAddresses as $a) {
            if ($a->isDefault) {
                return $a;
            }
        }
        throw new \LogicException('unreachable');
    }

    /** @return list<Member> */
    public function memberships(User $user): array
    {
        if (null === $user->id) {
            return [];
        }

        return $this->em->createQuery('SELECT m, p FROM App\Entity\Member m JOIN m.project p WHERE m.principal = :u AND p.status <> 9 ORDER BY p.lft')
            ->setParameter('u', $user->id)->getResult();
    }
}
