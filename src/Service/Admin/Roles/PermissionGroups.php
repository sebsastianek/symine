<?php

declare(strict_types=1);

namespace App\Service\Admin\Roles;

use App\I18n\Localizer;
use App\Security\AccessControl;

/** Arranges permissions by project module, with their labels, for the role form and the permissions report. */
final class PermissionGroups
{
    public function __construct(private readonly Localizer $i18n)
    {
    }

    /**
     * Groups permissions by module, modules sorted by name ('' = project first) with labels.
     *
     * @param list<string> $permissions
     *
     * @return list<array{module: string, id: string, label: string, permissions: list<array{name: string, label: string}>}>
     */
    public function byModule(array $permissions): array
    {
        $by = [];
        foreach ($permissions as $p) {
            $by[(string) AccessControl::moduleOf($p)][] = $p;
        }
        ksort($by, \SORT_STRING);
        $out = [];
        foreach ($by as $mod => $perms) {
            $out[] = [
                'module' => $mod,
                'id' => '' === $mod ? 'module_project' : 'module_'.$mod,
                'label' => '' === $mod ? $this->i18n->l('label_project') : $this->i18n->l('project_module_'.$mod),
                'permissions' => array_map(fn ($p) => ['name' => $p, 'label' => $this->i18n->l('permission_'.$p)], $perms),
            ];
        }

        return $out;
    }
}
