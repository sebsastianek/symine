<?php

declare(strict_types=1);

namespace App\Service\Admin\Roles;

use App\Entity\Role;
use App\I18n\Localizer;
use App\Security\AccessControl;
use App\Service\Admin\WorkflowService;

/**
 * The permissions report of Administration > Roles and permissions: every permission against the roles (all
 * of them, or the ones selected with ids[]), as a page and as a CSV file.
 */
final class PermissionsReport
{
    public function __construct(
        private readonly WorkflowService $workflows,
        private readonly PermissionGroups $groups,
        private readonly RoleNames $names,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * @param array<int|string, mixed> $ids the ids[] parameter
     *
     * @return array<string, mixed>
     */
    public function view(array $ids): array
    {
        $roles = $this->roles($ids);

        return [
            'roles' => $roles,
            'all_roles' => $this->workflows->sortedRoles(),
            'perms_by_module' => $this->groups->byModule($this->permissions()),
            'setable' => $this->setable($roles),
        ];
    }

    /**
     * RolesHelper#permissions_to_csv
     *
     * @param array<int|string, mixed> $ids the ids[] parameter
     */
    public function csv(array $ids, string $separator): string
    {
        $roles = $this->roles($ids);
        $setable = $this->setable($roles);
        $lines = [];
        $header = [$this->i18n->l('field_cvs_module'), $this->i18n->l('label_permissions')];
        foreach ($roles as $r) {
            $header[] = $this->names->of($r);
        }
        $lines[] = $header;
        foreach ($this->groups->byModule($this->permissions()) as $group) {
            foreach ($group['permissions'] as $perm) {
                $p = $perm['name'];
                $row = ['' === $group['module'] ? '' : $group['label'], $perm['label']];
                foreach ($roles as $r) {
                    $row[] = \in_array($p, $setable[$r->id], true) ? ($r->hasPermission($p) ? $this->i18n->l('general_text_Yes') : $this->i18n->l('general_text_No')) : '';
                }
                $lines[] = $row;
            }
        }
        $fh = fopen('php://temp', 'r+');
        foreach ($lines as $l) {
            fputcsv($fh, $l, $separator, '"', '');
        }
        rewind($fh);

        return (string) stream_get_contents($fh);
    }

    /**
     * @param array<int|string, mixed> $ids
     *
     * @return list<Role> the roles of the report: those selected, or all of them
     */
    private function roles(array $ids): array
    {
        $roles = $this->workflows->sortedRoles();
        $ids = array_map('intval', array_filter($ids));
        if ([] !== $ids) {
            $roles = array_values(array_filter($roles, static fn (Role $r) => \in_array($r->id, $ids, true)));
        }

        return $roles;
    }

    /** @return list<string> the permissions that are not public */
    private function permissions(): array
    {
        return array_values(array_filter(array_keys(AccessControl::permissions()), static fn ($p) => !AccessControl::isPublic($p)));
    }

    /**
     * @param list<Role> $roles
     *
     * @return array<int, list<string>> role id => permissions the role can be given
     */
    private function setable(array $roles): array
    {
        $setable = [];
        foreach ($roles as $r) {
            $setable[$r->id] = AccessControl::setablePermissions($r->builtin);
        }

        return $setable;
    }
}
