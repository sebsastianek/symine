<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Service\Admin\Workflows\FieldPermissionsForm;
use App\Service\Admin\Workflows\RefererUrl;
use App\Service\Admin\Workflows\TransitionsForm;
use App\Service\Admin\Workflows\WorkflowCopyForm;
use App\Service\Admin\Workflows\WorkflowSelector;
use App\Service\Admin\WorkflowService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's WorkflowsController (status transitions & fields permissions). */
final class WorkflowsController extends AbstractController
{
    public function __construct(
        private readonly WorkflowService $workflows,
        private readonly WorkflowSelector $selector,
        private readonly TransitionsForm $transitions,
        private readonly FieldPermissionsForm $fieldPermissions,
        private readonly WorkflowCopyForm $copyForm,
        private readonly RefererUrl $referer,
    ) {
    }

    #[Route('/workflows', name: 'workflows_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->requireAdmin();

        return $this->render('admin/workflows/index.html.twig', [
            'roles' => $this->workflows->workflowRoles(),
            'trackers' => $this->workflows->sortedTrackers(),
            'counts' => $this->workflows->transitionCounts(),
            'admin_menu_selected' => 'workflows',
            'page_title' => $this->l('field_summary'),
            'body_css' => 'controller-workflows action-index',
        ]);
    }

    #[Route('/workflows/edit', name: 'workflows_edit', methods: ['GET'])]
    public function edit(Request $request): Response
    {
        $this->requireAdmin();
        $selection = $this->selector->select($request->query->all(), true);

        return $this->render('admin/workflows/edit.html.twig', $this->selector->view($selection, $request->query->get('used_statuses_only')) + [
            'tables' => $this->transitions->tables($selection),
            'admin_menu_selected' => 'workflows',
            'page_title' => $this->l('label_workflow'),
            'body_css' => 'controller-workflows action-edit',
        ]);
    }

    #[Route('/workflows/update', name: 'workflows_update', methods: ['PATCH', 'POST'])]
    public function update(Request $request): Response
    {
        $this->requireAdmin();
        $selection = $this->selector->select($request->request->all());
        if ($selection->isComplete() && $request->request->has('transitions')) {
            $this->transitions->submit($selection, $request->request->all('transitions'));
            $this->flashNotice('notice_successful_update');
        }

        return $this->redirect($this->referer->orDefault($request, $this->generateUrl('workflows_edit')));
    }

    #[Route('/workflows/permissions', name: 'workflows_permissions', methods: ['GET'])]
    public function permissions(Request $request): Response
    {
        $this->requireAdmin();
        $selection = $this->selector->select($request->query->all(), true);

        return $this->render('admin/workflows/permissions.html.twig', $this->selector->view($selection, $request->query->get('used_statuses_only')) + [
            'rows' => $this->fieldPermissions->rows($selection),
            'admin_menu_selected' => 'workflows',
            'page_title' => $this->l('label_workflow'),
            'body_css' => 'controller-workflows action-permissions',
        ]);
    }

    #[Route('/workflows/update_permissions', name: 'workflows_update_permissions', methods: ['PATCH', 'POST'])]
    public function updatePermissions(Request $request): Response
    {
        $this->requireAdmin();
        $selection = $this->selector->select($request->request->all());
        if ($selection->isComplete() && $request->request->has('permissions')) {
            $this->fieldPermissions->submit($selection, $request->request->all('permissions'));
            $this->flashNotice('notice_successful_update');
        }

        return $this->redirect($this->referer->orDefault($request, $this->generateUrl('workflows_permissions')));
    }

    #[Route('/workflows/copy', name: 'workflows_copy', methods: ['GET'])]
    public function copy(Request $request): Response
    {
        $this->requireAdmin();

        return $this->render('admin/workflows/copy.html.twig', $this->copyForm->view($request->query->all()) + [
            'admin_menu_selected' => 'workflows',
            'page_title' => $this->l('button_copy'),
            'body_css' => 'controller-workflows action-copy',
        ]);
    }

    #[Route('/workflows/duplicate', name: 'workflows_duplicate', methods: ['POST'])]
    public function duplicate(Request $request): Response
    {
        $this->requireAdmin();
        $form = $this->copyForm->submit($request->request->all());
        if (null !== $form['error']) {
            return $this->render('admin/workflows/copy.html.twig', $form + [
                'admin_menu_selected' => 'workflows',
                'page_title' => $this->l('button_copy'),
                'body_css' => 'controller-workflows action-duplicate',
            ]);
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('workflows_copy', array_filter([
            'source_tracker_id' => $form['source_tracker']?->id,
            'source_role_id' => $form['source_role']?->id,
        ]));
    }
}
