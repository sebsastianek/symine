<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\IssueApiPresenter;
use App\Service\Issue\IssueFinder;
use App\Service\Issue\IssueRelationFinder;
use App\Service\Issue\IssueRelationForm;
use App\Service\Issue\IssueRelationJs;
use App\Service\IssueService;
use App\Twig\IssuesExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Port of Redmine's IssueRelationsController (HTML, JS and REST API). */
final class IssueRelationsController extends AbstractController
{
    public function __construct(
        private readonly IssueFinder $finder,
        private readonly IssueRelationFinder $relations,
        private readonly IssueRelationForm $form,
        private readonly IssueService $issues,
        private readonly ApiRenderer $api,
        private readonly IssueApiPresenter $presenter,
        private readonly IssuesExtension $helpers,
    ) {
    }

    #[Route('/issues/{issue_id}/relations.{_format}', name: 'issue_relations_index', requirements: ['issue_id' => '\d+', '_format' => 'json|xml|html'], defaults: ['_format' => 'html'], methods: ['GET'])]
    public function index(Request $request, int $issue_id): Response
    {
        $issue = $this->finder->find($issue_id);
        // permission map: issue_relations#index belongs to manage_issue_relations
        $this->authorize('manage_issue_relations', $issue->project);
        if (!$this->isApi($request)) {
            return new Response('');
        }

        return $this->api->render($request, ['relations' => array_map($this->presenter->relation(...), $this->presenter->visibleRelations($issue))]);
    }

    #[Route('/relations/{id}.{_format}', name: 'relation_show', requirements: ['id' => '\d+', '_format' => 'json|xml|html'], defaults: ['_format' => 'html'], methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $relation = $this->relations->find($id);
        if (!$this->isApi($request)) {
            return new Response('');
        }

        return $this->api->render($request, ['relation' => $this->presenter->relation($relation)]);
    }

    #[Route('/issues/{issue_id}/relations.{_format}', name: 'issue_relations_create', requirements: ['issue_id' => '\d+', '_format' => 'json|xml|js|html'], defaults: ['_format' => 'html'], methods: ['POST'])]
    public function create(Request $request, int $issue_id, string $_format = 'html'): Response
    {
        $issue = $this->finder->find($issue_id);
        $this->authorize('manage_issue_relations', $issue->project);
        $submission = $this->form->submit($request, $issue);
        if ($this->isApi($request)) {
            if (null !== $submission->relation && $submission->isSaved()) {
                $response = $this->api->render($request, ['relation' => $this->presenter->relation($submission->relation)], 201);
                $response->headers->set('Location', $this->generateUrl('relation_show', ['id' => $submission->relation->id], UrlGeneratorInterface::ABSOLUTE_URL));

                return $response;
            }

            return $this->api->errors($request, $submission->errors);
        }
        if ('js' === $_format || $request->isXmlHttpRequest()) {
            $html = $this->renderView('issues/_relations.html.twig', [
                'issue' => $issue,
                'project' => $issue->project,
                'relations' => $this->form->relationsOf($issue),
                'unsaved_relations' => $submission->unsaved,
            ]);

            return new Response(IssueRelationJs::created($html, $submission->isSaved()), 200, ['Content-Type' => IssueRelationJs::CONTENT_TYPE]);
        }
        if (!$submission->isSaved()) {
            $this->flashError(implode(', ', $submission->errors));
        }

        return $this->redirectToRoute('issue_show', ['id' => $issue->id]);
    }

    #[Route('/relations/{id}.{_format}', name: 'relation_destroy', requirements: ['id' => '\d+', '_format' => 'json|xml|js|html'], defaults: ['_format' => 'html'], methods: ['DELETE'])]
    public function destroy(Request $request, int $id, string $_format = 'html'): Response
    {
        $relation = $this->relations->findDeletable($id);
        $from = $relation->issueFrom;
        $this->issues->deleteRelation($relation);
        if ($this->isApi($request)) {
            return $this->api->noContent();
        }
        $issueId = (int) ($request->query->get('issue_id') ?: $from->id);
        if ('js' === $_format || $request->isXmlHttpRequest()) {
            $issue = $this->finder->find($issueId);

            return new Response(IssueRelationJs::destroyed($id, $this->helpers->renderRelationsStats($issue, $this->form->relationsOf($issue))), 200, ['Content-Type' => IssueRelationJs::CONTENT_TYPE]);
        }

        return $this->redirectToRoute('issue_show', ['id' => $from->id]);
    }
}
