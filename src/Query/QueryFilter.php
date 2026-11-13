<?php

declare(strict_types=1);

namespace App\Query;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use App\Entity\CustomField;

/**
 * Port of Redmine's QueryFilter: an available filter (type, name, values).
 * Values can be given as a closure (lazy) - such filters are "remote" by default: their values are
 * fetched with /queries/filter when the filter is added in the UI.
 */
#[Exclude]
final class QueryFilter
{
    /** @var list<array{0: string, 1: string, 2?: string}>|\Closure|null */
    private mixed $values;
    private ?array $resolved = null;
    public readonly bool $remote;

    /**
     * @param list<array>|\Closure|null $values [[label, value(, group)], ...]
     */
    public function __construct(
        public readonly string $field,
        public readonly string $type,
        public readonly string $name,
        array|\Closure|null $values = null,
        ?bool $remote = null,
        /** custom field behind the filter (cf_x, assoc.cf_x, cf_x.cf_y) */
        public readonly ?CustomField $customField = null,
        /** for chained custom field filters: the custom field the filter goes through */
        public readonly ?CustomField $through = null,
    ) {
        $this->values = $values;
        $this->remote = $remote ?? ($values instanceof \Closure);
    }

    /** @return list<array>|null */
    public function values(): ?array
    {
        if ($this->values instanceof \Closure) {
            return $this->resolved ??= array_values(($this->values)());
        }

        return null === $this->values ? null : array_values($this->values);
    }
}
