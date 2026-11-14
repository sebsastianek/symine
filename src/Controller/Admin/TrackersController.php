<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Api\ApiRenderer;
use App\Api\TrackerApiPresenter;
use App\Controller\AbstractController;
use App\Entity\Tracker;
use App\Service\Admin\Trackers\TrackerFieldsForm;
use App\Service\Admin\Trackers\TrackerFinder;
use App\Service\Admin\Trackers\TrackerForm;
use App\Service\Admin\Trackers\TrackerIndex;
use App\Service\Admin\Trackers\TrackerManager;
use App\Service\Admin\WorkflowService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's TrackersController. */
final class TrackersController extends AbstractController
{
    public function __construct(
        private readonly ApiRenderer $api,
        private readonly TrackerApiPresenter $presenter,
        private readonly WorkflowService $workflows,
        private readonly TrackerFinder $finder,
        private readonly TrackerIndex $list,
        private readonly TrackerForm $form,
        private readonly TrackerFieldsForm $fieldsForm,
        private readonly TrackerManager $trackers,
    ) {
    }

    #[Route('/trackers.{_format}', name: 'trackers_index', requirements: ['_format' => 'html|json|xml'], defaults: ['_format' => 'html'], methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($this->isApi($request)) {
            if ($this->settings->loginRequired() && !$this->user()->isLogged()) {
                $this->deny();
            }

            return $this->api->render($request, ['trackers' => $this->presenter->trackers($this->workflows->sortedTrackers())]);
        }
        $this->requireAdmin();

        return $this->render('admin/trackers/index.html.twig', $this->list->view() + [
            'admin_menu_selected' => 'trackers',
            'page_title' => $this->l('label_tracker_plural'),
            'body_css' => 'controller-trackers action-index',
        ]);
    }

    #[Route('/trackers/new', name: 'tracker_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->requireAdmin();
        $view = $this->form->newView($request->query->all('tracker'), (int) $request->query->get('copy'), (string) $request->query->get('copy_workflow_from', ''));

        return $this->render('admin/trackers/new.html.twig', $view + [
            'admin_menu_selected' => 'trackers',
            'page_title' => $this->l('label_tracker_new'),
            'body_css' => 'controller-trackers action-new',
        ]);
    }

    #[Route('/trackers', name: 'tracker_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->requireAdmin();
        $tracker = new Tracker();
        $submission = $this->form->submit($tracker, $request->request->all('tracker'), (int) $request->request->get('copy_workflow_from', 0));
        if (!$submission->isValid()) {
            return $this->render('admin/trackers/new.html.twig', $this->form->view($tracker, $submission, (string) $request->request->get('copy_workflow_from', '')) + [
                'admin_menu_selected' => 'trackers',
                'page_title' => $this->l('label_tracker_new'),
                'body_css' => 'controller-trackers action-new',
            ], new Response('', 422));
        }
        $this->flashNotice('notice_successful_create');

        return $this->redirectToRoute('trackers_index');
    }

    #[Route('/trackers/{id}/edit', name: 'tracker_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->requireAdmin();
        $tracker = $this->finder->find($id);

        return $this->render('admin/trackers/edit.html.twig', $this->form->view($tracker) + [
            'admin_menu_selected' => 'trackers',
            'page_title' => $tracker->name,
            'body_css' => 'controller-trackers action-edit',
        ]);
    }

    #[Route('/trackers/{id}', name: 'tracker_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $tracker = $this->finder->find($id);
        $submission = $this->form->submit($tracker, $request->request->all('tracker'));
        if ($request->isXmlHttpRequest()) {
            return new Response('', $submission->isValid() ? 200 : 422);
        }
        if (!$submission->isValid()) {
            return $this->render('admin/trackers/edit.html.twig', $this->form->view($tracker, $submission) + [
                'admin_menu_selected' => 'trackers',
                'page_title' => $tracker->name,
                'body_css' => 'controller-trackers action-edit',
            ], new Response('', 422));
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('trackers_index', array_filter(['page' => $request->query->get('page')]));
    }

    #[Route('/trackers/{id}', name: 'tracker_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $this->requireAdmin();
        $tracker = $this->finder->find($id);
        if (!$this->trackers->destroy($tracker)) {
            return $this->render('admin/trackers/index.html.twig', $this->list->view($tracker) + [
                'admin_menu_selected' => 'trackers',
                'page_title' => $this->l('label_tracker_plural'),
                'body_css' => 'controller-trackers action-index',
            ]);
        }

        return $this->redirectToRoute('trackers_index');
    }

    #[Route('/trackers/fields', name: 'trackers_fields', methods: ['GET', 'POST'])]
    public function fields(Request $request): Response
    {
        $this->requireAdmin();
        if ($request->isMethod('POST') && $request->request->has('trackers')) {
            $this->fieldsForm->submit($request->request->all('trackers'));
            $this->flashNotice('notice_successful_update');

            return $this->redirectToRoute('trackers_fields');
        }

        return $this->render('admin/trackers/fields.html.twig', $this->fieldsForm->view() + [
            'admin_menu_selected' => 'trackers',
            'page_title' => $this->l('field_summary'),
            'body_css' => 'controller-trackers action-fields',
        ]);
    }
}
