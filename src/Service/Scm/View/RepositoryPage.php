<?php

declare(strict_types=1);

namespace App\Service\Scm\View;

use App\Entity\Changeset;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Repository;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\AccountService;
use App\Service\IssueService;
use App\Service\Scm\RepositoryService;
use App\Service\Settings;

/**
 * What every page of the repository browser shows around its content: the repository, its branches and tags,
 * what its SCM supports and what the user may do; and, for the pages about a changeset, what surrounds it.
 */
final class RepositoryPage
{
    public function __construct(
        private readonly RepositoryService $repos,
        private readonly IssueService $issues,
        private readonly AccountService $accounts,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @param string               $action the Redmine action of the page (show, entry, diff, ...)
     * @param array<string, mixed> $vars   the variables of the page itself
     *
     * @return array<string, mixed>
     */
    public function vars(Project $project, Repository $repository, string $action, array $vars = []): array
    {
        $adapter = $this->repos->adapter($repository);
        $model = $this->repos->model($repository);
        $user = $this->currentUser->get();

        return $vars + [
            'project' => $project,
            'repository' => $repository,
            // User.current.atom_key: the Atom links carry the key so that feed readers can authenticate
            'atom_key' => $user->isLogged() ? $this->accounts->atomKey($user) : null,
            'action_name' => $action,
            'main_menu_selected' => 'repository',
            'body_css' => 'controller-repositories action-'.$action,
            'path' => '',
            'rev' => null,
            'entry' => null,
            'changeset' => null,
            'properties' => null,
            'branches' => $adapter->branches() ?? [],
            'tags' => $adapter->tags() ?? [],
            'report_last_commit' => $model->reportLastCommit($repository),
            'supports_cat' => $model->supportsCat($repository),
            'supports_annotate' => $model->supportsAnnotate($repository),
            'supports_history' => $model->supportsHistory(),
            'supports_directory_revisions' => $model->supportsDirectoryRevisions(),
            'supports_revision_graph' => $model->supportsRevisionGraph(),
            'can_browse' => $this->auth->allowedTo('browse_repository', $project),
            'can_view_changesets' => $this->auth->allowedTo('view_changesets', $project),
            'can_manage' => $this->auth->allowedTo('manage_repository', $project),
            'autofetch' => $this->settings->bool('autofetch_changesets'),
        ];
    }

    /**
     * The neighbours of the changeset, the issues related to it that the user may see and whether it has changes
     *
     * @return array<string, mixed>
     */
    public function changesetVars(Changeset $changeset): array
    {
        $issues = array_values(array_filter($changeset->issues->toArray(), fn (Issue $i) => $this->issues->isVisible($i)));

        return [
            'previous' => $this->repos->previousChangeset($changeset),
            'next' => $this->repos->nextChangeset($changeset),
            'children' => $this->repos->children($changeset),
            'related_issues' => $issues,
            'has_changes' => [] !== $this->repos->changes($changeset, 1),
            'can_manage_related' => $this->auth->allowedTo('manage_related_issues', $changeset->repository->project),
        ];
    }
}
