<?php

declare(strict_types=1);

namespace App\Service\Timelog;

use App\Entity\TimeEntry;
use App\Entity\TimeEntryCustomField;
use App\Service\CustomFieldService;
use App\Service\TimeEntryService;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the context menu of the selected time entries offers (ContextMenusController#time_entries): the
 * activities and the custom field values they can all be given, when the user may edit every one of them.
 */
final class TimeEntryContextMenu
{
    public function __construct(
        private readonly TimeEntryService $timeEntries,
        private readonly CustomFieldService $customFields,
    ) {
    }

    /**
     * @param non-empty-list<TimeEntry> $entries
     *
     * @return array<string, mixed>
     */
    public function view(array $entries, Request $request): array
    {
        $projects = TimeEntryService::projectsOf($entries);
        $activities = $this->timeEntries->commonActivities($projects);
        $editAllowed = true;
        foreach ($entries as $e) {
            $editAllowed = $editAllowed && $this->timeEntries->isEditable($e);
        }
        $optionsByField = [];
        if ($editAllowed) {
            $fieldSets = array_map(fn ($e) => array_map(static fn ($f) => $f->id, $this->timeEntries->editableCustomFields($e)), $entries);
            $common = [] !== $fieldSets ? array_intersect(...$fieldSets) : [];
            foreach ($this->customFields->fieldsOf(TimeEntryCustomField::class) as $f) {
                if (!\in_array($f->id, $common, true) || $f->isMultiple() || !\in_array($f->fieldFormat, ['list', 'bool', 'date', 'enumeration', 'user', 'version'], true)) {
                    continue;
                }
                $values = $this->customFields->possibleValuesOptions($f, 1 === \count($projects) ? $projects[0] : null);
                if ([] !== $values) {
                    $optionsByField[] = ['field' => $f, 'options' => $values];
                }
            }
        }

        return [
            'entries' => $entries,
            'entry' => 1 === \count($entries) ? $entries[0] : null,
            'ids' => array_map(static fn ($e) => $e->id, $entries),
            'activities' => $activities,
            'can_edit' => $editAllowed,
            'options_by_field' => $optionsByField,
            'back' => TimelogBackUrl::ofContextMenu($request),
        ];
    }
}
