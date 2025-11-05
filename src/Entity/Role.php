<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\RoleRepository;

/**
 * Role.
 * Table: roles
 */
#[ORM\Entity(repositoryClass: RoleRepository::class)]
#[ORM\Table(name: 'roles')]
class Role
{
    public const BUILTIN_NON_MEMBER = 1;
    public const BUILTIN_ANONYMOUS = 2;
    /**
     * Property id
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', nullable: false)]
    private int $id;

    /**
     * Property name
     */
    #[ORM\Column(type: 'string', length: 255, options: ['default' => ''])]
    private string $name = '';

    /**
     * Property position
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $position = NULL;

    /**
     * Property assignable
     */
    #[ORM\Column(type: 'boolean', nullable: true, options: ['default' => true])]
    private ?bool $assignable = true;

    /**
     * Property builtin
     */
    #[ORM\Column(type: 'integer', options: ['default' => '0'])]
    private int $builtin = 0;

    /**
     * Property permissions
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $permissions = NULL;

    /**
     * Property issuesVisibility
     */
    #[ORM\Column(type: 'string', length: 30, options: ['default' => 'default'])]
    private string $issuesVisibility = 'default';

    /**
     * Property usersVisibility
     */
    #[ORM\Column(type: 'string', length: 30, options: ['default' => 'members_of_visible_projects'])]
    private string $usersVisibility = 'members_of_visible_projects';

    /**
     * Property timeEntriesVisibility
     */
    #[ORM\Column(type: 'string', length: 30, options: ['default' => 'all'])]
    private string $timeEntriesVisibility = 'all';

    /**
     * Property allRolesManaged
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $allRolesManaged = true;

    /**
     * Property settings
     */
    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $settings = NULL;

    /**
     * Property defaultTimeEntryActivityId
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $defaultTimeEntryActivityId = NULL;

    /**
     * Getter for id
     */
    public function getId(): int    {
        return $this->id;
    }

    /**
     * Setter for id
     */
    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Getter for name
     */
    public function getName(): string    {
        return $this->name;
    }

    /**
     * Setter for name
     */
    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Getter for position
     */
    public function getPosition(): ?int    {
        return $this->position;
    }

    /**
     * Setter for position
     */
    public function setPosition(?int $position): static
    {
        $this->position = $position;
        return $this;
    }

    /**
     * Getter for assignable
     */
    public function getAssignable(): ?bool
    {
        return $this->assignable;
    }

    /**
     * Setter for assignable
     */
    public function setAssignable(?bool $assignable): static
    {
        $this->assignable = $assignable;
        return $this;
    }

    /**
     * Getter for builtin
     */
    public function getBuiltin(): int    {
        return $this->builtin;
    }

    /**
     * Setter for builtin
     */
    public function setBuiltin(int $builtin): static
    {
        $this->builtin = $builtin;
        return $this;
    }

    /**
     * Getter for permissions
     */
    public function getPermissions(): ?string    {
        return $this->permissions;
    }

    /**
     * Setter for permissions
     */
    public function setPermissions(?string $permissions): static
    {
        $this->permissions = $permissions;
        return $this;
    }

    /**
     * Getter for issuesVisibility
     */
    public function getIssuesVisibility(): string    {
        return $this->issuesVisibility;
    }

    /**
     * Setter for issuesVisibility
     */
    public function setIssuesVisibility(string $issuesVisibility): static
    {
        $this->issuesVisibility = $issuesVisibility;
        return $this;
    }

    /**
     * Getter for usersVisibility
     */
    public function getUsersVisibility(): string    {
        return $this->usersVisibility;
    }

    /**
     * Setter for usersVisibility
     */
    public function setUsersVisibility(string $usersVisibility): static
    {
        $this->usersVisibility = $usersVisibility;
        return $this;
    }

    /**
     * Getter for timeEntriesVisibility
     */
    public function getTimeEntriesVisibility(): string    {
        return $this->timeEntriesVisibility;
    }

    /**
     * Setter for timeEntriesVisibility
     */
    public function setTimeEntriesVisibility(string $timeEntriesVisibility): static
    {
        $this->timeEntriesVisibility = $timeEntriesVisibility;
        return $this;
    }

    /**
     * Getter for allRolesManaged
     */
    public function getAllRolesManaged(): bool
    {
        return $this->allRolesManaged;
    }

    /**
     * Setter for allRolesManaged
     */
    public function setAllRolesManaged(bool $allRolesManaged): static
    {
        $this->allRolesManaged = $allRolesManaged;
        return $this;
    }

    /**
     * Getter for settings
     */
    public function getSettings(): ?string    {
        return $this->settings;
    }

    /**
     * Setter for settings
     */
    public function setSettings(?string $settings): static
    {
        $this->settings = $settings;
        return $this;
    }

    /**
     * Getter for defaultTimeEntryActivityId
     */
    public function getDefaultTimeEntryActivityId(): ?int    {
        return $this->defaultTimeEntryActivityId;
    }

    /**
     * Setter for defaultTimeEntryActivityId
     */
    public function setDefaultTimeEntryActivityId(?int $defaultTimeEntryActivityId): static
    {
        $this->defaultTimeEntryActivityId = $defaultTimeEntryActivityId;
        return $this;
    }

}
