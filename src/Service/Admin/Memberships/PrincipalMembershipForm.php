<?php

declare(strict_types=1);

namespace App\Service\Admin\Memberships;

use App\Entity\Group;
use App\Entity\Member;
use App\Entity\Principal;
use App\Entity\Role;
use App\I18n\Localizer;
use App\Service\Admin\UserManager;
use App\Service\MembershipService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The forms of the "Projects" tab of a user or a group (PrincipalMembershipsController): adding the principal
 * to projects with roles (membership[project_ids][], membership[role_ids][]), changing the roles of a
 * membership, removing it.
 */
final class PrincipalMembershipForm
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MembershipService $memberships,
        private readonly UserManager $users,
        private readonly PrincipalMemberships $finder,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * Makes the principal a member of the submitted projects.
     *
     * @return list<string> error messages
     */
    public function create(Principal $principal, Request $request): array
    {
        $params = (array) $request->request->all('membership');
        $projectIds = array_values(array_filter(array_map('intval', (array) ($params['project_ids'] ?? []))));
        if (isset($params['project_id'])) {
            $projectIds[] = (int) $params['project_id'];
        }
        $roleIds = array_values(array_filter(array_map('intval', (array) ($params['role_ids'] ?? []))));
        if ([] === $projectIds) {
            return [];
        }

        return $this->users->createMemberships($principal, $projectIds, $roleIds);
    }

    /**
     * Gives the membership the submitted roles; the roles it inherits from a group are kept.
     *
     * @return list<string> error messages
     */
    public function update(Member $membership, Request $request): array
    {
        $roleIds = array_values(array_filter(array_map('intval', (array) ($request->request->all('membership')['role_ids'] ?? []))));
        $roles = $roleIds ? $this->em->getRepository(Role::class)->findBy(['id' => $roleIds, 'builtin' => Role::BUILTIN_NONE]) : [];
        $hasInherited = false;
        foreach ($membership->memberRoles as $mr) {
            if (null !== $mr->inheritedFrom) {
                $hasInherited = true;
            }
        }
        if ([] === $roles && !$hasInherited) {
            return [$this->i18n->l('label_role_plural').' '.$this->i18n->l('activerecord.errors.messages.empty')];
        }
        $this->memberships->setRoles($membership, $roles);

        return [];
    }

    /** Removes the membership, unless it only exists through a group */
    public function remove(Member $membership): void
    {
        if ($membership->isDeletable()) {
            $this->memberships->removeMember($membership);
        }
    }

    /**
     * Variables of the "add projects" page.
     *
     * @return array<string, mixed>
     */
    public function forNew(Principal $principal, string $type): array
    {
        $memberProjectIds = array_map(static fn (Member $m) => $m->project->id, $this->em->getRepository(Member::class)->findBy(['principal' => $principal]));

        return [
            'principal' => $principal,
            'type' => $type,
            'projects' => $this->em->createQuery('SELECT p FROM App\Entity\Project p WHERE p.status = 1 ORDER BY p.lft, p.name')->getResult(),
            'member_project_ids' => $memberProjectIds,
            'roles' => $this->finder->givableRoles(),
            'admin_menu_selected' => $principal instanceof Group ? 'groups' : 'users',
            'page_title' => $this->i18n->l('label_add_projects'),
            'body_css' => 'controller-principal_memberships action-new',
        ];
    }

    /**
     * Variables of the page editing the roles of a membership.
     *
     * @return array<string, mixed>
     */
    public function forEdit(Member $membership, string $type): array
    {
        return [
            'membership' => $membership,
            'principal' => $membership->principal,
            'type' => $type,
            'roles' => $this->finder->givableRoles(),
            'admin_menu_selected' => $membership->principal instanceof Group ? 'groups' : 'users',
            'page_title' => $membership->principal->getName().' - '.$membership->project->name,
            'body_css' => 'controller-principal_memberships action-edit',
        ];
    }
}
