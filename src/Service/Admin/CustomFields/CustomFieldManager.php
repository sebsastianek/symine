<?php

declare(strict_types=1);

namespace App\Service\Admin\CustomFields;

use App\Entity\CustomField;
use App\Entity\IssueCustomField;
use App\Service\Admin\ListPositioner;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Saves and deletes custom fields (Redmine's CustomField model callbacks): position among the fields of the
 * same type, projects of an issue custom field, roles of a field that becomes visible to all, values kept
 * when a field stops being multiple, and everything that goes away with a deleted field.
 */
final class CustomFieldManager
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly ListPositioner $positioner)
    {
    }

    /**
     * @param list<string|int>|null $projectIds projects of an issue custom field (null: not submitted)
     * @param \Closure|null         $saved      called once the field is saved
     */
    public function create(CustomField $field, ?array $projectIds = null, ?\Closure $saved = null): void
    {
        $scope = ['type' => $field::typeName()];
        $explicit = null !== $field->position;
        $field->position ??= $this->positioner->nextPosition('custom_fields', $scope);
        $this->em->persist($field);
        $this->em->flush();
        if ($explicit) {
            $this->positioner->inserted('custom_fields', $scope, $field->id, $field->position);
        }
        if ($field instanceof IssueCustomField && null !== $projectIds) {
            $this->setProjectIds($field, $projectIds);
        }
        if (null !== $saved) {
            $saved();
        }
    }

    /**
     * @param array{position: ?int, multiple: bool, visible: bool} $before     the field before it was changed
     * @param list<string|int>|null                                 $projectIds projects of an issue custom field (null: not submitted)
     * @param \Closure|null                                         $saved      called once the field is saved, before the
     *                                                                          values of a field that is no longer multiple
     *                                                                          are trimmed
     */
    public function update(CustomField $field, array $before, ?array $projectIds = null, ?\Closure $saved = null): void
    {
        if ($field->visible && !$before['visible']) {
            $field->roles->clear();
        }
        $this->em->flush();
        if (null !== $before['position'] && null !== $field->position && $before['position'] !== $field->position) {
            $this->positioner->moved('custom_fields', ['type' => $field::typeName()], $field->id, $before['position'], $field->position);
        } elseif (null === $before['position'] && null !== $field->position) {
            $this->positioner->inserted('custom_fields', ['type' => $field::typeName()], $field->id, $field->position);
        }
        if ($field instanceof IssueCustomField && null !== $projectIds) {
            $this->setProjectIds($field, $projectIds);
        }
        if (null !== $saved) {
            $saved();
        }
        if ($before['multiple'] && !$field->isMultiple()) {
            $this->handleMultiplicityChange($field);
        }
    }

    /**
     * Deletes the field with its values, its key/value list, its links and its workflow permissions.
     *
     * @return bool false when the field could not be deleted: nothing was removed
     */
    public function destroy(CustomField $field): bool
    {
        $id = (int) $field->id;
        $type = $field::typeName();
        $position = $field->position;
        $db = $this->em->getConnection();
        try {
            $db->beginTransaction();
            $db->executeStatement('DELETE FROM custom_values WHERE custom_field_id = ?', [$id]);
            $db->executeStatement('DELETE FROM custom_field_enumerations WHERE custom_field_id = ?', [$id]);
            $db->executeStatement('DELETE FROM custom_fields_roles WHERE custom_field_id = ?', [$id]);
            $db->executeStatement('DELETE FROM custom_fields_trackers WHERE custom_field_id = ?', [$id]);
            $db->executeStatement('DELETE FROM custom_fields_projects WHERE custom_field_id = ?', [$id]);
            if ($field instanceof IssueCustomField) {
                $db->executeStatement("DELETE FROM workflows WHERE type = 'WorkflowPermission' AND field_name = ?", [(string) $id]);
            }
            $db->executeStatement('DELETE FROM custom_fields WHERE id = ?', [$id]);
            $this->positioner->removed('custom_fields', ['type' => $type], $id, $position);
            $db->commit();
            $this->em->clear();
        } catch (\Throwable) {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }

            return false;
        }

        return true;
    }

    /** @param list<string|int> $ids */
    private function setProjectIds(IssueCustomField $field, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, static fn ($v) => '' !== (string) $v))));
        $db = $this->em->getConnection();
        $db->executeStatement('DELETE FROM custom_fields_projects WHERE custom_field_id = ?', [$field->id]);
        foreach ($ids as $pid) {
            if ($db->fetchOne('SELECT 1 FROM projects WHERE id = ?', [$pid])) {
                $db->executeStatement('INSERT INTO custom_fields_projects (custom_field_id, project_id) VALUES (?, ?)', [$field->id, $pid]);
            }
        }
        $this->em->refresh($field);
    }

    /** handle_multiplicity_change: keeps only the first value of each customized object */
    private function handleMultiplicityChange(CustomField $field): void
    {
        $this->em->getConnection()->executeStatement(
            'DELETE FROM custom_values WHERE custom_field_id = ? AND id NOT IN (SELECT MIN(id) FROM custom_values WHERE custom_field_id = ? GROUP BY customized_type, customized_id)',
            [$field->id, $field->id]
        );
    }
}
