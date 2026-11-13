<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Entity\IssueCategory;
use App\Entity\Project;
use App\Service\VersionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Port of Redmine's IssueCategoriesController. */
final class IssueCategoriesController extends AbstractController
{
    public function __construct(
        private readonly VersionService $service,
        private readonly ApiRenderer $api,
    ) {
    }

    #[Route('/projects/{project_id}/issue_categories', name: 'project_issue_categories', methods: ['GET'])]
    #[Route('/projects/{project_id}/issue_categories.{_format}', name: 'project_issue_categories_api', requirements: ['_format' => 'json|xml'], methods: ['GET'])]
    public function index(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorizeAny(['view_issues', 'manage_categories'], $project);
        if (!$this->isApi($request)) {
            return $this->redirect($this->settingsUrl($project));
        }
        $categories = $project->issueCategories->toArray();
        usort($categories, static fn (IssueCategory $a, IssueCategory $b) => strcmp($a->name, $b->name));

        return $this->api->render($request, [
            'issue_categories' => array_map($this->apiCategory(...), $categories),
            'total_count' => \count($categories),
        ]);
    }

    #[Route('/issue_categories/{id}', name: 'issue_category_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[Route('/issue_categories/{id}.{_format}', name: 'issue_category_show_api', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        [$category, $project] = $this->findCategory($id);
        $this->authorizeAny(['view_issues', 'manage_categories'], $project);
        if (!$this->isApi($request)) {
            return $this->redirect($this->settingsUrl($project));
        }

        return $this->api->render($request, ['issue_category' => $this->apiCategory($category)]);
    }

    #[Route('/projects/{project_id}/issue_categories/new', name: 'issue_category_new', methods: ['GET'])]
    public function new(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_categories', $project);
        $category = new IssueCategory();
        $category->project = $project;
        $this->service->assignCategory($category, $request->query->all('issue_category'));
        if ($this->wantsJs($request)) {
            return $this->modal($project, $category, []);
        }

        return $this->renderForm('issue_categories/new.html.twig', $project, $category, []);
    }

    #[Route('/projects/{project_id}/issue_categories', name: 'issue_category_create', methods: ['POST'])]
    #[Route('/projects/{project_id}/issue_categories.{_format}', name: 'issue_category_create_api', requirements: ['_format' => 'json|xml'], methods: ['POST'])]
    public function create(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_categories', $project);
        $isApi = $this->isApi($request);
        $category = new IssueCategory();
        $category->project = $project;
        $attrs = $isApi ? (array) (ApiRenderer::payload($request)['issue_category'] ?? []) : $request->request->all('issue_category');
        $this->service->assignCategory($category, $attrs);
        $errors = $this->service->saveCategory($category);
        if ($isApi) {
            if ([] !== $errors) {
                return $this->api->errors($request, $errors);
            }
            $response = $this->api->render($request, ['issue_category' => $this->apiCategory($category)], 201);
            $response->headers->set('Location', $this->generateUrl('issue_category_show', ['id' => $category->id], UrlGeneratorInterface::ABSOLUTE_URL));

            return $response;
        }
        $js = $this->wantsJs($request);
        if ([] !== $errors) {
            return $js ? $this->modal($project, $category, $errors) : $this->renderForm('issue_categories/new.html.twig', $project, $category, $errors, 422);
        }
        if ($js) {
            $options = '<option></option>';
            $categories = $project->issueCategories->toArray();
            usort($categories, static fn (IssueCategory $a, IssueCategory $b) => strcmp($a->name, $b->name));
            foreach ($categories as $c) {
                $options .= '<option value="'.$c->id.'"'.($c->id === $category->id ? ' selected="selected"' : '').'>'.htmlspecialchars($c->name).'</option>';
            }
            $select = '<select id="issue_category_id" name="issue[category_id]">'.$options.'</select>';

            return new Response("hideModal();\n$('#issue_category_id').replaceWith(".$this->jsString($select).');', 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }
        $this->flashNotice('notice_successful_create');

        return $this->redirect($this->settingsUrl($project));
    }

    #[Route('/issue_categories/{id}/edit', name: 'issue_category_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        [$category, $project] = $this->findCategory($id);
        $this->authorize('manage_categories', $project);

        return $this->renderForm('issue_categories/edit.html.twig', $project, $category, []);
    }

    #[Route('/issue_categories/{id}', name: 'issue_category_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    #[Route('/issue_categories/{id}.{_format}', name: 'issue_category_update_api', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id): Response
    {
        [$category, $project] = $this->findCategory($id);
        $this->authorize('manage_categories', $project);
        $isApi = $this->isApi($request);
        $attrs = $isApi ? (array) (ApiRenderer::payload($request)['issue_category'] ?? []) : $request->request->all('issue_category');
        $this->service->assignCategory($category, $attrs);
        $errors = $this->service->saveCategory($category);
        if ($isApi) {
            return [] === $errors ? $this->api->noContent() : $this->api->errors($request, $errors);
        }
        if ([] !== $errors) {
            return $this->renderForm('issue_categories/edit.html.twig', $project, $category, $errors, 422);
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirect($this->settingsUrl($project));
    }

    #[Route('/issue_categories/{id}', name: 'issue_category_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[Route('/issue_categories/{id}.{_format}', name: 'issue_category_destroy_api', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['DELETE'])]
    public function destroy(Request $request, int $id): Response
    {
        [$category, $project] = $this->findCategory($id);
        $this->authorize('manage_categories', $project);
        $isApi = $this->isApi($request);
        $issueCount = $this->service->categoryIssuesCount($category);
        $todo = (string) ($request->request->get('todo') ?? $request->query->get('todo', ''));
        if (0 === $issueCount || '' !== $todo || $isApi) {
            $reassignTo = null;
            $reassignId = $request->request->get('reassign_to_id') ?? $request->query->get('reassign_to_id');
            if (null !== $reassignId && ('reassign' === $todo || '' === $todo)) {
                $reassignTo = $this->em->getRepository(IssueCategory::class)->findOneBy(['id' => (int) $reassignId, 'project' => $project]);
            }
            $this->service->destroyCategory($category, $reassignTo);

            return $isApi ? $this->api->noContent() : $this->redirect($this->settingsUrl($project));
        }
        $categories = array_values(array_filter($project->issueCategories->toArray(), static fn (IssueCategory $c) => $c->id !== $category->id));

        return $this->render('issue_categories/destroy.html.twig', [
            'project' => $project,
            'category' => $category,
            'issue_count' => $issueCount,
            'categories' => $categories,
            'main_menu_selected' => 'settings',
            'page_title' => $this->l('label_issue_category'),
            'body_css' => 'controller-issue_categories action-destroy',
        ]);
    }

    // ------------------------------------------------------------------------------------------

    /** @return array{0: IssueCategory, 1: Project} */
    private function findCategory(int $id): array
    {
        $category = $this->em->find(IssueCategory::class, $id);
        if (null === $category) {
            throw $this->createNotFoundException();
        }

        return [$category, $this->findProject((int) $category->project->id)];
    }

    /** @param list<string> $permissions */
    private function authorizeAny(array $permissions, Project $project): void
    {
        foreach ($permissions as $p) {
            if ($this->auth->allowedTo($p, $project)) {
                return;
            }
        }
        $this->deny();
    }

    /** @param list<string> $errors */
    private function renderForm(string $template, Project $project, IssueCategory $category, array $errors, int $status = 200): Response
    {
        return $this->render($template, [
            'project' => $project,
            'category' => $category,
            'errors' => $errors,
            'assignable_users' => $this->projects->assignableUsers($project),
            'main_menu_selected' => 'settings',
            'page_title' => $this->l(null === $category->id ? 'label_issue_category_new' : 'label_issue_category'),
            'body_css' => 'controller-issue_categories action-'.(null === $category->id ? 'new' : 'edit'),
        ], new Response(null, $status));
    }

    private function modal(Project $project, IssueCategory $category, array $errors): Response
    {
        $html = $this->renderView('issue_categories/_new_modal.html.twig', [
            'project' => $project, 'category' => $category, 'errors' => $errors,
            'assignable_users' => $this->projects->assignableUsers($project),
        ]);

        return new Response("$('#ajax-modal').html(".$this->jsString($html).");\nshowModal('ajax-modal', '600px');", 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
    }

    private function settingsUrl(Project $project): string
    {
        if (null !== $this->container->get('router')->getRouteCollection()->get('project_settings')) {
            return $this->generateUrl('project_settings', ['id' => $project->identifier, 'tab' => 'categories']);
        }

        return '/projects/'.$project->identifier.'/settings/categories';
    }

    private function wantsJs(Request $request): bool
    {
        return str_contains((string) $request->headers->get('Accept'), 'javascript') || 'js' === $request->query->get('format');
    }

    private function jsString(string $s): string
    {
        return (string) json_encode($s, \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP | \JSON_UNESCAPED_UNICODE);
    }

    /** issue_categories/show.api.rsb */
    private function apiCategory(IssueCategory $category): array
    {
        $data = [
            'id' => $category->id,
            'project' => ['id' => $category->project->id, 'name' => $category->project->name],
            'name' => $category->name,
        ];
        if (null !== $category->assignedTo) {
            $data['assigned_to'] = ['id' => $category->assignedTo->id, 'name' => $category->assignedTo->getName()];
        }

        return $data;
    }
}
