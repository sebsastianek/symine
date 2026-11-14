<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Results of reads remembered for the current request, so that the menu, the form and the list of one page do
 * not each ask the database for the same custom fields or versions.
 *
 * An entry is forgotten as soon as one of the tables it was read from is written (WriteLog), when the entity
 * manager is cleared (the entities it holds are detached), and between requests (kernel.reset): asking the memo
 * never gives another answer than asking the database, it only gives it without the round trip.
 *
 * The service that owns the data keeps its key space: "<owner>:<what>".
 */
#[AsDoctrineListener(event: Events::onClear)]
final class QueryMemo implements ResetInterface
{
    /** @var array<string, array{value: mixed, total: int, tables: list<string>, version: int}> */
    private array $entries = [];

    public function __construct(private readonly WriteLog $writes)
    {
    }

    /**
     * @template T
     *
     * @param list<string>  $tables the tables the result depends on
     * @param callable(): T $load
     *
     * @return T
     */
    public function get(string $key, array $tables, callable $load): mixed
    {
        $entry = $this->entries[$key] ?? null;
        if (null !== $entry) {
            if ($entry['total'] === $this->writes->total) {
                return $entry['value'];
            }
            if ($entry['version'] === $this->writes->version(...$entry['tables'])) {
                // written since, but elsewhere
                $this->entries[$key]['total'] = $this->writes->total;

                return $entry['value'];
            }
        }
        $value = $load();
        $this->entries[$key] = ['value' => $value, 'total' => $this->writes->total, 'tables' => $tables, 'version' => $this->writes->version(...$tables)];

        return $value;
    }

    /**
     * Remembers a result that was read as part of a larger query (the values of a whole list at once).
     *
     * @param list<string> $tables
     */
    public function put(string $key, array $tables, mixed $value): void
    {
        $this->entries[$key] = ['value' => $value, 'total' => $this->writes->total, 'tables' => $tables, 'version' => $this->writes->version(...$tables)];
    }

    public function reset(): void
    {
        $this->entries = [];
    }

    public function onClear(): void
    {
        $this->entries = [];
    }
}
