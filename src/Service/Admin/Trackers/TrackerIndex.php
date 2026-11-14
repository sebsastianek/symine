<?php

declare(strict_types=1);

namespace App\Service\Admin\Trackers;

use App\Entity\Project;
use App\Entity\Tracker;
use App\I18n\Localizer;
use App\Service\Admin\WorkflowService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** The list of Administration > Trackers, with the message shown when a tracker could not be deleted. */
final class TrackerIndex
{
    public function __construct(
        private readonly WorkflowService $workflows,
        private readonly TrackerFinder $finder,
        private readonly Localizer $i18n,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param Tracker|null $undeletable the tracker that was not deleted because issues use it
     *
     * @return array<string, mixed>
     */
    public function view(?Tracker $undeletable = null): array
    {
        $trackers = $this->workflows->sortedTrackers();
        $withRules = [];
        foreach ($trackers as $t) {
            $withRules[$t->id] = $this->workflows->trackerHasRules($t);
        }

        return [
            'trackers' => $trackers,
            'with_rules' => $withRules,
            'error_html' => null === $undeletable ? null : $this->cannotDeleteHtml($undeletable),
        ];
    }

    /** error_can_not_delete_tracker_html: names the projects whose issues use the tracker */
    private function cannotDeleteHtml(Tracker $tracker): string
    {
        $links = implode(', ', array_map(fn (Project $p) => $this->projectIssuesLink($p, $tracker), $this->finder->projectsWithIssues($tracker)));

        return $this->i18n->l('error_can_not_delete_tracker_html', ['projects' => $links]);
    }

    private function projectIssuesLink(Project $p, Tracker $t): string
    {
        try {
            $url = $this->urls->generate('project_issues', ['id' => $p->identifier, 'set_filter' => 1, 'tracker_id' => $t->id, 'status_id' => '*']);
        } catch (\Throwable) {
            return htmlspecialchars($p->name);
        }

        return '<a href="'.htmlspecialchars($url).'">'.htmlspecialchars($p->name).'</a>';
    }
}
