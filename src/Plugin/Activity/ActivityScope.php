<?php

declare(strict_types=1);

namespace App\Plugin\Activity;

use App\Entity\Project;
use App\Entity\User;
use App\Security\Authorization;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Options of an activity fetch (Redmine find_events options) with the helpers used by the core providers.
 */
#[Exclude]
final class ActivityScope
{
    public function __construct(
        public readonly User $user,
        public readonly ?Project $project,
        public readonly bool $withSubprojects,
        public readonly ?User $author,
        public readonly ?\DateTimeInterface $from,
        public readonly ?\DateTimeInterface $to,
        public readonly ?int $limit,
        private readonly Authorization $auth,
    ) {
    }

    /** DQL condition: the user has the permission in the project whose id is $projectIdExpr (Project.allowed_to_condition) */
    public function allowedToCondition(string $permission, string $projectIdExpr = 'p.id'): string
    {
        return $this->auth->allowedToCondition($permission, $projectIdExpr, $this->user);
    }

    /** DQL restriction to the project (and its subprojects), null for a global fetch */
    public function projectCondition(string $projectAlias = 'p'): ?string
    {
        if (null === $this->project) {
            return null;
        }
        $s = "$projectAlias.id = ".(int) $this->project->id;
        if ($this->withSubprojects) {
            $s = "($s OR ($projectAlias.lft > ".(int) $this->project->lft." AND $projectAlias.rgt < ".(int) $this->project->rgt.'))';
        }

        return $s;
    }

    /**
     * Applies the common find_events options to a query builder joined to the project as $projectAlias:
     * date range on $timestamp, author, limit (most recent ids), visibility condition and project scope.
     * Returns null when an author is requested but the provider has no author key.
     */
    public function apply(QueryBuilder $qb, string $timestamp, ?string $authorKey, string $visibility, string $idExpr, string $projectAlias = 'p'): ?QueryBuilder
    {
        if (null !== $this->from) {
            $qb->andWhere("$timestamp >= :activity_from")->setParameter('activity_from', $this->from->format('Y-m-d'));
        }
        if (null !== $this->to) {
            $qb->andWhere("$timestamp <= :activity_to")->setParameter('activity_to', $this->to->format('Y-m-d'));
        }
        if (null !== $this->author) {
            if (null === $authorKey) {
                return null;
            }
            $qb->andWhere("$authorKey = :activity_author")->setParameter('activity_author', $this->author->id);
        }
        if (null !== $this->limit) {
            $qb->orderBy($idExpr, 'DESC')->setMaxResults($this->limit);
        }
        $qb->andWhere($visibility);
        if ($pc = $this->projectCondition($projectAlias)) {
            $qb->andWhere($pc);
        }

        return $qb;
    }
}
