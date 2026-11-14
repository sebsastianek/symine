<?php

declare(strict_types=1);

namespace App\Service\Admin\Trackers;

use App\Entity\IssueCustomField;
use App\Entity\Tracker;
use App\Service\Admin\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The summary of Administration > Trackers (trackers/fields): one grid to choose the standard fields and the
 * custom fields of every tracker at once.
 */
final class TrackerFieldsForm
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkflowService $workflows,
        private readonly TrackerFinder $finder,
        private readonly TrackerManager $trackers,
        private readonly CoreFields $coreFields,
    ) {
    }

    /**
     * Saves the fields ticked for each tracker; trackers that do not exist are skipped.
     *
     * @param array<int|string, mixed> $submitted tracker id => ['core_fields' => [...], 'custom_field_ids' => [...]]
     */
    public function submit(array $submitted): void
    {
        foreach ($submitted as $trackerId => $params) {
            $tracker = $this->em->find(Tracker::class, (int) $trackerId);
            if (null === $tracker || !\is_array($params)) {
                continue;
            }
            $tracker->setCoreFields(array_values(array_filter((array) ($params['core_fields'] ?? []), static fn ($v) => '' !== (string) $v)));
            $this->em->flush();
            $this->trackers->saveAssociations($tracker, TrackerForm::idList($params['custom_field_ids'] ?? []), null);
        }
    }

    /** @return array<string, mixed> */
    public function view(): array
    {
        $trackers = $this->workflows->sortedTrackers();
        $cfIds = [];
        foreach ($trackers as $t) {
            $cfIds[$t->id] = $this->finder->customFieldIds($t);
        }

        return [
            'trackers' => $trackers,
            'custom_fields' => $this->em->getRepository(IssueCustomField::class)->findBy([], ['position' => 'ASC']),
            'cf_ids' => $cfIds,
            'core_fields' => $this->coreFields->labels(),
        ];
    }
}
