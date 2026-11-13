<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\IssueApiPresenter;
use App\Entity\Issue;
use App\Entity\IssueRelation;
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
        private readonly IssueService $issues,
        private readonly ApiRenderer $api,
        private readonly IssueApiPresenter $presenter,
        private readonly IssuesExtension $helpers,
    ) {
    }

    private function findIssue(int $id): Issue
    {
        $issue = $this->em->find(Issue::class, $id);
        if (null === $issue) {
            throw $this->createNotFoundException();
        }
        if (!$this->issues->isVisible($issue)) {
            $this->deny();
        }

        return $issue;
    }

    #[Route('/issues/{issue_id}/relations.{_format}', name: 'issue_relations_index', requirements: ['issue_id' => '\d+', '_format' => 'json|xml|html'], defaults: ['_format' => 'html'], methods: ['GET'])]
    public function index(Request $request, int $issue_id): Response
    {
        $issue = $this->findIssue($issue_id);
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
        $relation = $this->em->find(IssueRelation::class, $id) ?? throw $this->createNotFoundException();
        if (!$this->issues->isVisible($relation->issueFrom) || !$this->issues->isVisible($relation->issueTo)) {
            $this->deny();
        }
        if (!$this->isApi($request)) {
            return new Response('');
        }

        return $this->api->render($request, ['relation' => $this->presenter->relation($relation)]);
    }

    #[Route('/issues/{issue_id}/relations.{_format}', name: 'issue_relations_create', requirements: ['issue_id' => '\d+', '_format' => 'json|xml|js|html'], defaults: ['_format' => 'html'], methods: ['POST'])]
    public function create(Request $request, int $issue_id, string $_format = 'html'): Response
    {
        $issue = $this->findIssue($issue_id);
        $this->authorize('manage_issue_relations', $issue->project);
        $isApi = $this->isApi($request);
        $params = $isApi ? (array) (ApiRenderer::payload($request)['relation'] ?? []) : $request->request->all('relation');
        $toIds = $params['issue_to_id'] ?? '';
        $toIds = \is_array($toIds) ? $toIds : (is_numeric($toIds) && !\is_string($toIds) ? [$toIds] : array_values(array_filter(array_map('trim', explode(',', (string) $toIds)), static fn ($v) => '' !== $v)));
        if ([] === $toIds) {
            $toIds = [''];
        }
        $type = (string) ($params['relation_type'] ?? IssueRelation::TYPE_RELATES);
        $delay = isset($params['delay']) && '' !== (string) $params['delay'] ? (int) $params['delay'] : null;
        $unsaved = [];
        $relation = null;
        $lastErrors = [];
        foreach ($toIds as $toId) {
            $toId = trim((string) $toId, " #\t");
            $to = ctype_digit($toId) ? (int) $toId : null;
            [$relation, $errors] = $this->issues->createRelation($issue, $to, $type, $delay);
            if (null === $relation) {
                if (null === $to) {
                    $errors = [$this->l('field_issue_to').' '.$this->l('activerecord.errors.messages.blank')];
                }
                $unsaved[] = ['issue_to_id' => $to, 'errors' => $errors];
                $lastErrors = $errors;
            }
        }
        if ($isApi) {
            if (null !== $relation && [] === $unsaved) {
                $response = $this->api->render($request, ['relation' => $this->presenter->relation($relation)], 201);
                $response->headers->set('Location', $this->generateUrl('relation_show', ['id' => $relation->id], UrlGeneratorInterface::ABSOLUTE_URL));

                return $response;
            }

            return $this->api->errors($request, $lastErrors);
        }
        if ('js' === $_format || $request->isXmlHttpRequest()) {
            $this->em->refresh($issue);
            $html = $this->renderView('issues/_relations.html.twig', [
                'issue' => $issue,
                'project' => $issue->project,
                'relations' => $this->presenter->visibleRelations($issue),
                'unsaved_relations' => $unsaved,
            ]);
            $js = '$("#relations").html('.json_encode($html, \JSON_HEX_TAG | \JSON_UNESCAPED_UNICODE).');';
            if ([] === $unsaved) {
                $js .= "$('#relation_delay').val('');$('#relation_issue_to_id').val('');";
            }
            $js .= "$('#new-relation-form').show();$('#relation_issue_to_id').focus();";

            return $this->js($js);
        }
        if ([] !== $unsaved) {
            $this->flashError(implode(', ', $lastErrors));
        }

        return $this->redirectToRoute('issue_show', ['id' => $issue->id]);
    }

    #[Route('/relations/{id}.{_format}', name: 'relation_destroy', requirements: ['id' => '\d+', '_format' => 'json|xml|js|html'], defaults: ['_format' => 'html'], methods: ['DELETE'])]
    public function destroy(Request $request, int $id, string $_format = 'html'): Response
    {
        $relation = $this->em->find(IssueRelation::class, $id) ?? throw $this->createNotFoundException();
        $from = $relation->issueFrom;
        $to = $relation->issueTo;
        if (!$this->issues->isVisible($from) || !$this->issues->isVisible($to)
            || (!$this->auth->allowedTo('manage_issue_relations', $from->project) && !$this->auth->allowedTo('manage_issue_relations', $to->project))) {
            $this->deny();
        }
        $this->issues->deleteRelation($relation);
        if ($this->isApi($request)) {
            return $this->api->noContent();
        }
        $issueId = (int) ($request->query->get('issue_id') ?: $from->id);
        if ('js' === $_format || $request->isXmlHttpRequest()) {
            $issue = $this->findIssue($issueId);
            $this->em->refresh($issue);
            $relations = $this->presenter->visibleRelations($issue);

            return $this->js('$("#relation-'.$id.'").remove();$(".issues-stat").replaceWith('.json_encode($this->helpers->renderRelationsStats($issue, $relations), \JSON_HEX_TAG | \JSON_UNESCAPED_UNICODE).');');
        }

        return $this->redirectToRoute('issue_show', ['id' => $from->id]);
    }

    private function js(string $js): Response
    {
        return new Response($js, 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
    }
}
