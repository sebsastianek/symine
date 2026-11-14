<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\Issue;
use App\Entity\Project;
use App\I18n\Localizer;
use App\Security\AccessGuard;
use App\Security\ApiRequestMatcher;
use App\Security\Authorization;
use App\Service\IssueService;
use App\Service\Settings;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Deletes the selected issues (issues#destroy). When time was logged on them the user first has to say what
 * becomes of it: deleted too, kept without issue, or reassigned to another issue.
 */
final class IssueDeletion
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly AccessGuard $guard,
        private readonly IssueFinder $finder,
        private readonly IssueService $issues,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
    ) {
    }

    public function submit(Request $request): IssueDeletionOutcome
    {
        $isApi = ApiRequestMatcher::isApiRequest($request);
        $issues = $this->finder->fromRequest($request);
        foreach ($issues as $i) {
            if (!$this->auth->allowedTo('delete_issues', $i->project) || !$this->issues->isDeletable($i)) {
                $this->guard->deny();
            }
        }
        $projects = IssueFinder::projectsOf($issues);
        $project = 1 === \count($projects) ? $projects[0] : null;
        $ids = [];
        foreach ($issues as $i) {
            $ids[] = $i->id;
            foreach ($i->getDescendants() as $d) {
                $ids[] = $d->id;
            }
        }
        $hours = $this->issues->spentHoursOn($ids);
        $todo = (string) IssueParams::get($request, 'todo', '');
        $reassignTo = null;
        if ($hours > 0) {
            $error = null;
            switch ($todo) {
                case 'destroy':
                    break;
                case 'nullify':
                    if (\in_array('issue_id', $this->settings->array('timelog_required_fields'), true)) {
                        $error = $this->i18n->l('field_issue').' '.$this->i18n->l('activerecord.errors.messages.blank');
                    }
                    break;
                case 'reassign':
                    $target = $project ? $this->em->find(Issue::class, (int) IssueParams::get($request, 'reassign_to_id')) : null;
                    if (null === $target || $target->project->id !== $project->id) {
                        $error = $this->i18n->l('error_issue_not_found_in_project');
                    } elseif (\in_array($target->id, $ids, true)) {
                        $error = $this->i18n->l('error_cannot_reassign_time_entries_to_an_issue_about_to_be_deleted');
                    } else {
                        $reassignTo = $target;
                    }
                    break;
                default:
                    if (!$isApi) {
                        $error = '';
                    } else {
                        $todo = 'destroy';
                    }
            }
            if (null !== $error) {
                return new IssueDeletionOutcome($project, [
                    'issues' => $issues,
                    'project' => $project,
                    'hours' => $hours,
                    'error' => '' === $error ? null : $error,
                    'reassign_to_id' => IssueParams::get($request, 'reassign_to_id'),
                    'back_url' => IssueParams::get($request, 'back_url'),
                    'main_menu_selected' => 'issues',
                    'page_title' => $this->i18n->l('label_confirmation'),
                    'body_css' => 'controller-issues action-destroy',
                ]);
            }
        }
        if ($reassignTo) {
            $this->em->getConnection()->executeStatement('UPDATE time_entries SET project_id = ? WHERE issue_id IN (?)', [$reassignTo->project->id, $ids], [ParameterType::INTEGER, ArrayParameterType::INTEGER]);
        }
        $this->issues->destroy($issues, '' === $todo ? 'destroy' : $todo, $reassignTo?->id);

        // the deletion clears the EntityManager: the project is loaded again for the redirection
        return new IssueDeletionOutcome(null !== $project ? $this->em->find(Project::class, $project->id) : null);
    }
}
