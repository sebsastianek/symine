<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\TimeEntry;
use App\Query\TimeEntry\TimeEntryQuery;
use App\Service\TimeEntryService;
use Symfony\Component\HttpFoundation\Request;

/** Builds the REST API structures of app/views/timelog/show.api.rsb and index.api.rsb. */
final class TimeEntryApiPresenter
{
    public function __construct(private readonly TimeEntryService $timeEntries)
    {
    }

    /** @return array{time_entry: array<string, mixed>} */
    public function show(TimeEntry $entry): array
    {
        return ['time_entry' => $this->entry($entry)];
    }

    /**
     * The page of entries of the query asked by limit (25 by default, 100 at most) and offset or page.
     *
     * @return array{time_entries: list<array<string, mixed>>, total_count: int, offset: int, limit: int}
     */
    public function index(Request $request, TimeEntryQuery $query, int $count): array
    {
        $limit = (int) $request->query->get('limit', 0);
        $limit = $limit < 1 ? 25 : min(100, $limit);
        $offset = $request->query->has('offset') ? max(0, (int) $request->query->get('offset')) : null;
        if (null === $offset && $request->query->has('page')) {
            $offset = max(0, ((int) $request->query->get('page') - 1) * $limit);
        }
        $offset ??= 0;
        $entries = $query->isValid() ? $query->entries($offset, $limit) : [];

        return [
            'time_entries' => array_map($this->entry(...), $entries),
            'total_count' => $count,
            'offset' => $offset,
            'limit' => $limit,
        ];
    }

    /**
     * API representation (timelog/show.api.rsb)
     *
     * @return array<string, mixed>
     */
    public function entry(TimeEntry $e): array
    {
        $data = ['id' => $e->id];
        $data['project'] = ['id' => $e->project->id, 'name' => $e->project->name];
        if (null !== $e->issue) {
            $data['issue'] = ['id' => $e->issue->id];
        }
        $data['user'] = ['id' => $e->user->id, 'name' => $e->user->getName()];
        $data['activity'] = ['id' => $e->activity->id, 'name' => $e->activity->name];
        $data['hours'] = round($e->hours, 2);
        $data['comments'] = $e->comments;
        $data['spent_on'] = $e->spentOn->format('Y-m-d');
        $data['created_on'] = ApiRenderer::time($e->createdOn);
        $data['updated_on'] = ApiRenderer::time($e->updatedOn);
        $fields = $this->timeEntries->editableCustomFields($e);
        if ([] !== $fields) {
            $values = $this->timeEntries->customValues($e);
            $data['custom_fields'] = array_map(static fn ($f) => ['id' => $f->id, 'name' => $f->name] + ($f->isMultiple() ? ['multiple' => true] : []) + ['value' => $values[$f->id] ?? ($f->isMultiple() ? [] : null)], $fields);
        }

        return $data;
    }
}
