<?php

declare(strict_types=1);

namespace App\Service\Admin\Trackers;

use App\Entity\IssueCustomField;
use App\Entity\IssueStatus;
use App\Entity\Tracker;
use App\I18n\Localizer;
use App\Service\Admin\ProjectNestedList;
use App\Service\Admin\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The tracker form of Administration > Trackers (trackers/_form): takes the submitted tracker[...] parameters,
 * validates them like Redmine's Tracker model, saves the tracker and gives the template its variables.
 */
final class TrackerForm
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Localizer $i18n,
        private readonly WorkflowService $workflows,
        private readonly TrackerFinder $finder,
        private readonly TrackerManager $trackers,
        private readonly CoreFields $coreFields,
        private readonly ProjectNestedList $projectList,
    ) {
    }

    /**
     * The form of a new tracker, pre-filled from the URL (tracker[...]) and from the tracker to copy.
     *
     * @param array<string, mixed> $params           the tracker[...] parameters
     * @param int                  $copyId           id of the tracker to copy (0: none)
     * @param string               $copyWorkflowFrom tracker selected in "Copy workflow from" when nothing is copied
     *
     * @return array<string, mixed>
     */
    public function newView(array $params, int $copyId, string $copyWorkflowFrom): array
    {
        $tracker = new Tracker();
        $tracker->defaultStatus = $this->workflows->sortedStatuses()[0] ?? null;
        [$customFieldIds, $projectIds] = $this->assign($tracker, $params);
        $customFieldIds ??= [];
        $projectIds ??= [];
        $copyFrom = null;
        if ($copyId && ($copyFrom = $this->em->find(Tracker::class, $copyId))) {
            $tracker->isInRoadmap = $copyFrom->isInRoadmap;
            $tracker->fieldsBits = $copyFrom->fieldsBits;
            $tracker->defaultStatus = $copyFrom->defaultStatus;
            $tracker->description = $copyFrom->description;
            $tracker->privateByDefault = $copyFrom->privateByDefault;
            $customFieldIds = $this->finder->customFieldIds($copyFrom);
            $projectIds = $this->finder->projectIds($copyFrom);
        }

        return $this->view($tracker, new TrackerSubmission([], $customFieldIds, $projectIds), $copyFrom ? (string) $copyFrom->id : $copyWorkflowFrom);
    }

    /**
     * Assigns the submitted parameters and saves the tracker when they are valid.
     *
     * @param array<string, mixed> $params           the tracker[...] parameters
     * @param int                  $copyWorkflowFrom id of the tracker whose workflow a new tracker gets (0: none)
     */
    public function submit(Tracker $tracker, array $params, int $copyWorkflowFrom = 0): TrackerSubmission
    {
        $oldPosition = $tracker->position;
        [$customFieldIds, $projectIds] = $this->assign($tracker, $params);
        $errors = $this->validate($tracker);
        if ([] !== $errors) {
            return new TrackerSubmission($errors, $customFieldIds, $projectIds);
        }
        if (null === $tracker->id) {
            $this->trackers->create($tracker, $customFieldIds ?? [], $projectIds ?? [], $copyWorkflowFrom);
        } else {
            $this->trackers->update($tracker, $oldPosition, $customFieldIds, $projectIds);
        }

        return new TrackerSubmission([], $customFieldIds, $projectIds);
    }

    /**
     * What the form shows: the custom fields and projects ticked in the submission, or the saved ones.
     *
     * @return array<string, mixed>
     */
    public function view(Tracker $tracker, ?TrackerSubmission $submission = null, ?string $copyWorkflowFrom = null): array
    {
        $projects = $this->projectList->projects();

        return [
            'tracker' => $tracker,
            'errors' => $submission?->errors ?? [],
            'statuses' => $this->workflows->sortedStatuses(),
            'core_fields' => $this->coreFields->labels(),
            'enabled_core_fields' => $tracker->getCoreFields(),
            'issue_custom_fields' => $this->em->getRepository(IssueCustomField::class)->findBy([], ['position' => 'ASC']),
            'custom_field_ids' => $submission?->customFieldIds ?? $this->finder->customFieldIds($tracker),
            'projects_html' => $this->projectList->checkboxes($projects, 'tracker[project_ids]', $submission?->projectIds ?? $this->finder->projectIds($tracker)),
            'has_projects' => [] !== $projects,
            'trackers' => $this->workflows->sortedTrackers(),
            'copy_workflow_from' => $copyWorkflowFrom,
        ];
    }

    /** @return list<int> the ids of a submitted list of checkboxes, without the blank ones */
    public static function idList(mixed $v): array
    {
        return array_values(array_unique(array_map('intval', array_filter((array) $v, static fn ($x) => '' !== (string) $x))));
    }

    /**
     * Tracker#safe_attributes= ; associations are kept aside until the tracker is saved
     *
     * @param array<string, mixed> $p
     *
     * @return array{0: list<int>|null, 1: list<int>|null} submitted custom field ids and project ids (null: not submitted)
     */
    private function assign(Tracker $tracker, array $p): array
    {
        $customFieldIds = null;
        $projectIds = null;
        if (\array_key_exists('name', $p)) {
            $tracker->name = trim((string) $p['name']);
        }
        if (\array_key_exists('default_status_id', $p)) {
            $tracker->defaultStatus = '' === (string) $p['default_status_id'] ? null : $this->em->find(IssueStatus::class, (int) $p['default_status_id']);
        }
        if (\array_key_exists('is_in_roadmap', $p)) {
            $tracker->isInRoadmap = \in_array((string) $p['is_in_roadmap'], ['1', 'true'], true);
        }
        if (\array_key_exists('private_by_default', $p)) {
            $tracker->privateByDefault = \in_array((string) $p['private_by_default'], ['1', 'true'], true);
        }
        if (\array_key_exists('core_fields', $p)) {
            $tracker->setCoreFields(array_values(array_filter((array) $p['core_fields'], static fn ($v) => '' !== (string) $v)));
        }
        if (\array_key_exists('position', $p) && '' !== (string) $p['position']) {
            $tracker->position = (int) $p['position'];
        }
        if (\array_key_exists('description', $p)) {
            $tracker->description = (string) $p['description'];
        }
        if (\array_key_exists('custom_field_ids', $p)) {
            $customFieldIds = self::idList($p['custom_field_ids']);
        }
        if (\array_key_exists('project_ids', $p)) {
            $projectIds = self::idList($p['project_ids']);
        }

        return [$customFieldIds, $projectIds];
    }

    /** @return list<string> */
    private function validate(Tracker $tracker): array
    {
        $errors = [];
        $label = $this->i18n->l('field_name');
        if (null === $tracker->defaultStatus) {
            $errors[] = $this->i18n->l('field_default_status').' '.$this->i18n->l('activerecord.errors.messages.blank');
        }
        if ('' === $tracker->name) {
            $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.blank');
        } else {
            $other = $this->em->getRepository(Tracker::class)->findOneBy(['name' => $tracker->name]);
            if (null !== $other && $other->id !== $tracker->id) {
                $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.taken');
            }
            if (mb_strlen($tracker->name) > 30) {
                $errors[] = $label.' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 30]);
            }
        }
        if (mb_strlen((string) $tracker->description) > 255) {
            $errors[] = $this->i18n->l('field_description').' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 255]);
        }

        return $errors;
    }
}
