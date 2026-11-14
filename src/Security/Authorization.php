<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\GroupAnonymous;
use App\Entity\GroupNonMember;
use App\Entity\Member;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\User;
use App\Doctrine\BatchLoader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Port of Redmine's authorization logic (User#allowed_to?, User#roles_for_project,
 * Project#allows_to?, Project.allowed_to_condition).
 */
final class Authorization implements ResetInterface
{
    /** @var array<int, array<int, Member>> userId => projectId => Member */
    private array $memberships = [];
    /** @var array<int, Role> */
    private array $builtinRoles = [];
    /** @var array<string, array<int, list<Role>>>|null groupClass => projectId => roles */
    private ?array $overrideRoles = null;
    /** @var array<string, list<int>> */
    private array $eligibleProjects = [];
    /** @var array<int, array{status: int, public: bool, modules: list<string>}>|null */
    private ?array $projectInfo = null;

    /** @var \WeakMap<User, list<string>> OAuth scopes of users authenticated by a Bearer token (User#oauth_scope) */
    private \WeakMap $oauthScopes;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurrentUser $currentUser,
        private readonly BatchLoader $batch,
    ) {
        $this->oauthScopes = new \WeakMap();
    }

    /** User#oauth_scope=: restricts the user's permissions to these scopes (null = not authorized by OAuth) */
    public function setOauthScope(User $user, ?array $scopes): void
    {
        if (null === $scopes) {
            unset($this->oauthScopes[$user]);
        } else {
            $this->oauthScopes[$user] = array_values(array_map('strval', $scopes));
        }
    }

    /** @return list<string>|null */
    public function oauthScope(?User $user = null): ?array
    {
        $user ??= $this->currentUser->get();

        return $this->oauthScopes[$user] ?? null;
    }

    public function reset(): void
    {
        $this->memberships = [];
        $this->builtinRoles = [];
        $this->overrideRoles = null;
        $this->eligibleProjects = [];
        $this->projectInfo = null;
    }

    public function user(?User $user = null): User
    {
        return $user ?? $this->currentUser->get();
    }

    public function builtinRole(User $user): Role
    {
        $builtin = $user->isLogged() ? Role::BUILTIN_NON_MEMBER : Role::BUILTIN_ANONYMOUS;
        if (!isset($this->builtinRoles[$builtin])) {
            $role = $this->em->getRepository(Role::class)->findOneBy(['builtin' => $builtin]);
            if (null === $role) {
                $role = new Role();
                $role->builtin = $builtin;
                $role->name = Role::BUILTIN_NON_MEMBER === $builtin ? 'Non member' : 'Anonymous';
                $role->position = 0;
                $role->permissions = [];
                $this->em->persist($role);
                $this->em->flush();
            }
            $this->builtinRoles[$builtin] = $role;
        }

        return $this->builtinRoles[$builtin];
    }

    /** @return array<int, Member> projectId => membership */
    public function membershipsOf(User $user): array
    {
        if (null === $user->id || !$user->isLogged()) {
            return [];
        }
        if (!isset($this->memberships[$user->id])) {
            $members = $this->em->createQuery(
                'SELECT m, mr, r, p FROM App\Entity\Member m JOIN m.memberRoles mr JOIN mr.role r JOIN m.project p WHERE m.principal = :u'
            )->setParameter('u', $user->id)->getResult();
            $this->memberships[$user->id] = [];
            foreach ($members as $m) {
                $this->memberships[$user->id][$m->project->id] = $m;
            }
        }

        return $this->memberships[$user->id];
    }

    public function membership(User $user, Project $project): ?Member
    {
        return $this->membershipsOf($user)[$project->id] ?? null;
    }

    public function isMemberOf(User $user, Project $project): bool
    {
        return null !== $this->membership($user, $project);
    }

    /** @return list<Role> */
    private function overrideRoles(Project $project, Role $builtin): array
    {
        if (null === $this->overrideRoles) {
            $this->overrideRoles = [GroupAnonymous::class => [], GroupNonMember::class => []];
            $members = $this->em->createQuery(
                'SELECT m, mr, r, pr FROM App\Entity\Member m JOIN m.memberRoles mr JOIN mr.role r JOIN m.principal pr WHERE pr INSTANCE OF App\Entity\GroupAnonymous OR pr INSTANCE OF App\Entity\GroupNonMember'
            )->getResult();
            foreach ($members as $m) {
                $this->overrideRoles[$m->principal::class][$m->project->id] = $m->getRoles();
            }
        }
        $class = Role::BUILTIN_ANONYMOUS === $builtin->builtin ? GroupAnonymous::class : GroupNonMember::class;

        return $this->overrideRoles[$class][$project->id ?? 0] ?? [$builtin];
    }

    /** @return list<Role> */
    public function rolesForProject(?Project $project, ?User $user = null): array
    {
        $user = $this->user($user);
        if (null === $project || $project->isArchived()) {
            return [];
        }
        if ($m = $this->membership($user, $project)) {
            return $m->getRoles();
        }
        if ($project->isPublic) {
            return $this->overrideRoles($project, $this->builtinRole($user));
        }

        return [];
    }

    /**
     * User#roles | [builtin_role] (User#allowed_to? with :global => true): the roles of the user's memberships
     * in non archived projects or, when there is none, the roles given to the user's built-in group
     * (Anonymous / Non member users) on public projects; plus the built-in role.
     *
     * @return list<Role>
     */
    public function allRoles(?User $user = null): array
    {
        $user = $this->user($user);
        $roles = [];
        foreach ($this->membershipsOf($user) as $m) {
            if ($m->project->isArchived()) {
                continue;
            }
            foreach ($m->getRoles() as $r) {
                $roles[$r->id] = $r;
            }
        }
        $b = $this->builtinRole($user);
        if ([] === $roles) {
            $info = $this->projectInfo();
            $this->overrideRoles(new Project(), $b);
            $class = Role::BUILTIN_ANONYMOUS === $b->builtin ? GroupAnonymous::class : GroupNonMember::class;
            foreach ($this->overrideRoles[$class] ?? [] as $projectId => $groupRoles) {
                if (!($info[$projectId]['public'] ?? false) || Project::STATUS_ARCHIVED === ($info[$projectId]['status'] ?? null)) {
                    continue;
                }
                foreach ($groupRoles as $r) {
                    $roles[$r->id] = $r;
                }
            }
        }
        $roles[$b->id] = $b;

        return array_values($roles);
    }

    /** Role#allowed_to?(permission, user.oauth_scope): the OAuth scope (if any) filters the role permissions */
    public function roleAllowedTo(Role $role, string $permission, ?User $user = null): bool
    {
        if (!$role->hasPermission($permission) && !AccessControl::isPublic($permission)) {
            return false;
        }
        $scope = $this->oauthScope($user);

        return null === $scope || \in_array($permission, $scope, true);
    }

    /** Project#allows_to? */
    public function projectAllowsTo(Project $project, string $permission): bool
    {
        if ($project->isArchived()) {
            return false;
        }
        if (!$project->isActive() && !AccessControl::isRead($permission)) {
            return false;
        }
        $module = AccessControl::moduleOf($permission);
        if (null === $module) {
            return true;
        }
        // a page checks permissions on every project it lists: their modules are loaded together
        $this->batch->collection($project, 'enabledModules');

        return $project->isModuleEnabled($module);
    }

    /**
     * User#allowed_to?. With a project: checks the user's roles on that project.
     * Without a project and $global = true: checks if any of the user's roles has the permission.
     *
     * @param callable(Role, User): bool|null $block additional per role check
     */
    public function allowedTo(string $permission, ?Project $project = null, ?User $user = null, bool $global = false, ?callable $block = null): bool
    {
        $user = $this->user($user);
        if (null !== $project) {
            if (!$this->projectAllowsTo($project, $permission)) {
                return false;
            }
            if ($user->admin) {
                return true;
            }
            foreach ($this->rolesForProject($project, $user) as $role) {
                if (($project->isPublic || $role->isMember())
                    && $this->roleAllowedTo($role, $permission, $user)
                    && (null === $block || $block($role, $user))) {
                    return true;
                }
            }

            return false;
        }
        if ($global) {
            if ($user->admin) {
                return true;
            }
            foreach ($this->allRoles($user) as $role) {
                if ($this->roleAllowedTo($role, $permission, $user) && (null === $block || $block($role, $user))) {
                    return true;
                }
            }
        }

        return false;
    }

    public function allowedToGlobally(string $permission, ?User $user = null, ?callable $block = null): bool
    {
        return $this->allowedTo($permission, null, $user, true, $block);
    }

    /** @return array<int, array{status: int, public: bool, modules: list<string>}> */
    private function projectInfo(): array
    {
        if (null === $this->projectInfo) {
            $this->projectInfo = [];
            $conn = $this->em->getConnection();
            foreach ($conn->fetchAllAssociative('SELECT id, status, is_public FROM projects') as $row) {
                $this->projectInfo[(int) $row['id']] = ['status' => (int) $row['status'], 'public' => (bool) $row['is_public'], 'modules' => []];
            }
            foreach ($conn->fetchAllAssociative('SELECT project_id, name FROM enabled_modules WHERE project_id IS NOT NULL') as $row) {
                if (isset($this->projectInfo[(int) $row['project_id']])) {
                    $this->projectInfo[(int) $row['project_id']]['modules'][] = $row['name'];
                }
            }
        }

        return $this->projectInfo;
    }

    /** Ids of projects where the permission is possible at all (status + module), regardless of user */
    private function eligibleProjectIds(string $permission, bool $skipPreCondition = false): array
    {
        $key = $permission.($skipPreCondition ? '#skip' : '');
        if (!isset($this->eligibleProjects[$key])) {
            $module = AccessControl::moduleOf($permission);
            $ids = [];
            foreach ($this->projectInfo() as $id => $p) {
                if (Project::STATUS_ARCHIVED === $p['status'] || Project::STATUS_SCHEDULED_FOR_DELETION === $p['status']) {
                    continue;
                }
                if (!$skipPreCondition) {
                    if (Project::STATUS_ACTIVE !== $p['status'] && !AccessControl::isRead($permission)) {
                        continue;
                    }
                    if (null !== $module && !\in_array($module, $p['modules'], true)) {
                        continue;
                    }
                }
                $ids[] = $id;
            }
            $this->eligibleProjects[$key] = $ids;
        }

        return $this->eligibleProjects[$key];
    }

    /**
     * Port of User#project_ids_by_role.
     *
     * @return array<int, array{role: Role, projects: list<int>}>
     */
    public function projectIdsByRole(?User $user = null): array
    {
        $user = $this->user($user);
        $out = [];
        $memberProjectIds = [];
        foreach ($this->membershipsOf($user) as $projectId => $m) {
            if ($m->project->isArchived()) {
                continue;
            }
            $memberProjectIds[] = $projectId;
            foreach ($m->getRoles() as $role) {
                $out[$role->id] ??= ['role' => $role, 'projects' => []];
                $out[$role->id]['projects'][] = $projectId;
            }
        }
        // roles given to the builtin group (non member / anonymous) on public projects
        $builtin = $this->builtinRole($user);
        $info = $this->projectInfo();
        $this->overrideRoles(new Project(), $builtin);
        $class = Role::BUILTIN_ANONYMOUS === $builtin->builtin ? GroupAnonymous::class : GroupNonMember::class;
        foreach ($this->overrideRoles[$class] ?? [] as $projectId => $roles) {
            if (\in_array($projectId, $memberProjectIds, true) || !($info[$projectId]['public'] ?? false)) {
                continue;
            }
            foreach ($roles as $role) {
                $out[$role->id] ??= ['role' => $role, 'projects' => []];
                $out[$role->id]['projects'][] = $projectId;
            }
        }

        return $out;
    }

    /**
     * Port of Project.allowed_to_condition: returns a DQL condition restricting $projectIdExpr
     * (e.g. "IDENTITY(i.project)" or "p.id") to projects where the user has the permission.
     *
     * @param callable(Role, User): (string|null)|null $roleCondition extra DQL condition per role
     */
    public function allowedToCondition(string $permission, string $projectIdExpr, ?User $user = null, ?callable $roleCondition = null, bool $memberOnly = false, bool $skipPreCondition = false): string
    {
        $user = $this->user($user);
        $eligible = $this->eligibleProjectIds($permission, $skipPreCondition);
        if ([] === $eligible) {
            return '1=0';
        }
        if ($user->admin) {
            return self::in($projectIdExpr, $eligible);
        }
        $info = $this->projectInfo();
        $statements = [];
        if (!$memberOnly) {
            $role = $this->builtinRole($user);
            if ($this->roleAllowedTo($role, $permission, $user)) {
                $memberIds = array_keys($this->membershipsOf($user));
                $overridden = [];
                $this->overrideRoles(new Project(), $role);
                $class = Role::BUILTIN_ANONYMOUS === $role->builtin ? GroupAnonymous::class : GroupNonMember::class;
                $overridden = array_keys($this->overrideRoles[$class] ?? []);
                $ids = array_values(array_filter($eligible, static fn (int $id) => $info[$id]['public'] && !\in_array($id, $memberIds, true) && !\in_array($id, $overridden, true)));
                if ([] !== $ids) {
                    $statements[] = [$role, $ids];
                }
            }
        }
        foreach ($this->projectIdsByRole($user) as ['role' => $role, 'projects' => $projectIds]) {
            if ($this->roleAllowedTo($role, $permission, $user)) {
                $ids = array_values(array_intersect($projectIds, $eligible));
                if ([] !== $ids) {
                    $statements[] = [$role, $ids];
                }
            }
        }
        if ([] === $statements) {
            return '1=0';
        }
        $parts = [];
        foreach ($statements as [$role, $ids]) {
            $s = self::in($projectIdExpr, $ids);
            if (null !== $roleCondition) {
                $extra = $roleCondition($role, $user);
                if (null !== $extra && '' !== $extra) {
                    $s = "($s AND ($extra))";
                }
            }
            $parts[] = $s;
        }

        return '('.implode(' OR ', $parts).')';
    }

    /** Project ids the user can see (Project.visible) */
    public function visibleProjectIds(?User $user = null): array
    {
        $user = $this->user($user);
        $eligible = $this->eligibleProjectIds('view_project');
        if ($user->admin) {
            return $eligible;
        }
        $info = $this->projectInfo();
        $memberships = $this->membershipsOf($user);

        return array_values(array_filter($eligible, static fn (int $id) => $info[$id]['public'] || isset($memberships[$id])));
    }

    /** Whether one of the projects the user can see has the module enabled (application menu) */
    public function anyVisibleProjectWithModule(string $module, ?User $user = null): bool
    {
        $info = $this->projectInfo();
        foreach ($this->visibleProjectIds($user) as $id) {
            if (\in_array($module, $info[$id]['modules'], true)) {
                return true;
            }
        }

        return false;
    }

    public function isProjectVisible(Project $project, ?User $user = null): bool
    {
        return $this->allowedTo('view_project', $project, $user);
    }

    /** @param list<int> $ids */
    public static function in(string $expr, array $ids): string
    {
        if ([] === $ids) {
            return '1=0';
        }

        return $expr.' IN ('.implode(',', array_map('intval', array_unique($ids))).')';
    }
}
