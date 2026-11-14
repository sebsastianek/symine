<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\AnonymousUser;
use App\Entity\AuthSource;
use App\Entity\CustomField;
use App\Entity\EmailAddress;
use App\Entity\Group;
use App\Entity\GroupAnonymous;
use App\Entity\GroupNonMember;
use App\Entity\Member;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\User;
use App\Entity\UserCustomField;
use App\Entity\GroupCustomField;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\CustomFieldService;
use App\Service\MembershipService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * User/Group management logic of Redmine's User, Group and Principal models
 * (safe attributes, validations, password generation, destroy with references cleanup).
 */
final class UserManager
{
    public const array STATUS_LABELS = [
        Principal::STATUS_ACTIVE => 'status_active',
        Principal::STATUS_REGISTERED => 'status_registered',
        Principal::STATUS_LOCKED => 'status_locked',
    ];

    public const array MAIL_NOTIFICATION_OPTIONS = [
        'all' => 'label_user_mail_option_all',
        'selected' => 'label_user_mail_option_selected',
        'only_my_events' => 'label_user_mail_option_only_my_events',
        'only_assigned' => 'label_user_mail_option_only_assigned',
        'only_owner' => 'label_user_mail_option_only_owner',
        'only_my_watches' => 'label_user_mail_option_only_my_watches',
        'none' => 'label_user_mail_option_none',
    ];

    public const array AUTO_WATCH_ON_OPTIONS = ['issue_created', 'issue_contributed_to', 'issue_assigned_to_me'];
    public const string DEFAULT_TOOLBAR_LANGUAGE_OPTIONS = 'c,cpp,csharp,css,diff,go,groovy,html,java,javascript,objc,perl,php,python,r,ruby,sass,scala,shell,sql,swift,xml,yaml';

    private const array PASSWORD_CHAR_CLASSES = [
        'uppercase' => '/[A-Z]/',
        'lowercase' => '/[a-z]/',
        'digits' => '/[0-9]/',
        'special_chars' => '/[[:punct:] ]/',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly CustomFieldService $customFields,
        private readonly MembershipService $memberships,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly SecurityNotifier $security,
        private readonly \App\Service\SyntaxHighlighting $highlighting,
    ) {
    }

    // --- creation ---------------------------------------------------------

    public function newUser(): User
    {
        $user = new User();
        $user->language = $this->settings->string('default_language');
        $user->mailNotification = $this->settings->string('default_notification_option') ?: 'only_my_events';
        $pref = $user->getPref();
        $pref->hideMail = $this->settings->bool('default_users_hide_mail');
        $pref->timeZone = $this->settings->string('default_users_time_zone') ?: null;
        $pref->set('no_self_notified', $this->settings->bool('default_users_no_self_notified') ? '1' : '0');
        $autoWatch = $this->settings->get('default_users_auto_watch_on');
        $pref->set('auto_watch_on', \is_array($autoWatch) ? array_values($autoWatch) : self::AUTO_WATCH_ON_OPTIONS);

        return $user;
    }

    /**
     * Applies safe attributes (as admin) and saves the user. Returns [errors, clearPassword].
     *
     * @param array<string, mixed> $attrs user[...] params
     * @param array<string, mixed>|null $pref  pref[...] params
     *
     * @return array{0: list<string>, 1: ?string}
     */
    public function save(User $user, array $attrs, ?array $pref = null): array
    {
        $isNew = null === $user->id;
        $current = $this->currentUser->get();
        $errors = [];
        $before = $this->security->snapshot($user);
        $previousMail = $isNew ? null : $user->getMail();

        foreach (['firstname', 'lastname'] as $k) {
            if (\array_key_exists($k, $attrs)) {
                $user->$k = trim((string) $attrs[$k]);
            }
        }
        if (\array_key_exists('language', $attrs)) {
            $user->language = (string) $attrs['language'];
        }
        if (\array_key_exists('mail_notification', $attrs)) {
            $user->mailNotification = (string) $attrs['mail_notification'];
        }
        if (\array_key_exists('login', $attrs) && ($isNew || $current->admin)) {
            $user->login = trim((string) $attrs['login']);
        }
        $statusChanged = null;
        if ($current->admin) {
            if (\array_key_exists('status', $attrs) && '' !== (string) $attrs['status']) {
                $status = (int) $attrs['status'];
                if (isset(self::STATUS_LABELS[$status])) {
                    $statusChanged = [$user->status, $status];
                    $user->status = $status;
                } else {
                    $errors[] = $this->i18n->l('field_status').' '.$this->i18n->l('activerecord.errors.messages.invalid');
                }
            }
            if (\array_key_exists('auth_source_id', $attrs)) {
                $id = (int) $attrs['auth_source_id'];
                $user->authSourceId = $id > 0 && null !== $this->em->find(AuthSource::class, $id) ? $id : null;
            }
            if (\array_key_exists('must_change_passwd', $attrs)) {
                $user->mustChangePasswd = self::bool($attrs['must_change_passwd']);
            }
            if (\array_key_exists('admin', $attrs) && !($user->id && $user->id === $current->id)) {
                $user->admin = self::bool($attrs['admin']);
            }
        }

        // email
        $mail = \array_key_exists('mail', $attrs) ? trim((string) $attrs['mail']) : $user->getMail();
        if (\array_key_exists('mail', $attrs)) {
            $errors = [...$errors, ...$this->validateMail($mail, $user)];
        } elseif ($isNew) {
            $errors[] = $this->i18n->l('field_mail').' '.$this->i18n->l('activerecord.errors.messages.blank');
        }

        // password
        $generate = $current->admin && self::bool($attrs['generate_password'] ?? false);
        $password = null;
        if (null === $user->authSourceId) {
            $pw = (string) ($attrs['password'] ?? '');
            if ('' !== $pw) {
                $password = $pw;
                $confirmation = $attrs['password_confirmation'] ?? null;
                if (null !== $confirmation && $pw !== (string) $confirmation) {
                    $errors[] = $this->i18n->l('field_password').' '.$this->i18n->l('activerecord.errors.messages.confirmation');
                }
                $errors = [...$errors, ...$this->validatePassword($pw, $user, $mail)];
            } elseif ($generate) {
                $password = $this->randomPassword(max($this->settings->int('password_min_length') + 2, 10));
            } elseif ($isNew) {
                $errors[] = $this->i18n->l('field_password').' '.$this->i18n->l('activerecord.errors.messages.too_short', ['count' => $this->settings->int('password_min_length')]);
            }
        }

        $errors = [...$this->validateUser($user), ...$errors];

        // custom fields
        $cfValues = $this->customFieldParams($attrs);
        $fields = $this->userCustomFields();
        $storedCf = $isNew ? [] : $this->customFields->values('Principal', $user->id);
        foreach ($fields as $f) {
            if (!\array_key_exists($f->id, $cfValues)) {
                if ($isNew && $f->isRequired && (null === $f->defaultValue || '' === $f->defaultValue)) {
                    $errors = [...$errors, ...$this->customFields->validate($f, null)];
                }
                continue;
            }
            $errors = [...$errors, ...$this->customFields->validate($f, $cfValues[$f->id], null, $storedCf[$f->id] ?? null)];
        }

        if ([] !== $errors) {
            return [array_values(array_unique($errors)), null];
        }

        if (null !== $mail && '' !== $mail) {
            // EmailAddress#destroy_tokens: changing the address removes the login tokens
            if (!$isNew && $mail !== $user->getMail()) {
                $this->deleteLoginTokens($user);
            }
            $user->setMail($mail);
        }
        if (null !== $password) {
            $this->setPassword($user, $password);
        }
        if ($isNew) {
            $user->status = $statusChanged[1] ?? $user->status;
            $this->em->persist($user);
        }
        foreach ($user->emailAddresses as $ea) {
            $this->em->persist($ea);
        }
        if (null !== $pref) {
            $this->applyPreferences($user, $pref);
        }
        $this->em->persist($user->getPref());
        $this->em->flush();

        if ($isNew) {
            $values = $this->customFields->defaultValues($fields);
            foreach ($cfValues as $k => $v) {
                $values[$k] = $v;
            }
            $this->customFields->saveValues('Principal', $user->id, $fields, $values);
        } else {
            $this->customFields->saveValues('Principal', $user->id, $fields, $cfValues);
        }

        if (\array_key_exists('notified_project_ids', $attrs)) {
            $ids = array_values(array_filter(array_map('intval', (array) $attrs['notified_project_ids'])));
            $this->updateNotifiedProjects($user, 'selected' === $user->mailNotification ? $ids : []);
        }
        if ($current->admin && !$isNew && \array_key_exists('group_ids', $attrs)) {
            $this->setGroupIds($user, array_values(array_filter(array_map('intval', (array) $attrs['group_ids']))));
        }
        if (null !== $statusChanged && Principal::STATUS_ACTIVE !== $user->status) {
            $this->deleteLoginTokens($user);
        }
        $this->em->flush();

        // EmailAddress#deliver_security_notification_update + User#deliver_security_notification
        if (null !== $previousMail && '' !== $previousMail && null !== $mail && '' !== $mail && $mail !== $previousMail) {
            $this->security->emailAddressChanged($user, $previousMail, $mail);
        }
        $this->security->userSaved($user, $before);

        return [[], $password];
    }

    /** @return array<int, mixed> custom field id => value */
    public function customFieldParams(array $attrs): array
    {
        $out = [];
        if (isset($attrs['custom_field_values']) && \is_array($attrs['custom_field_values'])) {
            foreach ($attrs['custom_field_values'] as $id => $v) {
                $out[(int) $id] = $v;
            }
        }
        if (isset($attrs['custom_fields']) && \is_array($attrs['custom_fields'])) {
            foreach ($attrs['custom_fields'] as $cf) {
                if (\is_array($cf) && isset($cf['id'])) {
                    $out[(int) $cf['id']] = $cf['value'] ?? null;
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    public function validateUser(User $user): array
    {
        $e = [];
        $msg = fn (string $field, string $k, array $p = []) => $this->i18n->l($field).' '.$this->i18n->l('activerecord.errors.messages.'.$k, $p);
        if ('' === $user->login) {
            $e[] = $msg('field_login', 'blank');
        } else {
            if (!preg_match('/\A[a-z0-9_\-@.]*\z/i', $user->login)) {
                $e[] = $msg('field_login', 'invalid');
            }
            if (mb_strlen($user->login) > 60) {
                $e[] = $msg('field_login', 'too_long', ['count' => 60]);
            }
            $qb = $this->em->createQuery('SELECT COUNT(p.id) FROM App\Entity\Principal p WHERE LOWER(p.login) = :l'.($user->id ? ' AND p.id <> :id' : ''))
                ->setParameter('l', mb_strtolower($user->login));
            if ($user->id) {
                $qb->setParameter('id', $user->id);
            }
            if ((int) $qb->getSingleScalarResult() > 0) {
                $e[] = $msg('field_login', 'taken');
            }
        }
        if ('' === $user->firstname) {
            $e[] = $msg('field_firstname', 'blank');
        } elseif (mb_strlen($user->firstname) > 30) {
            $e[] = $msg('field_firstname', 'too_long', ['count' => 30]);
        }
        if ('' === $user->lastname) {
            $e[] = $msg('field_lastname', 'blank');
        } elseif (mb_strlen($user->lastname) > 255) {
            $e[] = $msg('field_lastname', 'too_long', ['count' => 255]);
        }
        if ('' !== $user->mailNotification && !isset(self::MAIL_NOTIFICATION_OPTIONS[$user->mailNotification])) {
            $e[] = $msg('field_mail_notification', 'inclusion');
        }

        return $e;
    }

    /** @return list<string> */
    public function validateMail(?string $mail, ?User $user = null, string $label = 'field_mail'): array
    {
        $msg = fn (string $k, array $p = []) => $this->i18n->l($label).' '.$this->i18n->l('activerecord.errors.messages.'.$k, $p);
        if (null === $mail || '' === $mail) {
            return [$msg('blank')];
        }
        $e = [];
        if (false === filter_var($mail, \FILTER_VALIDATE_EMAIL)) {
            $e[] = $msg('invalid');
        }
        if (mb_strlen($mail) > 254) {
            $e[] = $msg('too_long', ['count' => 254]);
        }
        $q = $this->em->createQuery('SELECT ea FROM App\Entity\EmailAddress ea WHERE LOWER(ea.address) = :a')->setParameter('a', mb_strtolower($mail));
        foreach ($q->getResult() as $ea) {
            // the default address of the user being edited is the only allowed match
            if (null === $user || null === $user->id || $ea->user->id !== $user->id || !$ea->isDefault) {
                $e[] = $msg('taken');
                break;
            }
        }
        $domain = str_contains($mail, '@') ? substr($mail, strrpos($mail, '@') + 1) : '';
        if ('' !== $domain && !$this->validDomain($domain)) {
            $e[] = $msg('domain_not_allowed', ['domain' => $domain]);
        }

        return $e;
    }

    public function validDomain(string $domain): bool
    {
        $in = static function (string $domain, string $list): bool {
            $domain = strtolower($domain);
            foreach (preg_split('/[\s,]+/', $list) ?: [] as $s) {
                $s = strtolower(trim($s));
                if ('' === $s) {
                    continue;
                }
                if (str_starts_with($s, '.') ? str_ends_with($domain, $s) : $domain === $s) {
                    return true;
                }
            }

            return false;
        };
        $denied = $this->settings->string('email_domains_denied');
        $allowed = $this->settings->string('email_domains_allowed');
        if ('' !== trim($denied) && $in($domain, $denied)) {
            return false;
        }

        return !('' !== trim($allowed) && !$in($domain, $allowed));
    }

    /** @return list<string> */
    public function validatePassword(string $password, User $user, ?string $mail): array
    {
        $e = [];
        $label = $this->i18n->l('field_password').' ';
        $min = $this->settings->int('password_min_length');
        if (mb_strlen($password) < $min) {
            $e[] = $label.$this->i18n->l('activerecord.errors.messages.too_short', ['count' => $min]);
        }
        foreach ($this->settings->array('password_required_char_classes') as $class) {
            if (isset(self::PASSWORD_CHAR_CLASSES[$class]) && !preg_match(self::PASSWORD_CHAR_CLASSES[$class], $password)) {
                $e[] = $label.$this->i18n->l('activerecord.errors.messages.must_contain_'.$class);
            }
        }
        $bad = [$user->login, $user->firstname, $user->lastname, (string) $mail];
        foreach ($user->emailAddresses as $ea) {
            $bad[] = $ea->address;
        }
        foreach ($bad as $b) {
            if ('' !== $b && 0 === strcasecmp($b, $password)) {
                $e[] = $label.$this->i18n->l('activerecord.errors.messages.too_simple');
                break;
            }
        }

        return $e;
    }

    public function setPassword(User $user, string $password): void
    {
        $user->hashedPassword = $this->hasher->hashPassword($user, $password);
        $user->salt = null;
        $user->passwdChangedOn = new \DateTimeImmutable();
        if (null !== $user->id) {
            $this->deleteLoginTokens($user);
        }
    }

    public function deleteLoginTokens(User $user): void
    {
        if (null === $user->id) {
            return;
        }
        $this->em->createQuery("DELETE FROM App\Entity\Token t WHERE t.user = :u AND t.action IN ('autologin', 'recovery', 'session')")
            ->setParameter('u', $user->id)->execute();
    }

    /** User#random_password */
    public function randomPassword(int $length = 40): string
    {
        $exclude = str_split('0O1l|\'"`*');
        $lists = [range('A', 'Z'), range('a', 'z'), range('0', '9')];
        if (\in_array('special_chars', $this->settings->array('password_required_char_classes'), true)) {
            $lists[] = array_values(array_filter(array_map('chr', range(0x20, 0x7E)), static fn ($c) => (bool) preg_match('/[[:punct:] ]/', $c)));
        }
        $lists = array_map(static fn ($l) => array_values(array_diff($l, $exclude)), $lists);
        $chars = [];
        foreach ($lists as $l) {
            $chars[] = $l[random_int(0, \count($l) - 1)];
            --$length;
        }
        $all = array_merge(...$lists);
        for ($i = 0; $i < $length; ++$i) {
            $chars[] = $all[random_int(0, \count($all) - 1)];
        }
        shuffle($chars);

        return implode('', $chars);
    }

    /** @param array<string, mixed> $pref */
    public function applyPreferences(User $user, array $pref): void
    {
        $p = $user->getPref();
        if (\array_key_exists('hide_mail', $pref)) {
            $p->hideMail = self::bool($pref['hide_mail']);
        }
        if (\array_key_exists('time_zone', $pref)) {
            $p->timeZone = '' === (string) $pref['time_zone'] ? null : (string) $pref['time_zone'];
        }
        foreach (['comments_sorting', 'warn_on_leaving_unsaved', 'no_self_notified', 'notify_about_high_priority_issues', 'textarea_font', 'history_default_tab', 'default_issue_query', 'default_project_query'] as $k) {
            if (\array_key_exists($k, $pref)) {
                $p->set($k, \is_array($pref[$k]) ? (string) end($pref[$k]) : (string) $pref[$k]);
            }
        }
        if (\array_key_exists('recently_used_projects', $pref)) {
            $p->set('recently_used_projects', (int) $pref['recently_used_projects']);
        }
        if (\array_key_exists('toolbar_language_options', $pref)) {
            $p->set('toolbar_language_options', implode(',', array_filter(
                explode(',', str_replace(' ', '', (string) $pref['toolbar_language_options'])),
                fn (string $lang): bool => '' !== $lang && $this->highlighting->languageSupported($lang),
            )));
        }
        if (\array_key_exists('auto_watch_on', $pref)) {
            $p->set('auto_watch_on', array_values(array_intersect(self::AUTO_WATCH_ON_OPTIONS, (array) $pref['auto_watch_on'])));
        }
    }

    /** @param list<int> $projectIds */
    public function updateNotifiedProjects(User $user, array $projectIds): void
    {
        foreach ($this->em->getRepository(Member::class)->findBy(['principal' => $user]) as $m) {
            $m->mailNotification = \in_array($m->project->id, $projectIds, true);
        }
    }

    /** @return list<int> */
    public function notifiedProjectIds(User $user): array
    {
        $ids = [];
        foreach ($this->em->getRepository(Member::class)->findBy(['principal' => $user]) as $m) {
            if ($m->mailNotification) {
                $ids[] = $m->project->id;
            }
        }

        return $ids;
    }

    /** @param list<int> $groupIds */
    public function setGroupIds(User $user, array $groupIds): void
    {
        foreach ($this->givableGroups() as $g) {
            $in = $g->users->contains($user);
            if (\in_array($g->id, $groupIds, true) && !$in) {
                $this->memberships->addUserToGroup($g, $user);
            } elseif (!\in_array($g->id, $groupIds, true) && $in) {
                $this->memberships->removeUserFromGroup($g, $user);
            }
        }
    }

    /** @return list<Group> Group.givable.sorted */
    public function givableGroups(): array
    {
        return $this->em->createQuery('SELECT g FROM App\Entity\Group g WHERE g INSTANCE OF App\Entity\Group AND g NOT INSTANCE OF App\Entity\GroupBuiltin ORDER BY g.lastname')->getResult();
    }

    /** @return list<Group> Group.sorted (builtin types sorted by type like Redmine) */
    public function allGroups(): array
    {
        $groups = $this->em->createQuery('SELECT g FROM App\Entity\Group g ORDER BY g.lastname')->getResult();
        usort($groups, static fn (Group $a, Group $b) => [self::typeName($a), mb_strtolower($a->lastname)] <=> [self::typeName($b), mb_strtolower($b->lastname)]);

        return $groups;
    }

    public static function typeName(Principal $p): string
    {
        return match (true) {
            $p instanceof GroupAnonymous => 'GroupAnonymous',
            $p instanceof GroupNonMember => 'GroupNonMember',
            $p instanceof Group => 'Group',
            $p instanceof AnonymousUser => 'AnonymousUser',
            default => 'User',
        };
    }

    public static function builtinType(Group $g): ?string
    {
        return match (true) {
            $g instanceof GroupAnonymous => 'anonymous',
            $g instanceof GroupNonMember => 'non_member',
            default => null,
        };
    }

    /** @return list<UserCustomField> */
    public function userCustomFields(): array
    {
        return $this->customFields->fieldsOf(UserCustomField::class);
    }

    /** @return list<GroupCustomField> */
    public function groupCustomFields(): array
    {
        return $this->customFields->fieldsOf(GroupCustomField::class);
    }

    /** Custom fields visible by the current user (visible flag or admin) */
    public function visibleCustomFields(array $fields, ?User $viewer = null): array
    {
        $viewer ??= $this->currentUser->get();

        return array_values(array_filter($fields, static fn (CustomField $f) => $f->visible || $viewer->admin));
    }

    /** User#valid_notification_options */
    public function mailNotificationOptions(User $user): array
    {
        $opts = self::MAIL_NOTIFICATION_OPTIONS;
        if (null === $user->id || 0 === \count($this->em->getRepository(Member::class)->findBy(['principal' => $user]))) {
            unset($opts['selected']);
        }

        return $opts;
    }

    // --- visibility ------------------------------------------------------------

    /** Principal.visible */
    public function isVisible(Principal $principal, ?User $viewer = null): bool
    {
        $viewer ??= $this->currentUser->get();
        if ($viewer->admin) {
            return true;
        }
        if (!$principal->isActive()) {
            return false;
        }
        if ($principal->id === $viewer->id) {
            return true;
        }
        if ($this->viewAllActive($viewer)) {
            return true;
        }
        $ids = $this->auth->visibleProjectIds($viewer);
        if ([] === $ids) {
            return false;
        }

        return (int) $this->em->createQuery('SELECT COUNT(m.id) FROM App\Entity\Member m WHERE m.principal = :p AND IDENTITY(m.project) IN (:ids)')
            ->setParameters(['p' => $principal->id, 'ids' => $ids])->getSingleScalarResult() > 0;
    }

    private function viewAllActive(User $viewer): bool
    {
        $members = $this->auth->membershipsOf($viewer);
        if ([] !== $members) {
            foreach ($members as $m) {
                foreach ($m->getRoles() as $r) {
                    if ('all' === $r->usersVisibility) {
                        return true;
                    }
                }
            }

            return false;
        }

        return 'all' === $this->auth->builtinRole($viewer)->usersVisibility;
    }

    // --- destroy ---------------------------------------------------------------

    /** User#remove_references_before_destroy + dependent associations */
    public function destroyUser(User $user): void
    {
        $id = (int) $user->id;
        $login = (string) $user->login;
        $wasAdmin = (bool) $user->admin;
        $wasActive = Principal::STATUS_ACTIVE === $user->status;
        $anon = $this->anonymous();
        $sid = (int) $anon->id;
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $up = static fn (string $sql, array $p = []) => $conn->executeStatement($sql, $p);
            $up('UPDATE attachments SET author_id = ? WHERE author_id = ?', [$sid, $id]);
            $up('UPDATE comments SET author_id = ? WHERE author_id = ?', [$sid, $id]);
            $up('UPDATE issues SET author_id = ? WHERE author_id = ?', [$sid, $id]);
            $up('UPDATE issues SET assigned_to_id = NULL WHERE assigned_to_id = ?', [$id]);
            $up('UPDATE journals SET user_id = ? WHERE user_id = ?', [$sid, $id]);
            $up('UPDATE journals SET updated_by_id = ? WHERE updated_by_id = ?', [$sid, $id]);
            $up("UPDATE journal_details SET old_value = ? WHERE property = 'attr' AND prop_key = 'assigned_to_id' AND old_value = ?", [(string) $sid, (string) $id]);
            $up("UPDATE journal_details SET value = ? WHERE property = 'attr' AND prop_key = 'assigned_to_id' AND value = ?", [(string) $sid, (string) $id]);
            $up('UPDATE messages SET author_id = ? WHERE author_id = ?', [$sid, $id]);
            $up('UPDATE news SET author_id = ? WHERE author_id = ?', [$sid, $id]);
            $up('DELETE FROM queries WHERE user_id = ? AND visibility = 0', [$id]);
            $up('UPDATE queries SET user_id = ? WHERE user_id = ?', [$sid, $id]);
            $up('UPDATE time_entries SET user_id = ? WHERE user_id = ?', [$sid, $id]);
            $up('UPDATE time_entries SET author_id = ? WHERE author_id = ?', [$sid, $id]);
            $up('DELETE FROM tokens WHERE user_id = ?', [$id]);
            $up('DELETE FROM watchers WHERE user_id = ?', [$id]);
            $up('UPDATE wiki_contents SET author_id = ? WHERE author_id = ?', [$sid, $id]);
            $up('UPDATE wiki_content_versions SET author_id = ? WHERE author_id = ?', [$sid, $id]);
            $up('DELETE FROM email_addresses WHERE user_id = ?', [$id]);
            $up('DELETE FROM user_preferences WHERE user_id = ?', [$id]);
            $up('DELETE FROM reactions WHERE user_id = ?', [$id]);
            $up('DELETE FROM projects_webhooks WHERE webhook_id IN (SELECT id FROM webhooks WHERE user_id = ?)', [$id]);
            $up('DELETE FROM webhooks WHERE user_id = ?', [$id]);
            $up('DELETE FROM groups_users WHERE user_id = ?', [$id]);
            $this->removePrincipalReferences($id);
            $up("DELETE FROM custom_values WHERE customized_type = 'Principal' AND customized_id = ?", [$id]);
            $up('DELETE FROM users WHERE id = ?', [$id]);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
        $this->em->clear();
        $this->auth->reset();
        // User#deliver_security_notification (after_destroy)
        $this->security->userDestroyed($login, $wasAdmin, $wasActive);
    }

    /** Group#remove_references_before_destroy + dependent associations */
    public function destroyGroup(Group $group): void
    {
        $id = (int) $group->id;
        // remove memberships through the service so inherited roles of users are removed too
        foreach ($this->em->getRepository(Member::class)->findBy(['principal' => $group]) as $m) {
            foreach ($m->memberRoles->toArray() as $mr) {
                $this->memberships->removeMemberRole($mr, false);
            }
            $this->em->remove($m);
        }
        $this->em->flush();
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE issues SET assigned_to_id = NULL WHERE assigned_to_id = ?', [$id]);
        $conn->executeStatement('DELETE FROM watchers WHERE user_id = ?', [$id]);
        $conn->executeStatement('DELETE FROM groups_users WHERE group_id = ?', [$id]);
        $this->removePrincipalReferences($id);
        $conn->executeStatement("DELETE FROM custom_values WHERE customized_type = 'Principal' AND customized_id = ?", [$id]);
        $conn->executeStatement('DELETE FROM users WHERE id = ?', [$id]);
        $this->em->clear();
        $this->auth->reset();
    }

    private function removePrincipalReferences(int $id): void
    {
        $conn = $this->em->getConnection();
        // members (dependent destroy) and their roles
        $conn->executeStatement('DELETE FROM member_roles WHERE member_id IN (SELECT id FROM members WHERE user_id = ?)', [$id]);
        $conn->executeStatement('DELETE FROM members WHERE user_id = ?', [$id]);
        $conn->executeStatement('UPDATE issue_categories SET assigned_to_id = NULL WHERE assigned_to_id = ?', [$id]);
        $conn->executeStatement('UPDATE projects SET default_assigned_to_id = NULL WHERE default_assigned_to_id = ?', [$id]);
        $conn->executeStatement("DELETE FROM custom_values WHERE value = ? AND custom_field_id IN (SELECT id FROM custom_fields WHERE field_format = 'user')", [(string) $id]);
    }

    public function anonymous(): AnonymousUser
    {
        $anon = $this->em->getRepository(AnonymousUser::class)->findOneBy([]);
        if (null === $anon) {
            $anon = new AnonymousUser();
            $anon->status = Principal::STATUS_ANONYMOUS;
            $this->em->persist($anon);
            $this->em->flush();
        }

        return $anon;
    }

    // --- groups ----------------------------------------------------------------

    /**
     * Group safe attributes (name, twofa_required, user_ids, custom fields) + validation + save.
     *
     * @return list<string>
     */
    public function saveGroup(Group $group, array $attrs): array
    {
        $isNew = null === $group->id;
        $errors = [];
        if (!$group->isBuiltin()) {
            if (\array_key_exists('name', $attrs)) {
                $group->lastname = trim((string) $attrs['name']);
            }
            if (\array_key_exists('twofa_required', $attrs)) {
                $group->twofaRequired = self::bool($attrs['twofa_required']);
            }
            $label = $this->i18n->l('field_name').' ';
            if ('' === $group->lastname) {
                $errors[] = $label.$this->i18n->l('activerecord.errors.messages.blank');
            } elseif (mb_strlen($group->lastname) > 255) {
                $errors[] = $label.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 255]);
            } else {
                $q = $this->em->createQuery('SELECT COUNT(g.id) FROM App\Entity\Group g WHERE LOWER(g.lastname) = :n'.($group->id ? ' AND g.id <> :id' : ''))
                    ->setParameter('n', mb_strtolower($group->lastname));
                if ($group->id) {
                    $q->setParameter('id', $group->id);
                }
                if ((int) $q->getSingleScalarResult() > 0) {
                    $errors[] = $label.$this->i18n->l('activerecord.errors.messages.taken');
                }
            }
        }
        $fields = $this->groupCustomFields();
        $cf = $group->isBuiltin() ? [] : $this->customFieldParams($attrs);
        $storedCf = $isNew ? [] : $this->customFields->values('Principal', $group->id);
        foreach ($fields as $f) {
            if (\array_key_exists($f->id, $cf)) {
                $errors = [...$errors, ...$this->customFields->validate($f, $cf[$f->id], null, $storedCf[$f->id] ?? null)];
            } elseif ($isNew && $f->isRequired && (null === $f->defaultValue || '' === $f->defaultValue)) {
                $errors = [...$errors, ...$this->customFields->validate($f, null)];
            }
        }
        if ([] !== $errors) {
            return $errors;
        }
        if ($isNew) {
            $group->status = Principal::STATUS_ACTIVE;
            $this->em->persist($group);
        }
        $this->em->flush();
        if (!$group->isBuiltin()) {
            $values = $isNew ? $this->customFields->defaultValues($fields) : [];
            foreach ($cf as $k => $v) {
                $values[$k] = $v;
            }
            $this->customFields->saveValues('Principal', $group->id, $fields, $values);
            if (\array_key_exists('user_ids', $attrs)) {
                $ids = array_values(array_filter(array_map('intval', (array) $attrs['user_ids'])));
                foreach ($group->users->toArray() as $u) {
                    if (!\in_array($u->id, $ids, true)) {
                        $this->memberships->removeUserFromGroup($group, $u);
                    }
                }
                foreach ($ids ? $this->em->getRepository(User::class)->findBy(['id' => $ids]) : [] as $u) {
                    if ($u->isLogged()) {
                        $this->memberships->addUserToGroup($group, $u);
                    }
                }
            }
        }
        $this->em->flush();

        return [];
    }

    // --- memberships -------------------------------------------------------------

    /**
     * Member.create_principal_memberships
     *
     * @param list<int> $projectIds
     * @param list<int> $roleIds
     *
     * @return list<string> errors
     */
    public function createMemberships(Principal $principal, array $projectIds, array $roleIds): array
    {
        $roles = $roleIds ? $this->em->getRepository(Role::class)->findBy(['id' => $roleIds, 'builtin' => Role::BUILTIN_NONE]) : [];
        if ([] === $roles) {
            return [$this->i18n->l('label_role_plural').' '.$this->i18n->l('activerecord.errors.messages.empty')];
        }
        foreach ($projectIds ? $this->em->getRepository(Project::class)->findBy(['id' => $projectIds]) : [] as $project) {
            $this->memberships->addMember($project, $principal, $roles);
        }

        return [];
    }

    // --- mails --------------------------------------------------------------------

    /** Mailer.deliver_account_information */
    public function deliverAccountInformation(User $user, ?string $password): bool
    {
        $to = $user->getMail();
        if (null === $to) {
            return false;
        }
        try {
            $l = fn (string $k, array $p = []) => $this->i18n->l($k, $p, $user->language ?: null);
            $loginUrl = $this->urls->generate('signin', [], UrlGeneratorInterface::ABSOLUTE_URL);
            $lines = [$l('mail_body_account_information')];
            if (null !== $user->authSourceId) {
                $src = $this->em->find(AuthSource::class, $user->authSourceId);
                $lines[] = $l('mail_body_account_information_external', ['value' => $src?->name]);
            } else {
                $lines[] = '* '.$l('field_login').': '.$user->login;
                if (null !== $password) {
                    $lines[] = '* '.$l('field_password').': '.$password;
                }
            }
            $lines[] = '';
            $lines[] = $l('label_login').': '.$loginUrl;
            $from = $this->settings->string('mail_from') ?: 'redmine@example.net';
            $email = (new Email())->from($from)->to($to)
                ->subject($l('mail_subject_register', ['value' => $this->settings->appTitle()]))
                ->text(implode("\n", $lines)."\n\n-- \n".$this->settings->string('emails_footer'));
            $this->mailer->send($email);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function bool(mixed $v): bool
    {
        return \in_array($v, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    /** User#css_classes */
    public static function cssClasses(Principal $p): string
    {
        return 'user '.match ($p->status) {
            Principal::STATUS_ACTIVE => 'active',
            Principal::STATUS_REGISTERED => 'registered',
            Principal::STATUS_LOCKED => 'locked',
            default => 'anon',
        };
    }

    public function emailAddressFor(User $user, int $id): ?EmailAddress
    {
        foreach ($user->emailAddresses as $ea) {
            if ($ea->id === $id && !$ea->isDefault) {
                return $ea;
            }
        }

        return null;
    }
}
