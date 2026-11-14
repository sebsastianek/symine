<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\CustomField;
use App\Entity\IssueCustomField;
use App\Service\Admin\FieldFormats;

/** Builds the REST API structure of app/views/custom_fields/index.api.rsb. */
final class CustomFieldApiPresenter
{
    public function __construct(private readonly FieldFormats $formats)
    {
    }

    /**
     * @param list<CustomField> $fields
     *
     * @return list<array<string, mixed>> the fields, by id
     */
    public function customFields(array $fields): array
    {
        usort($fields, static fn ($a, $b) => $a->id <=> $b->id);

        return array_map($this->customField(...), $fields);
    }

    /** @return array<string, mixed> */
    public function customField(CustomField $field): array
    {
        $type = $field::typeName();
        $out = [
            'id' => $field->id,
            'name' => $field->name,
            'description' => $field->description,
            'customized_type' => FieldFormats::customizedTypeUnderscore($type),
            'field_format' => $field->fieldFormat,
            'regexp' => $field->regexp,
            'min_length' => $field->minLength,
            'max_length' => $field->maxLength,
            'is_required' => $field->isRequired,
            'is_for_all' => $field->isForAll,
            'is_filter' => $field->isFilter,
            'searchable' => (bool) $field->searchable,
            'multiple' => (bool) $field->multiple,
            'default_value' => $field->defaultValue,
        ];
        if ('date' === $field->fieldFormat) {
            $out['default_value_mode'] = (string) ($field->getFormatOption('default_value_mode') ?: 'fixed_date');
        }
        $out['visible'] = $field->visible;
        $out['editable'] = (bool) $field->editable;
        $values = $this->formats->possibleValuesOptions($field);
        if ([] !== $values) {
            $out['possible_values'] = array_map(static fn ($v) => ['value' => $v[1] ?? $v[0], 'label' => $v[0]], $values);
        }
        if ($field instanceof IssueCustomField) {
            $projects = $field->projects->toArray();
            usort($projects, static fn ($a, $b) => $a->id <=> $b->id);
            $out['projects'] = array_map(static fn ($p) => ['id' => $p->id, 'name' => $p->name], $projects);
            $trackers = $field->trackers->toArray();
            usort($trackers, static fn ($a, $b) => $a->id <=> $b->id);
            $out['trackers'] = array_map(static fn ($t) => ['id' => $t->id, 'name' => $t->name], $trackers);
        }
        if (\in_array($type, ['IssueCustomField', 'TimeEntryCustomField', 'ProjectCustomField', 'VersionCustomField'], true)) {
            $out['roles'] = array_map(static fn ($r) => ['id' => $r->id, 'name' => $r->name], $field->roles->toArray());
        }

        return $out;
    }
}
