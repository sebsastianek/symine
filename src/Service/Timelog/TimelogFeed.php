<?php

declare(strict_types=1);

namespace App\Service\Timelog;

use App\Entity\TimeEntry;
use App\I18n\Localizer;
use App\Query\TimeEntry\TimeEntryQuery;
use App\Service\IssueService;
use App\Service\Settings;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The items of the spent time Atom feed (timelog/index.atom): the latest entries of the query, titled with
 * their hours and the issue (when visible) or the project they were spent on.
 */
final class TimelogFeed
{
    public function __construct(
        private readonly Settings $settings,
        private readonly IssueService $issues,
        private readonly Localizer $i18n,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** @return list<array<string, mixed>> items of App\Service\AtomFeed::render() */
    public function items(TimeEntryQuery $query): array
    {
        $entries = $query->entries(0, $this->settings->int('feeds_limit') ?: 15, ['time_entries.created_on DESC', 'time_entries.id DESC']);

        return array_map(function (TimeEntry $e) {
            $related = null !== $e->issue && $this->issues->isVisible($e->issue)
                ? $e->issue->tracker->name.' #'.$e->issue->id.' ('.$e->issue->status->name.'): '.$e->issue->subject
                : $e->project->name;
            $params = ['project_id' => $e->project->identifier] + (null !== $e->issue ? ['issue_id' => $e->issue->id] : []);

            return [
                'title' => $this->i18n->l($e->hours < 2.0 ? 'label_f_hour' : 'label_f_hour_plural', ['value' => $this->i18n->formatHours($e->hours)]).' ('.$related.')',
                'url' => $this->urls->generate('project_time_entries', $params),
                'updated' => $e->createdOn,
                'author' => $e->user,
                'content' => (string) $e->comments,
                'project' => $e->project,
                'object' => $e,
            ];
        }, $entries);
    }
}
