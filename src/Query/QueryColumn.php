<?php

declare(strict_types=1);

namespace App\Query;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use App\Entity\CustomField;

/**
 * Port of Redmine's QueryColumn family (QueryColumn, TimestampQueryColumn, WatcherQueryColumn,
 * QueryAssociationColumn, QueryCustomFieldColumn, QueryAssociationCustomFieldColumn).
 *
 * The kind of column is given by $kind; sort/group SQL statements are plain SQL fragments
 * that refer to Redmine table names (issues, projects, ...).
 */
#[Exclude]
final class QueryColumn
{
    public const string STANDARD = 'standard';
    public const string TIMESTAMP = 'timestamp';
    public const string WATCHER = 'watcher';
    public const string ASSOCIATION = 'association';
    public const string CUSTOM_FIELD = 'custom_field';
    public const string ASSOCIATION_CUSTOM_FIELD = 'association_custom_field';

    /**
     * @param list<string>|null $sortable SQL expressions used for ORDER BY (null = not sortable)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $caption,
        public readonly ?array $sortable = null,
        public readonly ?string $groupStatement = null,
        public readonly bool $totalable = false,
        public readonly ?string $defaultOrder = null,
        public readonly bool $inline = true,
        public readonly bool $frozen = false,
        public readonly string $kind = self::STANDARD,
        public readonly ?CustomField $customField = null,
        /** association name for association columns (e.g. "parent", "project") */
        public readonly ?string $association = null,
        /** attribute name for association columns (e.g. "subject") */
        public readonly ?string $attribute = null,
        /** extra joins needed when sorting/grouping by this column */
        public readonly ?string $joins = null,
    ) {
    }

    public function isSortable(): bool
    {
        return null !== $this->sortable && [] !== $this->sortable;
    }

    public function isGroupable(): bool
    {
        return null !== $this->groupStatement && '' !== $this->groupStatement;
    }

    public function isCustomField(): bool
    {
        return self::CUSTOM_FIELD === $this->kind || self::ASSOCIATION_CUSTOM_FIELD === $this->kind;
    }

    public function cssClasses(): string
    {
        return match ($this->kind) {
            self::ASSOCIATION => $this->association.'-'.$this->attribute,
            self::CUSTOM_FIELD => $this->name.' '.$this->customField?->fieldFormat,
            self::ASSOCIATION_CUSTOM_FIELD => $this->association.'_cf_'.$this->customField?->id.' '.$this->customField?->fieldFormat,
            default => $this->name,
        };
    }
}
