<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\Project;
use App\Plugin\Activity\ActivityProviderRegistry;
use App\Plugin\Activity\ActivityScope;
use App\Entity\Role;
use App\Entity\User;
use App\Security\Authorization;
use App\Service\IssueService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Port of Redmine::Activity (registered providers) and Redmine::Activity::Fetcher
 * (+ acts_as_activity_provider#find_events of each provider).
 */
final class ActivityFetcher
{
    /** Redmine::Activity.available_event_types => [default?, permission used to filter the types of a project] */
    public const array EVENT_TYPES = [
        'issues' => [true, 'view_issues'],
        'changesets' => [true, 'view_changesets'],
        'news' => [true, 'view_news'],
        'documents' => [true, 'view_documents'],
        'files' => [true, 'view_files'],
        'wiki_edits' => [false, 'view_wiki_edits'],
        'messages' => [false, 'view_messages'],
        'time_entries' => [false, 'view_time_entries'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly IssueService $issues,
        private readonly EventBuilder $builder,
        private readonly ?ActivityProviderRegistry $providers = null,
    ) {
    }

    /**
     * Redmine::Activity.available_event_types: core types followed by the plugin providers' types.
     *
     * @return array<string, array{0: bool, 1: ?string}> type => [default?, permission]
     */
    public function availableEventTypes(): array
    {
        $types = self::EVENT_TYPES;
        foreach ($this->providers?->all() ?? [] as $type => $provider) {
            $types[$type] ??= [$provider->isDefault(), $provider->getPermission()];
        }

        return $types;
    }

    /**
     * Redmine::Activity.default_event_types, including the plugin providers.
     *
     * @return list<string>
     */
    public function defaultTypes(): array
    {
        return array_keys(array_filter($this->availableEventTypes(), static fn (array $t) => $t[0]));
    }

    /** @return list<string> */
    public static function defaultEventTypes(): array
    {
        return array_keys(array_filter(self::EVENT_TYPES, static fn (array $t) => $t[0]));
    }

    /**
     * Fetcher#event_types: available event types, restricted to those the user can view in the project (or its subprojects).
     *
     * @return list<string>
     */
    public function eventTypes(User $user, ?Project $project): array
    {
        $available = $this->availableEventTypes();
        $types = array_keys($available);
        if (null === $project) {
            return $types;
        }
        $projects = $project->getSelfAndDescendants();

        return array_values(array_filter($types, function (string $type) use ($user, $projects, $available): bool {
            if (null === $available[$type][1]) {
                return true;
            }
            foreach ($projects as $p) {
                if ($this->auth->allowedTo($available[$type][1], $p, $user)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Fetcher#events: events of the given types visible by the user, in reverse chronological order.
     *
     * @param list<string> $scope
     *
     * @return list<ActivityEvent>
     */
    public function events(User $user, array $scope, ?Project $project, bool $withSubprojects, ?User $author = null, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null, ?int $limit = null): array
    {
        $ctx = ['user' => $user, 'project' => $project, 'sub' => $withSubprojects, 'author' => $author, 'from' => $from, 'to' => $to, 'limit' => $limit];
        $events = [];
        foreach ($scope as $type) {
            $found = match ($type) {
                'issues' => [...$this->issueEvents($ctx), ...$this->journalEvents($ctx)],
                'changesets' => $this->changesetEvents($ctx),
                'news' => $this->newsEvents($ctx),
                'documents' => [...$this->documentEvents($ctx), ...$this->documentAttachmentEvents($ctx)],
                'files' => $this->fileEvents($ctx),
                'wiki_edits' => $this->wikiEditEvents($ctx),
                'messages' => $this->messageEvents($ctx),
                'time_entries' => $this->timeEntryEvents($ctx),
                default => $this->providers?->get($type)?->findEvents(new ActivityScope($user, $project, $withSubprojects, $author, $from, $to, $limit, $this->auth)) ?? [],
            };
            array_push($events, ...$found);
        }
        usort($events, static fn (ActivityEvent $a, ActivityEvent $b) => $b->datetime <=> $a->datetime);
        if (null !== $limit) {
            $events = \array_slice($events, 0, $limit);
        }

        return $events;
    }

    /** Project restriction of Project.allowed_to_condition(user, perm, :project => ..., :with_subprojects => ...) */
    private function projectCondition(array $ctx, string $alias = 'p'): ?string
    {
        $project = $ctx['project'];
        if (null === $project) {
            return null;
        }
        $s = "$alias.id = ".(int) $project->id;
        if ($ctx['sub']) {
            $s = "($s OR ($alias.lft > ".(int) $project->lft." AND $alias.rgt < ".(int) $project->rgt.'))';
        }

        return $s;
    }

    /**
     * Applies the common find_events options (date range, author, limit, project) to a query builder.
     *
     * @param string|null $authorKey DQL author expression, null when the provider has no author key
     */
    private function apply(QueryBuilder $qb, array $ctx, string $timestamp, ?string $authorKey, string $visibility, string $idExpr): ?QueryBuilder
    {
        if (null !== $ctx['from']) {
            $qb->andWhere("$timestamp >= :from")->setParameter('from', $ctx['from']->format('Y-m-d'));
        }
        if (null !== $ctx['to']) {
            $qb->andWhere("$timestamp <= :to")->setParameter('to', $ctx['to']->format('Y-m-d'));
        }
        if (null !== $ctx['author']) {
            if (null === $authorKey) {
                return null;
            }
            $qb->andWhere("$authorKey = :author")->setParameter('author', $ctx['author']->id);
        }
        if (null !== $ctx['limit']) {
            $qb->orderBy($idExpr, 'DESC')->setMaxResults($ctx['limit']);
        }
        $qb->andWhere($visibility);
        if ($pc = $this->projectCondition($ctx)) {
            $qb->andWhere($pc);
        }

        return $qb;
    }

    private function allowed(string $permission, array $ctx, string $expr = 'p.id'): string
    {
        return $this->auth->allowedToCondition($permission, $expr, $ctx['user']);
    }

    /** @return list<ActivityEvent> */
    private function issueEvents(array $ctx): array
    {
        $qb = $this->em->createQueryBuilder()->select('i', 'p', 't', 's')->from('App\Entity\Issue', 'i')
            ->join('i.project', 'p')->join('i.tracker', 't')->join('i.status', 's');
        $qb = $this->apply($qb, $ctx, 'i.createdOn', 'IDENTITY(i.author)', $this->issues->visibleCondition($ctx['user'], 'i'), 'i.id');

        return null === $qb ? [] : array_map($this->builder->issue(...), $qb->getQuery()->getResult());
    }

    /** @return list<ActivityEvent> */
    private function journalEvents(array $ctx): array
    {
        $user = $ctx['user'];
        $qb = $this->em->createQueryBuilder()->select('DISTINCT j')->from('App\Entity\Journal', 'j')
            ->join('j.issue', 'i')->join('i.project', 'p')->leftJoin('j.details', 'd')
            ->where("j.journalizedType = 'Issue' AND (d.propKey = 'status_id' OR j.notes <> '')");
        $privateNotes = $this->auth->allowedToCondition('view_private_notes', 'p.id', $user, null, false, true);
        $visibility = '('.$this->issues->visibleCondition($user, 'i').') AND (j.privateNotes = false OR IDENTITY(j.user) = '.(int) ($user->isLogged() ? $user->id : 0).' OR ('.$privateNotes.'))';
        $qb = $this->apply($qb, $ctx, 'j.createdOn', 'IDENTITY(j.user)', $visibility, 'j.id');

        return null === $qb ? [] : array_map($this->builder->journal(...), $qb->getQuery()->getResult());
    }

    /** @return list<ActivityEvent> */
    private function changesetEvents(array $ctx): array
    {
        $qb = $this->em->createQueryBuilder()->select('c', 'r', 'p')->from('App\Entity\Changeset', 'c')
            ->join('c.repository', 'r')->join('r.project', 'p');
        $qb = $this->apply($qb, $ctx, 'c.committedOn', 'IDENTITY(c.user)', $this->allowed('view_changesets', $ctx), 'c.id');

        return null === $qb ? [] : array_map($this->builder->changeset(...), $qb->getQuery()->getResult());
    }

    /** @return list<ActivityEvent> */
    private function newsEvents(array $ctx): array
    {
        $qb = $this->em->createQueryBuilder()->select('n', 'p')->from('App\Entity\News', 'n')->join('n.project', 'p');
        $qb = $this->apply($qb, $ctx, 'n.createdOn', 'IDENTITY(n.author)', $this->allowed('view_news', $ctx), 'n.id');

        return null === $qb ? [] : array_map($this->builder->news(...), $qb->getQuery()->getResult());
    }

    /** @return list<ActivityEvent> */
    private function documentEvents(array $ctx): array
    {
        $qb = $this->em->createQueryBuilder()->select('d', 'p')->from('App\Entity\Document', 'd')->join('d.project', 'p');
        $qb = $this->apply($qb, $ctx, 'd.createdOn', null, $this->allowed('view_documents', $ctx), 'd.id');

        return null === $qb ? [] : array_map($this->builder->document(...), $qb->getQuery()->getResult());
    }

    /** @return list<ActivityEvent> */
    private function documentAttachmentEvents(array $ctx): array
    {
        $qb = $this->em->createQueryBuilder()->select('a', 'd', 'p')->from('App\Entity\Attachment', 'a')
            ->join('App\Entity\Document', 'd', 'WITH', "a.containerType = 'Document' AND d.id = a.containerId")
            ->join('d.project', 'p');
        $qb = $this->apply($qb, $ctx, 'a.createdOn', 'IDENTITY(a.author)', $this->allowed('view_documents', $ctx), 'a.id');

        return null === $qb ? [] : $this->attachmentRows($qb->getQuery()->getResult(), static fn ($d) => $d->project);
    }

    /** @return list<ActivityEvent> */
    private function fileEvents(array $ctx): array
    {
        $events = [];
        $qb = $this->em->createQueryBuilder()->select('a', 'p')->from('App\Entity\Attachment', 'a')
            ->join('App\Entity\Project', 'p', 'WITH', "a.containerType = 'Project' AND p.id = a.containerId");
        $qb = $this->apply($qb, $ctx, 'a.createdOn', 'IDENTITY(a.author)', $this->allowed('view_files', $ctx), 'a.id');
        if (null !== $qb) {
            array_push($events, ...$this->attachmentRows($qb->getQuery()->getResult(), static fn ($p) => $p));
        }
        $qb = $this->em->createQueryBuilder()->select('a', 'v', 'p')->from('App\Entity\Attachment', 'a')
            ->join('App\Entity\Version', 'v', 'WITH', "a.containerType = 'Version' AND v.id = a.containerId")
            ->join('v.project', 'p');
        $qb = $this->apply($qb, $ctx, 'a.createdOn', 'IDENTITY(a.author)', $this->allowed('view_files', $ctx), 'a.id');
        if (null !== $qb) {
            array_push($events, ...$this->attachmentRows($qb->getQuery()->getResult(), static fn ($v) => $v->project));
        }

        return $events;
    }

    /**
     * Mixed results [Attachment, container, (project)...] to events.
     *
     * @return list<ActivityEvent>
     */
    private function attachmentRows(array $rows, callable $projectOf): array
    {
        $events = [];
        $attachment = null;
        foreach ($rows as $row) {
            if ($row instanceof \App\Entity\Attachment) {
                $attachment = $row;
            } elseif (null !== $attachment) {
                $events[] = $this->builder->attachment($attachment, $projectOf($row));
                $attachment = null;
            }
        }

        return $events;
    }

    /** @return list<ActivityEvent> */
    private function wikiEditEvents(array $ctx): array
    {
        $qb = $this->em->createQueryBuilder()->select('v')->from('App\Entity\WikiContentVersion', 'v')
            ->join('App\Entity\WikiPage', 'wp', 'WITH', 'wp.id = v.pageId')
            ->join('wp.wiki', 'w')->join('w.project', 'p');
        $qb = $this->apply($qb, $ctx, 'v.updatedOn', 'IDENTITY(v.author)', $this->allowed('view_wiki_edits', $ctx), 'v.id');
        if (null === $qb) {
            return [];
        }
        $events = [];
        foreach ($qb->getQuery()->getResult() as $version) {
            $page = $this->em->find('App\Entity\WikiPage', $version->pageId);
            if (null !== $page) {
                $events[] = $this->builder->wikiContentVersion($version, $page);
            }
        }

        return $events;
    }

    /** @return list<ActivityEvent> */
    private function messageEvents(array $ctx): array
    {
        $qb = $this->em->createQueryBuilder()->select('m', 'b', 'p')->from('App\Entity\Message', 'm')
            ->join('m.board', 'b')->join('b.project', 'p');
        $qb = $this->apply($qb, $ctx, 'm.createdOn', 'IDENTITY(m.author)', $this->allowed('view_messages', $ctx), 'm.id');

        return null === $qb ? [] : array_map($this->builder->message(...), $qb->getQuery()->getResult());
    }

    /** @return list<ActivityEvent> */
    private function timeEntryEvents(array $ctx): array
    {
        $visibility = $this->auth->allowedToCondition('view_time_entries', 'p.id', $ctx['user'], static function (Role $role, User $user): ?string {
            if ('all' === $role->timeEntriesVisibility) {
                return null;
            }
            if ('own' === $role->timeEntriesVisibility && $user->id && $user->isLogged()) {
                return 'IDENTITY(t.user) = '.(int) $user->id;
            }

            return '1=0';
        });
        $qb = $this->em->createQueryBuilder()->select('t', 'p')->from('App\Entity\TimeEntry', 't')->join('t.project', 'p');
        $qb = $this->apply($qb, $ctx, 't.createdOn', 'IDENTITY(t.user)', $visibility, 't.id');

        return null === $qb ? [] : array_map($this->builder->timeEntry(...), $qb->getQuery()->getResult());
    }

    /**
     * events.group_by {|e| User.current.time_to_date(e.event_datetime)} in the shape of
     * activities/_activities.html.twig (event_days): days in reverse order, events sorted with
     * sort_activity_events (grouped events).
     *
     * @param list<ActivityEvent> $events
     * @param list<int|string>    $nonWorkingDays ISO week days (Setting.non_working_week_days)
     *
     * @return list<array{date: \DateTimeImmutable, today: bool, non_working: bool, events: list<array{event: ActivityEvent, grouped: bool}>}>
     */
    public static function eventDays(array $events, \DateTimeZone $tz, \DateTimeInterface $today, array $nonWorkingDays = []): array
    {
        $byDay = [];
        foreach ($events as $e) {
            $byDay[\DateTimeImmutable::createFromInterface($e->datetime)->setTimezone($tz)->format('Y-m-d')][] = $e;
        }
        krsort($byDay);
        $nonWorking = array_map('intval', $nonWorkingDays);
        $days = [];
        foreach ($byDay as $day => $dayEvents) {
            $date = new \DateTimeImmutable((string) $day);
            $days[] = [
                'date' => $date,
                'today' => $date->format('Y-m-d') === $today->format('Y-m-d'),
                'non_working' => \in_array((int) $date->format('N'), $nonWorking, true),
                'events' => \App\Controller\ActivitiesController::sortEvents($dayEvents),
            ];
        }

        return $days;
    }
}
