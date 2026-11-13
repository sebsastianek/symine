<?php

declare(strict_types=1);

namespace App\Tests\Admin\Fixtures;

use Symfony\Component\Ldap\Adapter\CollectionInterface;
use Symfony\Component\Ldap\Adapter\EntryManagerInterface;
use Symfony\Component\Ldap\Adapter\QueryInterface;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\ConnectionException;
use Symfony\Component\Ldap\LdapInterface;

/** In-memory LDAP directory for tests: entries dn => [password, attributes] */
final class FakeLdap implements LdapInterface
{
    /** @var list<array{string, string|null}> */
    public array $binds = [];

    /** @param array<string, array{password: string, attributes: array<string, list<string>>}> $entries */
    public function __construct(public array $entries, private readonly ?string $serviceDn = null, private readonly ?string $servicePassword = null)
    {
    }

    public function bind(?string $dn = null, #[\SensitiveParameter] ?string $password = null): void
    {
        $this->binds[] = [(string) $dn, $password];
        if (null === $dn || '' === $dn) {
            return;
        }
        if ($dn === $this->serviceDn && $password === $this->servicePassword) {
            return;
        }
        if (isset($this->entries[$dn]) && $this->entries[$dn]['password'] === $password) {
            return;
        }
        throw new ConnectionException('Invalid credentials');
    }

    public function query(string $dn, string $query, array $options = []): QueryInterface
    {
        $matches = [];
        // the last assertion of the filter is the login condition: (attr=value) or (attr=value*)
        if (preg_match('/\((\w+)=([^()]*)\)\)$/', $query, $m)) {
            [$attr, $value] = [$m[1], $m[2]];
            $prefix = str_ends_with($value, '*');
            $value = rtrim($value, '*');
            foreach ($this->entries as $entryDn => $e) {
                foreach ($e['attributes'][$attr] ?? [] as $v) {
                    if ($prefix ? str_starts_with($v, $value) : $v === $value) {
                        $matches[] = new Entry($entryDn, $e['attributes']);
                        break;
                    }
                }
            }
        }

        return new class($matches) implements QueryInterface {
            public function __construct(private readonly array $entries)
            {
            }

            public function execute(): CollectionInterface
            {
                return new class($this->entries) extends \ArrayObject implements CollectionInterface {
                    public function toArray(): array
                    {
                        return $this->getArrayCopy();
                    }
                };
            }
        };
    }

    public function getEntryManager(): EntryManagerInterface
    {
        throw new \LogicException('not supported');
    }

    public function escape(string $subject, string $ignore = '', int $flags = 0): string
    {
        return addcslashes($subject, '()*\\');
    }
}
