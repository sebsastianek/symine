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

/** Port of Member / User#managed_roles helpers used by the members screens (MembersController). */
final class MembersManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly MembershipService $memberships,
    ) {
    }

    /** @return list<Role> Role.givable sorted */
    public function givableRoles(): array
    {
        return $this->em->getRepository(Role::class)->findBy(['builtin' => Role::BUILTIN_NONE], ['position' => 'ASC']);
    }

    /** @return list<Role> User#managed_roles(project) */
    public function managedRoles(Project $project, ?User $user = null): array
    {
        $user = $this->auth->user($user);
        if ($user->admin) {
            return $this->givableRoles();
        }
        $m = $this->auth->membership($user, $project);
        if (null === $m) {
            return [];
        }
        $managers = array_filter($m->getRoles(), static fn (Role $r) => $r->hasPermission('manage_members'));
        if ([] === $managers) {
            return [];
        }
        foreach ($managers as $r) {
            if ($r->allRolesManaged) {
                return $this->givableRoles();
            }
        }
        $ids = [];
        foreach ($managers as $r) {
            foreach ($r->managedRoles as $mr) {
                $ids[$mr->id] = $mr;
            }
        }
        $roles = array_values($ids);
        usort($roles, static fn (Role $a, Role $b) => $a->position <=> $b->position);

        return $roles;
    }

    private function hasInheritedRole(Member $member, Role $role): bool
    {
        foreach ($member->memberRoles as $mr) {
            if ($mr->role->id === $role->id && null !== $mr->inheritedFrom) {
                return true;
            }
        }

        return false;
    }

    public function isRoleEditable(Member $member, Role $role, ?User $user = null): bool
    {
        if ($this->hasInheritedRole($member, $role)) {
            return false;
        }

        return \in_array($role, $this->managedRoles($member->project, $user), true);
    }

    /** Member#deletable? */
    public function isDeletable(Member $member, ?User $user = null): bool
    {
        foreach ($member->memberRoles as $mr) {
            if (null !== $mr->inheritedFrom) {
                return false;
            }
        }
        $managed = array_map(static fn (Role $r) => $r->id, $this->managedRoles($member->project, $user));
        foreach ($member->getRoles() as $r) {
            if (!\in_array($r->id, $managed, true)) {
                return false;
            }
        }

        return true;
    }

    /** Member#include?(user) */
    public function includes(Member $member, User $user): bool
    {
        if ($member->principal instanceof Group) {
            return $member->principal->users->contains($user);
        }

        return $member->principal->id === $user->id;
    }

    /** @return list<string> inheritance labels source (Project|Group) for a role */
    public function roleInheritance(Member $member, Role $role): array
    {
        $out = [];
        foreach ($member->memberRoles as $mr) {
            if ($mr->role->id !== $role->id || null === $mr->inheritedFrom) {
                continue;
            }
            $src = $this->em->find(MemberRole::class, $mr->inheritedFrom);
            if (null === $src) {
                continue;
            }
            $out[] = $src->member->project->id === $member->project->id ? $src->member->principal : $src->member->project;
        }

        return $out;
    }

    /**
     * Member#set_editable_role_ids: computes the new direct roles from requested ids,
     * keeping roles the user cannot manage.
     *
     * @param list<int|string> $ids
     *
     * @return list<Role>
     */
    public function editableRoleIds(?Member $member, Project $project, array $ids, ?User $user = null): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        $managed = array_map(static fn (Role $r) => $r->id, $this->managedRoles($project, $user));
        $current = [];
        if (null !== $member) {
            foreach ($member->memberRoles as $mr) {
                if (null === $mr->inheritedFrom) {
                    $current[] = $mr->role->id;
                }
            }
        }
        $untouched = array_diff($current, $managed);
        $touched = array_intersect($ids, $managed);
        $final = array_values(array_unique([...$untouched, ...$touched]));

        return [] === $final ? [] : $this->em->getRepository(Role::class)->findBy(['id' => $final], ['position' => 'ASC']);
    }

    /**
     * Creates memberships for the given principals (MembersController#create).
     *
     * @param list<int|string> $principalIds
     * @param list<int|string> $roleIds
     *
     * @return array{members: list<Member>, errors: list<string>}
     */
    public function create(Project $project, array $principalIds, array $roleIds, ?User $user = null, ?callable $l = null): array
    {
        $members = [];
        $errors = [];
        $roles = $this->editableRoleIds(null, $project, $roleIds, $user);
        if ([] === $principalIds) {
            $principalIds = [null];
        }
        foreach ($principalIds as $pid) {
            $principal = null !== $pid && '' !== (string) $pid ? $this->em->find(Principal::class, (int) $pid) : null;
            if (null === $principal || (!$principal instanceof User && !$principal instanceof Group) || $principal instanceof \App\Entity\GroupBuiltin) {
                $errors[] = $l ? $l('field_principal').' '.$l('activerecord.errors.messages.blank') : 'User or Group cannot be blank';
                continue;
            }
            $existing = $this->memberships->find($project, $principal);
            if (null !== $existing) {
                $errors[] = $l ? $l('field_user').' '.$l('activerecord.errors.messages.taken') : 'User has already been taken';
                continue;
            }
            if ([] === $roles) {
                $errors[] = $l ? $l('field_role').' '.$l('activerecord.errors.messages.empty') : 'Role cannot be empty';
                continue;
            }
            $members[] = $this->memberships->addMember($project, $principal, $roles);
        }

        return ['members' => $members, 'errors' => array_values(array_unique($errors))];
    }

    /** Member#destroy with before_destroy callbacks */
    public function destroy(Member $member): void
    {
        $project = $member->project;
        $principalId = $member->principal->id;
        $this->memberships->removeMember($member);
        $stillMember = null !== $this->em->getRepository(Member::class)->findOneBy(['project' => $project, 'principal' => $principalId]);
        if (!$stillMember) {
            $conn = $this->em->getConnection();
            $conn->executeStatement('UPDATE issue_categories SET assigned_to_id = NULL WHERE project_id = ? AND assigned_to_id = ?', [$project->id, $principalId]);
            if ($project->defaultAssignedTo?->id === $principalId) {
                $project->defaultAssignedTo = null;
                $this->em->flush();
            }
        }
    }

    /**
     * Principals that can be added to the project (Principal.active.visible.not_member_of(project).like(q)).
     *
     * @return array{0: list<Principal>, 1: int}
     */
    public function principalsForNewMembers(Project $project, ?string $q, int $offset = 0, int $limit = 100, ?User $user = null): array
    {
        $user = $this->auth->user($user);
        $dql = 'FROM App\Entity\Principal pr WHERE pr.status = 1 AND (pr INSTANCE OF App\Entity\User OR pr INSTANCE OF App\Entity\Group)
                AND pr NOT INSTANCE OF App\Entity\AnonymousUser AND pr NOT INSTANCE OF App\Entity\GroupBuiltin
                AND pr.id NOT IN (SELECT IDENTITY(m.principal) FROM App\Entity\Member m WHERE m.project = :p)';
        $params = ['p' => $project->id];
        if (!$user->admin) {
            $all = false;
            $roles = $user->isLogged() && [] !== $this->auth->membershipsOf($user) ? array_merge(...array_map(static fn ($m) => $m->getRoles(), array_values($this->auth->membershipsOf($user)))) : [$this->auth->builtinRole($user)];
            foreach ($roles as $r) {
                $all = $all || 'all' === $r->usersVisibility;
            }
            if (!$all) {
                $visible = $this->auth->visibleProjectIds($user);
                $dql .= ' AND (pr INSTANCE OF App\Entity\Group OR pr.id = :me OR pr.id IN (SELECT IDENTITY(m2.principal) FROM App\Entity\Member m2 WHERE '.Authorization::in('IDENTITY(m2.project)', $visible).'))';
                $params['me'] = (int) $user->id;
            }
        }
        $q = trim((string) $q);
        if ('' !== $q) {
            $tokens = array_values(array_filter(preg_split('/\s+/', $q) ?: []));
            $conds = ['LOWER(pr.login) LIKE :q', 'pr.id IN (SELECT IDENTITY(e.user) FROM App\Entity\EmailAddress e WHERE LOWER(e.address) LIKE :q)'];
            $params['q'] = '%'.mb_strtolower(addcslashes($q, '%_\\')).'%';
            $tok = [];
            foreach ($tokens as $i => $t) {
                $params['t'.$i] = '%'.mb_strtolower(addcslashes($t, '%_\\')).'%';
                $tok[] = "(LOWER(pr.firstname) LIKE :t$i OR LOWER(pr.lastname) LIKE :t$i)";
            }
            if ([] !== $tok) {
                $conds[] = '('.implode(' AND ', $tok).')';
            }
            $dql .= ' AND ('.implode(' OR ', $conds).')';
        }
        $count = (int) $this->em->createQuery('SELECT COUNT(pr.id) '.$dql)->setParameters($params)->getSingleScalarResult();
        $list = $this->em->createQuery('SELECT pr '.$dql)->setParameters($params)->getResult();
        usort($list, static fn (Principal $a, Principal $b) => [$a instanceof Group ? 1 : 0, mb_strtolower($a->getName())] <=> [$b instanceof Group ? 1 : 0, mb_strtolower($b->getName())]);

        return [\array_slice($list, $offset, $limit), $count];
    }

    /** @return list<Member> project memberships sorted like Member.sorted (first role position, then principal) */
    public function projectMembers(Project $project, bool $activeOnly = true): array
    {
        $dql = 'SELECT m, mr, r, pr FROM App\Entity\Member m JOIN m.memberRoles mr JOIN mr.role r JOIN m.principal pr WHERE m.project = :p AND pr NOT INSTANCE OF App\Entity\GroupBuiltin';
        if ($activeOnly) {
            $dql .= ' AND pr.status = 1';
        }
        $members = $this->em->createQuery($dql)->setParameter('p', $project)->getResult();
        usort($members, static function (Member $a, Member $b) {
            $ra = $a->getRoles()[0]->position ?? 0;
            $rb = $b->getRoles()[0]->position ?? 0;

            return [$ra, $a->principal instanceof Group ? 1 : 0, mb_strtolower($a->principal->getName())] <=> [$rb, $b->principal instanceof Group ? 1 : 0, mb_strtolower($b->principal->getName())];
        });

        return $members;
    }
}
