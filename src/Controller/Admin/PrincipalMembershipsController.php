<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Entity\Group;
use App\Entity\Member;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\User;
use App\Service\Admin\UserManager;
use App\Service\MembershipService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's PrincipalMembershipsController (memberships of a user or a group, admin side) */
final class PrincipalMembershipsController extends AbstractController
{
    public function __construct(
        private readonly MembershipService $memberships,
        private readonly UserManager $users,
    ) {
    }

    #[Route('/{type}/{principal_id}/memberships/new', name: 'membership_new', methods: ['GET'], requirements: ['type' => 'users|groups', 'principal_id' => '\d+'])]
    public function new(string $type, int $principal_id): Response
    {
        $this->requireAdmin();
        $principal = $this->findPrincipal($type, $principal_id);
        $memberProjectIds = array_map(static fn (Member $m) => $m->project->id, $this->em->getRepository(Member::class)->findBy(['principal' => $principal]));

        return $this->render('admin/principal_memberships/new.html.twig', [
            'principal' => $principal,
            'type' => $type,
            'projects' => $this->em->createQuery('SELECT p FROM App\Entity\Project p WHERE p.status = 1 ORDER BY p.lft, p.name')->getResult(),
            'member_project_ids' => $memberProjectIds,
            'roles' => $this->givableRoles(),
            'admin_menu_selected' => $principal instanceof Group ? 'groups' : 'users',
            'page_title' => $this->l('label_add_projects'),
            'body_css' => 'controller-principal_memberships action-new',
        ]);
    }

    #[Route('/{type}/{principal_id}/memberships', name: 'membership_create', methods: ['POST'], requirements: ['type' => 'users|groups', 'principal_id' => '\d+'])]
    public function create(Request $request, string $type, int $principal_id): Response
    {
        $this->requireAdmin();
        $principal = $this->findPrincipal($type, $principal_id);
        $params = (array) $request->request->all('membership');
        $projectIds = array_values(array_filter(array_map('intval', (array) ($params['project_ids'] ?? []))));
        if (isset($params['project_id'])) {
            $projectIds[] = (int) $params['project_id'];
        }
        $roleIds = array_values(array_filter(array_map('intval', (array) ($params['role_ids'] ?? []))));
        if ([] !== $projectIds) {
            $errors = $this->users->createMemberships($principal, $projectIds, $roleIds);
            if ([] !== $errors) {
                $this->flashError($this->l('notice_failed_to_save_members', ['errors' => implode(', ', $errors)]));
            }
        }

        return $this->redirectToPrincipal($principal);
    }

    #[Route('/{type}/{principal_id}/memberships/{id}/edit', name: 'membership_edit', methods: ['GET'], requirements: ['type' => 'users|groups', 'principal_id' => '\d+', 'id' => '\d+'])]
    public function edit(string $type, int $principal_id, int $id): Response
    {
        $this->requireAdmin();
        $membership = $this->findMembership($id);

        return $this->render('admin/principal_memberships/edit.html.twig', [
            'membership' => $membership,
            'principal' => $membership->principal,
            'type' => $type,
            'roles' => $this->givableRoles(),
            'admin_menu_selected' => $membership->principal instanceof Group ? 'groups' : 'users',
            'page_title' => $membership->principal->getName().' - '.$membership->project->name,
            'body_css' => 'controller-principal_memberships action-edit',
        ]);
    }

    #[Route('/{type}/{principal_id}/memberships/{id}', name: 'membership_update', methods: ['PUT', 'PATCH'], requirements: ['type' => 'users|groups', 'principal_id' => '\d+', 'id' => '\d+'])]
    public function update(Request $request, string $type, int $principal_id, int $id): Response
    {
        $this->requireAdmin();
        $membership = $this->findMembership($id);
        $principal = $membership->principal;
        $roleIds = array_values(array_filter(array_map('intval', (array) ($request->request->all('membership')['role_ids'] ?? []))));
        $roles = $roleIds ? $this->em->getRepository(Role::class)->findBy(['id' => $roleIds, 'builtin' => Role::BUILTIN_NONE]) : [];
        $hasInherited = false;
        foreach ($membership->memberRoles as $mr) {
            if (null !== $mr->inheritedFrom) {
                $hasInherited = true;
            }
        }
        if ([] === $roles && !$hasInherited) {
            $this->flashError($this->l('notice_failed_to_save_members', ['errors' => $this->l('label_role_plural').' '.$this->l('activerecord.errors.messages.empty')]));
        } else {
            $this->memberships->setRoles($membership, $roles);
        }

        return $this->redirectToPrincipal($principal);
    }

    #[Route('/{type}/{principal_id}/memberships/{id}', name: 'membership_destroy', methods: ['DELETE'], requirements: ['type' => 'users|groups', 'principal_id' => '\d+', 'id' => '\d+'])]
    public function destroy(string $type, int $principal_id, int $id): Response
    {
        $this->requireAdmin();
        $membership = $this->findMembership($id);
        $principal = $membership->principal;
        if ($membership->isDeletable()) {
            $this->memberships->removeMember($membership);
        }

        return $this->redirectToPrincipal($principal);
    }

    /** @return list<Role> */
    private function givableRoles(): array
    {
        return $this->em->getRepository(Role::class)->findBy(['builtin' => Role::BUILTIN_NONE], ['position' => 'ASC']);
    }

    private function findPrincipal(string $type, int $id): Principal
    {
        $p = $this->em->find(Principal::class, $id);
        if (null === $p || ('users' === $type && !($p instanceof User && $p->isLogged())) || ('groups' === $type && !$p instanceof Group)) {
            throw $this->createNotFoundException();
        }

        return $p;
    }

    private function findMembership(int $id): Member
    {
        $m = $this->em->find(Member::class, $id);
        if (null === $m) {
            throw $this->createNotFoundException();
        }

        return $m;
    }

    private function redirectToPrincipal(Principal $principal): Response
    {
        return $this->redirectToRoute($principal instanceof Group ? 'group_edit' : 'user_edit', ['id' => $principal->id, 'tab' => 'memberships']);
    }
}
