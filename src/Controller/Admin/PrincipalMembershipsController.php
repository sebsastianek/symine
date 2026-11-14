<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Entity\Group;
use App\Service\Admin\Memberships\PrincipalMembershipForm;
use App\Service\Admin\Memberships\PrincipalMemberships;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's PrincipalMembershipsController (memberships of a user or a group, admin side) */
final class PrincipalMembershipsController extends AbstractController
{
    public function __construct(
        private readonly PrincipalMemberships $finder,
        private readonly PrincipalMembershipForm $form,
    ) {
    }

    #[Route('/{type}/{principal_id}/memberships/new', name: 'membership_new', methods: ['GET'], requirements: ['type' => 'users|groups', 'principal_id' => '\d+'])]
    public function new(string $type, int $principal_id): Response
    {
        $this->requireAdmin();

        return $this->render('admin/principal_memberships/new.html.twig', $this->form->forNew($this->finder->principal($type, $principal_id), $type));
    }

    #[Route('/{type}/{principal_id}/memberships', name: 'membership_create', methods: ['POST'], requirements: ['type' => 'users|groups', 'principal_id' => '\d+'])]
    public function create(Request $request, string $type, int $principal_id): Response
    {
        $this->requireAdmin();
        $principal = $this->finder->principal($type, $principal_id);
        $errors = $this->form->create($principal, $request);
        if ([] !== $errors) {
            $this->flashError($this->l('notice_failed_to_save_members', ['errors' => implode(', ', $errors)]));
        }

        return $this->redirectToRoute($principal instanceof Group ? 'group_edit' : 'user_edit', ['id' => $principal->id, 'tab' => 'memberships']);
    }

    #[Route('/{type}/{principal_id}/memberships/{id}/edit', name: 'membership_edit', methods: ['GET'], requirements: ['type' => 'users|groups', 'principal_id' => '\d+', 'id' => '\d+'])]
    public function edit(string $type, int $principal_id, int $id): Response
    {
        $this->requireAdmin();

        return $this->render('admin/principal_memberships/edit.html.twig', $this->form->forEdit($this->finder->membership($id), $type));
    }

    #[Route('/{type}/{principal_id}/memberships/{id}', name: 'membership_update', methods: ['PUT', 'PATCH'], requirements: ['type' => 'users|groups', 'principal_id' => '\d+', 'id' => '\d+'])]
    public function update(Request $request, string $type, int $principal_id, int $id): Response
    {
        $this->requireAdmin();
        $membership = $this->finder->membership($id);
        $principal = $membership->principal;
        $errors = $this->form->update($membership, $request);
        if ([] !== $errors) {
            $this->flashError($this->l('notice_failed_to_save_members', ['errors' => implode(', ', $errors)]));
        }

        return $this->redirectToRoute($principal instanceof Group ? 'group_edit' : 'user_edit', ['id' => $principal->id, 'tab' => 'memberships']);
    }

    #[Route('/{type}/{principal_id}/memberships/{id}', name: 'membership_destroy', methods: ['DELETE'], requirements: ['type' => 'users|groups', 'principal_id' => '\d+', 'id' => '\d+'])]
    public function destroy(string $type, int $principal_id, int $id): Response
    {
        $this->requireAdmin();
        $membership = $this->finder->membership($id);
        $principal = $membership->principal;
        $this->form->remove($membership);

        return $this->redirectToRoute($principal instanceof Group ? 'group_edit' : 'user_edit', ['id' => $principal->id, 'tab' => 'memberships']);
    }
}
