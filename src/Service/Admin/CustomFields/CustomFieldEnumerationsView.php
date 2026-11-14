<?php

declare(strict_types=1);

namespace App\Service\Admin\CustomFields;

use App\Entity\CustomField;
use App\Entity\CustomFieldEnumeration;
use App\Service\Admin\FieldFormats;

/** What the pages of the values of a "Key/value list" custom field show. */
final class CustomFieldEnumerationsView
{
    public function __construct(private readonly CustomFieldEnumerationManager $enumerations)
    {
    }

    /**
     * The list of values, with the errors of the value that could not be added.
     *
     * @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    public function index(CustomField $field, array $errors = []): array
    {
        return [
            'custom_field' => $field,
            'type' => $field::typeName(),
            'type_label' => FieldFormats::TABS[$field::typeName()] ?? 'label_custom_field_plural',
            'errors' => $errors,
        ];
    }

    /**
     * The confirmation asked before removing a value that objects have: which value takes them over.
     *
     * @return array<string, mixed>
     */
    public function destroy(CustomField $field, CustomFieldEnumeration $value): array
    {
        return [
            'custom_field' => $field,
            'type' => $field::typeName(),
            'type_label' => FieldFormats::TABS[$field::typeName()] ?? 'label_custom_field_plural',
            'value' => $value,
            'objects_count' => $this->enumerations->objectsCount($field, $value),
            'enumerations' => array_values(array_filter($field->enumerations->toArray(), static fn ($e) => $e !== $value)),
        ];
    }
}
