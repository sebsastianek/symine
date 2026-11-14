<?php

declare(strict_types=1);

namespace App\Api;

use App\Service\Admin\EnumerationManager;
use App\Service\CustomFieldService;

/** Builds the REST API structure of app/views/enumerations/index.api.rsb. */
final class EnumerationApiPresenter
{
    public function __construct(private readonly EnumerationManager $manager, private readonly CustomFieldService $customFields)
    {
    }

    /**
     * The system values of a type ("IssuePriority"...), each with its visible custom fields.
     *
     * @return list<array<string, mixed>>
     */
    public function enumerations(string $type): array
    {
        $enums = $this->manager->shared($type);
        $fields = $this->manager->availableCustomFields($type);
        $values = $this->customFields->valuesFor('Enumeration', array_map(static fn ($e) => $e->id, $enums));
        $items = [];
        foreach ($enums as $e) {
            $item = ['id' => $e->id, 'name' => $e->name, 'is_default' => $e->isDefault, 'active' => $e->active];
            $cfs = [];
            foreach ($fields as $cf) {
                if (!$cf->visible) {
                    continue;
                }
                $attrs = ['id' => $cf->id, 'name' => $cf->name];
                if ($cf->isMultiple()) {
                    $attrs['multiple'] = true;
                }
                $v = $values[$e->id][$cf->id] ?? ($cf->isMultiple() ? [] : null);
                $attrs['value'] = \is_array($v) ? array_values(array_filter($v, static fn ($x) => '' !== (string) $x)) : $v;
                $cfs[] = $attrs;
            }
            if ([] !== $cfs) {
                $item['custom_fields'] = $cfs;
            }
            $items[] = $item;
        }

        return $items;
    }
}
