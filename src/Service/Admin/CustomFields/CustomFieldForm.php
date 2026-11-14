<?php

declare(strict_types=1);

namespace App\Service\Admin\CustomFields;

use App\Entity\CustomField;
use App\Entity\CustomFieldEnumeration;
use App\Entity\IssueCustomField;
use App\Entity\Role;
use App\Entity\Tracker;
use App\I18n\Localizer;
use App\Service\Admin\FieldFormats;
use App\Service\Admin\ProjectNestedList;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The custom field form of Administration > Custom fields: creates the field of the chosen type (possibly as
 * a copy), takes the submitted custom_field[...] parameters, validates and saves them, and gives the
 * templates their variables.
 */
final class CustomFieldForm
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Localizer $i18n,
        private readonly FieldFormats $formats,
        private readonly CustomFieldManager $fields,
        private readonly ProjectNestedList $projectList,
    ) {
    }

    /**
     * The first step of a new custom field: choosing what it customizes.
     *
     * @param string $tab the tab of the list the administrator comes from
     *
     * @return array<string, mixed>
     */
    public function typeSelectionView(string $tab): array
    {
        return [
            'type_options' => array_map(fn ($name) => [$this->i18n->l(FieldFormats::TABS[$name]), $name], array_keys(FieldFormats::TABS)),
            'default_type' => isset(FieldFormats::TABS[$tab]) ? $tab : 'IssueCustomField',
        ];
    }

    /** A new, unsaved field of the type, with the attributes of the field it is copied from */
    public function newField(string $type, ?CustomField $copyFrom = null): CustomField
    {
        $field = FieldFormats::newInstance($type);
        if (null !== $copyFrom) {
            $this->copyFrom($field, $copyFrom);
        }

        return $field;
    }

    /**
     * The form of a new field, pre-filled from the URL (custom_field[...]) and from the field to copy.
     *
     * @param array<string, mixed> $attrs the custom_field[...] parameters
     *
     * @return array<string, mixed>
     */
    public function newView(string $type, array $attrs, ?CustomField $copyFrom = null): array
    {
        $field = $this->newField($type, $copyFrom);
        $this->formats->assign($field, $attrs);
        if ('' === $field->fieldFormat) {
            $field->fieldFormat = 'string';
        }
        if (null === $copyFrom) {
            $field->defaultValue = $attrs['default_value'] ?? null;
        }

        return $this->view($field, [], $copyFrom);
    }

    /**
     * Assigns the submitted parameters and saves the field when they are valid.
     *
     * @param array<string, mixed> $attrs the custom_field[...] parameters
     * @param \Closure|null        $saved called once the field is saved: the place of Redmine's
     *                                    controller_custom_fields_*_after_save hooks
     *
     * @return list<string> the validation errors; empty when the field was saved
     */
    public function submit(CustomField $field, array $attrs, ?\Closure $saved = null): array
    {
        $before = ['position' => $field->position, 'multiple' => $field->isMultiple(), 'visible' => $field->visible];
        $this->formats->assign($field, $attrs);
        $this->formats->normalize($field);
        $errors = $this->formats->validate($field);
        if ([] !== $errors) {
            return $errors;
        }
        $projectIds = \array_key_exists('project_ids', $attrs) ? (array) $attrs['project_ids'] : null;
        if (null === $field->id) {
            $this->fields->create($field, $projectIds, $saved);
        } else {
            $this->fields->update($field, $before, $projectIds, $saved);
        }

        return [];
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    public function view(CustomField $field, array $errors = [], ?CustomField $copyFrom = null): array
    {
        $type = $field::typeName();
        $projectIds = [];
        if ($field instanceof IssueCustomField) {
            if (null !== $field->id) {
                $projectIds = array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT project_id FROM custom_fields_projects WHERE custom_field_id = ?', [$field->id]));
            } else {
                $projectIds = array_map(static fn ($p) => $p->id, $field->projects->toArray());
            }
        }

        return [
            'custom_field' => $field,
            'type' => $type,
            'type_label' => FieldFormats::TABS[$type] ?? 'label_custom_field_plural',
            'format' => $this->formats->info((string) $field->fieldFormat),
            'format_options' => $this->formats->formatsForSelect($type),
            'possible_values_options' => $this->formats->possibleValuesOptions($field),
            'givable_roles' => $this->em->getRepository(Role::class)->findBy(['builtin' => 0], ['position' => 'ASC']),
            'trackers' => $this->em->getRepository(Tracker::class)->findBy([], ['position' => 'ASC']),
            'role_ids' => array_map(static fn ($r) => $r->id, $field->roles->toArray()),
            'tracker_ids' => $field instanceof IssueCustomField ? array_map(static fn ($t) => $t->id, $field->trackers->toArray()) : [],
            'project_nested_list' => $this->projectList->checkboxes($this->projectList->projects(), 'custom_field[project_ids]', $projectIds),
            'errors' => $errors,
            'copy_from' => $copyFrom,
            'active_enumerations' => array_values(array_filter($field->enumerations->toArray(), static fn ($e) => $e->active)),
            'version_statuses' => ['open', 'locked', 'closed'],
        ];
    }

    /** CustomField#copy_from */
    private function copyFrom(CustomField $field, CustomField $src): void
    {
        foreach (['fieldFormat', 'possibleValues', 'regexp', 'minLength', 'maxLength', 'isRequired', 'isForAll', 'isFilter', 'searchable', 'defaultValue', 'editable', 'visible', 'multiple', 'formatStore', 'description'] as $p) {
            $field->$p = $src->$p;
        }
        foreach ($src->enumerations as $e) {
            $ne = new CustomFieldEnumeration();
            $ne->customField = $field;
            $ne->name = $e->name;
            $ne->active = $e->active;
            $ne->position = $e->position;
            $field->enumerations->add($ne);
        }
        if ($src->enumerations->count() > 0) {
            $field->defaultValue = null;
        }
        if (\in_array($field::typeName(), ['IssueCustomField', 'TimeEntryCustomField', 'ProjectCustomField', 'VersionCustomField'], true)) {
            foreach ($src->roles as $r) {
                $field->roles->add($r);
            }
        }
        if ($field instanceof IssueCustomField && $src instanceof IssueCustomField) {
            foreach ($src->trackers as $t) {
                $field->trackers->add($t);
            }
            foreach ($src->projects as $p) {
                $field->projects->add($p);
            }
        }
    }
}
