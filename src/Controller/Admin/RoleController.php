<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Role;
use App\Repository\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/roles')]
#[IsGranted('ROLE_ADMIN')]
class RoleController extends AbstractController
{
    public function __construct(
        private RoleRepository $roleRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('', name: 'admin_role_index', methods: ['GET'])]
    public function index(): Response
    {
        $roles = $this->roleRepository->findBy([], ['position' => 'ASC']);

        return $this->render('admin/role/index.html.twig', [
            'roles' => $roles,
        ]);
    }

    #[Route('/new', name: 'admin_role_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $role = new Role();

        if ($request->isMethod('POST')) {
            $role->setName($request->request->get('name'));
            $role->setPosition((int) $request->request->get('position', 1));
            $role->setAssignable($request->request->has('assignable'));
            $role->setIssuesVisibility($request->request->get('issues_visibility', 'default'));
            $role->setUsersVisibility($request->request->get('users_visibility', 'members_of_visible_projects'));
            $role->setTimeEntriesVisibility($request->request->get('time_entries_visibility', 'all'));
            $role->setAllRolesManaged($request->request->has('all_roles_managed'));
            $role->setBuiltin(0); // Custom roles are always builtin = 0

            $this->entityManager->persist($role);
            $this->entityManager->flush();

            $this->addFlash('success', 'Role created successfully.');

            return $this->redirectToRoute('admin_role_index');
        }

        return $this->render('admin/role/new.html.twig', [
            'role' => $role,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_role_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Role $role): Response
    {
        // Prevent editing built-in roles
        if ($role->getBuiltin() > 0) {
            $this->addFlash('error', 'Built-in roles cannot be edited.');
            return $this->redirectToRoute('admin_role_index');
        }

        if ($request->isMethod('POST')) {
            $role->setName($request->request->get('name'));
            $role->setPosition((int) $request->request->get('position', 1));
            $role->setAssignable($request->request->has('assignable'));
            $role->setIssuesVisibility($request->request->get('issues_visibility', 'default'));
            $role->setUsersVisibility($request->request->get('users_visibility', 'members_of_visible_projects'));
            $role->setTimeEntriesVisibility($request->request->get('time_entries_visibility', 'all'));
            $role->setAllRolesManaged($request->request->has('all_roles_managed'));

            $this->entityManager->flush();

            $this->addFlash('success', 'Role updated successfully.');

            return $this->redirectToRoute('admin_role_index');
        }

        return $this->render('admin/role/edit.html.twig', [
            'role' => $role,
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_role_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Role $role): Response
    {
        // Verify CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete_role_' . $role->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_role_index');
        }

        // Prevent deleting built-in roles
        if ($role->getBuiltin() > 0) {
            $this->addFlash('error', 'Built-in roles cannot be deleted.');
            return $this->redirectToRoute('admin_role_index');
        }

        $roleName = $role->getName();

        $this->entityManager->remove($role);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Role "%s" deleted successfully.', $roleName));

        return $this->redirectToRoute('admin_role_index');
    }
}
