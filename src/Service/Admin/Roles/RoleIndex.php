<?php

declare(strict_types=1);

namespace App\Service\Admin\Roles;

use App\Entity\Project;
use App\Entity\Role;
use App\I18n\Localizer;
use App\Service\Admin\WorkflowService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** The list of Administration > Roles and permissions, with the message shown when a role could not be deleted. */
final class RoleIndex
{
    public function __construct(
        private readonly WorkflowService $workflows,
        private readonly RoleFinder $finder,
        private readonly Localizer $i18n,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param Role|null $undeletable the role that was not deleted (built-in, or given to members)
     *
     * @return array<string, mixed>
     */
    public function view(?Role $undeletable = null): array
    {
        $roles = $this->workflows->sortedRoles();
        $withRules = [];
        foreach ($roles as $r) {
            $withRules[$r->id] = $r->isBuiltin() || $this->workflows->roleHasRules($r);
        }

        return [
            'roles' => $roles,
            'with_rules' => $withRules,
            'error_html' => null === $undeletable ? null : $this->cannotRemoveHtml($undeletable),
        ];
    }

    /** error_can_not_remove_role, followed by the projects where members have the role */
    private function cannotRemoveHtml(Role $role): string
    {
        $error = htmlspecialchars($this->i18n->l('error_can_not_remove_role'));
        if ($this->finder->hasMembers($role)) {
            $links = implode(', ', array_map(fn (Project $p) => $this->projectSettingsLink($p), $this->finder->projectsWithMembers($role)));
            $error .= $this->i18n->l('error_can_not_remove_role_reason_members_html', ['projects' => $links]);
        }

        return $error;
    }

    private function projectSettingsLink(Project $p): string
    {
        try {
            $url = $this->urls->generate('project_settings', ['id' => $p->identifier, 'tab' => 'members']);
        } catch (\Throwable) {
            return htmlspecialchars($p->name);
        }

        return '<a href="'.htmlspecialchars($url).'">'.htmlspecialchars($p->name).'</a>';
    }
}
