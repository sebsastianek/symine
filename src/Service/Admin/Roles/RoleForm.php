<?php

declare(strict_types=1);

namespace App\Service\Admin\Roles;

use App\Entity\Role;
use App\Entity\Tracker;
use App\I18n\Localizer;
use App\Security\AccessControl;
use App\Service\Admin\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The role form of Administration > Roles and permissions (roles/_form): takes the submitted role[...]
 * parameters, validates them like Redmine's Role model, saves the role and gives the template its variables.
 */
final class RoleForm
{
    /** Permissions that can be restricted to some trackers (roles/_form) */
    public const array TRACKER_PERMISSIONS = ['view_issues', 'add_issues', 'edit_issues', 'add_issue_notes', 'delete_issues'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Localizer $i18n,
        private readonly WorkflowService $workflows,
        private readonly RoleFinder $finder,
        private readonly RoleManager $roles,
        private readonly RoleNames $names,
        private readonly PermissionGroups $groups,
    ) {
    }

    /**
     * The form of a new role: pre-filled from the URL (role[...]) or with the permissions of the non member
     * role, then from the role to copy.
     *
     * @param array<string, mixed> $params the role[...] parameters
     * @param int                  $copyId id of the role to copy (0: none)
     *
     * @return array<string, mixed>
     */
    public function newView(array $params, int $copyId): array
    {
        $role = new Role();
        if ([] !== $params) {
            $this->assign($role, $params);
        } else {
            $role->setPermissions($this->finder->nonMember()?->getPermissions() ?? []);
        }
        $copyFrom = null;
        if ($copyId && ($copyFrom = $this->em->find(Role::class, $copyId))) {
            $this->copyFrom($role, $copyFrom);
        }

        return $this->view($role, [], $copyFrom ? (string) $copyFrom->id : null);
    }

    /**
     * Assigns the submitted parameters and saves the role when they are valid.
     *
     * @param array<string, mixed> $params           the role[...] parameters
     * @param int                  $copyWorkflowFrom id of the role whose workflow a new role gets (0: none)
     *
     * @return list<string> the validation errors; empty when the role was saved
     */
    public function submit(Role $role, array $params, int $copyWorkflowFrom = 0): array
    {
        $oldPosition = $role->position;
        $this->assign($role, $params);
        $errors = $this->validate($role);
        if ([] !== $errors) {
            return $errors;
        }
        if (null === $role->id) {
            $this->roles->create($role, $copyWorkflowFrom);
        } else {
            $this->roles->update($role, $oldPosition);
        }

        return [];
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    public function view(Role $role, array $errors = [], ?string $copyWorkflowFrom = null): array
    {
        $setable = AccessControl::setablePermissions($role->builtin);
        $trackers = $this->em->getRepository(Tracker::class)->findBy([], ['position' => 'ASC']);
        $activities = $this->em->createQuery('SELECT a FROM App\Entity\TimeEntryActivity a WHERE a.project IS NULL AND a.active = true ORDER BY a.position')->getResult();
        $allTrackers = [];
        $trackerIds = [];
        foreach (self::TRACKER_PERMISSIONS as $perm) {
            // Redmine Role#permissions_all_trackers?: checked unless explicitly '0' (even when the permission is not granted yet)
            $allTrackersSetting = \is_array($role->settings) && \is_array($role->settings['permissions_all_trackers'] ?? null) ? $role->settings['permissions_all_trackers'] : [];
            $allTrackers[$perm] = '0' !== (string) ($allTrackersSetting[$perm] ?? '1');
            $trackerIds[$perm] = $role->hasPermission($perm) ? $role->permissionsTrackerIds($perm) : [];
        }
        $managedIds = array_map(static fn (Role $r) => $r->id, $role->managedRoles->toArray());

        return [
            'role' => $role,
            'role_display_name' => $role->id ? $this->names->of($role) : '',
            'errors' => $errors,
            'roles' => $this->workflows->sortedRoles(),
            'givable_roles' => $this->finder->givable(),
            'managed_ids' => $managedIds,
            'activities' => $activities,
            'perms_by_module' => $this->groups->byModule($setable),
            'tracker_permissions' => array_values(array_intersect(self::TRACKER_PERMISSIONS, $setable)),
            'all_trackers' => $allTrackers,
            'tracker_ids' => $trackerIds,
            'trackers' => $trackers,
            'copy_workflow_from' => $copyWorkflowFrom,
        ];
    }

    /** Role#copy_from */
    private function copyFrom(Role $role, Role $source): void
    {
        $role->assignable = $source->assignable;
        $role->issuesVisibility = $source->issuesVisibility;
        $role->usersVisibility = $source->usersVisibility;
        $role->timeEntriesVisibility = $source->timeEntriesVisibility;
        $role->allRolesManaged = $source->allRolesManaged;
        $role->settings = $source->settings;
        $role->defaultTimeEntryActivityId = $source->defaultTimeEntryActivityId;
        $role->setPermissions($source->getPermissions());
        $role->managedRoles->clear();
        foreach ($source->managedRoles as $m) {
            $role->managedRoles->add($m);
        }
    }

    /**
     * Role#safe_attributes=
     *
     * @param array<string, mixed> $p
     */
    private function assign(Role $role, array $p): void
    {
        if (\array_key_exists('name', $p)) {
            $role->name = trim((string) $p['name']);
        }
        if (\array_key_exists('assignable', $p)) {
            $role->assignable = '1' === (string) $p['assignable'] || 'true' === (string) $p['assignable'];
        }
        if (\array_key_exists('position', $p) && '' !== (string) $p['position']) {
            $role->position = (int) $p['position'];
        }
        foreach (['issues_visibility' => 'issuesVisibility', 'users_visibility' => 'usersVisibility', 'time_entries_visibility' => 'timeEntriesVisibility'] as $k => $prop) {
            if (\array_key_exists($k, $p)) {
                $role->{$prop} = (string) $p[$k];
            }
        }
        if (\array_key_exists('all_roles_managed', $p)) {
            $role->allRolesManaged = '1' === (string) $p['all_roles_managed'] || 'true' === (string) $p['all_roles_managed'];
        }
        if (\array_key_exists('managed_role_ids', $p)) {
            $ids = array_map('intval', array_filter((array) $p['managed_role_ids'], static fn ($v) => '' !== (string) $v));
            $role->managedRoles->clear();
            foreach ($ids as $mid) {
                if ($m = $this->em->find(Role::class, $mid)) {
                    $role->managedRoles->add($m);
                }
            }
        }
        if (\array_key_exists('permissions', $p)) {
            $role->setPermissions(array_values(array_filter((array) $p['permissions'], static fn ($v) => '' !== (string) $v)));
        }
        if (\array_key_exists('permissions_all_trackers', $p) && \is_array($p['permissions_all_trackers'])) {
            $role->setSetting('permissions_all_trackers', array_map('strval', $p['permissions_all_trackers']));
        }
        if (\array_key_exists('permissions_tracker_ids', $p) && \is_array($p['permissions_tracker_ids'])) {
            $h = [];
            foreach ($p['permissions_tracker_ids'] as $perm => $ids) {
                $h[$perm] = array_values(array_map('strval', array_filter((array) $ids, static fn ($v) => '' !== (string) $v)));
            }
            $role->setSetting('permissions_tracker_ids', $h);
        }
        if (\array_key_exists('default_time_entry_activity_id', $p)) {
            $v = (string) $p['default_time_entry_activity_id'];
            $role->defaultTimeEntryActivityId = '' === $v ? null : (int) $v;
        }
    }

    /** @return list<string> */
    private function validate(Role $role): array
    {
        $errors = [];
        $name = (string) $role->name;
        $label = $this->i18n->l('field_name');
        if ('' === $name) {
            $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.blank');
        } else {
            $other = $this->em->getRepository(Role::class)->findOneBy(['name' => $name]);
            if (null !== $other && $other->id !== $role->id) {
                $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.taken');
            }
            if (mb_strlen($name) > 255) {
                $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 255]);
            }
        }
        foreach ([
            ['issuesVisibility', Role::ISSUES_VISIBILITY_OPTIONS, 'field_issues_visibility'],
            ['usersVisibility', Role::USERS_VISIBILITY_OPTIONS, 'field_users_visibility'],
            ['timeEntriesVisibility', Role::TIME_ENTRIES_VISIBILITY_OPTIONS, 'field_time_entries_visibility'],
        ] as [$prop, $options, $field]) {
            if (!\array_key_exists($role->{$prop}, $options)) {
                $errors[] = $this->i18n->l($field).' '.$this->i18n->l('activerecord.errors.messages.inclusion');
            }
        }

        return $errors;
    }
}
