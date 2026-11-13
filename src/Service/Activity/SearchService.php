<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\CustomField;
use App\Entity\Project;
use App\Entity\User;
use App\Plugin\Search\SearchProviderRegistry;
use App\Security\Authorization;
use App\Service\IssueService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Port of Redmine::Search (Fetcher, Tokenizer) and acts_as_searchable#search_result_ranks_and_ids
 * for the registered search types.
 */
final class SearchService
{
    /** Redmine::Search.available_search_types */
    public const array TYPES = ['issues', 'news', 'documents', 'changesets', 'wiki_pages', 'messages', 'projects'];

    public const int MAX_TOKENS = 5;

    /**
     * Searchable definitions: entity, alias, title+other columns, date column, project key, custom field
     * type (customized_type) for searchable custom fields, attachment container type, journals.
     */
    private const array DEFS = [
        'issues' => ['entity' => 'App\Entity\Issue', 'alias' => 'i', 'columns' => ['i.subject', 'i.description'], 'date' => 'i.createdOn', 'project' => 'p.id', 'cf' => ['Issue', 'IssueCustomField'], 'attachments' => 'Issue', 'journals' => true],
        'news' => ['entity' => 'App\Entity\News', 'alias' => 'n', 'columns' => ['n.title', 'n.summary', 'n.description'], 'date' => 'n.createdOn', 'project' => 'p.id', 'cf' => null, 'attachments' => 'News', 'journals' => false],
        'documents' => ['entity' => 'App\Entity\Document', 'alias' => 'd', 'columns' => ['d.title', 'd.description'], 'date' => 'd.createdOn', 'project' => 'p.id', 'cf' => ['Document', 'DocumentCustomField'], 'attachments' => 'Document', 'journals' => false],
        'changesets' => ['entity' => 'App\Entity\Changeset', 'alias' => 'c', 'columns' => ['c.comments'], 'date' => 'c.committedOn', 'project' => 'p.id', 'cf' => null, 'attachments' => null, 'journals' => false],
        'wiki_pages' => ['entity' => 'App\Entity\WikiPage', 'alias' => 'wp', 'columns' => ['wp.title', 'wc.text'], 'date' => 'wp.createdOn', 'project' => 'p.id', 'cf' => null, 'attachments' => 'WikiPage', 'journals' => false],
        'messages' => ['entity' => 'App\Entity\Message', 'alias' => 'm', 'columns' => ['m.subject', 'm.content'], 'date' => 'm.createdOn', 'project' => 'p.id', 'cf' => null, 'attachments' => 'Message', 'journals' => false],
        'projects' => ['entity' => 'App\Entity\Project', 'alias' => 'p', 'columns' => ['p.name', 'p.identifier', 'p.description'], 'date' => 'p.createdOn', 'project' => 'p.id', 'cf' => ['Project', 'ProjectCustomField'], 'attachments' => 'Project', 'journals' => false],
    ];

    private int $param = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly IssueService $issues,
        private readonly EventBuilder $builder,
        private readonly ?SearchProviderRegistry $providers = null,
    ) {
    }

    /**
     * Redmine::Search.available_search_types: core types followed by the plugin providers' types.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return array_values(array_unique([...self::TYPES, ...array_keys($this->providers?->all() ?? [])]));
    }

    /**
     * Redmine::Search::Tokenizer: "hello "bye bye"" => ["hello", "bye bye"]; tokens of at least 2 chars
     * (or one Han char), max 5 tokens.
     *
     * @return list<string>
     */
    public static function tokens(string $question): array
    {
        preg_match_all('/"[^"]+"|[^\p{Zs}\s]+/u', $question, $m);
        $tokens = [];
        foreach ($m[0] as $t) {
            $t = preg_replace('/\A"\p{Zs}*|\p{Zs}*"\z/u', '', $t) ?? $t;
            if ((mb_strlen($t) > 1 || preg_match('/\p{Han}/u', $t)) && !\in_array($t, $tokens, true)) {
                $tokens[] = $t;
            }
        }

        return \array_slice($tokens, 0, self::MAX_TOKENS);
    }

    /**
     * Search result ranks and ids for the given types, sorted by rank (date) and id desc.
     *
     * @param list<string>       $scope    search types
     * @param list<Project>|null $projects null = all projects
     * @param array{all_words?: bool, titles_only?: bool, attachments?: string, open_issues?: bool} $options
     *
     * @return list<array{0: string, 1: int}> [type, id]
     */
    public function resultIds(array $tokens, User $user, array $scope, ?array $projects, array $options = []): array
    {
        $ret = [];
        foreach ($scope as $type) {
            if (!isset(self::DEFS[$type])) {
                if (null !== ($provider = $this->providers?->get($type))) {
                    if (null !== $projects && [] === $projects) {
                        continue;
                    }
                    foreach ($provider->resultRanksAndIds($tokens, $user, $projects, $options) as [$rank, $id]) {
                        $ret[] = [$type, (int) $rank, (int) $id];
                    }
                }
                continue;
            }
            foreach ($this->rankAndIds($type, $tokens, $user, $projects, $options) as [$rank, $id]) {
                $ret[] = [$type, $rank, $id];
            }
        }
        usort($ret, static fn (array $a, array $b) => [$b[1], $b[2]] <=> [$a[1], $a[2]]);

        return array_map(static fn (array $r) => [$r[0], $r[2]], $ret);
    }

    /**
     * @param list<array{0: string, 1: int}> $ids
     *
     * @return list<ActivityEvent>
     */
    public function results(array $ids): array
    {
        $byType = [];
        foreach ($ids as [$type, $id]) {
            $byType[$type][] = $id;
        }
        $records = [];
        $provided = [];
        foreach ($byType as $type => $typeIds) {
            if (!isset(self::DEFS[$type])) {
                $provided[$type] = $this->providers?->get($type)?->results($typeIds) ?? [];
                continue;
            }
            $entity = self::DEFS[$type]['entity'];
            foreach ($this->em->getRepository($entity)->findBy(['id' => $typeIds]) as $r) {
                $records[$type][$r->id] = $r;
            }
        }
        $events = [];
        foreach ($ids as [$type, $id]) {
            if (isset($provided[$type])) {
                if (isset($provided[$type][$id])) {
                    $events[] = $provided[$type][$id];
                }
                continue;
            }
            $r = $records[$type][$id] ?? null;
            if (null === $r) {
                continue;
            }
            $events[] = match ($type) {
                'issues' => $this->builder->issue($r),
                'news' => $this->builder->news($r),
                'documents' => $this->builder->document($r),
                'changesets' => $this->builder->changeset($r),
                'wiki_pages' => $this->builder->wikiPage($r),
                'messages' => $this->builder->message($r),
                'projects' => $this->builder->project($r),
            };
        }

        return $events;
    }

    /** @return list<array{0: int, 1: int}> */
    private function rankAndIds(string $type, array $tokens, User $user, ?array $projects, array $options): array
    {
        if (null !== $projects && [] === $projects) {
            return [];
        }
        $def = self::DEFS[$type];
        $columns = $def['columns'];
        $titlesOnly = (bool) ($options['titles_only'] ?? false);
        $allWords = (bool) ($options['all_words'] ?? true);
        $attachments = (string) ($options['attachments'] ?? '0');
        if ($titlesOnly) {
            $columns = [$columns[0]];
        }
        $r = [];
        $queries = 0;
        if ('only' !== $attachments) {
            $qb = $this->scope($type, $user, $projects, $options);
            $this->tokensCondition($qb, $columns, $tokens, $allWords);
            $r = $this->fetch($qb, $def);
            ++$queries;
            if (!$titlesOnly && null !== $def['cf']) {
                $fields = $this->em->getRepository(CustomField::class)->findBy(['searchable' => true]);
                $fields = array_values(array_filter($fields, static fn (CustomField $f) => $f::customizedType() === $def['cf'][0]));
                if ([] !== $fields) {
                    $clauses = [];
                    foreach ($fields as $f) {
                        $clauses[] = '(IDENTITY(cv.customField) = '.(int) $f->id.' AND ('.$this->fieldVisibility($f, $user, $def['project']).'))';
                    }
                    $qb = $this->scope($type, $user, $projects, $options);
                    $qb->join('App\Entity\CustomValue', 'cv', 'WITH', "cv.customizedType = '".$def['cf'][0]."' AND cv.customizedId = ".$def['alias'].'.id')
                        ->andWhere(implode(' OR ', $clauses));
                    $this->tokensCondition($qb, ['cv.value'], $tokens, $allWords);
                    $r = self::union($r, $this->fetch($qb, $def));
                    ++$queries;
                }
            }
            if (!$titlesOnly && $def['journals']) {
                $qb = $this->scope($type, $user, $projects, $options);
                $qb->join('i.journals', 'j')
                    ->andWhere('j.privateNotes = false OR ('.$this->auth->allowedToCondition('view_private_notes', 'p.id', $user).')');
                $this->tokensCondition($qb, ['j.notes'], $tokens, $allWords);
                $r = self::union($r, $this->fetch($qb, $def));
                ++$queries;
            }
        }
        if (null !== $def['attachments'] && ($titlesOnly ? 'only' === $attachments : '0' !== $attachments)) {
            $qb = $this->scope($type, $user, $projects, $options);
            $qb->join('App\Entity\Attachment', 'att', 'WITH', "att.containerType = '".$def['attachments']."' AND att.containerId = ".$def['alias'].'.id');
            $this->tokensCondition($qb, ['att.filename', 'att.description'], $tokens, $allWords);
            $r = self::union($r, $this->fetch($qb, $def));
            ++$queries;
        }
        if ($queries > 1) {
            usort($r, static fn (array $a, array $b) => $b <=> $a);
        }

        return $r;
    }

    /** @param list<array{0: int, 1: int}> $a @param list<array{0: int, 1: int}> $b */
    private static function union(array $a, array $b): array
    {
        $out = [];
        foreach ([...$a, ...$b] as $x) {
            $out[$x[0].'-'.$x[1]] = $x;
        }

        return array_values($out);
    }

    /** CustomField#visibility_by_project_condition */
    private function fieldVisibility(CustomField $field, User $user, string $projectKey): string
    {
        if ($field->visible || $user->admin) {
            return '1=1';
        }
        if (!$user->isLogged()) {
            return '1=0';
        }
        $roleIds = array_map(static fn ($r) => $r->id, $field->roles->toArray());
        $projectIds = [];
        foreach ($this->auth->membershipsOf($user) as $projectId => $member) {
            foreach ($member->getRoles() as $role) {
                if (\in_array($role->id, $roleIds, true)) {
                    $projectIds[] = $projectId;
                }
            }
        }

        return Authorization::in($projectKey, $projectIds);
    }

    /** acts_as_searchable#search_scope */
    private function scope(string $type, User $user, ?array $projects, array $options): QueryBuilder
    {
        $def = self::DEFS[$type];
        $a = $def['alias'];
        $qb = $this->em->createQueryBuilder()->from($def['entity'], $a);
        switch ($type) {
            case 'issues':
                $qb->join('i.project', 'p');
                if ($options['open_issues'] ?? false) {
                    $qb->join('i.status', 'st')->andWhere('st.isClosed = false');
                }
                $qb->andWhere($this->issues->visibleCondition($user, 'i'));
                break;
            case 'news':
                $qb->join('n.project', 'p')->andWhere($this->auth->allowedToCondition('view_news', 'p.id', $user));
                break;
            case 'documents':
                $qb->join('d.project', 'p')->andWhere($this->auth->allowedToCondition('view_documents', 'p.id', $user));
                break;
            case 'changesets':
                $qb->join('c.repository', 'r')->join('r.project', 'p')->andWhere($this->auth->allowedToCondition('view_changesets', 'p.id', $user));
                break;
            case 'wiki_pages':
                $qb->join('wp.content', 'wc')->join('wp.wiki', 'w')->join('w.project', 'p')->andWhere($this->auth->allowedToCondition('view_wiki_pages', 'p.id', $user));
                break;
            case 'messages':
                $qb->join('m.board', 'b')->join('b.project', 'p')->andWhere($this->auth->allowedToCondition('view_messages', 'p.id', $user));
                break;
            case 'projects':
                $qb->andWhere($this->auth->allowedToCondition('view_project', 'p.id', $user));
                break;
        }
        if (null !== $projects) {
            $qb->andWhere(Authorization::in($def['project'], array_map(static fn (Project $p) => (int) $p->id, $projects)));
        }

        return $qb;
    }

    /** acts_as_searchable#search_tokens_condition (case insensitive LIKE) */
    private function tokensCondition(QueryBuilder $qb, array $columns, array $tokens, bool $allWords): void
    {
        $parts = [];
        foreach ($tokens as $token) {
            $name = 'tok'.++$this->param;
            $qb->setParameter($name, '%'.mb_strtolower(str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $token)).'%');
            $parts[] = '('.implode(' OR ', array_map(static fn (string $c) => "(LOWER($c) LIKE :$name ESCAPE '!')", $columns)).')';
        }
        if ([] !== $parts) {
            $qb->andWhere(implode($allWords ? ' AND ' : ' OR ', $parts));
        }
    }

    /** @return list<array{0: int, 1: int}> [timestamp, id] */
    private function fetch(QueryBuilder $qb, array $def): array
    {
        $a = $def['alias'];
        $rows = $qb->select("DISTINCT {$def['date']} AS rank_date, $a.id AS rank_id")
            ->orderBy('rank_date', 'DESC')->addOrderBy('rank_id', 'DESC')
            ->getQuery()->getScalarResult();
        $out = [];
        foreach ($rows as $row) {
            $d = $row['rank_date'];
            $ts = $d instanceof \DateTimeInterface ? $d->getTimestamp() : (null === $d ? 0 : (int) strtotime((string) $d.' UTC'));
            $out[] = [$ts, (int) $row['rank_id']];
        }

        return $out;
    }
}
