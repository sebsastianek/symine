<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Api\ApiRenderer;
use App\Api\RoleApiPresenter;
use App\Controller\AbstractController;
use App\Entity\Role;
use App\Service\Admin\Roles\PermissionsReport;
use App\Service\Admin\Roles\RoleFinder;
use App\Service\Admin\Roles\RoleForm;
use App\Service\Admin\Roles\RoleIndex;
use App\Service\Admin\Roles\RoleManager;
use App\Service\Admin\Roles\RoleNames;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's RolesController (Administration » Roles and permissions). */
final class RolesController extends AbstractController
{
    public function __construct(
        private readonly ApiRenderer $api,
        private readonly RoleApiPresenter $presenter,
        private readonly RoleFinder $finder,
        private readonly RoleIndex $list,
        private readonly RoleForm $form,
        private readonly RoleManager $roles,
        private readonly RoleNames $names,
        private readonly PermissionsReport $report,
    ) {
    }

    #[Route('/roles.{_format}', name: 'roles_index', requirements: ['_format' => 'html|json|xml'], defaults: ['_format' => 'html'], methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($this->isApi($request)) {
            // require_admin_or_api_request + check_if_login_required
            if ($this->settings->loginRequired() && !$this->user()->isLogged()) {
                $this->deny();
            }

            return $this->api->render($request, ['roles' => $this->presenter->roles($this->finder->givable())]);
        }
        $this->requireAdmin();

        return $this->render('admin/roles/index.html.twig', $this->list->view() + [
            'admin_menu_selected' => 'roles',
            'page_title' => $this->l('label_role_plural'),
            'body_css' => 'controller-roles action-index',
        ]);
    }

    #[Route('/roles/{id}.{_format}', name: 'role_show', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        // require_admin_or_api_request + check_if_login_required
        if ($this->settings->loginRequired() && !$this->user()->isLogged()) {
            $this->deny();
        }

        return $this->api->render($request, ['role' => $this->presenter->role($this->finder->find($id))]);
    }

    #[Route('/roles/new', name: 'role_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->requireAdmin();

        return $this->render('admin/roles/new.html.twig', $this->form->newView($request->query->all('role'), (int) $request->query->get('copy')) + [
            'admin_menu_selected' => 'roles',
            'page_title' => $this->l('label_role_new'),
            'body_css' => 'controller-roles action-new',
        ]);
    }

    #[Route('/roles', name: 'role_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->requireAdmin();
        $role = new Role();
        $errors = $this->form->submit($role, $request->request->all('role'), (int) $request->request->get('copy_workflow_from', 0));
        if ([] !== $errors) {
            return $this->render('admin/roles/new.html.twig', $this->form->view($role, $errors, (string) $request->request->get('copy_workflow_from', '')) + [
                'admin_menu_selected' => 'roles',
                'page_title' => $this->l('label_role_new'),
                'body_css' => 'controller-roles action-new',
            ], new Response('', 422));
        }
        $this->flashNotice('notice_successful_create');

        return $this->redirectToRoute('roles_index');
    }

    #[Route('/roles/{id}/edit', name: 'role_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->requireAdmin();
        $role = $this->finder->find($id);

        return $this->render('admin/roles/edit.html.twig', $this->form->view($role) + [
            'admin_menu_selected' => 'roles',
            'page_title' => $this->names->of($role),
            'body_css' => 'controller-roles action-edit',
        ]);
    }

    #[Route('/roles/{id}', name: 'role_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $role = $this->finder->find($id);
        $errors = $this->form->submit($role, $request->request->all('role'));
        if ($request->isXmlHttpRequest()) {
            return new Response('', [] === $errors ? 200 : 422);
        }
        if ([] !== $errors) {
            return $this->render('admin/roles/edit.html.twig', $this->form->view($role, $errors) + [
                'admin_menu_selected' => 'roles',
                'page_title' => $this->names->of($role),
                'body_css' => 'controller-roles action-edit',
            ], new Response('', 422));
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('roles_index', array_filter(['page' => $request->query->get('page')]));
    }

    #[Route('/roles/{id}', name: 'role_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $this->requireAdmin();
        $role = $this->finder->find($id);
        if (!$this->roles->destroy($role)) {
            return $this->render('admin/roles/index.html.twig', $this->list->view($role) + [
                'admin_menu_selected' => 'roles',
                'page_title' => $this->l('label_role_plural'),
                'body_css' => 'controller-roles action-index',
            ]);
        }

        return $this->redirectToRoute('roles_index');
    }

    #[Route('/roles/permissions.{_format}', name: 'roles_permissions', requirements: ['_format' => 'html|csv'], defaults: ['_format' => 'html'], methods: ['GET'])]
    public function permissions(Request $request, string $_format): Response
    {
        $this->requireAdmin();
        if ('csv' === $_format) {
            $csv = $this->report->csv($request->query->all('ids'), (string) ($request->query->get('separator') ?: ','));

            return new Response($csv, 200, ['Content-Type' => 'text/csv; header=present', 'Content-Disposition' => 'attachment; filename="permissions.csv"']);
        }

        return $this->render('admin/roles/permissions.html.twig', $this->report->view($request->query->all('ids')) + [
            'admin_menu_selected' => 'roles',
            'page_title' => $this->l('label_permissions_report'),
            'body_css' => 'controller-roles action-permissions',
        ]);
    }

    #[Route('/roles/permissions', name: 'roles_update_permissions', methods: ['POST'])]
    public function updatePermissions(Request $request): Response
    {
        $this->requireAdmin();
        $this->roles->updatePermissions($request->request->all('permissions'));
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('roles_index');
    }
}
