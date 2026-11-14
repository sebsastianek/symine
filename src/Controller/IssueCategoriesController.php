<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\IssueCategoryApiPresenter;
use App\Service\Tracking\Categories\CategorySettingsUrl;
use App\Service\Tracking\Categories\IssueCategoryFinder;
use App\Service\Tracking\Categories\IssueCategoryForm;
use App\Service\Tracking\Categories\IssueCategoryRemoval;
use App\Service\Tracking\Categories\IssueCategoryScript;
use App\Service\Tracking\Http\JavascriptResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Port of Redmine's IssueCategoriesController. */
final class IssueCategoriesController extends AbstractController
{
    public function __construct(
        private readonly IssueCategoryFinder $finder,
        private readonly IssueCategoryForm $form,
        private readonly IssueCategoryRemoval $removal,
        private readonly IssueCategoryScript $script,
        private readonly CategorySettingsUrl $settingsUrl,
        private readonly IssueCategoryApiPresenter $presenter,
        private readonly ApiRenderer $api,
    ) {
    }

    #[Route('/projects/{project_id}/issue_categories', name: 'project_issue_categories', methods: ['GET'])]
    #[Route('/projects/{project_id}/issue_categories.{_format}', name: 'project_issue_categories_api', requirements: ['_format' => 'json|xml'], methods: ['GET'])]
    public function index(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        if (!$this->auth->allowedTo('view_issues', $project) && !$this->auth->allowedTo('manage_categories', $project)) {
            $this->deny();
        }
        if (!$this->isApi($request)) {
            return $this->redirect($this->settingsUrl->of($project));
        }

        return $this->api->render($request, $this->presenter->index($project));
    }

    #[Route('/issue_categories/{id}', name: 'issue_category_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[Route('/issue_categories/{id}.{_format}', name: 'issue_category_show_api', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $category = $this->finder->find($id);
        if (!$this->auth->allowedTo('view_issues', $category->project) && !$this->auth->allowedTo('manage_categories', $category->project)) {
            $this->deny();
        }
        if (!$this->isApi($request)) {
            return $this->redirect($this->settingsUrl->of($category->project));
        }

        return $this->api->render($request, ['issue_category' => $this->presenter->category($category)]);
    }

    #[Route('/projects/{project_id}/issue_categories/new', name: 'issue_category_new', methods: ['GET'])]
    public function new(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_categories', $project);
        $category = $this->form->blank($project);
        $this->form->prefill($category, $request);
        if ($this->script->isRequested($request)) {
            return new JavascriptResponse($this->script->modal($this->renderView('issue_categories/_new_modal.html.twig', $this->form->view($category))));
        }

        return $this->render('issue_categories/new.html.twig', $this->form->view($category) + [
            'main_menu_selected' => 'settings',
            'page_title' => $this->l('label_issue_category_new'),
            'body_css' => 'controller-issue_categories action-new',
        ]);
    }

    #[Route('/projects/{project_id}/issue_categories', name: 'issue_category_create', methods: ['POST'])]
    #[Route('/projects/{project_id}/issue_categories.{_format}', name: 'issue_category_create_api', requirements: ['_format' => 'json|xml'], methods: ['POST'])]
    public function create(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_categories', $project);
        $isApi = $this->isApi($request);
        $category = $this->form->blank($project);
        $errors = $this->form->submit($category, $request, $isApi);
        if ($isApi) {
            if ([] !== $errors) {
                return $this->api->errors($request, $errors);
            }
            $response = $this->api->render($request, ['issue_category' => $this->presenter->category($category)], 201);
            $response->headers->set('Location', $this->generateUrl('issue_category_show', ['id' => $category->id], UrlGeneratorInterface::ABSOLUTE_URL));

            return $response;
        }
        $js = $this->script->isRequested($request);
        if ([] !== $errors && $js) {
            return new JavascriptResponse($this->script->modal($this->renderView('issue_categories/_new_modal.html.twig', $this->form->view($category, $errors))));
        }
        if ([] !== $errors) {
            return $this->render('issue_categories/new.html.twig', $this->form->view($category, $errors) + [
                'main_menu_selected' => 'settings',
                'page_title' => $this->l('label_issue_category_new'),
                'body_css' => 'controller-issue_categories action-new',
            ], new Response(null, 422));
        }
        if ($js) {
            return new JavascriptResponse($this->script->created($category));
        }
        $this->flashNotice('notice_successful_create');

        return $this->redirect($this->settingsUrl->of($project));
    }

    #[Route('/issue_categories/{id}/edit', name: 'issue_category_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $category = $this->finder->find($id);
        $this->authorize('manage_categories', $category->project);

        return $this->render('issue_categories/edit.html.twig', $this->form->view($category) + [
            'main_menu_selected' => 'settings',
            'page_title' => $this->l('label_issue_category'),
            'body_css' => 'controller-issue_categories action-edit',
        ]);
    }

    #[Route('/issue_categories/{id}', name: 'issue_category_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    #[Route('/issue_categories/{id}.{_format}', name: 'issue_category_update_api', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id): Response
    {
        $category = $this->finder->find($id);
        $this->authorize('manage_categories', $category->project);
        $isApi = $this->isApi($request);
        $errors = $this->form->submit($category, $request, $isApi);
        if ($isApi) {
            return [] === $errors ? $this->api->noContent() : $this->api->errors($request, $errors);
        }
        if ([] !== $errors) {
            return $this->render('issue_categories/edit.html.twig', $this->form->view($category, $errors) + [
                'main_menu_selected' => 'settings',
                'page_title' => $this->l('label_issue_category'),
                'body_css' => 'controller-issue_categories action-edit',
            ], new Response(null, 422));
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirect($this->settingsUrl->of($category->project));
    }

    #[Route('/issue_categories/{id}', name: 'issue_category_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[Route('/issue_categories/{id}.{_format}', name: 'issue_category_destroy_api', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['DELETE'])]
    public function destroy(Request $request, int $id): Response
    {
        $category = $this->finder->find($id);
        $project = $category->project;
        $this->authorize('manage_categories', $project);
        $isApi = $this->isApi($request);
        $confirmation = $this->removal->attempt($category, $request, $isApi);
        if (null === $confirmation) {
            return $isApi ? $this->api->noContent() : $this->redirect($this->settingsUrl->of($project));
        }

        return $this->render('issue_categories/destroy.html.twig', $confirmation + [
            'main_menu_selected' => 'settings',
            'page_title' => $this->l('label_issue_category'),
            'body_css' => 'controller-issue_categories action-destroy',
        ]);
    }
}
