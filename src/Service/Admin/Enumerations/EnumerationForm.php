<?php

declare(strict_types=1);

namespace App\Service\Admin\Enumerations;

use App\Entity\Enumeration;
use App\Service\Admin\EnumerationManager;
use App\Service\CustomFieldService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The enumeration form of Administration > Enumerations: takes the submitted enumeration[...] parameters with
 * the custom field values, validates and saves them, and gives the template its variables.
 */
final class EnumerationForm
{
    public function __construct(
        private readonly EnumerationManager $manager,
        private readonly CustomFieldService $customFields,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Assigns the submitted parameters and saves the enumeration when they are valid.
     *
     * @param array<string, mixed> $params the enumeration[...] parameters
     *
     * @return list<string> the validation errors; empty when the enumeration was saved
     */
    public function submit(Enumeration $e, array $params): array
    {
        $before = ['position' => $e->position, 'active' => $e->active, 'isDefault' => $e->isDefault, 'name' => $e->name];
        $this->manager->assign($e, $params);
        $errors = $this->manager->validate($e);
        $cfValues = self::customFieldValues($params);
        $errors = [...$errors, ...$this->validateCustomValues($e::typeName(), $cfValues, null !== $e->id ? $this->customFields->values('Enumeration', $e->id) : [])];
        if ([] !== $errors) {
            return $errors;
        }
        if (null === $e->id) {
            $this->manager->create($e);
        } else {
            $this->manager->update($e, $before);
        }
        $this->saveCustomValues($e, $cfValues);

        return [];
    }

    /**
     * What the form shows: the custom field values as they were submitted, or the saved ones, or the defaults.
     *
     * @param list<string>              $errors
     * @param array<string, mixed>|null $params the enumeration[...] parameters when the form is shown again
     *
     * @return array<string, mixed>
     */
    public function view(Enumeration $e, array $errors = [], ?array $params = null): array
    {
        $type = $e::typeName();
        $fields = $this->manager->availableCustomFields($type);
        $values = null !== $params ? self::customFieldValues($params) : (null !== $e->id ? $this->customFields->values('Enumeration', $e->id) : $this->customFields->defaultValues($fields));
        $cfs = [];
        foreach ($fields as $cf) {
            $cfs[] = [
                'field' => $cf,
                'value' => $values[$cf->id] ?? ($cf->isMultiple() ? [] : ''),
                'options' => $this->customFields->possibleValuesOptions($cf),
            ];
        }

        return [
            'enumeration' => $e,
            'type' => $type,
            'option_name' => EnumerationManager::OPTION_NAMES[$type],
            'custom_field_values' => $cfs,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<int|string, mixed> custom field id => submitted value
     */
    private static function customFieldValues(array $params): array
    {
        return (array) ($params['custom_field_values'] ?? []);
    }

    /**
     * @param array<int|string, mixed> $values
     * @param array<int, mixed>        $current
     *
     * @return list<string>
     */
    private function validateCustomValues(string $type, array $values, array $current = []): array
    {
        $errors = [];
        foreach ($this->manager->availableCustomFields($type) as $cf) {
            if (\array_key_exists($cf->id, $values) || \array_key_exists((string) $cf->id, $values)) {
                $errors = [...$errors, ...$this->customFields->validate($cf, $values[$cf->id] ?? null, null, $current[$cf->id] ?? null)];
            }
        }

        return $errors;
    }

    /** @param array<int|string, mixed> $values */
    private function saveCustomValues(Enumeration $e, array $values): void
    {
        $fields = $this->manager->availableCustomFields($e::typeName());
        if ([] === $fields) {
            return;
        }
        $this->customFields->saveValues('Enumeration', $e->id, $fields, $values);
        $this->em->flush();
    }
}
