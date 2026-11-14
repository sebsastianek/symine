<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\TimeEntry;
use App\Entity\TimeEntryActivity;
use App\Entity\TimeEntryCustomField;
use App\Event\ContentEvent;
use App\Security\CurrentUser;
use App\Service\CustomFieldService;
use App\Service\TimeEntryService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The time logged along with an issue update (the "Log time" fieldset of the edit form): the fields offered
 * and the time entry built from what was submitted.
 */
final class IssueTimeLog
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurrentUser $currentUser,
        private readonly TimeEntryService $timeEntries,
        private readonly CustomFieldService $customFields,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * The time entry logged along with an issue update (IssuesController#save_issue_with_child_records):
     * built and validated like any time entry, including its custom fields.
     *
     * @param array<string, mixed> $params
     *
     * @return array{0: TimeEntry, 1: list<string>}
     */
    public function build(Issue $issue, array $params): array
    {
        $user = $this->currentUser->get();
        $te = $this->timeEntries->newEntry($issue->project, $issue, $user);
        unset($params['project_id'], $params['issue_id']);
        $this->timeEntries->setAttributes($te, $params + ['hours' => null], $user);
        // validated now, while the issue still has its previous status in the database: TimeEntry#validate_time_entry
        // only refuses the issues that were already closed (issue.closed? && issue.was_closed?)
        $errors = $this->timeEntries->validate($te, $user);

        return [$te, $errors];
    }

    /** Persists the (already validated) time entry once the issue is saved, with its custom field values */
    public function save(TimeEntry $entry): void
    {
        $now = new \DateTimeImmutable();
        // TimeEntry#hours is rounded to the minute
        $entry->hours = round($entry->hours * 60) / 60;
        $entry->createdOn = $now;
        $entry->updatedOn = $now;
        $this->em->persist($entry);
        $this->em->flush();
        $this->customFields->saveValues('TimeEntry', (int) $entry->id, $this->customFields->fieldsOf(TimeEntryCustomField::class), $this->timeEntries->customValues($entry));
        $this->em->flush();
        $this->dispatcher->dispatch(new ContentEvent('time_entry', 'created', $entry, $this->currentUser->get()));
    }

    /** @time_entry.editable_custom_field_values of the "Log time" fieldset @return list<TimeEntryCustomField> */
    public function customFields(Issue $issue): array
    {
        $entry = new TimeEntry();
        $entry->project = $issue->project;

        return $this->timeEntries->editableCustomFields($entry, $this->currentUser->get());
    }

    /**
     * @param array<string, mixed> $timeParams
     *
     * @return array<int, mixed> submitted values, else the default values
     */
    public function customValues(array $timeParams): array
    {
        $values = $this->customFields->defaultValues($this->customFields->fieldsOf(TimeEntryCustomField::class));
        foreach ((array) ($timeParams['custom_field_values'] ?? []) as $id => $v) {
            $values[(int) $id] = $v;
        }

        return $values;
    }

    /** Project#activities: active system activities, overridden by project specific ones @return list<TimeEntryActivity> */
    public function activities(Project $project): array
    {
        $system = $this->em->createQuery('SELECT a FROM App\Entity\TimeEntryActivity a WHERE a.project IS NULL ORDER BY a.position')->getResult();
        $overrides = $this->em->createQuery('SELECT a FROM App\Entity\TimeEntryActivity a WHERE a.project = :p')->setParameter('p', $project->id)->getResult();
        $byParent = [];
        foreach ($overrides as $o) {
            if ($o->parent) {
                $byParent[$o->parent->id] = $o;
            }
        }
        $out = [];
        foreach ($system as $a) {
            $eff = $byParent[$a->id] ?? $a;
            if ($eff->active) {
                $out[] = $eff;
            }
        }

        return $out;
    }
}
