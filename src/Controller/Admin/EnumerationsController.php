<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Api\ApiRenderer;
use App\Api\EnumerationApiPresenter;
use App\Controller\AbstractController;
use App\Service\Admin\EnumerationManager;
use App\Service\Admin\Enumerations\EnumerationFinder;
use App\Service\Admin\Enumerations\EnumerationForm;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's EnumerationsController (issue priorities, time entry activities, document categories) */
final class EnumerationsController extends AbstractController
{
    public function __construct(
        private readonly EnumerationManager $manager,
        private readonly EnumerationFinder $finder,
        private readonly EnumerationForm $form,
        private readonly EnumerationApiPresenter $presenter,
        private readonly ApiRenderer $api,
    ) {
    }

    #[Route('/enumerations', name: 'enumerations_index', methods: ['GET'])]
    #[Route('/enumerations/{type}', name: 'enumerations_index_type', requirements: ['type' => 'issue_priorities|time_entry_activities|document_categories'], methods: ['GET'], priority: -10)]
    public function index(): Response
    {
        $this->requireAdmin();

        return $this->render('admin/enumerations/index.html.twig', [
            'sections' => $this->manager->sections(),
            'admin_menu_selected' => 'enumerations',
            'page_title' => $this->l('label_enumerations'),
            'body_css' => 'controller-enumerations action-index',
        ]);
    }

    /** GET /enumerations/:type.json (public API, like require_admin_or_api_request) */
    #[Route('/enumerations/{type}.{_format}', name: 'enumerations_index_api', requirements: ['type' => '[a-z_]+', '_format' => 'json|xml'], methods: ['GET'])]
    public function apiIndex(Request $request, string $type): Response
    {
        return $this->api->render($request, [$type => $this->presenter->enumerations($this->finder->typeOfCollection($type))]);
    }

    #[Route('/enumerations/new', name: 'enumeration_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->requireAdmin();
        $e = $this->finder->newOfType((string) ($request->query->all('enumeration')['type'] ?? $request->query->get('type', '')));

        return $this->render('admin/enumerations/new.html.twig', $this->form->view($e) + [
            'admin_menu_selected' => 'enumerations',
            'body_css' => 'controller-enumerations action-new',
        ]);
    }

    #[Route('/enumerations', name: 'enumeration_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->requireAdmin();
        $params = $request->request->all('enumeration');
        $e = $this->finder->newOfType((string) ($params['type'] ?? $request->query->get('type', '')));
        $errors = $this->form->submit($e, $params);
        if ([] !== $errors) {
            return $this->render('admin/enumerations/new.html.twig', $this->form->view($e, $errors, $params) + [
                'admin_menu_selected' => 'enumerations',
                'body_css' => 'controller-enumerations action-new',
            ], new Response('', 422));
        }
        $this->flashNotice('notice_successful_create');

        return $this->redirectToRoute('enumerations_index');
    }

    #[Route('/enumerations/{id}/edit', name: 'enumeration_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->requireAdmin();

        return $this->render('admin/enumerations/edit.html.twig', $this->form->view($this->finder->find($id)) + [
            'admin_menu_selected' => 'enumerations',
            'body_css' => 'controller-enumerations action-edit',
        ]);
    }

    #[Route('/enumerations/{id}', name: 'enumeration_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH', 'POST'])]
    public function update(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $e = $this->finder->find($id);
        $params = $request->request->all('enumeration');
        $errors = $this->form->submit($e, $params);
        if ($request->isXmlHttpRequest()) {
            return new Response('', [] === $errors ? 200 : 422);
        }
        if ([] !== $errors) {
            return $this->render('admin/enumerations/edit.html.twig', $this->form->view($e, $errors, $params) + [
                'admin_menu_selected' => 'enumerations',
                'body_css' => 'controller-enumerations action-edit',
            ], new Response('', 422));
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('enumerations_index');
    }

    #[Route('/enumerations/{id}', name: 'enumeration_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $e = $this->finder->find($id);
        if ($this->manager->destroyOrReassign($e, (string) $request->request->get('reassign_to_id', $request->query->get('reassign_to_id', '')))) {
            return $this->redirectToRoute('enumerations_index');
        }

        return $this->render('admin/enumerations/destroy.html.twig', [
            'enumeration' => $e,
            'option_name' => EnumerationManager::OPTION_NAMES[$e::typeName()],
            'objects_count' => $this->manager->objectsCount($e),
            'enumerations' => $this->manager->others($e),
            'admin_menu_selected' => 'enumerations',
            'body_css' => 'controller-enumerations action-destroy',
        ]);
    }
}
