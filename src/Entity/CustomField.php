<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** Redmine "custom_fields" table (STI on type). */
#[ORM\Entity(repositoryClass: \App\Repository\CustomFieldRepository::class)]
#[ORM\Table(name: 'custom_fields')]
#[ORM\Index(name: 'index_custom_fields_on_id_and_type', columns: ['id', 'type'])]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string', length: 30)]
#[ORM\DiscriminatorMap(CustomField::TYPES)]
abstract class CustomField implements \Stringable
{
    public const array TYPES = [
        'IssueCustomField' => IssueCustomField::class,
        'ProjectCustomField' => ProjectCustomField::class,
        'UserCustomField' => UserCustomField::class,
        'GroupCustomField' => GroupCustomField::class,
        'TimeEntryCustomField' => TimeEntryCustomField::class,
        'VersionCustomField' => VersionCustomField::class,
        'DocumentCustomField' => DocumentCustomField::class,
        'DocumentCategoryCustomField' => DocumentCategoryCustomField::class,
        'IssuePriorityCustomField' => IssuePriorityCustomField::class,
        'TimeEntryActivityCustomField' => TimeEntryActivityCustomField::class,
    ];

    /** Field formats supported by Redmine core */
    public const array FORMATS = ['string', 'text', 'link', 'int', 'float', 'date', 'list', 'bool', 'enumeration', 'user', 'version', 'attachment', 'progressbar'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 30, options: ['default' => ''])]
    public string $name = '';

    #[ORM\Column(length: 30, options: ['default' => ''])]
    public string $fieldFormat = 'string';

    #[ORM\Column(type: 'redmine_serialized', nullable: true)]
    public mixed $possibleValues = null;

    #[ORM\Column(length: 255, nullable: true, options: ['default' => ''])]
    public ?string $regexp = '';

    #[ORM\Column(nullable: true)]
    public ?int $minLength = null;

    #[ORM\Column(nullable: true)]
    public ?int $maxLength = null;

    #[ORM\Column(options: ['default' => false])]
    public bool $isRequired = false;

    #[ORM\Column(options: ['default' => false])]
    public bool $isForAll = false;

    #[ORM\Column(options: ['default' => false])]
    public bool $isFilter = false;

    #[ORM\Column(nullable: true)]
    public ?int $position = null;

    #[ORM\Column(nullable: true, options: ['default' => false])]
    public ?bool $searchable = false;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $defaultValue = null;

    #[ORM\Column(nullable: true, options: ['default' => true])]
    public ?bool $editable = true;

    #[ORM\Column(options: ['default' => true])]
    public bool $visible = true;

    #[ORM\Column(nullable: true, options: ['default' => false])]
    public ?bool $multiple = false;

    /** Format specific options (url_pattern, text_formatting, edit_tag_style, user_role, version_status, ...) */
    #[ORM\Column(type: 'redmine_serialized', nullable: true)]
    public mixed $formatStore = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null;

    /** @var Collection<int, Role> roles that can see the field when visible = false */
    #[ORM\ManyToMany(targetEntity: Role::class)]
    #[ORM\JoinTable(name: 'custom_fields_roles')]
    #[ORM\JoinColumn(name: 'custom_field_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'role_id', referencedColumnName: 'id')]
    public Collection $roles;

    /** @var Collection<int, CustomFieldEnumeration> */
    #[ORM\OneToMany(targetEntity: CustomFieldEnumeration::class, mappedBy: 'customField', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    public Collection $enumerations;

    public function __construct()
    {
        $this->roles = new ArrayCollection();
        $this->enumerations = new ArrayCollection();
    }

    abstract public static function typeName(): string;

    /** customized_type stored in custom_values for this kind of field */
    abstract public static function customizedType(): string;

    /** @return list<string> */
    public function getPossibleValues(): array
    {
        $v = $this->possibleValues;
        if (\is_string($v)) {
            $v = preg_split('/\r?\n/', $v) ?: [];
        }

        return \is_array($v) ? array_values(array_filter(array_map('strval', $v), static fn ($s) => '' !== $s)) : [];
    }

    public function getFormatOption(string $key, mixed $default = null): mixed
    {
        return \is_array($this->formatStore) ? ($this->formatStore[$key] ?? $default) : $default;
    }

    public function setFormatOption(string $key, mixed $value): void
    {
        $s = \is_array($this->formatStore) ? $this->formatStore : [];
        $s[$key] = $value;
        $this->formatStore = $s;
    }

    public function isMultiple(): bool
    {
        return (bool) $this->multiple && \in_array($this->fieldFormat, ['list', 'enumeration', 'user', 'version', 'attachment'], true);
    }

    public function isVisibleBy(?Project $project, User $user, callable $rolesForProject): bool
    {
        if ($this->visible || $user->admin) {
            return true;
        }
        $roleIds = array_map(static fn (Role $r) => $r->id, $this->roles->toArray());
        foreach ($rolesForProject($project) as $role) {
            if (\in_array($role->id, $roleIds, true)) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
