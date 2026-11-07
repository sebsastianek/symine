<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\RoleRepository::class)]
#[ORM\Table(name: 'roles')]
class Role implements \Stringable
{
    public const int BUILTIN_NONE = 0;
    public const int BUILTIN_NON_MEMBER = 1;
    public const int BUILTIN_ANONYMOUS = 2;

    public const array ISSUES_VISIBILITY_OPTIONS = ['all' => 'label_issues_visibility_all', 'default' => 'label_issues_visibility_public', 'own' => 'label_issues_visibility_own'];
    public const array TIME_ENTRIES_VISIBILITY_OPTIONS = ['all' => 'label_time_entries_visibility_all', 'own' => 'label_time_entries_visibility_own'];
    public const array USERS_VISIBILITY_OPTIONS = ['all' => 'label_users_visibility_all', 'members_of_visible_projects' => 'label_users_visibility_members_of_visible_projects'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255, nullable: true, options: ['default' => ''])]
    public ?string $name = '';

    #[ORM\Column(nullable: true)]
    public ?int $position = null;

    #[ORM\Column(nullable: true, options: ['default' => true])]
    public ?bool $assignable = true;

    #[ORM\Column(options: ['default' => 0])]
    public int $builtin = self::BUILTIN_NONE;

    /** @var list<string>|null */
    #[ORM\Column(type: 'redmine_serialized', nullable: true)]
    public mixed $permissions = [];

    #[ORM\Column(length: 30, options: ['default' => 'default'])]
    public string $issuesVisibility = 'default';

    #[ORM\Column(length: 30, options: ['default' => 'members_of_visible_projects'])]
    public string $usersVisibility = 'members_of_visible_projects';

    #[ORM\Column(length: 30, options: ['default' => 'all'])]
    public string $timeEntriesVisibility = 'all';

    #[ORM\Column(options: ['default' => true])]
    public bool $allRolesManaged = true;

    /** permissions_all_trackers / permissions_tracker_ids */
    #[ORM\Column(type: 'redmine_serialized', nullable: true)]
    public mixed $settings = null;

    #[ORM\Column(nullable: true)]
    public ?int $defaultTimeEntryActivityId = null;

    /** @var Collection<int, Role> */
    #[ORM\ManyToMany(targetEntity: Role::class)]
    #[ORM\JoinTable(name: 'roles_managed_roles')]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'managed_role_id', referencedColumnName: 'id')]
    public Collection $managedRoles;

    public function __construct()
    {
        $this->managedRoles = new ArrayCollection();
    }

    public function isBuiltin(): bool
    {
        return self::BUILTIN_NONE !== $this->builtin;
    }

    public function isMember(): bool
    {
        return !$this->isBuiltin();
    }

    public function isGivable(): bool
    {
        return !$this->isBuiltin();
    }

    /** @return list<string> */
    public function getPermissions(): array
    {
        return \is_array($this->permissions) ? array_values(array_map('strval', $this->permissions)) : [];
    }

    /** @param list<string> $permissions */
    public function setPermissions(array $permissions): void
    {
        $this->permissions = array_values(array_unique(array_filter(array_map('strval', $permissions))));
    }

    public function hasPermission(string $permission): bool
    {
        return \in_array($permission, $this->getPermissions(), true);
    }

    public function addPermission(string ...$perms): void
    {
        $this->setPermissions([...$this->getPermissions(), ...$perms]);
    }

    public function removePermission(string ...$perms): void
    {
        $this->setPermissions(array_diff($this->getPermissions(), $perms));
    }

    public function considerWorkflow(): bool
    {
        return $this->hasPermission('add_issues') || $this->hasPermission('edit_issues');
    }

    private function setting(string $key): array
    {
        $s = \is_array($this->settings) ? ($this->settings[$key] ?? []) : [];

        return \is_array($s) ? $s : [];
    }

    public function setSetting(string $key, mixed $value): void
    {
        $s = \is_array($this->settings) ? $this->settings : [];
        $s[$key] = $value;
        $this->settings = $s;
    }

    public function permissionsAllTrackers(string $permission): bool
    {
        if (!$this->hasPermission($permission)) {
            return false;
        }

        return '0' !== (string) ($this->setting('permissions_all_trackers')[$permission] ?? '1');
    }

    /** @return list<int> */
    public function permissionsTrackerIds(string $permission): array
    {
        return array_values(array_map('intval', array_filter((array) ($this->setting('permissions_tracker_ids')[$permission] ?? []), static fn ($v) => '' !== (string) $v)));
    }

    public function permissionsTrackerIdsHas(string $permission, int $trackerId): bool
    {
        return $this->hasPermission($permission) && \in_array($trackerId, $this->permissionsTrackerIds($permission), true);
    }

    /** Whether the permission applies to the given tracker. */
    public function allowedTracker(string $permission, int $trackerId): bool
    {
        return $this->permissionsAllTrackers($permission) || $this->permissionsTrackerIdsHas($permission, $trackerId);
    }

    public function canManageRole(Role $role): bool
    {
        if ($this->allRolesManaged) {
            return true;
        }
        foreach ($this->managedRoles as $r) {
            if ($r->id === $role->id) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
