<?php

declare(strict_types=1);

namespace App\Service\Tracking\Previews;

use App\Entity\Issue;
use App\Entity\News;
use App\Entity\Project;
use App\Security\AccessGuard;
use App\Security\Authorization;
use App\Service\IssueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Finds what the previewed text belongs to: the project of the form (find_project) and the issue or the news
 * being edited, which give the links and inline images of the text their context.
 */
final class PreviewSubjectFinder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessGuard $guard,
        private readonly Authorization $auth,
        private readonly IssueService $issues,
    ) {
    }

    /** The project named by issue[project_id] or project_id, if any (404 / 403 like any project page) */
    public function project(Request $request): ?Project
    {
        $issue = $request->request->all('issue');
        $pid = $issue['project_id'] ?? $request->query->get('project_id') ?? $request->request->get('project_id');
        if (null === $pid || '' === $pid) {
            return null;
        }

        return $this->guard->findProject((string) $pid);
    }

    /** The issue named by issue_id, when the user can see it */
    public function issue(Request $request): ?Issue
    {
        $issueId = $request->query->get('issue_id') ?? $request->request->get('issue_id');
        if (null === $issueId || '' === $issueId) {
            return null;
        }
        $issue = $this->em->find(Issue::class, (int) $issueId);

        return $issue && $this->issues->isVisible($issue) ? $issue : null;
    }

    /** The news named by id, when the user can see it */
    public function news(Request $request): ?News
    {
        $id = $request->query->get('id') ?? $request->request->get('id');
        if (null === $id || '' === $id) {
            return null;
        }
        $news = $this->em->find(News::class, (int) $id);

        return $news && $news->project && $this->auth->allowedTo('view_news', $news->project) ? $news : null;
    }
}
