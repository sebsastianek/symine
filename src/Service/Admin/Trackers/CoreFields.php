<?php

declare(strict_types=1);

namespace App\Service\Admin\Trackers;

use App\Entity\Tracker;
use App\I18n\Localizer;

/** Names the standard fields of issues the way the tracker and workflow pages label them. */
final class CoreFields
{
    public function __construct(private readonly Localizer $i18n)
    {
    }

    /** Label of a standard field: "assigned_to_id" is labelled by field_assigned_to */
    public function label(string $field): string
    {
        return $this->i18n->l('field_'.(str_ends_with($field, '_id') ? substr($field, 0, -3) : $field));
    }

    /** @return array<string, string> core field => label, for the fields a tracker can disable */
    public function labels(): array
    {
        $out = [];
        foreach (Tracker::CORE_FIELDS as $f) {
            $out[$f] = $this->label($f);
        }

        return $out;
    }
}
