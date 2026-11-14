<?php

declare(strict_types=1);

namespace App\Service\Timelog;

use App\Entity\TimeEntry;
use App\I18n\Localizer;
use App\Security\AccessGuard;
use App\Security\Authorization;
use App\Service\TimeEntryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads the time entries an action works on and refuses those the user may not see or change
 * (TimelogController#find_time_entry, #find_time_entries, #check_editability).
 */
final class TimeEntryFinder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TimeEntryService $timeEntries,
        private readonly AccessGuard $guard,
        private readonly Authorization $auth,
        private readonly Localizer $i18n,
    ) {
    }

    /** The entry (404 when it does not exist, 403 when the user may not see it) */
    public function find(int $id): TimeEntry
    {
        $entry = $this->em->find(TimeEntry::class, $id);
        if (null === $entry) {
            throw new NotFoundHttpException();
        }
        if (!$this->timeEntries->isVisible($entry)) {
            $this->guard->deny();
        }

        return $entry;
    }

    /** The entry, which the user must be allowed to edit */
    public function editable(int $id): TimeEntry
    {
        $entry = $this->find($id);
        if (!$this->auth->allowedTo('edit_time_entries', $entry->project) && !$this->auth->allowedTo('edit_own_time_entries', $entry->project)) {
            $this->guard->deny();
        }
        if (!$this->timeEntries->isEditable($entry)) {
            throw new HttpException(403, $this->i18n->l('notice_not_authorized'));
        }

        return $entry;
    }

    /**
     * find_time_entries: the entry of the route, or those of params[:ids] / params[:id]; all must be editable
     *
     * @return non-empty-list<TimeEntry>
     */
    public function editableEntries(Request $request, ?int $id = null): array
    {
        $ids = null !== $id ? [$id] : (array) ($request->request->all()['ids'] ?? $request->query->all()['ids'] ?? $request->request->all()['id'] ?? $request->query->all()['id'] ?? []);
        $entries = $this->timeEntries->findByIds($ids);
        if ([] === $entries) {
            throw new NotFoundHttpException();
        }
        foreach ($entries as $e) {
            if (!$this->timeEntries->isEditable($e)) {
                $this->guard->deny();
            }
        }

        return $entries;
    }

    /**
     * The entries of params[:ids] (context menu): unknown or hidden entries are a 404
     *
     * @return non-empty-list<TimeEntry>
     */
    public function visibleEntries(Request $request): array
    {
        $ids = (array) ($request->request->all()['ids'] ?? $request->query->all()['ids'] ?? []);
        $entries = $this->timeEntries->findByIds($ids);
        if ([] === $entries) {
            throw new NotFoundHttpException();
        }
        foreach ($entries as $e) {
            if (!$this->timeEntries->isVisible($e)) {
                throw new NotFoundHttpException();
            }
        }

        return $entries;
    }
}
