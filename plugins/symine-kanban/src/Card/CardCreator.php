<?php

declare(strict_types=1);

namespace Symine\Kanban\Card;

use App\Entity\Project;
use App\Entity\User;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Service\IssueService;
use App\Service\ProjectService;
use Symfony\Component\HttpFoundation\Request;
use Symine\Kanban\Board\BoardScope;
use Symine\Kanban\Entity\Board;

/**
 * Adds a card: a new issue with a subject, in a project and tracker of the board, starting in a status of the
 * column it was added to when the workflow allows it (otherwise in the default status of the tracker).
 * Everything else takes Redmine's defaults; issues that need more (required fields) are refused with the reason,
 * and are created from the full form instead.
 *
 * Fields: subject, project_id, tracker_id, column_id.
 */
final class CardCreator
{
    public function __construct(
        private readonly BoardScope $scope,
        private readonly IssueService $issues,
        private readonly ProjectService $projects,
        private readonly Authorization $auth,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * The projects of the board the user may add issues to, with the trackers they may use there.
     *
     * @return list<array{project: Project, trackers: list<\App\Entity\Tracker>}>
     */
    public function targets(Board $board, User $user): array
    {
        $only = array_map('intval', $board->trackerIds ?? []);
        $targets = [];
        foreach ($this->scope->projects($board, $user) as $project) {
            if (!$project->isActive() || !$this->auth->allowedTo('add_issues', $project, $user)) {
                continue;
            }
            $trackers = $this->projects->allowedTargetTrackers($project, $user);
            if ([] !== $only) {
                $trackers = array_values(array_filter($trackers, static fn ($t) => \in_array($t->id, $only, true)));
            }
            if ([] !== $trackers) {
                $targets[] = ['project' => $project, 'trackers' => array_values($trackers)];
            }
        }

        return $targets;
    }

    public function create(Board $board, Request $request, User $user): MoveResult
    {
        $projectId = (int) $request->request->get('project_id');
        $trackerId = (int) $request->request->get('tracker_id');
        $target = null;
        foreach ($this->targets($board, $user) as $candidate) {
            if ($candidate['project']->id === $projectId || (0 === $projectId && null === $target)) {
                $target = $candidate;
            }
        }
        if (null === $target) {
            return MoveResult::refused($this->i18n->l('notice_not_authorized'), 403);
        }
        $tracker = $target['trackers'][0];
        foreach ($target['trackers'] as $candidate) {
            if ($candidate->id === $trackerId) {
                $tracker = $candidate;
            }
        }

        $issue = $this->issues->newIssue($target['project'], $user);
        $this->issues->setSafeAttributes($issue, ['tracker_id' => (string) $tracker->id], $user);
        $attributes = ['subject' => trim((string) $request->request->get('subject'))];
        $column = $board->column((int) $request->request->get('column_id'));
        if (null !== $column) {
            // a new issue starts in the column it was added to when it can; otherwise where its tracker starts
            foreach ($this->issues->newStatusesAllowedTo($issue, $user, true) as $status) {
                if (\in_array($status->id, $column->statusIds(), true)) {
                    $attributes['status_id'] = (string) $status->id;
                    break;
                }
            }
        }
        $this->issues->setSafeAttributes($issue, $attributes, $user);
        $errors = $this->issues->save($issue, $user);

        return [] === $errors ? new MoveResult($issue, [], 201) : new MoveResult(null, array_values($errors), 422);
    }
}
