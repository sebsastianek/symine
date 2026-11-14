<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Token;
use App\Entity\User;
use App\I18n\Localizer;
use App\Pdf\PdfExport;
use App\Query\IssueQuery;
use App\Security\AccessGuard;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\ProjectService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The issue list (issues#index): the project it is about, the variables of the page and whether the user may
 * export what the query finds.
 */
final class IssueListView
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurrentUser $currentUser,
        private readonly Authorization $auth,
        private readonly AccessGuard $guard,
        private readonly ProjectService $projects,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly PdfExport $pdfExport,
    ) {
    }

    /**
     * find_optional_project: the project of the URL (/projects/x/issues), else the one given as parameter
     * (/issues?project_id=x is the project's issue list), else none.
     */
    public function project(Request $request, ?string $routeProjectId): ?Project
    {
        if (null !== $routeProjectId) {
            return $this->guard->findProject($routeProjectId);
        }
        $projectId = $request->query->get('project_id');
        if (\is_string($projectId) && '' !== $projectId) {
            if (null === $this->projects->findByIdentifierOrId($projectId) && !$this->currentUser->get()->isLogged()) {
                $this->guard->deny();
            }

            return $this->guard->findProject($projectId);
        }

        return null;
    }

    /** ApplicationController#export_allowed? */
    public function exportAllowed(IssueQuery $query, ?Project $project): bool
    {
        return $this->pdfExport->exportAllowed($query, $project);
    }

    /** Refuses the export (CSV, PDF) of issues of projects the user may not export from */
    public function authorizeExport(IssueQuery $query, ?Project $project): void
    {
        if (!$this->exportAllowed($query, $project)) {
            $this->guard->deny();
        }
    }

    /**
     * Variables of issues/index (and of issues/_index_content, its part refreshed by Ajax).
     *
     * @param list<Issue>               $issues
     * @param array<string, mixed>|null $pager
     *
     * @return array<string, mixed>
     */
    public function vars(IssueQuery $query, ?Project $project, array $issues, int $count, ?array $pager): array
    {
        $atomKey = $this->atomKey($this->currentUser->get());

        return [
            'project' => $project,
            'query' => $query,
            'issues' => $issues,
            'issue_count' => $count,
            'pager' => $pager,
            'main_menu_selected' => 'issues',
            'body_css' => 'controller-issues action-index',
            'page_title' => $query->isNew() ? $this->i18n->l('label_issue_plural') : $query->name(),
            'atom_key' => $atomKey,
            'export_allowed' => $this->exportAllowed($query, $project),
            'can_add_issues' => $this->auth->allowedTo('add_issues', $project, null, null === $project) && (null === $project || [] !== $this->projects->allowedTargetTrackers($project)),
            'can_import' => $this->auth->allowedTo('import_issues', $project, null, true) && $this->auth->allowedTo('add_issues', $project, null, true),
            'can_save_queries' => $this->auth->allowedTo('save_queries', $project, null, true),
            'issues_export_limit' => $this->settings->int('issues_export_limit'),
        ];
    }

    /** User#atom_key */
    private function atomKey(User $user): ?string
    {
        if (!$user->isLogged() || null === $user->id) {
            return null;
        }
        $token = $this->em->getRepository(Token::class)->findOneBy(['user' => $user, 'action' => 'feeds']);
        if (null === $token) {
            $token = new Token($user, 'feeds');
            $this->em->persist($token);
            $this->em->flush();
        }

        return $token->value;
    }
}
