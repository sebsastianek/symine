<?php

declare(strict_types=1);

namespace App\Service\Timelog;

use App\Entity\TimeEntry;
use App\Entity\TimeEntryCustomField;
use App\I18n\Localizer;
use App\Service\CustomFieldService;
use App\Service\TimeEntryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The form changing several time entries at once (timelog/bulk_edit).
 *
 * Fields: ids[], time_entry[<attribute>] where a blank value leaves the attribute as it is and "none"
 * ("__none__" for a custom field) clears it.
 */
final class TimeEntryBulkForm
{
    public function __construct(
        private readonly TimeEntryService $timeEntries,
        private readonly TimelogScope $scope,
        private readonly CustomFieldService $customFields,
        private readonly EntityManagerInterface $em,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * Applies the submitted values to each entry and saves those that are valid.
     *
     * @param list<TimeEntry>            $entries
     * @param \Closure(TimeEntry): mixed $beforeSave called with each entry once it is changed, before it is saved
     */
    public function submit(array $entries, Request $request, \Closure $beforeSave): TimeEntryBulkResult
    {
        // parse_params_for_bulk_update: drop blank values, "none" clears
        $clean = [];
        foreach (self::params($request) as $k => $v) {
            if ('custom_field_values' === $k) {
                foreach ((array) $v as $cfId => $cfv) {
                    if (\is_array($cfv)) {
                        $cfv = array_values(array_filter($cfv, static fn ($x) => '' !== (string) $x));
                        if ([] === $cfv) {
                            continue;
                        }
                        if (\in_array('__none__', $cfv, true)) {
                            $cfv = [];
                        }
                    } elseif ('' === (string) $cfv) {
                        continue;
                    } elseif ('__none__' === $cfv) {
                        $cfv = '';
                    }
                    $clean['custom_field_values'][$cfId] = $cfv;
                }
                continue;
            }
            if (null === $v || '' === $v) {
                continue;
            }
            $clean[$k] = 'none' === $v ? '' : $v;
        }
        $unsaved = [];
        $saved = [];
        $errors = [];
        foreach ($entries as $e) {
            $this->em->refresh($e);
            $this->timeEntries->snapshot($e);
            $this->timeEntries->setAttributes($e, $clean);
            $beforeSave($e);
            $err = $this->timeEntries->save($e);
            if ([] === $err) {
                $saved[] = $e;
            } else {
                $unsaved[] = $e;
                foreach ($err as $m) {
                    $errors[$m] = $m;
                }
                $this->em->refresh($e);
            }
        }

        return new TimeEntryBulkResult($saved, $unsaved, array_values($errors));
    }

    /**
     * What the form shows for the entries: the projects they can be moved to, the activities they can all take
     * (those of the target project once one is chosen) and the custom fields.
     *
     * @param list<TimeEntry> $entries      the entries edited (after a submission: those that could not be saved)
     * @param list<TimeEntry> $savedEntries
     * @param list<string>    $errors
     *
     * @return array<string, mixed>
     */
    public function view(Request $request, array $entries, array $savedEntries = [], array $errors = []): array
    {
        $params = self::params($request);
        $targetProjects = $this->scope->logTimeProjects();
        $projects = TimeEntryService::projectsOf($entries);
        $target = null;
        if (isset($params['project_id']) && '' !== (string) $params['project_id']) {
            foreach ($targetProjects as $p) {
                if ((string) $p->id === (string) $params['project_id']) {
                    $target = $p;
                }
            }
        }

        return [
            'entries' => $entries,
            'saved_entries' => $savedEntries,
            'unsaved_entries' => [] !== $errors ? $entries : [],
            'errors' => $errors,
            'target_projects' => $targetProjects,
            'target_project' => $target,
            'activities' => null !== $target ? $this->timeEntries->activities($target) : $this->timeEntries->commonActivities($projects),
            'custom_fields' => array_values(array_filter($this->customFields->fieldsOf(TimeEntryCustomField::class), static fn ($f) => !\in_array($f->fieldFormat, ['attachment'], true))),
            'params' => $params,
            'project' => 1 === \count($projects) ? $projects[0] : null,
            'main_menu_selected' => 'time_entries',
            'body_css' => 'controller-timelog action-bulk_edit',
            'page_title' => $this->i18n->l('label_bulk_edit_selected_time_entries'),
        ];
    }

    /**
     * params[:time_entry] of the form (posted, or in the URL when the form is first opened)
     *
     * @return array<string, mixed>
     */
    private static function params(Request $request): array
    {
        return $request->request->all('time_entry') ?: $request->query->all('time_entry');
    }
}
