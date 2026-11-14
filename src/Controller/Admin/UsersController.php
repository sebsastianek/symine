<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Api\ApiRenderer;
use App\Controller\AbstractController;
use App\Entity\Principal;
use App\Query\StatementInvalid;
use App\Service\Admin\BackUrl;
use App\Service\Admin\PrincipalApi;
use App\Service\Admin\Users\RemovalOutcome;
use App\Service\Admin\Users\UserCsvExport;
use App\Service\Admin\Users\UserFinder;
use App\Service\Admin\Users\UserForm;
use App\Service\Admin\Users\UserFormView;
use App\Service\Admin\Users\UserList;
use App\Service\Admin\Users\UserProfile;
use App\Service\Admin\Users\UserRemoval;
use App\Service\Admin\Users\UserStatusChanger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's UsersController */
final class UsersController extends AbstractController
{
    public function __construct(
        private readonly UserFinder $finder,
        private readonly UserList $list,
        private readonly UserCsvExport $csv,
        private readonly UserProfile $profile,
        private readonly UserForm $form,
        private readonly UserFormView $formView,
        private readonly UserRemoval $removal,
        private readonly UserStatusChanger $status,
        private readonly PrincipalApi $api,
        private readonly ApiRenderer $renderer,
        private readonly BackUrl $backUrl,
    ) {
    }

    #[Route('/users.{_format}', name: 'users_index', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|csv|json|xml'])]
    public function index(Request $request, string $_format = 'html'): Response
    {
        $this->requireAdmin();
        $query = $this->list->query($request, $_format);
        if (!$query->isValid()) {
            if ($this->isApi($request)) {
                return $this->renderer->errors($request, $query->errors);
            }
            if ('csv' === $_format) {
                return new Response('', 422);
            }

            return $this->render('admin/users/index.html.twig', $this->list->view($query, [], null));
        }
        try {
            $count = $query->userCount();
            if ('csv' === $_format) {
                return $this->csv->response($request, $query);
            }
            $pager = $this->paginate($request, $count);
            $users = $query->results($pager['offset'], $pager['limit']);
        } catch (StatementInvalid) {
            return $this->render('common/error.html.twig', ['message' => $this->l('error_query_statement_invalid'), 'status' => 500], new Response('', 500));
        }
        if ($this->isApi($request)) {
            return $this->renderer->render($request, $this->api->userList($users, $count, $pager, PrincipalApi::includes($request)));
        }

        return $this->render('admin/users/index.html.twig', $this->list->view($query, $users, $pager));
    }

    #[Route('/users/new', name: 'user_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->requireAdmin();

        return $this->render('admin/users/new.html.twig', $this->formView->forNew($request));
    }

    #[Route('/users.{_format}', name: 'user_create', methods: ['POST'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'])]
    public function create(Request $request): Response
    {
        $this->requireAdmin();
        $submission = $this->form->create($request);
        $user = $submission->user;
        if ($this->isApi($request)) {
            if (!$submission->isSaved()) {
                return $this->renderer->errors($request, $submission->errors);
            }
            $response = $this->renderer->render($request, ['user' => $this->api->user($user)], 201);
            $response->headers->set('Location', $this->generateUrl('user_show', ['id' => $user->id], 0));

            return $response;
        }
        if (!$submission->isSaved()) {
            return $this->render('admin/users/new.html.twig', $this->formView->forCreate($submission));
        }
        $this->addFlash('notice', $this->l('notice_user_successful_create', ['id' => $user->login]));
        if ($request->request->has('continue')) {
            return $this->redirectToRoute('user_new', ['user' => ['generate_password' => $submission->generatePassword() ? '1' : '']]);
        }

        return $this->redirectToRoute('user_edit', ['id' => $user->id]);
    }

    #[Route('/users/current.{_format}', name: 'users_current', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'])]
    public function current(Request $request): Response
    {
        $this->requireLogin();
        $user = $this->finder->visible($this->user());
        if ($this->isApi($request)) {
            return $this->renderer->render($request, ['user' => $this->api->user($user, PrincipalApi::includes($request))]);
        }

        return $this->render('admin/users/show.html.twig', $this->profile->view($user));
    }

    #[Route('/users/{id}.{_format}', name: 'user_show', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function show(Request $request, int $id): Response
    {
        $user = $this->finder->findVisible($id);
        if ($this->isApi($request)) {
            return $this->renderer->render($request, ['user' => $this->api->user($user, PrincipalApi::includes($request))]);
        }

        return $this->render('admin/users/show.html.twig', $this->profile->view($user));
    }

    #[Route('/users/{id}/edit', name: 'user_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
    {
        $this->requireAdmin();

        return $this->render('admin/users/edit.html.twig', $this->formView->forEdit($this->finder->find($id)));
    }

    #[Route('/users/{id}.{_format}', name: 'user_update', methods: ['PUT', 'PATCH'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function update(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $user = $this->finder->find($id);
        $submission = $this->form->update($user, $request);
        if ($this->isApi($request)) {
            return $submission->isSaved() ? $this->renderer->noContent() : $this->renderer->errors($request, $submission->errors);
        }
        if (!$submission->isSaved()) {
            return $this->render('admin/users/edit.html.twig', $this->formView->forEdit($user, $submission));
        }
        $this->flashNotice();

        return $this->redirect($this->backUrl->localReferer($request) ?? $this->generateUrl('user_edit', ['id' => $user->id]));
    }

    #[Route('/users/{id}.{_format}', name: 'user_destroy', methods: ['DELETE'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function destroy(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $user = $this->finder->find($id);
        $api = $this->isApi($request);
        if (!$this->removal->isAllowed($user)) {
            if ($api) {
                return $this->renderer->errors($request, [], 422);
            }

            return new Response($this->renderView('common/error.html.twig', ['status' => 422, 'message' => $this->l('notice_not_authorized')]), 422);
        }
        $outcome = $this->removal->remove($user, $request);
        if (RemovalOutcome::Unconfirmed === $outcome) {
            return $this->render('admin/users/destroy.html.twig', [
                'edited_user' => $user,
                'admin_menu_selected' => 'users',
                'page_title' => $this->l('label_confirmation'),
                'body_css' => 'controller-users action-destroy',
            ]);
        }
        if (RemovalOutcome::Locked === $outcome) {
            $this->flashNotice();
        } elseif (!$api) {
            $this->flashNotice('notice_successful_delete');
        }
        if ($api) {
            return $this->renderer->noContent();
        }

        return $this->redirectBackOr($request, $this->generateUrl('users_index'));
    }

    #[Route('/users/bulk_lock', name: 'users_bulk_lock', methods: ['POST'])]
    public function bulkLock(Request $request): Response
    {
        $this->requireAdmin();
        $this->status->change($this->finder->forBulkStatus($request), Principal::STATUS_LOCKED);
        $this->flashNotice();

        return $this->redirectToRoute('users_index');
    }

    #[Route('/users/bulk_unlock', name: 'users_bulk_unlock', methods: ['POST'])]
    public function bulkUnlock(Request $request): Response
    {
        $this->requireAdmin();
        $this->status->change($this->finder->forBulkStatus($request), Principal::STATUS_ACTIVE);
        $this->flashNotice();

        return $this->redirectToRoute('users_index');
    }

    /** UsersController#bulk_destroy (confirmation page until "Yes" is typed) */
    #[Route('/users/bulk_destroy', name: 'users_bulk_destroy', methods: ['DELETE'])]
    public function bulkDestroy(Request $request): Response
    {
        $this->requireAdmin();
        $users = $this->finder->forBulkDestroy($request);
        if ($this->removal->removeAll($users, $request)) {
            $this->flashNotice('notice_successful_delete');

            return $this->redirectToRoute('users_index');
        }

        return $this->render('admin/users/bulk_destroy.html.twig', $this->removal->bulkConfirmation($users) + [
            'admin_menu_selected' => 'users',
            'page_title' => $this->l('label_confirmation'),
            'body_css' => 'controller-users action-bulk_destroy',
        ]);
    }
}
