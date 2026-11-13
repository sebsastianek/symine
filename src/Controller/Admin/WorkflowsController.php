<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Entity\CustomField;
use App\Entity\IssueStatus;
use App\Entity\Role;
use App\Entity\Tracker;
use App\Service\Admin\WorkflowService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's WorkflowsController (status transitions & fields permissions). */
final class WorkflowsController extends AbstractController
{
    public function __construct(private readonly WorkflowService $workflows)
    {
    }

    #[Route('/workflows', name: 'workflows_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->requireAdmin();

        return $this->render('admin/workflows/index.html.twig', [
            'roles' => $this->workflows->workflowRoles(),
            'trackers' => $this->workflows->sortedTrackers(),
            'counts' => $this->workflows->transitionCounts(),
            'admin_menu_selected' => 'workflows',
            'page_title' => $this->l('field_summary'),
            'body_css' => 'controller-workflows action-index',
        ]);
    }

    #[Route('/workflows/edit', name: 'workflows_edit', methods: ['GET'])]
    public function edit(Request $request): Response
    {
        $this->requireAdmin();
        [$roles, $trackers, $statuses, $usedOnly] = $this->findTrackersRolesAndStatuses($request->query->all());
        $tables = [];
        if (null !== $roles && null !== $trackers && [] !== $statuses) {
            $counts = $this->workflows->transitionCountsByRule($trackers, $roles);
            $max = \count($roles) * \count($trackers);
            foreach (['always', 'author', 'assignee'] as $name) {
                $tables[$name] = [
                    'present' => [] !== $counts[$name],
                    'rows' => $this->transitionRows($name, $statuses, $counts[$name], $max),
                ];
            }
        }

        return $this->render('admin/workflows/edit.html.twig', $this->formVars($roles, $trackers, $statuses, $usedOnly, $request) + [
            'tables' => $tables,
            'body_css' => 'controller-workflows action-edit',
        ]);
    }

    #[Route('/workflows/update', name: 'workflows_update', methods: ['PATCH', 'POST'])]
    public function update(Request $request): Response
    {
        $this->requireAdmin();
        [$roles, $trackers] = $this->findTrackersRolesAndStatuses($request->request->all());
        if (null !== $roles && null !== $trackers && $request->request->has('transitions')) {
            $transitions = $request->request->all('transitions');
            foreach ($transitions as $old => $byNew) {
                foreach ((array) $byNew as $new => $byRule) {
                    $transitions[$old][$new] = array_filter((array) $byRule, static fn ($v) => 'no_change' !== $v);
                }
            }
            $this->workflows->replaceTransitions($trackers, $roles, $transitions);
            $this->flashNotice('notice_successful_update');
        }

        return $this->redirectToRefererOr($request, $this->generateUrl('workflows_edit'));
    }

    #[Route('/workflows/permissions', name: 'workflows_permissions', methods: ['GET'])]
    public function permissions(Request $request): Response
    {
        $this->requireAdmin();
        [$roles, $trackers, $statuses, $usedOnly] = $this->findTrackersRolesAndStatuses($request->query->all());
        $rows = ['core' => [], 'custom' => []];
        if (null !== $roles && null !== $trackers) {
            $disabled = null;
            foreach ($trackers as $t) {
                $disabled = null === $disabled ? $t->getDisabledCoreFields() : array_values(array_intersect($disabled, $t->getDisabledCoreFields()));
            }
            $fields = array_values(array_diff([...Tracker::CORE_FIELDS_UNDISABLABLE, ...Tracker::CORE_FIELDS], $disabled ?? []));
            $customFields = [];
            foreach ($trackers as $t) {
                foreach ($t->customFields as $cf) {
                    $customFields[$cf->id] = $cf;
                }
            }
            usort($customFields, static fn (CustomField $a, CustomField $b) => [$a->position, $a->id] <=> [$b->position, $b->id]);
            $permissions = $this->workflows->rulesByStatusId($trackers, $roles);
            $max = \count($roles) * \count($trackers);
            $roleCf = $this->roleCustomFieldIds($roles);
            foreach ($fields as $field) {
                $label = $this->l('field_'.(str_ends_with($field, '_id') ? substr($field, 0, -3) : $field));
                $required = \in_array($field, ['project_id', 'tracker_id', 'subject', 'priority_id', 'is_private'], true);
                $rows['core'][] = $this->permissionRow($field, $label, $required, false, $statuses, $permissions, $max);
            }
            foreach ($customFields as $cf) {
                $hidden = !$cf->visible && [] === array_intersect([$cf->id], $roleCf);
                $rows['custom'][] = $this->permissionRow((string) $cf->id, $cf->name, $cf->isRequired, $hidden, $statuses, $permissions, $max);
            }
        }

        return $this->render('admin/workflows/permissions.html.twig', $this->formVars($roles, $trackers, $statuses, $usedOnly, $request) + [
            'rows' => $rows,
            'body_css' => 'controller-workflows action-permissions',
        ]);
    }

    #[Route('/workflows/update_permissions', name: 'workflows_update_permissions', methods: ['PATCH', 'POST'])]
    public function updatePermissions(Request $request): Response
    {
        $this->requireAdmin();
        [$roles, $trackers] = $this->findTrackersRolesAndStatuses($request->request->all());
        if (null !== $roles && null !== $trackers && $request->request->has('permissions')) {
            $permissions = $request->request->all('permissions');
            foreach ($permissions as $statusId => $byField) {
                $permissions[$statusId] = array_filter((array) $byField, static fn ($v) => 'no_change' !== $v);
            }
            $this->workflows->replacePermissions($trackers, $roles, $permissions);
            $this->flashNotice('notice_successful_update');
        }

        return $this->redirectToRefererOr($request, $this->generateUrl('workflows_permissions'));
    }

    #[Route('/workflows/copy', name: 'workflows_copy', methods: ['GET'])]
    public function copy(Request $request): Response
    {
        $this->requireAdmin();

        return $this->renderCopy($this->findSourcesAndTargets($request->query->all()));
    }

    #[Route('/workflows/duplicate', name: 'workflows_duplicate', methods: ['POST'])]
    public function duplicate(Request $request): Response
    {
        $this->requireAdmin();
        $p = $request->request->all();
        $v = $this->findSourcesAndTargets($p);
        if ('' === (string) ($p['source_tracker_id'] ?? '') || '' === (string) ($p['source_role_id'] ?? '') || (null === $v['source_tracker'] && null === $v['source_role'])) {
            return $this->renderCopy($v, $this->l('error_workflow_copy_source'));
        }
        if ([] === ($v['target_trackers'] ?? []) || [] === ($v['target_roles'] ?? [])) {
            return $this->renderCopy($v, $this->l('error_workflow_copy_target'));
        }
        $this->workflows->copy($v['source_tracker'], $v['source_role'], $v['target_trackers'], $v['target_roles']);
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('workflows_copy', array_filter([
            'source_tracker_id' => $v['source_tracker']?->id,
            'source_role_id' => $v['source_role']?->id,
        ]));
    }

    // ------------------------------------------------------------------

    private function renderCopy(array $v, ?string $error = null): Response
    {
        return $this->render('admin/workflows/copy.html.twig', $v + [
            'error' => $error,
            'admin_menu_selected' => 'workflows',
            'page_title' => $this->l('button_copy'),
            'body_css' => 'controller-workflows action-'.(null === $error ? 'copy' : 'duplicate'),
        ]);
    }

    private function findSourcesAndTargets(array $p): array
    {
        $st = (string) ($p['source_tracker_id'] ?? '');
        $sr = (string) ($p['source_role_id'] ?? '');
        $tt = array_filter((array) ($p['target_tracker_ids'] ?? []), static fn ($x) => '' !== (string) $x);
        $tr = array_filter((array) ($p['target_role_ids'] ?? []), static fn ($x) => '' !== (string) $x);

        return [
            'roles' => $this->workflows->workflowRoles(),
            'trackers' => $this->workflows->sortedTrackers(),
            'source_tracker' => '' === $st || 'any' === $st ? null : $this->em->find(Tracker::class, (int) $st),
            'source_role' => '' === $sr || 'any' === $sr ? null : $this->em->find(Role::class, (int) $sr),
            'target_trackers' => [] === $tt ? null : $this->em->getRepository(Tracker::class)->findBy(['id' => array_map('intval', $tt)]),
            'target_roles' => [] === $tr ? null : $this->em->getRepository(Role::class)->findBy(['id' => array_map('intval', $tr)]),
        ];
    }

    /**
     * find_roles / find_trackers / find_statuses
     *
     * @return array{0: ?list<Role>, 1: ?list<Tracker>, 2: list<IssueStatus>, 3: bool}
     */
    private function findTrackersRolesAndStatuses(array $p): array
    {
        $roleIds = array_values(array_filter(array_map('strval', (array) ($p['role_id'] ?? [])), static fn ($v) => '' !== $v));
        $roles = null;
        if (['all'] === $roleIds) {
            $roles = $this->workflows->workflowRoles();
        } elseif ([] !== $roleIds) {
            $roles = $this->em->getRepository(Role::class)->findBy(['id' => array_map('intval', $roleIds)], ['builtin' => 'ASC', 'position' => 'ASC']);
        }
        $roles = [] === $roles ? null : $roles;

        $trackerIds = array_values(array_filter(array_map('strval', (array) ($p['tracker_id'] ?? [])), static fn ($v) => '' !== $v));
        $trackers = null;
        if (['all'] === $trackerIds) {
            $trackers = $this->workflows->sortedTrackers();
        } elseif ([] !== $trackerIds) {
            $trackers = $this->em->getRepository(Tracker::class)->findBy(['id' => array_map('intval', $trackerIds)], ['position' => 'ASC']);
        }
        $trackers = [] === $trackers ? null : $trackers;

        $usedOnly = '0' !== (string) ($p['used_statuses_only'] ?? '');
        $statuses = null;
        if (null !== $trackers && $usedOnly) {
            $ids = $this->workflows->usedStatusIds(array_map(static fn (Tracker $t) => (int) $t->id, $trackers));
            if ([] !== $ids) {
                $statuses = $this->em->getRepository(IssueStatus::class)->findBy(['id' => $ids], ['position' => 'ASC']);
            }
        }
        $statuses ??= $this->workflows->sortedStatuses();

        return [$roles, $trackers, $statuses, $usedOnly];
    }

    /** Common variables of the edit / permissions pages (role/tracker selectors) */
    private function formVars(?array $roles, ?array $trackers, array $statuses, bool $usedOnly, Request $request): array
    {
        $allRoles = $this->workflows->workflowRoles();
        $allTrackers = $this->workflows->sortedTrackers();
        $selParams = [
            'role_id' => null === $roles ? [] : array_map(static fn ($r) => $r->id, $roles),
            'tracker_id' => null === $trackers ? [] : array_map(static fn ($t) => $t->id, $trackers),
        ];

        return [
            'roles' => $roles,
            'trackers' => $trackers,
            'statuses' => $statuses,
            'used_statuses_only' => $usedOnly,
            'used_statuses_only_param' => $request->query->get('used_statuses_only'),
            'role_select' => $this->workflowSelect($allRoles, $roles),
            'tracker_select' => $this->workflowSelect($allTrackers, $trackers),
            'tab_query' => self::railsQuery($selParams),
            'admin_menu_selected' => 'workflows',
            'page_title' => $this->l('label_workflow'),
        ];
    }

    /**
     * options_for_workflow_select: [multiple, all_selected, all_hidden, options => [[id, name, selected]]]
     */
    private function workflowSelect(array $objects, ?array $selected): array
    {
        $multiple = false;
        if (null !== $selected) {
            if (\count($selected) === \count($objects)) {
                $sel = 'all';
            } else {
                $sel = array_map(static fn ($o) => $o->id, $selected);
                $multiple = \count($sel) > 1;
            }
        } else {
            $sel = isset($objects[0]) ? [$objects[0]->id] : [];
        }
        $options = [];
        foreach ($objects as $o) {
            $options[] = ['id' => $o->id, 'name' => $o instanceof Role ? $this->roleName($o) : $o->name, 'selected' => \is_array($sel) && \in_array($o->id, $sel, true)];
        }

        return ['multiple' => $multiple, 'all_selected' => 'all' === $sel, 'options' => $options];
    }

    private function roleName(Role $role): string
    {
        return match ($role->builtin) {
            Role::BUILTIN_NON_MEMBER => $this->l('label_role_non_member'),
            Role::BUILTIN_ANONYMOUS => $this->l('label_role_anonymous'),
            default => (string) $role->name,
        };
    }

    /**
     * Rows of a transitions table (workflows/_form + transition_tag).
     *
     * @param list<IssueStatus>  $statuses
     * @param array<string, int> $counts   "old-new" => count
     */
    private function transitionRows(string $name, array $statuses, array $counts, int $max): array
    {
        $rows = [];
        $olds = 'always' === $name ? [null, ...$statuses] : $statuses;
        foreach ($olds as $old) {
            $oldId = $old?->id ?? 0;
            $oldName = null === $old ? $this->l('label_issue_new') : $old->name;
            $cells = [];
            foreach ($statuses as $new) {
                $w = $counts[$oldId.'-'.$new->id] ?? 0;
                $same = null !== $old && $old->id === $new->id;
                $cells[] = [
                    'new' => $new,
                    'checked' => $same || $w > 0,
                    'title' => $oldName.' » '.$new->name,
                    'kind' => $same ? 'disabled' : ((0 === $w || $w === $max) ? 'checkbox' : 'select'),
                    'value' => 0 !== $w,
                    'tag_name' => 'transitions['.$oldId.']['.$new->id.']['.$name.']',
                    'tag_id' => 'transitions_'.$oldId.'_'.$new->id.'_'.$name,
                    'css' => 'old-status-'.$oldId.' new-status-'.$new->id,
                ];
            }
            $rows[] = ['old_id' => $oldId, 'old_name' => $oldName, 'is_new' => null === $old, 'cells' => $cells];
        }

        return $rows;
    }

    /** One row of the fields permissions table (field_permission_tag) */
    private function permissionRow(string $field, string $label, bool $required, bool $hidden, array $statuses, array $permissions, int $max): array
    {
        $cells = [];
        foreach ($statuses as $status) {
            $perm = $permissions[$status->id][$field] ?? null;
            $options = [['', ''], [$this->l('label_readonly'), 'readonly']];
            if (!$required) {
                $options[] = [$this->l('label_required'), 'required'];
            }
            $selected = null;
            if (null !== $perm) {
                if (\count(array_unique($perm)) > 1 || \count($perm) < $max) {
                    $options[] = [$this->l('label_no_change_option'), 'no_change'];
                    $selected = 'no_change';
                } else {
                    $selected = $perm[0];
                }
            }
            if ($hidden) {
                $options[0][0] = $this->l('label_hidden');
                $selected = '';
            }
            $cells[] = [
                'status' => $status,
                'class' => null === $perm ? '' : implode(' ', $perm),
                'name' => 'permissions['.$status->id.']['.$field.']',
                'id' => 'permissions_'.$status->id.'_'.$field,
                'options' => $options,
                'selected' => $selected,
                'disabled' => $hidden,
            ];
        }

        return ['field' => $field, 'label' => $label, 'required' => $required, 'cells' => $cells];
    }

    /** @return list<int> custom field ids visible by at least one of the roles */
    private function roleCustomFieldIds(array $roles): array
    {
        $ids = array_map(static fn (Role $r) => (int) $r->id, $roles);
        if ([] === $ids) {
            return [];
        }

        return array_map('intval', $this->em->getConnection()->executeQuery(
            'SELECT DISTINCT custom_field_id FROM custom_fields_roles WHERE role_id IN (?)',
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        )->fetchFirstColumn());
    }

    /** Rails-like query string (role_id[]=1&tracker_id[]=2) */
    private static function railsQuery(array $params): string
    {
        $parts = [];
        foreach ($params as $k => $values) {
            foreach ($values as $v) {
                $parts[] = rawurlencode($k.'[]').'='.rawurlencode((string) $v);
            }
        }

        return [] === $parts ? '' : '?'.implode('&', $parts);
    }

    /** redirect_to_referer_or */
    private function redirectToRefererOr(Request $request, string $default): Response
    {
        $referer = (string) $request->headers->get('referer');
        if ('' !== $referer) {
            $host = parse_url($referer, \PHP_URL_HOST);
            if (null === $host || $host === $request->getHost()) {
                return $this->redirect($referer);
            }
        }

        return $this->redirect($default);
    }
}
