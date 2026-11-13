<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Issue;
use App\Entity\Project;
use App\Service\IssueService;
use App\Service\TextFormatter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's PreviewsController (wiki toolbar "Preview" tab). */
final class PreviewsController extends AbstractController
{
    public function __construct(
        private readonly TextFormatter $formatter,
        private readonly IssueService $issues,
    ) {
    }

    #[Route('/issues/preview', name: 'preview_issue', methods: ['GET', 'POST', 'PUT', 'PATCH'])]
    public function issue(Request $request): Response
    {
        $project = $this->optionalProject($request);
        $issueId = $request->query->get('issue_id') ?? $request->request->get('issue_id');
        if (null !== $issueId && '' !== $issueId) {
            $issue = $this->em->find(Issue::class, (int) $issueId);
            if ($issue && $this->issues->isVisible($issue)) {
                $project ??= $issue->project;
            }
        }

        return $this->preview($request, $project);
    }

    #[Route('/news/preview', name: 'preview_news', methods: ['GET', 'POST', 'PUT', 'PATCH'])]
    public function news(Request $request): Response
    {
        return $this->preview($request, $this->optionalProject($request));
    }

    #[Route('/preview/text', name: 'preview_text', methods: ['GET', 'POST', 'PUT', 'PATCH'])]
    public function text(Request $request): Response
    {
        return $this->preview($request, null);
    }

    private function optionalProject(Request $request): ?Project
    {
        $issue = $request->request->all('issue');
        $pid = $issue['project_id'] ?? $request->query->get('project_id') ?? $request->request->get('project_id');
        if (null === $pid || '' === $pid) {
            return null;
        }

        return $this->findProject((string) $pid);
    }

    private function preview(Request $request, ?Project $project): Response
    {
        $text = $request->request->get('text') ?? $request->query->get('text');
        if (null === $text || '' === trim((string) $text)) {
            return new Response('<p class="empty-preview">'.htmlspecialchars($this->l('label_nothing_to_preview')).'</p>');
        }

        return new Response($this->formatter->format((string) $text, $project));
    }
}
