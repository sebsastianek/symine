<?php

declare(strict_types=1);

namespace Symine\Hello\Search;

use App\Entity\Project;
use App\Entity\User;
use App\Plugin\Search\SearchProviderInterface;
use App\Security\Authorization;
use Doctrine\ORM\EntityManagerInterface;
use Symine\Hello\Activity\GreetingActivityProvider;
use Symine\Hello\Entity\Greeting;
use Symine\Hello\HelloSchema;

/** Redmine::Search.register :hello_greetings (acts_as_searchable :columns => ['message']) */
final class GreetingSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly HelloSchema $schema,
    ) {
    }

    public function getSearchType(): string
    {
        return 'hello_greetings';
    }

    public function resultRanksAndIds(array $tokens, User $user, ?array $projects, array $options): array
    {
        if (!$this->schema->isInstalled()) {
            return [];
        }
        $qb = $this->em->createQueryBuilder()->select('g.createdOn AS rank_date, g.id AS rank_id')->from(Greeting::class, 'g')->join('g.project', 'p')
            ->andWhere($this->auth->allowedToCondition('view_hello_greetings', 'p.id', $user));
        if (null !== $projects) {
            $qb->andWhere(Authorization::in('p.id', array_map(static fn (Project $p) => (int) $p->id, $projects)));
        }
        $parts = [];
        foreach (array_values($tokens) as $i => $token) {
            $qb->setParameter('t'.$i, '%'.mb_strtolower(str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $token)).'%');
            $parts[] = "LOWER(g.message) LIKE :t$i ESCAPE '!'";
        }
        if ([] !== $parts) {
            $qb->andWhere(implode(($options['all_words'] ?? true) ? ' AND ' : ' OR ', $parts));
        }

        return array_map(static fn (array $r) => [$r['rank_date'] instanceof \DateTimeInterface ? $r['rank_date']->getTimestamp() : (int) strtotime((string) $r['rank_date']), (int) $r['rank_id']], $qb->getQuery()->getArrayResult());
    }

    public function results(array $ids): array
    {
        $out = [];
        foreach ($this->em->getRepository(Greeting::class)->findBy(['id' => $ids]) as $g) {
            $out[(int) $g->id] = GreetingActivityProvider::event($g);
        }

        return $out;
    }
}
