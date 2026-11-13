<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuthSource;
use App\Entity\Principal;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\ConnectionException;
use Symfony\Component\Ldap\Exception\ConnectionTimeoutException;
use Symfony\Component\Ldap\Exception\ExceptionInterface as LdapExceptionInterface;
use Symfony\Component\Ldap\Exception\InvalidCredentialsException;
use Symfony\Component\Ldap\Ldap;
use Symfony\Component\Ldap\LdapInterface;

/**
 * Port of Redmine's AuthSourceLdap (app/models/auth_source_ldap.rb) on top of symfony/ldap.
 *
 * - authenticate(source, login, password): attributes (firstname, lastname, mail, auth_source_id) or null
 * - testConnection(source): throws \RuntimeException with a message when the connection fails
 * - tryToLogin(login, password): Redmine's User.try_to_login for LDAP users, incl. on-the-fly registration
 *
 * Network errors are reported as \RuntimeException("LDAP: <message>") like AuthSourceException.
 */
class LdapService
{
    public const array LDAP_MODES = ['ldap', 'ldaps_verify_none', 'ldaps_verify_peer'];

    /** @var (callable(AuthSource): LdapInterface)|null */
    private $ldapFactory = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?\App\I18n\Localizer $i18n = null,
    ) {
    }

    /** Replaces the LDAP connection factory (used by tests) */
    public function setLdapFactory(?callable $factory): void
    {
        $this->ldapFactory = $factory;
    }

    public static function ldapMode(AuthSource $source): string
    {
        return match (true) {
            $source->tls && $source->verifyPeer => 'ldaps_verify_peer',
            $source->tls => 'ldaps_verify_none',
            default => 'ldap',
        };
    }

    public static function setLdapMode(AuthSource $source, ?string $mode): void
    {
        [$source->tls, $source->verifyPeer] = match ($mode) {
            'ldaps_verify_peer' => [true, true],
            'ldaps_verify_none' => [true, false],
            default => [false, false],
        };
    }

    /** Whether the filter is a valid LDAP filter (balanced parentheses, attribute assertions) */
    public static function isValidFilter(?string $filter): bool
    {
        $filter = trim((string) $filter);
        if ('' === $filter) {
            return true;
        }
        $filter = self::normalizeFilter($filter);
        $depth = 0;
        $len = \strlen($filter);
        for ($i = 0; $i < $len; ++$i) {
            if ('(' === $filter[$i]) {
                ++$depth;
            } elseif (')' === $filter[$i]) {
                --$depth;
                if ($depth < 0) {
                    return false;
                }
            } elseif ('\\' === $filter[$i]) {
                ++$i;
            }
        }
        if (0 !== $depth) {
            return false;
        }

        return (bool) preg_match('/^\((?:[&|!]|[^()=]+[~<>:]?=)/', $filter) && !preg_match('/\(\)/', $filter);
    }

    private static function normalizeFilter(string $filter): string
    {
        $filter = trim($filter);

        return str_starts_with($filter, '(') ? $filter : '('.$filter.')';
    }

    /** @return array{firstname: ?string, lastname: ?string, mail: ?string, auth_source_id: int|null}|null */
    public function authenticate(AuthSource $source, string $login, string $password): ?array
    {
        if ('' === trim($login) || '' === $password) {
            return null;
        }
        try {
            $attrs = $this->getUserDn($source, $login, $password);
            if (null !== $attrs && '' !== (string) ($attrs['dn'] ?? '') && $this->authenticateDn($source, $attrs['dn'], $password)) {
                $this->logger?->debug("Authentication successful for '$login'");
                unset($attrs['dn']);

                return $attrs;
            }
        } catch (ConnectionException $e) {
            // NETWORK_EXCEPTIONS are reported (AuthSourceException), failed binds are not
            if (self::isNetworkError($e)) {
                throw new \RuntimeException('LDAP: '.trim($e->getMessage()), 0, $e);
            }

            return null;
        } catch (LdapExceptionInterface $e) {
            if (self::isNetworkError($e)) {
                throw new \RuntimeException('LDAP: '.trim($e->getMessage()), 0, $e);
            }
            // Net::LDAP#search returns false on operation errors (eg. no such object, insufficient access)
            $this->logger?->warning("LDAP search failed for '$login': ".$e->getMessage());

            return null;
        }

        return null;
    }

    /** @throws \RuntimeException when the server cannot be reached or the bind credentials are invalid */
    public function testConnection(AuthSource $source): void
    {
        try {
            $ldap = $this->connection($source);
            $account = (string) $source->account;
            if ('' !== $account && !str_contains($account, '$login') && '' !== (string) $source->accountPassword) {
                try {
                    $ldap->bind($account, (string) $source->accountPassword);
                } catch (ConnectionException $e) {
                    if (self::isNetworkError($e)) {
                        throw $e;
                    }
                    // raised inside with_timeout: not prefixed with the auth method name
                    throw new \RuntimeException($this->l('error_ldap_bind_credentials'));
                }
            } else {
                // Redmine only opens the connection: a refused anonymous bind is not an error
                try {
                    $ldap->bind();
                } catch (ConnectionException $e) {
                    if (self::isNetworkError($e)) {
                        throw $e;
                    }
                }
            }
        } catch (LdapExceptionInterface $e) {
            throw new \RuntimeException('LDAP: '.trim($e->getMessage()), 0, $e);
        }
    }

    /** Whether a failed bind is a network failure (server down, refused, timeout) rather than a rejected bind */
    public static function isNetworkError(\Throwable $e): bool
    {
        if ($e instanceof InvalidCredentialsException) {
            return false;
        }
        if ($e instanceof ConnectionTimeoutException) {
            return true;
        }

        return (bool) preg_match("/Can't contact LDAP server|Connect error|Timed out|Timeout|Server is unavailable|Can't connect/i", $e->getMessage());
    }

    public function isSearchable(AuthSource $source): bool
    {
        return !str_contains((string) $source->account, '$login')
            && '' !== (string) $source->attrLogin && '' !== (string) $source->attrFirstname
            && '' !== (string) $source->attrLastname && '' !== (string) $source->attrMail;
    }

    /**
     * Searches every searchable source for logins starting with $q (AuthSource.search).
     *
     * @return list<array{login: string, firstname: ?string, lastname: ?string, mail: ?string, auth_source_id: ?int}>
     */
    public function search(string $q): array
    {
        $q = trim($q);
        $results = [];
        if ('' === $q) {
            return [];
        }
        foreach ($this->em->getRepository(AuthSource::class)->findAll() as $source) {
            if (!$this->isSearchable($source)) {
                continue;
            }
            try {
                $ldap = $this->connection($source);
                $this->bindService($ldap, $source, null, null);
                $filter = $this->filterFor($source, '('.$source->attrLogin.'='.$ldap->escape($q, '', LdapInterface::ESCAPE_FILTER).'*)');
                $entries = $ldap->query((string) $source->baseDn, $filter, ['filter' => ['dn', $source->attrLogin, $source->attrFirstname, $source->attrLastname, $source->attrMail], 'sizeLimit' => 10])->execute();
                foreach ($entries as $entry) {
                    $attrs = $this->entryAttributes($source, $entry);
                    unset($attrs['dn']);
                    $results[] = ['login' => (string) self::attr($entry, $source->attrLogin)] + $attrs;
                }
            } catch (LdapExceptionInterface $e) {
                $this->logger?->error('Error while searching users in '.$source->name.': '.$e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Redmine's User.try_to_login for LDAP: existing users with an auth source are checked against
     * their source; unknown logins are looked up in the on-the-fly sources and registered.
     * AuthSource errors are logged and null is returned.
     */
    public function tryToLogin(string $login, string $password): ?User
    {
        try {
            $user = $this->tryToLoginStrict($login, $password);
        } catch (\RuntimeException $e) {
            $this->logger?->error("An error occurred when authenticating $login: ".$e->getMessage());

            return null;
        }

        return null !== $user && null !== $user->id ? $user : null;
    }

    /**
     * User.try_to_login!: AuthSource errors (\RuntimeException "LDAP: ...") are passed through.
     * Returns an unsaved User (id null) when the LDAP authentication succeeded but the on-the-fly
     * registration failed (eg. missing mail), like Redmine (AccountController#onthefly_creation_failed).
     */
    public function tryToLoginStrict(string $login, string $password): ?User
    {
        $login = trim($login);
        if ('' === $login || '' === $password) {
            return null;
        }
        $user = $this->findUserByLogin($login);
        if (null !== $user) {
            if (null === $user->authSourceId || $user->isLocked()) {
                return null;
            }
            $source = $this->em->find(AuthSource::class, $user->authSourceId);
            if (null === $source || null === $this->authenticate($source, $user->login, $password)) {
                return null;
            }

            return $user;
        }
        $attrs = $this->authenticateOnTheFly($login, $password);
        if (null === $attrs) {
            return null;
        }

        return $this->registerUser($login, $attrs) ?? $this->newUser($login, $attrs);
    }

    /** AuthSource.authenticate: tries every source with on-the-fly registration */
    public function authenticateOnTheFly(string $login, string $password): ?array
    {
        foreach ($this->em->getRepository(AuthSource::class)->findBy(['ontheflyRegister' => true], ['id' => 'ASC']) as $source) {
            try {
                $this->logger?->debug("Authenticating '$login' against '{$source->name}'");
                $attrs = $this->authenticate($source, $login, $password);
            } catch (\Throwable $e) {
                $this->logger?->error('Error during authentication: '.$e->getMessage());
                $attrs = null;
            }
            if (null !== $attrs) {
                return $attrs;
            }
        }

        return null;
    }

    public function hasOnTheFlySources(): bool
    {
        return null !== $this->em->getRepository(AuthSource::class)->findOneBy(['ontheflyRegister' => true]);
    }

    public function findUserByLogin(string $login): ?User
    {
        $users = $this->em->createQuery('SELECT u FROM App\Entity\User u WHERE LOWER(u.login) = :l AND u NOT INSTANCE OF App\Entity\AnonymousUser')
            ->setParameter('l', mb_strtolower($login))->getResult();
        foreach ($users as $u) {
            if ($u->login === $login) {
                return $u;
            }
        }

        return $users[0] ?? null;
    }

    /** Creates a user from LDAP attributes (on-the-fly registration); null when invalid (e.g. no mail) */
    public function registerUser(string $login, array $attrs): ?User
    {
        $mail = trim((string) ($attrs['mail'] ?? ''));
        $firstname = trim((string) ($attrs['firstname'] ?? ''));
        $lastname = trim((string) ($attrs['lastname'] ?? ''));
        if ('' === $mail || '' === $firstname || '' === $lastname || !filter_var($mail, \FILTER_VALIDATE_EMAIL)) {
            $this->logger?->warning("Could not create account '$login' from LDAP: missing attributes");

            return null;
        }
        $taken = (int) $this->em->createQuery('SELECT COUNT(e.id) FROM App\Entity\EmailAddress e WHERE LOWER(e.address) = :m')
            ->setParameter('m', mb_strtolower($mail))->getSingleScalarResult();
        if ($taken > 0) {
            return null;
        }
        $user = $this->newUser($login, $attrs);
        $this->em->persist($user);
        $this->em->flush();
        $this->logger?->info("User '$login' created from external auth source");

        return $user;
    }

    /** User.new(attrs) for an LDAP login (not persisted) */
    public function newUser(string $login, array $attrs): User
    {
        $user = new User();
        $user->login = $login;
        $user->firstname = mb_substr(trim((string) ($attrs['firstname'] ?? '')), 0, 30);
        $user->lastname = mb_substr(trim((string) ($attrs['lastname'] ?? '')), 0, 255);
        $user->setMail(trim((string) ($attrs['mail'] ?? '')));
        $user->authSourceId = isset($attrs['auth_source_id']) ? (int) $attrs['auth_source_id'] : null;
        $user->language = $this->settings->string('default_language');
        $user->status = Principal::STATUS_ACTIVE;
        $user->mailNotification = $this->settings->string('default_notification_option') ?: 'only_my_events';
        $user->hashedPassword = '';

        return $user;
    }

    // --- internals -----------------------------------------------------------

    private function connection(AuthSource $source): LdapInterface
    {
        if (null !== $this->ldapFactory) {
            return ($this->ldapFactory)($source);
        }
        $timeout = $source->timeout && $source->timeout > 0 ? $source->timeout : 20;
        $options = ['network_timeout' => $timeout, 'timelimit' => $timeout, 'protocol_version' => 3, 'referrals' => false];
        if ($source->tls) {
            $options['x_tls_require_cert'] = $source->verifyPeer ? \LDAP_OPT_X_TLS_DEMAND : \LDAP_OPT_X_TLS_NEVER;
        }

        return Ldap::create('ext_ldap', [
            'host' => (string) $source->host,
            'port' => $source->port ?: 389,
            'encryption' => $source->tls ? 'ssl' : 'none',
            'version' => 3,
            'options' => $options,
        ]);
    }

    private function bindService(LdapInterface $ldap, AuthSource $source, ?string $login, ?string $password): void
    {
        $account = (string) $source->account;
        if (null !== $login && str_contains($account, '$login')) {
            $ldap->bind(str_replace('$login', $ldap->escape($login, '', LdapInterface::ESCAPE_DN), $account), $password);
        } elseif ('' !== $account || '' !== (string) $source->accountPassword) {
            $ldap->bind($account, (string) $source->accountPassword);
        } else {
            $ldap->bind();
        }
    }

    private function filterFor(AuthSource $source, string $condition): string
    {
        $parts = '(objectClass=*)';
        $custom = trim((string) $source->filter);
        if ('' !== $custom && self::isValidFilter($custom)) {
            $parts .= self::normalizeFilter($custom);
        }

        return '(&'.$parts.$condition.')';
    }

    /** @return array{dn: string, firstname?: ?string, lastname?: ?string, mail?: ?string, auth_source_id?: ?int}|null */
    private function getUserDn(AuthSource $source, string $login, string $password): ?array
    {
        $ldap = $this->connection($source);
        $this->bindService($ldap, $source, $login, $password);
        $attributes = $source->ontheflyRegister
            ? array_values(array_filter(['dn', $source->attrFirstname, $source->attrLastname, $source->attrMail]))
            : ['dn'];
        $filter = $this->filterFor($source, '('.$source->attrLogin.'='.$ldap->escape($login, '', LdapInterface::ESCAPE_FILTER).')');
        $attrs = null;
        foreach ($ldap->query((string) $source->baseDn, $filter, ['filter' => $attributes])->execute() as $entry) {
            $attrs = $source->ontheflyRegister ? $this->entryAttributes($source, $entry) : ['dn' => $entry->getDn()];
            $this->logger?->debug("DN found for $login: ".$entry->getDn());
        }

        return $attrs;
    }

    private function authenticateDn(AuthSource $source, string $dn, string $password): bool
    {
        if ('' === $dn || '' === $password) {
            return false;
        }
        try {
            $this->connection($source)->bind($dn, $password);

            return true;
        } catch (ConnectionException $e) {
            if (self::isNetworkError($e)) {
                throw $e;
            }

            return false;
        }
    }

    private function entryAttributes(AuthSource $source, Entry $entry): array
    {
        return [
            'dn' => $entry->getDn(),
            'firstname' => self::attr($entry, $source->attrFirstname),
            'lastname' => self::attr($entry, $source->attrLastname),
            'mail' => self::attr($entry, $source->attrMail),
            'auth_source_id' => $source->id,
        ];
    }

    private static function attr(Entry $entry, ?string $name): ?string
    {
        if (null === $name || '' === $name) {
            return null;
        }
        $values = $entry->getAttribute($name, false);

        return null === $values ? '' : (string) ($values[0] ?? '');
    }

    private function l(string $key): string
    {
        return null !== $this->i18n ? $this->i18n->l($key) : $key;
    }
}
