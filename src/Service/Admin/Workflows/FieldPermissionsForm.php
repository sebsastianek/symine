<?php

declare(strict_types=1);

namespace App\Service\Admin\Workflows;

use App\Entity\CustomField;
use App\Entity\IssueStatus;
use App\Entity\Role;
use App\Entity\Tracker;
use App\I18n\Localizer;
use App\Service\Admin\Trackers\CoreFields;
use App\Service\Admin\WorkflowService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The fields permissions form of Administration > Workflow (workflows/permissions): which fields the
 * selected roles see read-only or required in each status, and their update.
 */
final class FieldPermissionsForm
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkflowService $workflows,
        private readonly CoreFields $coreFields,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * The rows of the table: the standard fields enabled in one of the trackers, then their custom fields.
     *
     * @return array{core: list<array<string, mixed>>, custom: list<array<string, mixed>>}
     */
    public function rows(WorkflowSelection $selection): array
    {
        $rows = ['core' => [], 'custom' => []];
        if (!$selection->isComplete()) {
            return $rows;
        }
        $roles = $selection->roles;
        $trackers = $selection->trackers;
        $statuses = $selection->statuses;
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
            $required = \in_array($field, ['project_id', 'tracker_id', 'subject', 'priority_id', 'is_private'], true);
            $rows['core'][] = $this->permissionRow($field, $this->coreFields->label($field), $required, false, $statuses, $permissions, $max);
        }
        foreach ($customFields as $cf) {
            $hidden = !$cf->visible && [] === array_intersect([$cf->id], $roleCf);
            $rows['custom'][] = $this->permissionRow((string) $cf->id, $cf->name, $cf->isRequired, $hidden, $statuses, $permissions, $max);
        }

        return $rows;
    }

    /**
     * Replaces the fields permissions of the selected roles and trackers; fields left on "no change" keep
     * what each role and tracker has.
     *
     * @param array<int|string, mixed> $permissions status id => field => '' | 'readonly' | 'required' | 'no_change'
     */
    public function submit(WorkflowSelection $selection, array $permissions): void
    {
        if (!$selection->isComplete()) {
            return;
        }
        foreach ($permissions as $statusId => $byField) {
            $permissions[$statusId] = array_filter((array) $byField, static fn ($v) => 'no_change' !== $v);
        }
        $this->workflows->replacePermissions($selection->trackers, $selection->roles, $permissions);
    }

    /**
     * One row of the fields permissions table (field_permission_tag)
     *
     * @param list<IssueStatus>                              $statuses
     * @param array<int, array<string, list<string>>> $permissions status id => field => rules
     *
     * @return array<string, mixed>
     */
    private function permissionRow(string $field, string $label, bool $required, bool $hidden, array $statuses, array $permissions, int $max): array
    {
        $cells = [];
        foreach ($statuses as $status) {
            $perm = $permissions[$status->id][$field] ?? null;
            $options = [['', ''], [$this->i18n->l('label_readonly'), 'readonly']];
            if (!$required) {
                $options[] = [$this->i18n->l('label_required'), 'required'];
            }
            $selected = null;
            if (null !== $perm) {
                if (\count(array_unique($perm)) > 1 || \count($perm) < $max) {
                    $options[] = [$this->i18n->l('label_no_change_option'), 'no_change'];
                    $selected = 'no_change';
                } else {
                    $selected = $perm[0];
                }
            }
            if ($hidden) {
                $options[0][0] = $this->i18n->l('label_hidden');
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

    /**
     * @param list<Role> $roles
     *
     * @return list<int> custom field ids visible by at least one of the roles
     */
    private function roleCustomFieldIds(array $roles): array
    {
        $ids = array_map(static fn (Role $r) => (int) $r->id, $roles);
        if ([] === $ids) {
            return [];
        }

        return array_map('intval', $this->em->getConnection()->executeQuery(
            'SELECT DISTINCT custom_field_id FROM custom_fields_roles WHERE role_id IN (?)',
            [$ids],
            [ArrayParameterType::INTEGER],
        )->fetchFirstColumn());
    }
}
