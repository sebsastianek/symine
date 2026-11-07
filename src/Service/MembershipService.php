<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Group;
use App\Entity\Member;
use App\Entity\MemberRole;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\User;
use App\Security\Authorization;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Project memberships with Redmine's inheritance rules:
 * - roles given to a group are copied to its users (MemberRole.inherited_from = group's member_role id)
 * - roles of a project are copied to subprojects having inherit_members = true
 * Removing a MemberRole removes the roles inherited from it, and the Member when it has no role left.
 */
final class MembershipService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
    ) {
    }

    public function find(Project $project, Principal $principal): ?Member
    {
        return $this->em->getRepository(Member::class)->findOneBy(['project' => $project, 'principal' => $principal]);
    }

    private function findOrCreate(Project $project, Principal $principal): Member
    {
        $m = $this->find($project, $principal);
        if (null === $m) {
            foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $e) {
                if ($e instanceof Member && $e->project === $project && $e->principal === $principal) {
                    return $e;
                }
            }
            $m = new Member();
            $m->project = $project;
            $m->principal = $principal;
            $this->em->persist($m);
            $project->memberships->add($m);
        }

        return $m;
    }

    /**
     * Adds a principal with roles (or adds roles to an existing membership).
     *
     * @param list<Role> $roles
     */
    public function addMember(Project $project, Principal $principal, array $roles, bool $mailNotification = false): Member
    {
        $member = $this->findOrCreate($project, $principal);
        $member->mailNotification = $mailNotification;
        foreach ($roles as $role) {
            if (!$this->hasDirectRole($member, $role)) {
                $mr = $member->addRole($role);
                $this->em->persist($mr);
                $this->em->flush();
                $this->propagate($mr);
            }
        }
        $this->em->flush();
        $this->auth->reset();

        return $member;
    }

    /** Replaces the direct (non inherited) roles of a membership. @param list<Role> $roles */
    public function setRoles(Member $member, array $roles): void
    {
        $wanted = array_map(static fn (Role $r) => $r->id, $roles);
        foreach ($member->memberRoles->toArray() as $mr) {
            if (null === $mr->inheritedFrom && !\in_array($mr->role->id, $wanted, true)) {
                $this->removeMemberRole($mr, false);
            }
        }
        foreach ($roles as $role) {
            if (!$this->hasDirectRole($member, $role)) {
                $mr = $member->addRole($role);
                $this->em->persist($mr);
                $this->em->flush();
                $this->propagate($mr);
            }
        }
        $this->em->flush();
        if (0 === $member->memberRoles->count()) {
            $this->em->remove($member);
            $this->em->flush();
        }
        $this->auth->reset();
    }

    /** Removes the direct roles of a membership (inherited roles stay, like Redmine) */
    public function removeMember(Member $member): void
    {
        foreach ($member->memberRoles->toArray() as $mr) {
            if (null === $mr->inheritedFrom) {
                $this->removeMemberRole($mr, false);
            }
        }
        $this->em->flush();
        if (0 === $member->memberRoles->count()) {
            $this->em->remove($member);
        }
        $this->em->flush();
        $this->auth->reset();
    }

    private function hasDirectRole(Member $member, Role $role): bool
    {
        foreach ($member->memberRoles as $mr) {
            if (null === $mr->inheritedFrom && $mr->role->id === $role->id) {
                return true;
            }
        }

        return false;
    }

    /** Copies a new MemberRole to group users and inheriting subprojects */
    private function propagate(MemberRole $mr): void
    {
        $member = $mr->member;
        if ($member->principal instanceof Group && null === $mr->inheritedFrom) {
            foreach ($member->principal->users as $user) {
                $um = $this->findOrCreate($member->project, $user);
                $this->em->persist($um->addRole($mr->role, $mr->id));
            }
        }
        foreach ($member->project->children as $child) {
            if ($child->inheritMembers) {
                $cm = $this->findOrCreate($child, $member->principal);
                $child_mr = $cm->addRole($mr->role, $mr->id);
                $this->em->persist($child_mr);
                $this->em->flush();
                $this->propagate($child_mr);
            }
        }
        $this->em->flush();
    }

    /** Removes a member role and everything inherited from it; removes empty memberships */
    public function removeMemberRole(MemberRole $mr, bool $flush = true): void
    {
        foreach ($this->em->getRepository(MemberRole::class)->findBy(['inheritedFrom' => $mr->id]) as $child) {
            $this->removeMemberRole($child, false);
        }
        $member = $mr->member;
        $member->memberRoles->removeElement($mr);
        $this->em->remove($mr);
        if (0 === $member->memberRoles->count() && $mr->inheritedFrom !== null) {
            $this->em->remove($member);
        }
        if ($flush) {
            $this->em->flush();
        }
    }

    /** Group#user_added */
    public function addUserToGroup(Group $group, User $user): void
    {
        if ($group->users->contains($user)) {
            return;
        }
        $group->users->add($user);
        $user->groups->add($group);
        foreach ($this->em->getRepository(Member::class)->findBy(['principal' => $group]) as $gm) {
            if (0 === $gm->memberRoles->count()) {
                continue;
            }
            $um = $this->findOrCreate($gm->project, $user);
            foreach ($gm->memberRoles as $gmr) {
                $this->em->persist($um->addRole($gmr->role, $gmr->id));
            }
        }
        $this->em->flush();
        $this->auth->reset();
    }

    /** Group#user_removed */
    public function removeUserFromGroup(Group $group, User $user): void
    {
        $group->users->removeElement($user);
        $user->groups->removeElement($group);
        $groupMrIds = [];
        foreach ($this->em->getRepository(Member::class)->findBy(['principal' => $group]) as $gm) {
            foreach ($gm->memberRoles as $gmr) {
                $groupMrIds[] = $gmr->id;
            }
        }
        foreach ($this->em->getRepository(Member::class)->findBy(['principal' => $user]) as $um) {
            foreach ($um->memberRoles->toArray() as $umr) {
                if (null !== $umr->inheritedFrom && \in_array($umr->inheritedFrom, $groupMrIds, true)) {
                    $this->removeMemberRole($umr, false);
                }
            }
        }
        $this->em->flush();
        foreach ($this->em->getRepository(Member::class)->findBy(['principal' => $user]) as $um) {
            if (0 === $um->memberRoles->count()) {
                $this->em->remove($um);
            }
        }
        $this->em->flush();
        $this->auth->reset();
    }

    /** Project#update_inherited_members (after inherit_members or parent change) */
    public function updateInheritedMembers(Project $project): void
    {
        // remove roles inherited from outside the project
        $ids = [];
        foreach ($project->memberships as $m) {
            foreach ($m->memberRoles as $mr) {
                $ids[] = $mr->id;
            }
        }
        foreach ($project->memberships->toArray() as $m) {
            foreach ($m->memberRoles->toArray() as $mr) {
                if (null !== $mr->inheritedFrom && !\in_array($mr->inheritedFrom, $ids, true)) {
                    // inherited from a group membership of the same project is kept
                    $src = $this->em->find(MemberRole::class, $mr->inheritedFrom);
                    if ($src && $src->member->project->id === $project->id) {
                        continue;
                    }
                    $this->removeMemberRole($mr, false);
                }
            }
        }
        $this->em->flush();
        if ($project->inheritMembers && $project->parent) {
            foreach ($project->parent->memberships as $pm) {
                $m = $this->findOrCreate($project, $pm->principal);
                foreach ($pm->memberRoles as $pmr) {
                    $mr = $m->addRole($pmr->role, $pmr->id);
                    $this->em->persist($mr);
                }
            }
        }
        $this->em->flush();
        $this->auth->reset();
    }
}
