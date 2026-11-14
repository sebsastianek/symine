<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Version;
use App\Security\Authorization;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;

/** Project related domain logic (port of parts of Redmine's Project model). */
final class ProjectService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly Settings $settings,
        private readonly ?\App\Doctrine\QueryMemo $memo = null,
    ) {
    }

    public function findByIdentifierOrId(string|int $id): ?Project
    {
        $repo = $this->em->getRepository(Project::class);
        if (\is_int($id) || ctype_digit($id)) {
            return $repo->find((int) $id) ?? $repo->findOneBy(['identifier' => (string) $id]);
        }

        return $repo->findOneBy(['identifier' => $id]);
    }

    /** @return list<Project> visible projects sorted as a tree (lft) */
    public function visibleProjects(?User $user = null): array
    {
        $ids = $this->auth->visibleProjectIds($user);
        if ([] === $ids) {
            return [];
        }

        return $this->em->createQuery('SELECT p FROM App\Entity\Project p WHERE p.id IN (:ids) ORDER BY p.lft ASC, p.name ASC')
            ->setParameter('ids', $ids)->getResult();
    }

    public function anyVisibleWithModule(string $module): bool
    {
        // the authorization already knows the modules of every project: no query per menu item
        return $this->auth->anyVisibleProjectWithModule($module);
    }

    /** @return list<Tracker> Issue.allowed_target_trackers */
    public function allowedTargetTrackers(Project $project, ?User $user = null, ?int $currentTrackerId = null): array
    {
        $user ??= $this->currentUser->get();
        $trackers = $project->trackers->toArray();
        usort($trackers, static fn (Tracker $a, Tracker $b) => $a->position <=> $b->position);
        if ($user->admin) {
            return $trackers;
        }
        $roles = array_filter($this->auth->rolesForProject($project, $user), fn (Role $r) => $this->auth->roleAllowedTo($r, 'add_issues'));
        foreach ($roles as $r) {
            if ($r->permissionsAllTrackers('add_issues')) {
                return $trackers;
            }
        }
        $ids = [];
        foreach ($roles as $r) {
            array_push($ids, ...$r->permissionsTrackerIds('add_issues'));
        }
        if (null !== $currentTrackerId) {
            $ids[] = $currentTrackerId;
        }

        return array_values(array_filter($trackers, static fn (Tracker $t) => \in_array($t->id, $ids, true)));
    }

    /** @return list<Project> Issue.allowed_target_projects */
    public function allowedTargetProjects(?User $user = null, ?Project $current = null): array
    {
        $cond = $this->auth->allowedToCondition('add_issues', 'p.id', $user);
        if (null !== $current) {
            $cond = "($cond) OR p.id = ".(int) $current->id;
        }

        return $this->em->createQuery("SELECT p FROM App\Entity\Project p WHERE ($cond) AND SIZE(p.trackers) > 0 ORDER BY p.lft ASC")->getResult();
    }

    /**
     * Users (and groups if enabled) the issues of the project can be assigned to.
     *
     * @return list<Principal>
     */
    public function assignableUsers(Project $project, ?Tracker $tracker = null): array
    {
        $types = ['App\Entity\User'];
        $dql = 'SELECT DISTINCT pr FROM App\Entity\Principal pr JOIN pr.memberships m JOIN m.memberRoles mr JOIN mr.role r
                WHERE m.project = :p AND r.assignable = true AND pr.status = :active AND (pr INSTANCE OF App\Entity\User';
        if ($this->settings->bool('issue_group_assignment')) {
            $dql .= ' OR pr INSTANCE OF App\Entity\Group';
        }
        $dql .= ') AND pr NOT INSTANCE OF App\Entity\AnonymousUser AND pr NOT INSTANCE OF App\Entity\GroupBuiltin';
        $params = ['p' => $project->id, 'active' => Principal::STATUS_ACTIVE];
        if (null !== $tracker) {
            $roleIds = [];
            foreach ($this->em->getRepository(Role::class)->findBy(['assignable' => true]) as $role) {
                if ($role->allowedTracker('view_issues', (int) $tracker->id)) {
                    $roleIds[] = $role->id;
                }
            }
            if ([] === $roleIds) {
                return [];
            }
            $dql .= ' AND r.id IN (:roles)';
            $params['roles'] = $roleIds;
        }
        $users = $this->em->createQuery($dql)->setParameters($params)->getResult();
        usort($users, static fn (Principal $a, Principal $b) => [$a instanceof User ? 0 : 1, mb_strtolower($a->getName())] <=> [$b instanceof User ? 0 : 1, mb_strtolower($b->getName())]);
        unset($types);

        return $users;
    }

    /** @return list<Principal> members of the project (active users and groups) */
    public function members(Project $project): array
    {
        return $this->em->createQuery(
            'SELECT pr FROM App\Entity\Principal pr JOIN pr.memberships m WHERE m.project = :p AND pr.status = 1
             AND pr NOT INSTANCE OF App\Entity\GroupBuiltin ORDER BY pr.firstname, pr.lastname'
        )->setParameter('p', $project->id)->getResult();
    }

    /** @return list<Version> Project#shared_versions */
    public function sharedVersions(Project $project): array
    {
        if (null === $project->id) {
            return $this->em->createQuery("SELECT v, p FROM App\Entity\Version v JOIN v.project p WHERE p.status <> 9 AND v.sharing = 'system'")->getResult();
        }
        if (null !== $this->memo) {
            // the menu, the issue form and the sidebar of a page ask for the versions of the same project
            return $this->memo->get('shared_versions:'.$project->id, ['versions', 'projects'], fn (): array => $this->loadSharedVersions($project));
        }

        return $this->loadSharedVersions($project);
    }

    /** @return list<Version> */
    private function loadSharedVersions(Project $project): array
    {
        $root = $project->getRoot();
        $dql = "SELECT v, p FROM App\Entity\Version v JOIN v.project p WHERE p.id = :id OR (p.status <> 9 AND (
                  v.sharing = 'system'
                  OR (p.lft >= :rl AND p.rgt <= :rr AND v.sharing = 'tree')
                  OR (p.lft < :l AND p.rgt > :r AND v.sharing IN ('hierarchy', 'descendants'))
                  OR (p.lft > :l AND p.rgt < :r AND v.sharing = 'hierarchy')))";
        $versions = $this->em->createQuery($dql)->setParameters([
            'id' => $project->id, 'rl' => $root->lft ?? 0, 'rr' => $root->rgt ?? 0, 'l' => $project->lft ?? 0, 'r' => $project->rgt ?? 0,
        ])->getResult();

        return $this->sortVersions($versions);
    }

    /** @return list<Version> versions of the project and its descendants (Project#rolled_up_versions) */
    public function rolledUpVersions(Project $project): array
    {
        $versions = $this->em->createQuery('SELECT v, p FROM App\Entity\Version v JOIN v.project p WHERE p.lft >= :l AND p.rgt <= :r AND p.status <> 9')
            ->setParameters(['l' => $project->lft ?? 0, 'r' => $project->rgt ?? 0])->getResult();

        return $this->sortVersions($versions);
    }

    /** @param list<Version> $versions @return list<Version> Version#<=> (effective date, then name) */
    public function sortVersions(array $versions): array
    {
        usort($versions, static function (Version $a, Version $b): int {
            if ($a->effectiveDate && $b->effectiveDate) {
                return [$a->effectiveDate, $a->project->name, $a->name] <=> [$b->effectiveDate, $b->project->name, $b->name];
            }
            if ($a->effectiveDate) {
                return -1;
            }
            if ($b->effectiveDate) {
                return 1;
            }

            return [$a->project->name, mb_strtolower((string) $a->name)] <=> [$b->project->name, mb_strtolower((string) $b->name)];
        });

        return $versions;
    }

    public function hasRoadmap(Project $project): bool
    {
        if ([] !== $this->sharedVersions($project)) {
            return true;
        }

        return $this->settings->displaySubprojectsIssues() && [] !== $this->rolledUpVersions($project);
    }

    public function hasWiki(Project $project): bool
    {
        return null !== $this->em->getRepository(\App\Entity\Wiki::class)->findOneBy(['project' => $project]);
    }

    public function hasBoards(Project $project): bool
    {
        return null !== $this->em->getRepository(\App\Entity\Board::class)->findOneBy(['project' => $project]);
    }

    public function hasRepository(Project $project): bool
    {
        return null !== $this->em->getRepository(\App\Entity\Repository::class)->findOneBy(['project' => $project]);
    }

    public function canSeeSettings(Project $project): bool
    {
        foreach (['edit_project', 'select_project_modules', 'manage_members', 'manage_versions', 'manage_categories', 'manage_repository', 'manage_boards', 'manage_project_activities', 'manage_wiki'] as $perm) {
            if ($this->auth->allowedTo($perm, $project)) {
                return true;
            }
        }

        return false;
    }

    /** Valid identifier, same rules as Redmine */
    public function isIdentifierValid(string $identifier): bool
    {
        return (bool) preg_match('/\A(?!\d+$)[a-z0-9\-_]*\z/', $identifier)
            && \strlen($identifier) >= 1 && \strlen($identifier) <= Project::IDENTIFIER_MAX_LENGTH
            && !\in_array($identifier, ['new', 'issues'], true);
    }

    /** Rebuilds the projects nested set (lft/rgt), ordered by name like Redmine. */
    public function rebuildTree(): void
    {
        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative('SELECT id, parent_id, name FROM projects');
        $children = [];
        foreach ($rows as $r) {
            $children[(int) ($r['parent_id'] ?? 0)][] = $r;
        }
        foreach ($children as &$list) {
            usort($list, static fn ($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));
        }
        unset($list);
        $counter = 0;
        $updates = [];
        $walk = function (int $parentId) use (&$walk, &$counter, &$updates, $children): void {
            foreach ($children[$parentId] ?? [] as $r) {
                $lft = ++$counter;
                $walk((int) $r['id']);
                $updates[(int) $r['id']] = [$lft, ++$counter];
            }
        };
        $walk(0);
        foreach ($updates as $id => [$l, $r]) {
            $conn->executeStatement('UPDATE projects SET lft = ?, rgt = ? WHERE id = ?', [$l, $r, $id]);
        }
        foreach ($this->em->getUnitOfWork()->getIdentityMap()[Project::class] ?? [] as $p) {
            if (isset($updates[$p->id])) {
                [$p->lft, $p->rgt] = $updates[$p->id];
            }
        }
    }

    /** Default modules / trackers for new projects (settings default_projects_modules, default_projects_tracker_ids) */
    public function applyDefaults(Project $project): void
    {
        $project->isPublic = $this->settings->bool('default_projects_public');
        $project->setEnabledModuleNames(array_map('strval', $this->settings->array('default_projects_modules')));
        $trackerIds = array_map('intval', $this->settings->array('default_projects_tracker_ids'));
        $trackers = [] === $trackerIds
            ? $this->em->getRepository(Tracker::class)->findAll()
            : $this->em->getRepository(Tracker::class)->findBy(['id' => $trackerIds]);
        foreach ($trackers as $t) {
            if (!$project->hasTracker($t)) {
                $project->trackers->add($t);
            }
        }
    }

    /** Next identifier suggestion (setting sequential_project_identifiers) */
    public function nextIdentifier(): ?string
    {
        $last = $this->em->getConnection()->fetchOne('SELECT identifier FROM projects ORDER BY id DESC LIMIT 1');

        return \is_string($last) ? (str_increment($last) ?: null) : null;
    }
}
