<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\MembershipApiPresenter;
use App\Service\CsvExport;
use App\Service\MembersManager;
use App\Service\Project\MemberAccess;
use App\Service\Project\MemberFinder;
use App\Service\Project\MembersJs;
use App\Service\Project\MembersRequest;
use App\Service\Project\MembersView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's MembersController (project memberships, HTML + REST API). */
final class MembersController extends AbstractController
{
    public function __construct(
        private readonly MembersManager $members,
        private readonly MemberFinder $finder,
        private readonly MemberAccess $access,
        private readonly MembersView $view,
        private readonly MembershipApiPresenter $presenter,
        private readonly ApiRenderer $api,
        private readonly CsvExport $csv,
    ) {
    }

    /**
     * MembersController#index (HTML): the members of the project, for every user allowed to view them.
     * Users who manage the members get the same list (with its actions) in the project settings.
     */
    #[Route('/projects/{project_id}/members', name: 'project_members', methods: ['GET'])]
    public function members(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->access->authorizeRead($project);

        return $this->render('members/index.html.twig', $this->view->list($project, $request, true) + [
            'main_menu_selected' => 'overview',
            'page_title' => $this->l('label_member_plural'),
            'body_css' => 'controller-members action-index',
        ]);
    }

    /** MembersController#index (CSV): every membership of the project, for the users who manage the members */
    #[Route('/projects/{project_id}/memberships.csv', name: 'project_memberships_csv', methods: ['GET'], priority: 5)]
    public function exportCsv(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->access->authorizeRead($project);
        if (!$this->access->canManage($project)) {
            return new Response('', 403);
        }

        return $this->csv->response($request, $this->members->csvRows($project, $this->l(...)), $project->identifier.'-members.csv');
    }

    #[Route('/projects/{project_id}/memberships', name: 'project_memberships', methods: ['GET'])]
    #[Route('/projects/{project_id}/memberships.{_format}', name: 'project_memberships_format', requirements: ['_format' => 'json|xml'], methods: ['GET'])]
    public function index(Request $request, string $project_id, string $_format = 'html'): Response
    {
        $project = $this->findProject($project_id);
        $this->access->authorizeRead($project);
        if ('html' === $_format) {
            return $this->render('members/index.html.twig', $this->view->list($project, $request, true) + [
                'main_menu_selected' => 'overview',
                'page_title' => $this->l('label_member_plural'),
                'body_css' => 'controller-members action-index',
            ]);
        }
        $all = $this->members->memberships($project);

        return $this->api->render($request, $this->presenter->memberships($all, $this->paginate($request, \count($all))));
    }

    #[Route('/memberships/{id}.{_format}', name: 'membership_show', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['GET'])]
    public function show(Request $request, string $id): Response
    {
        $member = $this->finder->find($id);
        $this->access->authorizeRead($member->project);

        return $this->api->render($request, ['membership' => $this->presenter->membership($member)]);
    }

    #[Route('/projects/{project_id}/memberships/new', name: 'member_new', methods: ['GET'])]
    public function new(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->access->authorizeManage($project);
        $vars = $this->view->newForm($project, $request);
        if (MembersRequest::wantsJs($request)) {
            return new Response(MembersJs::newModal($this->renderView('members/_new_modal.html.twig', $vars)), 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }

        return $this->render('members/new.html.twig', $vars + [
            'main_menu_selected' => 'settings', 'page_title' => $this->l('label_member_new'), 'body_css' => 'controller-members action-new',
        ]);
    }

    #[Route('/projects/{project_id}/memberships/autocomplete', name: 'member_autocomplete', methods: ['GET'])]
    #[Route('/projects/{project_id}/memberships/autocomplete.js', name: 'member_autocomplete_js', methods: ['GET'])]
    public function autocomplete(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->access->authorizeManage($project);
        $html = $this->renderView('members/_principals.html.twig', $this->view->principals($project, $request));

        return new Response(MembersJs::principals($html), 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
    }

    #[Route('/projects/{project_id}/memberships', name: 'member_create', methods: ['POST'])]
    #[Route('/projects/{project_id}/memberships.{_format}', name: 'member_create_format', requirements: ['_format' => 'json|xml|js'], methods: ['POST'])]
    public function create(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->access->authorizeManage($project);
        $api = $this->isApi($request);
        $result = $this->members->createFromParams($project, MembersRequest::membership($request), $api, $this->l(...));
        if ($api) {
            if ([] !== ($errors = $this->presenter->creationErrors($result))) {
                return $this->api->errors($request, $errors);
            }
            $member = $result['members'][0];
            $response = $this->api->render($request, ['membership' => $this->presenter->membership($member)], 201);
            $response->headers->set('Location', $this->generateUrl('membership_show', ['id' => $member->id, '_format' => ApiRenderer::format($request)], 0));

            return $response;
        }
        $failure = [] === $result['errors'] ? '' : $this->l('notice_failed_to_save_members', ['errors' => implode(', ', $result['errors'])]);
        if (MembersRequest::wantsJs($request)) {
            $html = $this->renderView('members/_members.html.twig', $this->view->list($project, $request));

            return new Response(MembersJs::tab($html, MembersJs::created($result['members'], $result['errors'], $failure)), 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }
        if ([] !== $result['errors']) {
            $this->flashError($failure);
        }

        // MembersController#redirect_to_settings_in_projects: the list keeps its page and filters
        return $this->redirectToRoute('project_settings', ['id' => $project->identifier, 'tab' => 'members'] + MembersRequest::listParams($request));
    }

    #[Route('/memberships/{id}/edit', name: 'member_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(Request $request, string $id): Response
    {
        $member = $this->finder->find($id);
        $this->access->authorizeManage($member->project);
        $vars = $this->view->editForm($member, $request);
        if (MembersRequest::wantsJs($request)) {
            return new Response(MembersJs::editForm($member, $this->renderView('members/_edit.html.twig', $vars)), 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }

        return $this->render('members/edit.html.twig', $vars + [
            'project' => $member->project, 'main_menu_selected' => 'settings',
            'page_title' => $member->principal->getName().' - '.$member->project->name, 'body_css' => 'controller-members action-edit',
        ]);
    }

    #[Route('/memberships/{id}', name: 'member_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH', 'POST'])]
    #[Route('/memberships/{id}.{_format}', name: 'member_update_format', requirements: ['id' => '\d+', '_format' => 'json|xml|js'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, string $id): Response
    {
        $member = $this->finder->find($id);
        $project = $member->project;
        $this->access->authorizeManage($project);
        $errors = $this->members->updateRoles($member, MembersRequest::membership($request), $this->l(...));
        if ($this->isApi($request)) {
            return [] === $errors ? $this->api->noContent() : $this->api->errors($request, $errors);
        }
        if (MembersRequest::wantsJs($request)) {
            $html = $this->renderView('members/_members.html.twig', $this->view->list($project, $request));

            return new Response(MembersJs::tab($html, MembersJs::highlight((int) $id)), 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }

        return $this->redirectToRoute('project_settings', ['id' => $project->identifier, 'tab' => 'members'] + MembersRequest::listParams($request));
    }

    #[Route('/memberships/{id}', name: 'member_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[Route('/memberships/{id}.{_format}', name: 'member_destroy_format', requirements: ['id' => '\d+', '_format' => 'json|xml|js'], methods: ['DELETE'])]
    public function destroy(Request $request, string $id): Response
    {
        $member = $this->finder->find($id);
        $project = $member->project;
        $this->access->authorizeManage($project);
        $destroyed = $this->members->destroyIfDeletable($member);
        if ($this->isApi($request)) {
            return $destroyed ? $this->api->noContent() : new Response('', 422);
        }
        if (MembersRequest::wantsJs($request)) {
            $html = $this->renderView('members/_members.html.twig', $this->view->list($project, $request));

            return new Response(MembersJs::tab($html), 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }

        return $this->redirectToRoute('project_settings', ['id' => $project->identifier, 'tab' => 'members'] + MembersRequest::listParams($request));
    }
}
