<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Tracker;

/** Builds the REST API structure of app/views/trackers/index.api.rsb. */
final class TrackerApiPresenter
{
    /**
     * @param list<Tracker> $trackers
     *
     * @return list<array<string, mixed>>
     */
    public function trackers(array $trackers): array
    {
        return array_map(static fn (Tracker $t) => [
            'id' => $t->id,
            'name' => $t->name,
            ...(null !== $t->defaultStatus ? ['default_status' => ['id' => $t->defaultStatus->id, 'name' => $t->defaultStatus->name]] : []),
            'description' => $t->description,
            'enabled_standard_fields' => $t->getCoreFields(),
        ], $trackers);
    }
}
