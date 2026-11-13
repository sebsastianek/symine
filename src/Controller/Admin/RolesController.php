<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Api\ApiRenderer;
use App\Controller\AbstractController;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\TimeEntryActivity;
use App\Entity\Tracker;
use App\Security\AccessControl;
use App\Service\Admin\ActsAsPositioned;
use App\Service\Admin\WorkflowService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's RolesController (Administration » Roles and permissions). */
final class RolesController extends AbstractController
{
    /** Permissions that can be restricted to some trackers (roles/_form) */
    public const array TRACKER_PERMISSIONS = ['view_issues', 'add_issues', 'edit_issues', 'add_issue_notes', 'delete_issues'];

    public function __construct(
        private readonly ApiRenderer $api,
        private readonly WorkflowService $workflows,
        private readonly ActsAsPositioned $positioned,
    ) {
    }

    #[Route('/roles.{_format}', name: 'roles_index', requirements: ['_format' => 'html|json|xml'], defaults: ['_format' => 'html'], methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($this->isApi($request)) {
            $this->requireApiAccess();
            $roles = array_values(array_filter($this->workflows->sortedRoles(), static fn (Role $r) => !$r->isBuiltin()));

            return $this->api->render($request, ['roles' => array_map(static fn (Role $r) => ['id' => $r->id, 'name' => $r->name], $roles)]);
        }
        $this->requireAdmin();

        return $this->renderIndex();
    }

    private function renderIndex(?string $errorHtml = null): Response
    {
        $roles = $this->workflows->sortedRoles();
        $withRules = [];
        foreach ($roles as $r) {
            $withRules[$r->id] = $r->isBuiltin() || $this->workflows->roleHasRules($r);
        }

        return $this->render('admin/roles/index.html.twig', [
            'roles' => $roles,
            'with_rules' => $withRules,
            'error_html' => $errorHtml,
            'admin_menu_selected' => 'roles',
            'page_title' => $this->l('label_role_plural'),
            'body_css' => 'controller-roles action-index',
        ]);
    }

    #[Route('/roles/{id}.{_format}', name: 'role_show', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $this->requireApiAccess();
        $role = $this->findRole($id);

        return $this->api->render($request, ['role' => [
            'id' => $role->id,
            'name' => $this->roleName($role),
            'assignable' => (bool) $role->assignable,
            'issues_visibility' => $role->issuesVisibility,
            'time_entries_visibility' => $role->timeEntriesVisibility,
            'users_visibility' => $role->usersVisibility,
            'permissions' => $role->getPermissions(),
        ]]);
    }

    #[Route('/roles/new', name: 'role_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->requireAdmin();
        $role = new Role();
        $params = $request->query->all('role');
        if ([] !== $params) {
            $this->assign($role, $params);
        } else {
            $role->setPermissions($this->nonMemberRole()?->getPermissions() ?? []);
        }
        $copyFrom = null;
        if ($request->query->get('copy') && ($copyFrom = $this->em->find(Role::class, (int) $request->query->get('copy')))) {
            $this->copyFrom($role, $copyFrom);
        }

        return $this->renderForm($role, 'new', [], $copyFrom ? (string) $copyFrom->id : null);
    }

    #[Route('/roles', name: 'role_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->requireAdmin();
        $role = new Role();
        $this->assign($role, $request->request->all('role'));
        $errors = $this->validate($role);
        if ([] !== $errors) {
            return $this->renderForm($role, 'new', $errors, (string) $request->request->get('copy_workflow_from', ''), 422);
        }
        $role->position = $this->positioned->nextPosition('roles', ['builtin' => $role->builtin]);
        $this->em->persist($role);
        $this->em->flush();
        $copyId = (int) $request->request->get('copy_workflow_from', 0);
        if ($copyId && ($source = $this->em->find(Role::class, $copyId))) {
            $this->workflows->copy(null, $source, [], [$role]);
        }
        $this->flashNotice('notice_successful_create');

        return $this->redirectToRoute('roles_index');
    }

    #[Route('/roles/{id}/edit', name: 'role_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->requireAdmin();

        return $this->renderForm($this->findRole($id), 'edit');
    }

    #[Route('/roles/{id}', name: 'role_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $role = $this->findRole($id);
        $oldPosition = $role->position;
        $this->assign($role, $request->request->all('role'));
        $errors = $this->validate($role);
        $xhr = $request->isXmlHttpRequest();
        if ([] !== $errors) {
            if ($xhr) {
                return new Response('', 422);
            }

            return $this->renderForm($role, 'edit', $errors, null, 422);
        }
        $this->em->flush();
        if (null !== $role->position && $oldPosition !== $role->position) {
            $this->positioned->positionChanged('roles', (int) $role->id, $oldPosition, $role->position, ['builtin' => $role->builtin]);
        }
        if ($xhr) {
            return new Response('', 200);
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('roles_index', array_filter(['page' => $request->query->get('page')]));
    }

    #[Route('/roles/{id}', name: 'role_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $this->requireAdmin();
        $role = $this->findRole($id);
        $conn = $this->em->getConnection();
        $inUse = false !== $conn->fetchOne('SELECT 1 FROM member_roles WHERE role_id = ? LIMIT 1', [$role->id]);
        if ($inUse || $role->isBuiltin()) {
            $error = htmlspecialchars($this->l('error_can_not_remove_role'));
            if ($inUse) {
                $projects = $this->em->createQuery('SELECT DISTINCT p FROM App\Entity\Project p JOIN p.memberships m JOIN m.memberRoles mr WHERE mr.role = :r ORDER BY p.lft')
                    ->setParameter('r', $role)->getResult();
                $links = implode(', ', array_map(fn (Project $p) => $this->projectSettingsLink($p), $projects));
                $error .= $this->l('error_can_not_remove_role_reason_members_html', ['projects' => $links]);
            }

            return $this->renderIndex($error);
        }
        $rid = (int) $role->id;
        $position = $role->position;
        $builtin = $role->builtin;
        $this->workflows->deleteForRole($rid);
        $conn->executeStatement('DELETE FROM custom_fields_roles WHERE role_id = ?', [$rid]);
        $conn->executeStatement('DELETE FROM queries_roles WHERE role_id = ?', [$rid]);
        $role->managedRoles->clear();
        $this->em->remove($role);
        $this->em->flush();
        $this->positioned->removePosition('roles', $rid, $position, ['builtin' => $builtin]);

        return $this->redirectToRoute('roles_index');
    }

    #[Route('/roles/permissions.{_format}', name: 'roles_permissions', requirements: ['_format' => 'html|csv'], defaults: ['_format' => 'html'], methods: ['GET'])]
    public function permissions(Request $request, string $_format): Response
    {
        $this->requireAdmin();
        $roles = $this->workflows->sortedRoles();
        $ids = array_map('intval', array_filter((array) $request->query->all('ids')));
        if ([] !== $ids) {
            $roles = array_values(array_filter($roles, static fn (Role $r) => \in_array($r->id, $ids, true)));
        }
        $permissions = array_values(array_filter(array_keys(AccessControl::permissions()), static fn ($p) => !AccessControl::isPublic($p)));
        $setable = [];
        foreach ($roles as $r) {
            $setable[$r->id] = AccessControl::setablePermissions($r->builtin);
        }
        if ('csv' === $_format) {
            return $this->permissionsCsv($request, $roles, $permissions, $setable);
        }

        return $this->render('admin/roles/permissions.html.twig', [
            'roles' => $roles,
            'all_roles' => $this->workflows->sortedRoles(),
            'perms_by_module' => $this->groupByModule($permissions),
            'setable' => $setable,
            'admin_menu_selected' => 'roles',
            'page_title' => $this->l('label_permissions_report'),
            'body_css' => 'controller-roles action-permissions',
        ]);
    }

    #[Route('/roles/permissions', name: 'roles_update_permissions', methods: ['POST'])]
    public function updatePermissions(Request $request): Response
    {
        $this->requireAdmin();
        $perms = $request->request->all('permissions');
        foreach ($perms as $roleId => $list) {
            $role = $this->em->find(Role::class, (int) $roleId);
            if (null !== $role) {
                $role->setPermissions(array_values(array_filter((array) $list, static fn ($p) => '' !== (string) $p)));
            }
        }
        $this->em->flush();
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('roles_index');
    }

    // ------------------------------------------------------------------

    private function requireApiAccess(): void
    {
        // require_admin_or_api_request + check_if_login_required
        if ($this->settings->loginRequired() && !$this->user()->isLogged()) {
            $this->deny();
        }
    }

    private function findRole(int $id): Role
    {
        return $this->em->find(Role::class, $id) ?? throw $this->createNotFoundException();
    }

    private function nonMemberRole(): ?Role
    {
        return $this->em->getRepository(Role::class)->findOneBy(['builtin' => Role::BUILTIN_NON_MEMBER]);
    }

    private function roleName(Role $role): string
    {
        return match ($role->builtin) {
            Role::BUILTIN_NON_MEMBER => $this->l('label_role_non_member'),
            Role::BUILTIN_ANONYMOUS => $this->l('label_role_anonymous'),
            default => (string) $role->name,
        };
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

    /** Role#safe_attributes= */
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
        $label = $this->l('field_name');
        if ('' === $name) {
            $errors[] = $label.' '.$this->l('activerecord.errors.messages.blank');
        } else {
            $other = $this->em->getRepository(Role::class)->findOneBy(['name' => $name]);
            if (null !== $other && $other->id !== $role->id) {
                $errors[] = $label.' '.$this->l('activerecord.errors.messages.taken');
            }
            if (mb_strlen($name) > 255) {
                $errors[] = $label.' '.$this->l('activerecord.errors.messages.too_long', ['count' => 255]);
            }
        }
        foreach ([
            ['issuesVisibility', Role::ISSUES_VISIBILITY_OPTIONS, 'field_issues_visibility'],
            ['usersVisibility', Role::USERS_VISIBILITY_OPTIONS, 'field_users_visibility'],
            ['timeEntriesVisibility', Role::TIME_ENTRIES_VISIBILITY_OPTIONS, 'field_time_entries_visibility'],
        ] as [$prop, $options, $field]) {
            if (!\array_key_exists($role->{$prop}, $options)) {
                $errors[] = $this->l($field).' '.$this->l('activerecord.errors.messages.inclusion');
            }
        }

        return $errors;
    }

    /** @param list<string> $errors */
    private function renderForm(Role $role, string $action, array $errors = [], ?string $copyWorkflowFrom = null, int $status = 200): Response
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

        return $this->render('admin/roles/'.$action.'.html.twig', [
            'role' => $role,
            'role_display_name' => $role->id ? $this->roleName($role) : '',
            'errors' => $errors,
            'roles' => $this->workflows->sortedRoles(),
            'givable_roles' => array_values(array_filter($this->workflows->sortedRoles(), static fn (Role $r) => !$r->isBuiltin())),
            'managed_ids' => $managedIds,
            'activities' => $activities,
            'perms_by_module' => $this->groupByModule($setable),
            'tracker_permissions' => array_values(array_intersect(self::TRACKER_PERMISSIONS, $setable)),
            'all_trackers' => $allTrackers,
            'tracker_ids' => $trackerIds,
            'trackers' => $trackers,
            'copy_workflow_from' => $copyWorkflowFrom,
            'admin_menu_selected' => 'roles',
            'page_title' => 'new' === $action ? $this->l('label_role_new') : $this->roleName($role),
            'body_css' => 'controller-roles action-'.$action,
        ], new Response('', $status));
    }

    /**
     * Groups permissions by module, modules sorted by name ('' = project first) with labels.
     *
     * @param list<string> $permissions
     *
     * @return list<array{module: string, id: string, label: string, permissions: list<array{name: string, label: string}>}>
     */
    private function groupByModule(array $permissions): array
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
                'label' => '' === $mod ? $this->l('label_project') : $this->l('project_module_'.$mod),
                'permissions' => array_map(fn ($p) => ['name' => $p, 'label' => $this->l('permission_'.$p)], $perms),
            ];
        }

        return $out;
    }

    private function projectSettingsLink(Project $p): string
    {
        try {
            $url = $this->generateUrl('project_settings', ['id' => $p->identifier, 'tab' => 'members']);
        } catch (\Throwable) {
            return htmlspecialchars($p->name);
        }

        return '<a href="'.htmlspecialchars($url).'">'.htmlspecialchars($p->name).'</a>';
    }

    /** RolesHelper#permissions_to_csv */
    private function permissionsCsv(Request $request, array $roles, array $permissions, array $setable): Response
    {
        $sep = (string) ($request->query->get('separator') ?: ',');
        $lines = [];
        $header = [$this->l('field_cvs_module'), $this->l('label_permissions')];
        foreach ($roles as $r) {
            $header[] = $this->roleName($r);
        }
        $lines[] = $header;
        foreach ($this->groupByModule($permissions) as $group) {
            foreach ($group['permissions'] as $perm) {
                $p = $perm['name'];
                $row = ['' === $group['module'] ? '' : $group['label'], $perm['label']];
                foreach ($roles as $r) {
                    $row[] = \in_array($p, $setable[$r->id], true) ? ($r->hasPermission($p) ? $this->l('general_text_Yes') : $this->l('general_text_No')) : '';
                }
                $lines[] = $row;
            }
        }
        $fh = fopen('php://temp', 'r+');
        foreach ($lines as $l) {
            fputcsv($fh, $l, $sep, '"', '');
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);

        return new Response($csv, 200, ['Content-Type' => 'text/csv; header=present', 'Content-Disposition' => 'attachment; filename="permissions.csv"']);
    }
}
