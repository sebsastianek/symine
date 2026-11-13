<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Issue;
use App\Entity\IssueQuery;
use App\Entity\Project;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\I18n\Localizer;
use App\Query\QueryFactory;
use App\Security\Authorization;
use App\Service\Activity\ActivityFetcher;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Port of Redmine::MyPage, MyHelper and the my_page parts of UserPreference: blocks layout and
 * settings stored in user_preferences.others (my_page_layout / my_page_settings) and block data.
 */
final class MyPageService
{
    public const array GROUPS = ['top', 'left', 'right'];

    /** block => [label, max_occurs] */
    public const array BLOCKS = [
        'issuesassignedtome' => ['label_assigned_to_me_issues', 1],
        'issuesreportedbyme' => ['label_reported_issues', 1],
        'issuesupdatedbyme' => ['label_updated_issues', 1],
        'issueswatched' => ['label_watched_issues', 1],
        'issuequery' => ['label_issue_plural', 3],
        'news' => ['label_news_latest', 1],
        'calendar' => ['label_calendar', 1],
        'documents' => ['label_document_plural', 1],
        'timelog' => ['label_spent_time', 1],
        'activity' => ['label_activity', 1],
    ];

    public const array DEFAULT_LAYOUT = ['left' => ['issuesassignedtome'], 'right' => ['issuesreportedbyme']];

    public const array DEFAULT_ISSUE_COLUMNS = ['project', 'tracker', 'status', 'subject'];

    /** Issue columns usable in issue blocks: name => [caption key, DQL sort expressions, default order] */
    public const array ISSUE_COLUMNS = [
        'id' => ['#', ['i.id'], 'desc'],
        'project' => ['field_project', ['p.name'], null],
        'tracker' => ['field_tracker', ['t.position'], null],
        'parent' => ['field_parent_issue', ['i.rootId', 'i.lft'], 'desc'],
        'status' => ['field_status', ['s.position'], null],
        'priority' => ['field_priority', ['pr.position'], 'desc'],
        'subject' => ['field_subject', ['i.subject'], null],
        'author' => ['field_author', ['au.firstname', 'au.lastname', 'au.id'], null],
        'assigned_to' => ['field_assigned_to', ['asg.firstname', 'asg.lastname', 'asg.id'], null],
        'updated_on' => ['field_updated_on', ['i.updatedOn'], 'desc'],
        'category' => ['field_category', ['cat.name'], null],
        'fixed_version' => ['field_fixed_version', ['fv.effectiveDate', 'fv.name'], 'desc'],
        'start_date' => ['field_start_date', ['i.startDate'], null],
        'due_date' => ['field_due_date', ['i.dueDate'], null],
        'estimated_hours' => ['field_estimated_hours', ['i.estimatedHours'], null],
        'done_ratio' => ['field_done_ratio', ['i.doneRatio'], null],
        'created_on' => ['field_created_on', ['i.createdOn'], 'desc'],
        'closed_on' => ['field_closed_on', ['i.closedOn'], 'desc'],
        'is_private' => ['field_is_private', ['i.isPrivate'], 'desc'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly IssueService $issues,
        private readonly Localizer $i18n,
        private readonly Settings $settings,
        private readonly ?QueryFactory $queries = null,
        private readonly ?ActivityFetcher $activity = null,
    ) {
    }

    // --- Layout ---------------------------------------------------------------

    /** @return array<string, list<string>> */
    public function layout(User $user): array
    {
        $layout = $user->getPref()->get('my_page_layout');
        if (!\is_array($layout) || [] === $layout) {
            $layout = self::DEFAULT_LAYOUT;
        }
        $out = [];
        foreach ($layout as $group => $blocks) {
            $out[(string) $group] = array_values(array_map('strval', (array) $blocks));
        }

        return $out;
    }

    /** @param array<string, list<string>> $layout */
    private function setLayout(User $user, array $layout): void
    {
        $user->getPref()->set('my_page_layout', $layout);
    }

    /** @return array<string, array<string, mixed>> */
    public function allSettings(User $user): array
    {
        $s = $user->getPref()->get('my_page_settings');

        return \is_array($s) ? $s : [];
    }

    /** @return array<string, mixed> */
    public function blockSettings(User $user, string $block): array
    {
        $s = $this->allSettings($user)[$block] ?? [];

        return \is_array($s) ? $s : [];
    }

    /** @return list<string> */
    public function blocksInUse(User $user): array
    {
        return array_merge(...array_values($this->layout($user)) ?: [[]]);
    }

    /** Redmine::MyPage.find_block: block name without the "__N" suffix, null if unknown */
    public static function blockName(string $block): ?string
    {
        if (!preg_match('/\A(.*?)(__\d+)?\z/', $block, $m)) {
            return null;
        }

        return isset(self::BLOCKS[$m[1]]) ? $m[1] : null;
    }

    /**
     * Redmine::MyPage.block_options
     *
     * @param list<string> $inUse
     *
     * @return list<array{0: string, 1: ?string}> [label, block id or null if disabled]
     */
    public function blockOptions(array $inUse): array
    {
        $out = [];
        foreach (self::BLOCKS as $block => [$label, $max]) {
            $indexes = [];
            foreach ($inUse as $n) {
                if (preg_match('/\A'.preg_quote($block, '/').'(__(\d+))?\z/', $n, $m)) {
                    $indexes[] = (int) ($m[2] ?? 0);
                }
            }
            $id = [] !== $indexes ? $block.'__'.(max($indexes) + 1) : $block;
            if (\count($indexes) >= $max) {
                $id = null;
            }
            $key = 'my.blocks.'.$label;
            $out[] = [$this->i18n->has($key) ? $this->i18n->l($key) : $this->i18n->l($label), $id];
        }

        return $out;
    }

    public function isValidBlock(string $block, array $inUse): bool
    {
        if ('' === $block) {
            return false;
        }
        foreach ($this->blockOptions($inUse) as [, $id]) {
            if ($id === $block) {
                return true;
            }
        }

        return false;
    }

    public function removeBlock(User $user, string $block): void
    {
        $layout = $this->layout($user);
        foreach ($layout as $group => $blocks) {
            $layout[$group] = array_values(array_filter($blocks, static fn ($b) => $b !== $block));
        }
        $this->setLayout($user, $layout);
    }

    /** Adds a block on top of the first group; false if not valid */
    public function addBlock(User $user, string $block): bool
    {
        if (!$this->isValidBlock($block, $this->blocksInUse($user))) {
            return false;
        }
        $this->removeBlock($user, $block);
        $layout = $this->layout($user);
        $group = self::GROUPS[0];
        $layout[$group] ??= [];
        array_unshift($layout[$group], $block);
        $this->setLayout($user, $layout);

        return true;
    }

    /** @param list<string> $blocks */
    public function orderBlocks(User $user, string $group, array $blocks): void
    {
        if (!\in_array($group, self::GROUPS, true) || [] === $blocks) {
            return;
        }
        $inUse = $this->blocksInUse($user);
        $blocks = array_values(array_intersect(array_map('strval', $blocks), $inUse));
        foreach ($blocks as $b) {
            $this->removeBlock($user, $b);
        }
        $layout = $this->layout($user);
        $layout[$group] = $blocks;
        $this->setLayout($user, $layout);
    }

    /** @param array<string, mixed> $settings */
    public function updateBlockSettings(User $user, string $block, array $settings): void
    {
        $all = $this->allSettings($user);
        $current = \is_array($all[$block] ?? null) ? $all[$block] : [];
        $all[$block] = array_merge($current, $settings);
        $user->getPref()->set('my_page_settings', $all);
    }

    /** UserPreference#clear_unused_block_settings + save */
    public function save(User $user): void
    {
        $inUse = $this->blocksInUse($user);
        $all = $this->allSettings($user);
        $all = array_filter($all, static fn ($k) => \in_array((string) $k, $inUse, true), \ARRAY_FILTER_USE_KEY);
        $pref = $user->getPref();
        $pref->set('my_page_settings', $all);
        if (null === $pref->id) {
            $this->em->persist($pref);
        }
        // force Doctrine to see the change of the serialized column
        $pref->others = \is_array($pref->others) ? [...$pref->others] : $pref->others;
        $this->em->flush();
    }

    // --- Blocks data ------------------------------------------------------------

    /**
     * Data for a block: ['name' => block type, 'block' => id, 'template' => ..., ...vars] or null if unknown.
     *
     * @return array<string, mixed>|null
     */
    public function blockData(User $user, string $block): ?array
    {
        $name = self::blockName($block);
        if (null === $name) {
            return null;
        }
        $settings = $this->blockSettings($user, $block);
        $data = match ($name) {
            'issuesassignedtome' => $this->issuesBlock($user, $block, 'label_assigned_to_me_issues', ['assigned_to_id' => 'me'], $settings, [['priority', 'desc'], ['updated_on', 'desc']]),
            'issuesreportedbyme' => $this->issuesBlock($user, $block, 'label_reported_issues', ['author_id' => 'me'], $settings),
            'issuesupdatedbyme' => $this->issuesBlock($user, $block, 'label_updated_issues', ['updated_by' => 'me'], $settings),
            'issueswatched' => $this->issuesBlock($user, $block, 'label_watched_issues', ['watcher_id' => 'me'], $settings),
            'issuequery' => $this->issueQueryBlock($user, $block, $settings),
            'news' => $this->newsBlock($user),
            'calendar' => $this->calendarBlock($user),
            'documents' => $this->documentsBlock(),
            'timelog' => $this->timelogBlock($user, $settings),
            'activity' => $this->activityBlock($user),
        };

        return ['name' => $name, 'block' => $block, 'settings' => $settings] + $data;
    }

    /** Parses "a:desc,b" or [["a","desc"],...] into [[col, dir], ...] limited to 3 known columns */
    public static function parseSort(mixed $sort): array
    {
        $out = [];
        if (\is_string($sort)) {
            foreach (explode(',', $sort) as $part) {
                $p = explode(':', trim($part));
                if ('' !== $p[0]) {
                    $out[] = [$p[0], 'desc' === ($p[1] ?? '') ? 'desc' : 'asc'];
                }
            }
        } elseif (\is_array($sort)) {
            foreach ($sort as $c) {
                if (\is_array($c) && isset($c[0])) {
                    $out[] = [(string) $c[0], 'desc' === ($c[1] ?? '') ? 'desc' : 'asc'];
                }
            }
        }
        $seen = [];
        $out = array_values(array_filter($out, static function ($c) use (&$seen) {
            if (isset($seen[$c[0]]) || !isset(self::ISSUE_COLUMNS[$c[0]])) {
                return false;
            }
            $seen[$c[0]] = true;

            return true;
        }));

        return \array_slice($out, 0, 3);
    }

    public static function sortToString(array $criteria): string
    {
        return implode(',', array_map(static fn ($c) => $c[0].('desc' === $c[1] ? ':desc' : ''), $criteria));
    }

    /** SortCriteria#add (link of a column header): column first, toggled if already first */
    public static function sortAdd(array $criteria, string $column): array
    {
        $default = self::ISSUE_COLUMNS[$column][2] ?? 'asc';
        $dir = $default ?? 'asc';
        if (isset($criteria[0]) && $criteria[0][0] === $column) {
            $dir = 'desc' === $criteria[0][1] ? 'asc' : 'desc';
        }
        $rest = array_values(array_filter($criteria, static fn ($c) => $c[0] !== $column));

        return \array_slice([[$column, $dir], ...$rest], 0, 3);
    }

    /**
     * MyHelper#render_issuesassignedtome_block & co: an IssueQuery with the "me" filter on active projects.
     *
     * @param array<string, string> $filter
     * @param list<array{0: string, 1: string}> $defaultSort
     */
    private function issuesBlock(User $user, string $block, string $titleKey, array $filter, array $settings, array $defaultSort = [['updated_on', 'desc']]): array
    {
        /** @var \App\Query\IssueQuery $query */
        $query = $this->queries->create('IssueQuery', null, $this->i18n->l($titleKey));
        foreach ($filter as $field => $value) {
            $query->addFilter($field, '=', [$value]);
        }
        $query->addFilter('project.status', '=', [(string) Project::STATUS_ACTIVE]);
        $columns = array_values(array_filter(array_map('strval', (array) ($settings['columns'] ?? []))));
        $query->setColumnNames([] !== $columns ? $columns : self::DEFAULT_ISSUE_COLUMNS);
        $query->setSortCriteria(!empty($settings['sort']) ? $settings['sort'] : $defaultSort);

        return $this->queryBlock($block, $query);
    }

    /** MyHelper#render_issuequery_block: a saved issue query (all its filters, columns and sort) */
    private function issueQueryBlock(User $user, string $block, array $settings): array
    {
        $record = null;
        if (!empty($settings['query_id'])) {
            $record = $this->em->find(IssueQuery::class, (int) $settings['query_id']);
            if (null !== $record && !$this->isQueryVisible($record, $user)) {
                $record = null;
            }
        }
        if (null === $record) {
            return ['template' => 'my/blocks/_issue_query_selection.html.twig', 'queries' => $this->visibleIssueQueries($user)];
        }
        /** @var \App\Query\IssueQuery $query */
        $query = $this->queries->wrap($record, $record->project);
        if (!empty($settings['columns'])) {
            $query->setColumnNames(array_values(array_map('strval', (array) $settings['columns'])));
        }
        if (!empty($settings['sort'])) {
            $query->setSortCriteria($settings['sort']);
        }

        return $this->queryBlock($block, $query);
    }

    /** query.issues(:limit => 10) rendered by my/blocks/_issues */
    private function queryBlock(string $block, \App\Query\IssueQuery $query): array
    {
        try {
            $issues = $query->issues(0, 10);
            $count = $query->issueCount();
        } catch (\App\Query\StatementInvalid) {
            $issues = [];
            $count = 0;
        }

        return [
            'template' => 'my/blocks/_issues.html.twig',
            'title' => $query->name(),
            'query' => $query,
            'issues' => $issues,
            'issue_count' => $count,
            'issues_url_params' => $query->asParams(),
        ];
    }

    public function isQueryVisible(IssueQuery $q, User $user): bool
    {
        if ($user->admin) {
            return 0 !== $q->visibility || $q->userId === $user->id;
        }
        if (null !== $q->project && !$this->auth->allowedTo('view_issues', $q->project, $user)) {
            return false;
        }

        return match ($q->visibility) {
            2 => true,
            1 => $q->userId === $user->id || [] !== array_intersect(
                array_map(static fn ($r) => $r->id, $q->roles->toArray()),
                array_map(static fn ($r) => $r->id, null !== $q->project ? $this->auth->rolesForProject($q->project, $user) : $this->auth->allRoles($user)),
            ),
            default => $q->userId === $user->id,
        };
    }

    /** @return list<IssueQuery> */
    public function visibleIssueQueries(User $user): array
    {
        $all = $this->em->getRepository(IssueQuery::class)->findBy([], ['name' => 'ASC', 'id' => 'ASC']);

        return array_values(array_filter($all, fn (IssueQuery $q) => $this->isQueryVisible($q, $user)));
    }

    private function newsBlock(User $user): array
    {
        $projectIds = array_keys($this->auth->membershipsOf($user));
        $cond = $this->auth->allowedToCondition('view_news', 'IDENTITY(n.project)', $user);
        $news = [] === $projectIds ? [] : $this->em->createQuery("SELECT n, p FROM App\Entity\News n JOIN n.project p WHERE ($cond) AND p.id IN (:ids) ORDER BY n.createdOn DESC")
            ->setParameter('ids', $projectIds)->setMaxResults(10)->getResult();

        return ['template' => 'my/blocks/_news.html.twig', 'news' => $news];
    }

    private function documentsBlock(): array
    {
        $cond = $this->auth->allowedToCondition('view_documents', 'IDENTITY(d.project)');
        $docs = $this->em->createQuery("SELECT d FROM App\Entity\Document d WHERE $cond ORDER BY d.createdOn DESC")->setMaxResults(10)->getResult();

        return ['template' => 'my/blocks/_documents.html.twig', 'documents' => $docs];
    }

    private function calendarBlock(User $user): array
    {
        $today = $this->i18n->today($user);
        $firstWday = $this->i18n->startOfWeek() ?: 7;
        $cwday = (int) $today->format('N');
        $start = $today->modify('-'.(($cwday - $firstWday + 7) % 7).' days');
        $end = $start->modify('+6 days');
        $projectIds = array_keys($this->auth->membershipsOf($user));
        $events = [];
        if ([] !== $projectIds) {
            $cond = $this->issues->visibleCondition($user, 'i');
            $events = $this->em->createQuery("SELECT i, p, t FROM App\Entity\Issue i JOIN i.project p JOIN i.tracker t WHERE ($cond) AND p.id IN (:ids) AND ((i.startDate >= :s AND i.startDate <= :e) OR (i.dueDate >= :s AND i.dueDate <= :e))")
                ->setParameters(['ids' => $projectIds, 's' => $start->format('Y-m-d'), 'e' => $end->format('Y-m-d')])->getResult();
        }
        $days = [];
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $dayEvents = array_values(array_filter($events, static fn (Issue $i) => $i->startDate?->format('Y-m-d') === $key || $i->dueDate?->format('Y-m-d') === $key));
            $css = ((int) $d->format('n') === (int) $today->format('n') ? 'this-month' : 'other-month');
            if ($key === $today->format('Y-m-d')) {
                $css .= ' today';
            }
            if (\in_array((int) $d->format('N'), array_map('intval', $this->settings->array('non_working_week_days')), true)) {
                $css .= ' nwday';
            }
            $days[] = ['date' => $d, 'css' => $css, 'events' => $dayEvents];
        }
        $weekRef = $start->modify('+'.((11 - (int) $start->format('N')) % 7).' days');

        return [
            'template' => 'my/blocks/_calendar.html.twig',
            'days' => $days,
            'week_number' => (int) $weekRef->format('W'),
            'first_wday' => $firstWday,
        ];
    }

    private function timelogBlock(User $user, array $settings): array
    {
        $days = (int) ($settings['days'] ?? 0);
        if ($days < 1 || $days > 365) {
            $days = 7;
        }
        $today = $this->i18n->today($user);
        $from = $today->modify('-'.($days - 1).' days');
        /** @var list<TimeEntry> $entries */
        $entries = $this->em->createQuery(
            'SELECT te, a, p, i FROM App\Entity\TimeEntry te JOIN te.activity a JOIN te.project p LEFT JOIN te.issue i LEFT JOIN i.tracker t
             WHERE te.user = :u AND te.spentOn BETWEEN :f AND :t ORDER BY te.spentOn DESC, p.name ASC, t.position ASC, i.id ASC'
        )->setParameters(['u' => $user->id, 'f' => $from->format('Y-m-d'), 't' => $today->format('Y-m-d')])->getResult();
        $byDay = [];
        $total = 0.0;
        foreach ($entries as $e) {
            $byDay[$e->spentOn->format('Y-m-d')][] = $e;
            $total += $e->hours;
        }
        krsort($byDay);

        return [
            'template' => 'my/blocks/_timelog.html.twig',
            'days' => $days,
            'entries' => $entries,
            'entries_by_day' => $byDay,
            'total_hours' => $total,
            'today' => $today->format('Y-m-d'),
        ];
    }

    /**
     * MyHelper#render_activity_block: Redmine::Activity::Fetcher.new(User.current, :author => User.current)
     * .events(nil, nil, :limit => 10) grouped by day.
     */
    private function activityBlock(User $user): array
    {
        $events = $this->activity->events($user, $this->activity->eventTypes($user, null), null, false, $user, null, null, 10);

        return [
            'template' => 'my/blocks/_activity.html.twig',
            'event_days' => $this->activity->eventDays($events, $this->i18n->userTimezone($user), $this->i18n->today($user), $this->settings->array('non_working_week_days')),
        ];
    }
}
