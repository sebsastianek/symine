<?php

declare(strict_types=1);

namespace App\Service\Tracking\Watchers;

use App\Entity\AnonymousUser;
use App\Entity\Group;
use App\Entity\GroupBuiltin;
use App\Entity\Issue;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\User;
use App\Security\Authorization;
use App\Service\IssueService;
use App\Service\ProjectService;
use App\Service\WatcherService;
use App\Twig\IssuesExtension;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The users and groups that can be added as watchers: the members of the project, or the active principals
 * matching a search (WatchersController#users_for_new_watcher, Principal.assignable_watchers).
 */
final class WatcherCandidates
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly ProjectService $projects,
        private readonly IssueService $issues,
        private readonly WatcherService $watchers,
        private readonly IssuesExtension $helpers,
    ) {
    }

    /**
     * Those that can be offered for the objects of the request: not watching yet (for a single object), matching
     * the search and valid watchers of every object.
     *
     * @return list<Principal>
     */
    public function search(WatcherContext $context, string $q): array
    {
        if ('' === $q) {
            if ($context->project) {
                $candidates = $this->ofProject($context->project);
            } elseif (\count($context->projects) > 1) {
                $candidates = [];
                foreach ($context->projects as $p) {
                    foreach ($this->ofProject($p) as $u) {
                        $candidates[$u->id] = $u;
                    }
                }
                $candidates = array_values($candidates);
            } else {
                $candidates = [];
            }
        } else {
            $candidates = $this->matching($q);
        }
        if ($context->isSingle()) {
            $w = $context->watchables[0];
            $existing = array_map(static fn ($p) => $p->id, $this->watchers->watchers(IssuesExtension::watchableClassName($w), (int) $w->id));
            $candidates = array_values(array_filter($candidates, static fn ($u) => !\in_array($u->id, $existing, true)));
        }
        if ('' !== $q) {
            $candidates = array_values(array_filter($candidates, fn ($u) => $this->matches($u, $q)));
        }

        return array_values(array_filter($candidates, function ($u) use ($context) {
            foreach ($context->watchables as $w) {
                if (!$this->isValid($w, $u)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** Principal.assignable_watchers: active users and groups (not builtin) @return list<Principal> */
    public function assignable(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->em->createQuery('SELECT p FROM App\Entity\Principal p WHERE p.id IN (:ids) AND p.status = 1 AND (p INSTANCE OF App\Entity\User OR p INSTANCE OF App\Entity\Group) AND p NOT INSTANCE OF App\Entity\AnonymousUser AND p NOT INSTANCE OF App\Entity\GroupBuiltin')
            ->setParameter('ids', $ids)->getResult();
    }

    /** @return list<Principal> */
    public function ofProject(Project $project): array
    {
        $members = array_values(array_filter($this->projects->members($project), static fn ($p) => ($p instanceof User && !$p instanceof AnonymousUser) || ($p instanceof Group && !$p instanceof GroupBuiltin)));

        return $this->sort($members);
    }

    /** @return list<Principal> */
    public function matching(string $q): array
    {
        $like = '%'.mb_strtolower($q).'%';
        $rows = $this->em->createQuery('SELECT p FROM App\Entity\Principal p WHERE p.status = 1 AND (p INSTANCE OF App\Entity\User OR p INSTANCE OF App\Entity\Group) AND p NOT INSTANCE OF App\Entity\AnonymousUser AND p NOT INSTANCE OF App\Entity\GroupBuiltin
            AND (LOWER(p.login) LIKE :q OR LOWER(p.firstname) LIKE :q OR LOWER(p.lastname) LIKE :q OR LOWER(CONCAT(p.firstname, CONCAT(\' \', p.lastname))) LIKE :q)')
            ->setParameter('q', $like)->getResult();

        return $this->sort($rows);
    }

    public function matches(Principal $u, string $q): bool
    {
        $q = mb_strtolower($q);
        foreach ([$u->login, $u->firstname, $u->lastname, $u->getName(), $u instanceof User ? (string) $u->getMail() : ''] as $s) {
            if (str_contains(mb_strtolower((string) $s), $q)) {
                return true;
            }
        }

        return false;
    }

    /** Watchable#valid_watcher? (issues: the user must see the issue; groups always valid) */
    public function isValid(object $watchable, Principal $p): bool
    {
        if ($watchable instanceof Issue && $p instanceof User) {
            return $this->issues->isVisible($watchable, $p);
        }
        $project = $this->helpers->watchableProject($watchable);
        if ($p instanceof User && null !== $project) {
            return $this->auth->isProjectVisible($project, $p);
        }

        return true;
    }

    /** @param list<Principal> $list @return list<Principal> */
    private function sort(array $list): array
    {
        usort($list, static fn (Principal $a, Principal $b) => [$a instanceof Group ? 1 : 0, mb_strtolower($a->getName())] <=> [$b instanceof Group ? 1 : 0, mb_strtolower($b->getName())]);

        return $list;
    }
}
