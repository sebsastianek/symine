<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Group;
use App\Entity\GroupAnonymous;
use App\Entity\GroupNonMember;
use App\Entity\Member;
use App\Entity\MemberRole;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\User;
use App\I18n\Localizer;
use App\Security\Authorization;
use Doctrine\ORM\EntityManagerInterface;

/** Port of Member / User#managed_roles helpers used by the members screens (MembersController). */
final class MembersManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly MembershipService $memberships,
        private readonly ?Localizer $i18n = null,
    ) {
    }

    /**
     * Principal#name: the built-in groups (GroupAnonymous, GroupNonMember) are named by a label
     * (label_group_anonymous / label_group_non_member), in the language of the current user.
     */
    public function principalName(Principal $principal): string
    {
        if (null !== $this->i18n) {
            if ($principal instanceof GroupAnonymous) {
                return $this->i18n->l('label_group_anonymous');
            }
            if ($principal instanceof GroupNonMember) {
                return $this->i18n->l('label_group_non_member');
            }
        }

        return (string) $principal->getName();
    }

    /** Principal.fields_for_order_statement ("type DESC"): users, then the built-in groups, then the groups */
    public static function principalTypeRank(Principal $principal): int
    {
        return match (true) {
            $principal instanceof GroupNonMember => 1,
            $principal instanceof GroupAnonymous => 2,
            $principal instanceof Group => 3,
            default => 0,
        };
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
            // any principal can be a member, including the built-in groups (their roles replace the
            // Anonymous / Non member role on the project); the anonymous user itself is not a principal to add
            if (null === $principal || (!$principal instanceof User && !$principal instanceof Group) || $principal instanceof \App\Entity\AnonymousUser) {
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

    /**
     * MembersController#create from params[:membership] (user_ids or user_id, role_ids). Nothing is attempted
     * for a form posted without membership; an API request always is, so that it gets its validation errors.
     *
     * @param array<string, mixed> $data
     *
     * @return array{members: list<Member>, errors: list<string>}
     */
    public function createFromParams(Project $project, array $data, bool $api, ?callable $l = null): array
    {
        $userIds = $data['user_ids'] ?? $data['user_id'] ?? [];
        $userIds = \is_array($userIds) ? array_values(array_filter($userIds, static fn ($v) => '' !== (string) $v)) : [$userIds];
        $roleIds = (array) ($data['role_ids'] ?? []);
        if (!$api && [] === $data) {
            return ['members' => [], 'errors' => []];
        }

        return $this->create($project, $userIds, $roleIds, null, $l);
    }

    /**
     * MembersController#update from params[:membership]: replaces the roles the user may manage. A membership
     * cannot be left without any role, unless it still has inherited ones.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string> errors (empty when saved, or when no role_ids were submitted)
     */
    public function updateRoles(Member $member, array $data, ?callable $l = null): array
    {
        if (!\array_key_exists('role_ids', $data)) {
            return [];
        }
        $roles = $this->editableRoleIds($member, $member->project, (array) $data['role_ids']);
        $hasInherited = false;
        foreach ($member->memberRoles as $mr) {
            $hasInherited = $hasInherited || null !== $mr->inheritedFrom;
        }
        if ([] === $roles && !$hasInherited) {
            return [$l ? $l('field_role').' '.$l('activerecord.errors.messages.empty') : 'Role cannot be empty'];
        }
        $this->memberships->setRoles($member, $roles);

        return [];
    }

    /** MembersController#destroy: removes the membership when Member#deletable?, tells whether it was removed */
    public function destroyIfDeletable(Member $member): bool
    {
        if (!$this->isDeletable($member)) {
            return false;
        }
        $this->destroy($member);

        return true;
    }

    /** @return list<Member> project.memberships in the order of the REST API and of the CSV export (by id) */
    public function memberships(Project $project): array
    {
        return $this->em->createQuery('SELECT m FROM App\Entity\Member m WHERE m.project = :p ORDER BY m.id')->setParameter('p', $project)->getResult();
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
                AND pr NOT INSTANCE OF App\Entity\AnonymousUser
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
                // Principal.visible: oneself and the members (users and groups alike) of the visible projects
                $dql .= ' AND (pr.id = :me OR pr.id IN (SELECT IDENTITY(m2.principal) FROM App\Entity\Member m2 WHERE '.Authorization::in('IDENTITY(m2.project)', $visible).'))';
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
        usort($list, fn (Principal $a, Principal $b) => [self::principalTypeRank($a), mb_strtolower($this->principalName($a)), $a->id] <=> [self::principalTypeRank($b), mb_strtolower($this->principalName($b)), $b->id]);

        return [\array_slice($list, $offset, $limit), $count];
    }

    /**
     * Project memberships (project.memberships: users, groups and built-in groups) sorted like Member.sorted
     * (first role position, then principal).
     *
     * @return list<Member>
     */
    public function projectMembers(Project $project, bool $activeOnly = true): array
    {
        $dql = 'SELECT m, mr, r, pr FROM App\Entity\Member m JOIN m.memberRoles mr JOIN mr.role r JOIN m.principal pr WHERE m.project = :p';
        if ($activeOnly) {
            $dql .= ' AND pr.status = 1';
        }
        $members = $this->em->createQuery($dql)->setParameter('p', $project)->getResult();
        usort($members, function (Member $a, Member $b) {
            $ra = $a->getRoles()[0]->position ?? 0;
            $rb = $b->getRoles()[0]->position ?? 0;

            return [$ra, self::principalTypeRank($a->principal), mb_strtolower($this->principalName($a->principal)), $a->id]
                <=> [$rb, self::principalTypeRank($b->principal), mb_strtolower($this->principalName($b->principal)), $b->id];
        });

        return $members;
    }

    // --- members list (MembersHelper) ---------------------------------------------------------

    public const array LIST_PARAMS = ['members_page', 'member_name', 'member_role_id', 'member_status'];

    /**
     * MembersHelper#members_list_params: the params that define the members list currently displayed
     * (pagination and filters), preserved across the member creation, edition and deletion requests.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, string>
     */
    public static function listParams(array $params): array
    {
        $out = [];
        foreach (self::LIST_PARAMS as $name) {
            $value = $params[$name] ?? null;
            if (\is_scalar($value) && '' !== trim((string) $value)) {
                $out[$name] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * MembersHelper#members_scope: memberships of the project matching the filters of the request, sorted
     * like Member.sorted. Users who cannot manage the members only see the active ones.
     *
     * @param array<string, mixed> $params member_name, member_role_id, member_status
     *
     * @return list<Member>
     */
    public function filteredMembers(Project $project, array $params, ?User $user = null): array
    {
        $params = self::listParams($params);
        $canManage = $this->auth->allowedTo('manage_members', $project, $user);
        $status = $canManage ? ($params['member_status'] ?? '') : (string) Principal::STATUS_ACTIVE;
        $name = trim($params['member_name'] ?? '');
        $roleId = (int) ($params['member_role_id'] ?? 0);
        $tokens = '' === $name ? [] : array_values(array_filter(preg_split('/\s+/', mb_strtolower($name)) ?: [], static fn ($t) => '' !== $t));

        return array_values(array_filter($this->projectMembers($project, false), static function (Member $m) use ($status, $name, $roleId, $tokens) {
            $principal = $m->principal;
            if ('' !== $status && 'all' !== $status && $principal->status !== (int) $status) {
                return false;
            }
            if ($roleId > 0 && [] === array_filter($m->getRoles(), static fn (Role $r) => $r->id === $roleId)) {
                return false;
            }
            if ('' === $name) {
                return true;
            }
            // Principal.like: login or e-mail address containing the string, or every word found in the names
            $needle = mb_strtolower($name);
            if (str_contains(mb_strtolower((string) $principal->login), $needle)) {
                return true;
            }
            if ($principal instanceof User) {
                foreach ($principal->emailAddresses as $address) {
                    if (str_contains(mb_strtolower((string) $address->address), $needle)) {
                        return true;
                    }
                }
            }
            foreach ($tokens as $token) {
                if (!str_contains(mb_strtolower((string) $principal->firstname), $token) && !str_contains(mb_strtolower((string) $principal->lastname), $token)) {
                    return false;
                }
            }

            return [] !== $tokens;
        }));
    }

    /**
     * Variables of members/_members.html.twig: MembersHelper#paginate_members(members_scope(project)).
     *
     * @param array<string, mixed> $params         request parameters (filters, members_page, per_page)
     * @param list<int>            $perPageOptions Setting.per_page_options_array
     * @param bool                 $indexPage      true on MembersController#index, false in the project settings
     *
     * @return array<string, mixed>
     */
    public function listView(Project $project, array $params, array $perPageOptions, bool $indexPage = false, ?User $user = null): array
    {
        $listParams = self::listParams($params);
        $canManage = $this->auth->allowedTo('manage_members', $project, $user);
        if (!$canManage) {
            unset($listParams['member_status']);
        }
        $all = $this->filteredMembers($project, $listParams, $user);
        $count = \count($all);
        $perPage = (int) (\is_scalar($params['per_page'] ?? null) ? $params['per_page'] : 0);
        $perPageGiven = \in_array($perPage, $perPageOptions, true);
        if (!$perPageGiven) {
            $perPage = $perPageOptions[0] ?? 25;
        }
        $pages = max(1, (int) ceil($count / $perPage));
        $page = max(1, min($pages, (int) ($listParams['members_page'] ?? 1)));
        $offset = ($page - 1) * $perPage;
        $query = $listParams;
        unset($query['members_page']);
        if ($perPageGiven) {
            $query['per_page'] = (string) $perPage;
        }

        return [
            'members' => \array_slice($all, $offset, $perPage),
            'members_manager' => $this,
            'member_count' => $count,
            'member_pager' => ['page' => $page, 'pages' => $pages, 'count' => $count, 'limit' => $perPage, 'offset' => $offset, 'per_page_options' => $perPageOptions],
            // members_list_params: added to the new/edit/delete links
            'member_list_params' => $listParams,
            // query of the list links (filters and per_page, without the page)
            'member_list_query' => $query,
            'member_filters' => [
                'member_name' => $listParams['member_name'] ?? '',
                'member_role_id' => $listParams['member_role_id'] ?? '',
                'member_status' => $listParams['member_status'] ?? '',
            ],
            'member_role_options' => $this->givableRoles(),
            'members_route' => $indexPage ? 'project_members' : 'project_settings',
            'members_route_params' => $indexPage ? ['project_id' => $project->identifier] : ['id' => $project->identifier, 'tab' => 'members'],
        ];
    }

    /**
     * MembersHelper#members_to_csv rows: one line per role of every membership of the project.
     *
     * @param callable(string): string $l translator
     *
     * @return list<list<string>>
     */
    public function csvRows(Project $project, callable $l): array
    {
        $rows = [[$l('field_principal'), $l('field_type'), $l('label_role'), $l('label_project')]];
        $members = $this->em->createQuery('SELECT m FROM App\Entity\Member m WHERE m.project = :p ORDER BY m.id')->setParameter('p', $project)->getResult();
        foreach ($members as $member) {
            foreach ($member->getRoles() as $role) {
                $rows[] = [
                    $this->principalName($member->principal),
                    $member->principal instanceof Group ? $l('label_group') : $l('label_user'),
                    (string) $role->name,
                    (string) $member->project->name,
                ];
            }
        }

        return $rows;
    }
}
