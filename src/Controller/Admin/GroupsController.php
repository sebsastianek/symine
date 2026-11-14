<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Api\ApiRenderer;
use App\Controller\AbstractController;
use App\Service\Admin\BackUrl;
use App\Service\Admin\Groups\GroupFinder;
use App\Service\Admin\Groups\GroupForm;
use App\Service\Admin\Groups\GroupFormView;
use App\Service\Admin\Groups\GroupList;
use App\Service\Admin\Groups\GroupProfile;
use App\Service\Admin\Groups\GroupRemoval;
use App\Service\Admin\Groups\GroupUsers;
use App\Service\Admin\PrincipalApi;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's GroupsController */
final class GroupsController extends AbstractController
{
    public function __construct(
        private readonly GroupFinder $finder,
        private readonly GroupList $list,
        private readonly GroupProfile $profile,
        private readonly GroupForm $form,
        private readonly GroupFormView $formView,
        private readonly GroupRemoval $removal,
        private readonly GroupUsers $groupUsers,
        private readonly PrincipalApi $api,
        private readonly ApiRenderer $renderer,
        private readonly BackUrl $backUrl,
    ) {
    }

    #[Route('/groups.{_format}', name: 'groups_index', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'])]
    public function index(Request $request): Response
    {
        $this->requireAdmin();
        if ($this->isApi($request)) {
            return $this->renderer->render($request, $this->api->groupList($this->list->forApi($request)));
        }

        return $this->render('admin/groups/index.html.twig', $this->list->view($request, $this->paginate(...)));
    }

    #[Route('/groups/new', name: 'group_new', methods: ['GET'])]
    public function new(): Response
    {
        $this->requireAdmin();

        return $this->render('admin/groups/new.html.twig', $this->formView->forNew());
    }

    #[Route('/groups.{_format}', name: 'group_create', methods: ['POST'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'])]
    public function create(Request $request): Response
    {
        $this->requireAdmin();
        $submission = $this->form->create($request);
        $group = $submission->group;
        if ($this->isApi($request)) {
            if (!$submission->isSaved()) {
                return $this->renderer->errors($request, $submission->errors);
            }
            $response = $this->renderer->render($request, ['group' => $this->api->group($group)], 201);
            $response->headers->set('Location', $this->generateUrl('group_show', ['id' => $group->id], 0));

            return $response;
        }
        if (!$submission->isSaved()) {
            return $this->render('admin/groups/new.html.twig', $this->formView->forNew($submission));
        }
        $this->flashNotice('notice_successful_create');

        return $this->redirectToRoute($request->request->has('continue') ? 'group_new' : 'groups_index');
    }

    #[Route('/groups/{id}.{_format}', name: 'group_show', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function show(Request $request, int $id): Response
    {
        $group = $this->finder->find($id);
        if ($this->isApi($request)) {
            return $this->renderer->render($request, ['group' => $this->api->group($group, PrincipalApi::includes($request))]);
        }

        return $this->render('admin/groups/show.html.twig', $this->profile->view($group));
    }

    #[Route('/groups/{id}/edit', name: 'group_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        $this->requireAdmin();

        return $this->render('admin/groups/edit.html.twig', $this->formView->forEdit($request, $this->finder->find($id)));
    }

    #[Route('/groups/{id}.{_format}', name: 'group_update', methods: ['PUT', 'PATCH'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function update(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $group = $this->finder->find($id);
        $submission = $this->form->update($group, $request);
        if ($this->isApi($request)) {
            return $submission->isSaved() ? $this->renderer->noContent() : $this->renderer->errors($request, $submission->errors);
        }
        if (!$submission->isSaved()) {
            return $this->render('admin/groups/edit.html.twig', $this->formView->forEdit($request, $group, $submission));
        }
        $this->flashNotice();

        return $this->redirect($this->backUrl->localReferer($request) ?? $this->generateUrl('groups_index'));
    }

    #[Route('/groups/{id}.{_format}', name: 'group_destroy', methods: ['DELETE'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function destroy(Request $request, int $id): Response
    {
        $this->requireAdmin();
        if (!$this->removal->remove($this->finder->find($id))) {
            // builtin groups cannot be deleted
            if ($this->isApi($request)) {
                return $this->renderer->errors($request, [$this->l('notice_not_authorized')], 422);
            }
            throw $this->createAccessDeniedException();
        }
        if ($this->isApi($request)) {
            return $this->renderer->noContent();
        }

        return $this->redirect($this->backUrl->localReferer($request, '/groups/'.$id) ?? $this->generateUrl('groups_index'));
    }

    #[Route('/groups/{id}/users/new', name: 'group_users_new', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function newUsers(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $group = $this->finder->find($id);

        return $this->render('admin/groups/new_users.html.twig', [
            'group' => $group,
            'principals' => $this->groupUsers->candidates($group, $request),
            'admin_menu_selected' => 'groups',
            'page_title' => $this->l('label_user_new'),
            'body_css' => 'controller-groups action-new_users',
        ]);
    }

    #[Route('/groups/{id}/autocomplete_for_user.{_format}', name: 'group_autocomplete_for_user', methods: ['GET'], requirements: ['id' => '\d+', '_format' => 'js|html'], defaults: ['_format' => 'html'])]
    public function autocompleteForUser(Request $request, int $id): Response
    {
        $this->requireAdmin();

        return $this->render('admin/groups/_principals.html.twig', [
            'principals' => $this->groupUsers->candidates($this->finder->find($id), $request),
        ]);
    }

    #[Route('/groups/{id}/users.{_format}', name: 'group_users_add', methods: ['POST'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function addUsers(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $group = $this->finder->find($id);
        $errors = $this->groupUsers->add($group, $request);
        if ($this->isApi($request)) {
            return [] === $errors ? $this->renderer->noContent() : $this->renderer->errors($request, $errors);
        }
        $this->flashNotice();

        return $this->redirectBackOr($request, $this->groupUsers->tabUrl($request, $group));
    }

    #[Route('/groups/{id}/users.{_format}', name: 'group_users_remove', methods: ['DELETE'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function removeUsers(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $group = $this->finder->find($id);
        $users = $this->groupUsers->requested($group, $request);
        if (!$this->groupUsers->remove($group, $users, $request)) {
            return $this->render('admin/groups/remove_users.html.twig', $this->groupUsers->removalConfirmation($request, $group, $users));
        }
        if ($this->isApi($request)) {
            return $this->renderer->noContent();
        }
        $this->flashNotice('notice_successful_delete');

        return $this->redirectBackOr($request, $this->groupUsers->tabUrl($request, $group));
    }

    #[Route('/groups/{id}/users/{user_id}.{_format}', name: 'group_user_remove', methods: ['DELETE'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', 'user_id' => '\d+', '_format' => 'html|json|xml'])]
    public function removeUser(Request $request, int $id, int $user_id): Response
    {
        $this->requireAdmin();
        $group = $this->finder->find($id);
        $users = $this->groupUsers->among($group, [$user_id]);
        if (!$this->groupUsers->remove($group, $users, $request)) {
            return $this->render('admin/groups/remove_users.html.twig', $this->groupUsers->removalConfirmation($request, $group, $users));
        }
        if ($this->isApi($request)) {
            return $this->renderer->noContent();
        }
        $this->flashNotice('notice_successful_delete');

        return $this->redirectBackOr($request, $this->groupUsers->tabUrl($request, $group));
    }
}
