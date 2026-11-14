<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Api\ApiRenderer;
use App\Api\IssueStatusApiPresenter;
use App\Controller\AbstractController;
use App\Entity\IssueStatus;
use App\Service\Admin\IssueStatuses\IssueStatusFinder;
use App\Service\Admin\IssueStatuses\IssueStatusForm;
use App\Service\Admin\IssueStatuses\IssueStatusManager;
use App\Service\Admin\WorkflowService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's IssueStatusesController. */
final class IssueStatusesController extends AbstractController
{
    public function __construct(
        private readonly ApiRenderer $api,
        private readonly IssueStatusApiPresenter $presenter,
        private readonly WorkflowService $workflows,
        private readonly IssueStatusFinder $finder,
        private readonly IssueStatusForm $form,
        private readonly IssueStatusManager $statuses,
    ) {
    }

    #[Route('/issue_statuses.{_format}', name: 'issue_statuses_index', requirements: ['_format' => 'html|json|xml'], defaults: ['_format' => 'html'], methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($this->isApi($request)) {
            if ($this->settings->loginRequired() && !$this->user()->isLogged()) {
                $this->deny();
            }

            return $this->api->render($request, ['issue_statuses' => $this->presenter->statuses($this->workflows->sortedStatuses())]);
        }
        $this->requireAdmin();

        return $this->render('admin/issue_statuses/index.html.twig', [
            'issue_statuses' => $this->workflows->sortedStatuses(),
            'used_ids' => $this->workflows->statusIdsWithTransitions(),
            'use_status_for_done_ratio' => $this->statuses->useStatusForDoneRatio(),
            'admin_menu_selected' => 'issue_statuses',
            'page_title' => $this->l('label_issue_status_plural'),
            'body_css' => 'controller-issue_statuses action-index',
        ]);
    }

    #[Route('/issue_statuses/new', name: 'issue_status_new', methods: ['GET'])]
    public function new(): Response
    {
        $this->requireAdmin();

        return $this->render('admin/issue_statuses/new.html.twig', $this->form->view(new IssueStatus()) + [
            'admin_menu_selected' => 'issue_statuses',
            'page_title' => $this->l('label_issue_status_new'),
            'body_css' => 'controller-issue_statuses action-new',
        ]);
    }

    #[Route('/issue_statuses', name: 'issue_status_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->requireAdmin();
        $status = new IssueStatus();
        $errors = $this->form->submit($status, $request->request->all('issue_status'));
        if ([] !== $errors) {
            return $this->render('admin/issue_statuses/new.html.twig', $this->form->view($status, $errors) + [
                'admin_menu_selected' => 'issue_statuses',
                'page_title' => $this->l('label_issue_status_new'),
                'body_css' => 'controller-issue_statuses action-new',
            ], new Response('', 422));
        }
        $this->flashNotice('notice_successful_create');

        return $this->redirectToRoute('issue_statuses_index');
    }

    #[Route('/issue_statuses/{id}/edit', name: 'issue_status_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->requireAdmin();
        $status = $this->finder->find($id);

        return $this->render('admin/issue_statuses/edit.html.twig', $this->form->view($status) + [
            'admin_menu_selected' => 'issue_statuses',
            'page_title' => $status->name,
            'body_css' => 'controller-issue_statuses action-edit',
        ]);
    }

    #[Route('/issue_statuses/{id}', name: 'issue_status_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $status = $this->finder->find($id);
        $errors = $this->form->submit($status, $request->request->all('issue_status'));
        if ($request->isXmlHttpRequest()) {
            return new Response('', [] === $errors ? 200 : 422);
        }
        if ([] !== $errors) {
            return $this->render('admin/issue_statuses/edit.html.twig', $this->form->view($status, $errors) + [
                'admin_menu_selected' => 'issue_statuses',
                'page_title' => $status->name,
                'body_css' => 'controller-issue_statuses action-edit',
            ], new Response('', 422));
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('issue_statuses_index', array_filter(['page' => $request->query->get('page')]));
    }

    #[Route('/issue_statuses/{id}', name: 'issue_status_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $this->requireAdmin();
        $reason = $this->statuses->destroy($this->finder->find($id));
        if (null !== $reason) {
            $this->flashError($this->l('error_unable_delete_issue_status', ['value' => $this->l($reason)]));
        }

        return $this->redirectToRoute('issue_statuses_index');
    }

    #[Route('/issue_statuses/update_issue_done_ratio', name: 'issue_statuses_update_issue_done_ratio', methods: ['POST'])]
    public function updateIssueDoneRatio(): Response
    {
        $this->requireAdmin();
        if ($this->statuses->updateIssueDoneRatios()) {
            $this->flashNotice('notice_issue_done_ratios_updated');
        } else {
            $this->flashError($this->l('error_issue_done_ratios_not_updated'));
        }

        return $this->redirectToRoute('issue_statuses_index');
    }
}
