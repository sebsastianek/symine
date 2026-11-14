<?php

declare(strict_types=1);

namespace App\Helper;

use App\Entity\Issue;
use App\Entity\IssueRelation;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\Version;
use App\Query\IssueQuery;
use App\Query\QueryColumn;
use App\Query\QueryContext;
use App\Service\VersionService;
use App\Twig\AppExtension;
use App\Twig\QueryExtension;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Port of Redmine::Helpers::Gantt (lib/redmine/helpers/gantt.rb, HTML rendering) and
 * GanttHelper::ChartLayout (app/helpers/gantt_helper.rb). The PDF/PNG exports (to_pdf / to_image) are
 * in App\Pdf\GanttExporter, which uses rows() / lineData() / coordinates().
 *
 * Dates are handled as day numbers (days since 1970-01-01) to port Ruby's Date arithmetic.
 */
#[Exclude]
final class Gantt
{
    /** Relation types that are rendered */
    public const array DRAW_TYPES = [
        IssueRelation::TYPE_BLOCKS => ['landscape_margin' => 16, 'color' => '#fa5252'],
        IssueRelation::TYPE_PRECEDES => ['landscape_margin' => 20, 'color' => '#1c7ed6'],
    ];

    public const array UNAVAILABLE_COLUMNS = ['tracker', 'id', 'subject'];

    public int $yearFrom;
    public int $monthFrom;
    public int $zoom;
    public int $months;
    public \DateTimeImmutable $dateFrom;
    public \DateTimeImmutable $dateTo;
    public bool $truncated = false;
    public ?int $maxRows;
    public ?Project $project = null;
    public ?IssueQuery $query = null;

    // Layout (GanttHelper::ChartLayout)
    public int $zoomPx;
    public int $subjectWidth = 330;
    public int $headerHeight = 18;
    public int $contentTop = 8;

    private string $subjects = '';
    private string $lines = '';
    /** @var array<string, string> */
    private array $columns = [];
    private int $numberOfRows = 0;
    private bool $rendered = false;

    /** @var list<Issue>|null */
    private ?array $issues = null;
    /** @var array<int, array<string, list<int>>>|null */
    private ?array $relations = null;
    /** @var list<Project>|null */
    private ?array $projects = null;
    /** @var array<int, list<Issue>> */
    private array $issuesByProject = [];
    private \DateTimeImmutable $today;
    /** @var list<int> */
    private array $nonWorkingWeekDays;

    /**
     * @param array<string, mixed> $params request params (year, month, zoom, months)
     * @param array<string, mixed> $prefs  user preference values (gantt_zoom, gantt_months)
     */
    public function __construct(
        array $params,
        array $prefs,
        private readonly QueryContext $ctx,
        private readonly AppExtension $app,
        private readonly QueryExtension $qx,
        private readonly VersionService $versions,
    ) {
        $this->today = $ctx->i18n->today();
        $year = (int) ($params['year'] ?? 0);
        if ($year > 0) {
            $this->yearFrom = $year;
            $month = (int) ($params['month'] ?? 0);
            $this->monthFrom = $month >= 1 && $month <= 12 ? $month : 1;
        } else {
            $this->monthFrom = (int) $this->today->format('n');
            $this->yearFrom = (int) $this->today->format('Y');
        }
        $zoom = (int) ($params['zoom'] ?? $prefs['gantt_zoom'] ?? 0);
        $this->zoom = $zoom > 0 && $zoom < 5 ? $zoom : 2;
        $months = (int) ($params['months'] ?? $prefs['gantt_months'] ?? 0);
        $limit = $ctx->settings->int('gantt_months_limit');
        $this->months = $months > 0 && $months < $limit + 1 ? $months : 6;
        $this->dateFrom = new \DateTimeImmutable(\sprintf('%04d-%02d-01', $this->yearFrom, $this->monthFrom));
        $this->dateTo = $this->dateFrom->modify('+'.$this->months.' months')->modify('-1 day');
        $max = $ctx->settings->string('gantt_items_limit');
        $this->maxRows = '' !== $max ? (int) $max : null;
        $this->zoomPx = 2 ** $this->zoom;
        $days = $ctx->settings->array('non_working_week_days');
        $this->nonWorkingWeekDays = \count($days) < 7 ? array_map('intval', $days) : [];
    }

    // ------------------------------------------------------------------------------------------
    // Params / navigation
    // ------------------------------------------------------------------------------------------

    /** @return array{zoom: int, year: int, month: int, months: int} */
    public function params(): array
    {
        return ['zoom' => $this->zoom, 'year' => $this->yearFrom, 'month' => $this->monthFrom, 'months' => $this->months];
    }

    public function paramsPrevious(): array
    {
        $d = $this->dateFrom->modify('-'.$this->months.' months');

        return ['year' => (int) $d->format('Y'), 'month' => (int) $d->format('n'), 'zoom' => $this->zoom, 'months' => $this->months];
    }

    public function paramsNext(): array
    {
        $d = $this->dateFrom->modify('+'.$this->months.' months');

        return ['year' => (int) $d->format('Y'), 'month' => (int) $d->format('n'), 'zoom' => $this->zoom, 'months' => $this->months];
    }

    // ------------------------------------------------------------------------------------------
    // Data
    // ------------------------------------------------------------------------------------------

    /** @return list<Issue> */
    public function issues(): array
    {
        if (null === $this->issues) {
            $this->issues = null === $this->query ? [] : $this->query->issues(null, $this->maxRows, null, ['projects.lft ASC', 'issues.id ASC']);
        }

        return $this->issues;
    }

    /** @return array<int, array<string, list<int>>> issue_from_id => type => [issue_to_id] */
    public function relations(): array
    {
        if (null !== $this->relations) {
            return $this->relations;
        }
        $this->relations = [];
        $ids = array_map(static fn (Issue $i) => (int) $i->id, $this->issues());
        if ([] === $ids) {
            return $this->relations;
        }
        $list = implode(',', $ids);
        $rows = $this->ctx->conn()->fetchAllAssociative(
            "SELECT issue_from_id, issue_to_id, relation_type FROM issue_relations WHERE issue_from_id IN ($list) AND issue_to_id IN ($list)"
            ." AND relation_type IN ('".IssueRelation::TYPE_BLOCKS."', '".IssueRelation::TYPE_PRECEDES."') ORDER BY id"
        );
        foreach ($rows as $r) {
            $this->relations[(int) $r['issue_from_id']][(string) $r['relation_type']][] = (int) $r['issue_to_id'];
        }

        return $this->relations;
    }

    /** @return list<Project> projects of the issues and their visible ancestors */
    public function projects(): array
    {
        if (null !== $this->projects) {
            return $this->projects;
        }
        $byId = [];
        foreach ($this->issues() as $issue) {
            $byId[$issue->project->id] = $issue->project;
        }
        if ([] === $byId) {
            return $this->projects = [];
        }
        $visible = $this->ctx->auth->visibleProjectIds();
        $result = [];
        foreach ($byId as $p) {
            foreach (array_merge($p->getAncestors(), [$p]) as $a) {
                if (\in_array($a->id, $visible, true)) {
                    $result[$a->id] = $a;
                }
            }
        }
        $result = array_values($result);
        usort($result, static fn (Project $a, Project $b) => ($a->lft ?? 0) <=> ($b->lft ?? 0));

        return $this->projects = $result;
    }

    /** @return list<Issue> */
    public function projectIssues(Project $project): array
    {
        if ([] === $this->issuesByProject) {
            foreach ($this->issues() as $issue) {
                $this->issuesByProject[$issue->project->id][] = $issue;
            }
        }

        return $this->issuesByProject[$project->id] ?? [];
    }

    /** @return list<Version> distinct versions of the project issues */
    public function projectVersions(Project $project): array
    {
        $out = [];
        foreach ($this->projectIssues($project) as $issue) {
            if (null !== $issue->fixedVersion) {
                $out[$issue->fixedVersion->id] = $issue->fixedVersion;
            }
        }

        return array_values($out);
    }

    /** @return list<Issue> */
    public function versionIssues(Project $project, Version $version): array
    {
        return array_values(array_filter($this->projectIssues($project), static fn (Issue $i) => $i->fixedVersion?->id === $version->id));
    }

    /** Number of rows rendered */
    public function numberOfRows(): int
    {
        $this->render();

        return $this->numberOfRows;
    }

    // ------------------------------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------------------------------

    public function subjects(): string
    {
        $this->render();

        return $this->subjects;
    }

    public function lines(): string
    {
        $this->render();

        return $this->lines;
    }

    public function selectedColumnContent(QueryColumn $column): string
    {
        $this->render();

        return $this->columns[$column->name] ?? '';
    }

    /** @return list<QueryColumn> columns shown as selected columns */
    public function selectedColumns(): array
    {
        if (null === $this->query) {
            return [];
        }

        return array_values(array_filter($this->query->columns(), static fn (QueryColumn $c) => !\in_array($c->name, self::UNAVAILABLE_COLUMNS, true)));
    }

    public function render(): void
    {
        if ($this->rendered) {
            return;
        }
        $this->rendered = true;
        foreach ($this->selectedColumns() as $c) {
            $this->columns[$c->name] = '';
        }
        $options = ['top' => $this->contentTop, 'top_increment' => 20, 'indent_increment' => 20, 'indent' => 4];
        $indent = 4;
        $this->numberOfRows = 0;
        try {
            $ancestors = [];
            foreach ($this->projectTree($this->projects()) as [$project, $level]) {
                $ancestors = \array_slice($ancestors, 0, $level);
                $options['indent'] = $indent + $level * $options['indent_increment'];
                $parentKey = [] !== $ancestors ? 'project-'.end($ancestors)->id : null;
                $this->renderProject($project, $options, $parentKey);
                $ancestors[] = $project;
            }
        } catch (MaxLinesLimitReached) {
            $this->truncated = true;
        }
    }

    /**
     * Project.project_tree: projects sorted by lft with their level among the given projects.
     *
     * @param list<Project> $projects
     *
     * @return list<array{0: Project, 1: int}>
     */
    private function projectTree(array $projects): array
    {
        usort($projects, static fn (Project $a, Project $b) => ($a->lft ?? 0) <=> ($b->lft ?? 0));
        $stack = [];
        $out = [];
        foreach ($projects as $p) {
            while ([] !== $stack && !(($p->lft ?? 0) > end($stack)->lft && ($p->rgt ?? 0) < end($stack)->rgt)) {
                array_pop($stack);
            }
            $out[] = [$p, \count($stack)];
            $stack[] = $p;
        }

        return $out;
    }

    private function renderProject(Project $project, array &$options, ?string $parentKey): void
    {
        $row = ['gantt_row_key' => 'project-'.$project->id, 'gantt_row_type' => 'project'];
        if (null !== $parentKey) {
            $row['gantt_parent_row_key'] = $parentKey;
        }
        $this->renderObjectRow($project, $options, $row);
        $options['indent'] += $options['indent_increment'];
        $issues = array_values(array_filter($this->projectIssues($project), static fn (Issue $i) => null === $i->fixedVersion));
        $this->renderIssues($issues, $options, $row['gantt_row_key']);
        $versions = $this->projectVersions($project);
        usort($versions, self::compareVersions(...));
        foreach ($versions as $version) {
            $this->renderVersion($project, $version, $options);
        }
        $options['indent'] -= $options['indent_increment'];
    }

    private function renderVersion(Project $project, Version $version, array &$options): void
    {
        $row = ['gantt_row_key' => 'version-'.$version->id.'-project-'.$project->id, 'gantt_row_type' => 'version', 'gantt_parent_row_key' => 'project-'.$project->id];
        $this->renderObjectRow($version, $options, $row);
        $options['indent'] += $options['indent_increment'];
        $this->renderIssues($this->versionIssues($project, $version), $options, $row['gantt_row_key']);
        $options['indent'] -= $options['indent_increment'];
    }

    /** @param list<Issue> $issues */
    private function renderIssues(array $issues, array &$options, ?string $parentKey): void
    {
        usort($issues, self::compareIssues(...));
        $ancestors = [];
        foreach ($issues as $issue) {
            while ([] !== $ancestors && !$issue->isDescendantOf(end($ancestors))) {
                array_pop($ancestors);
                $options['indent'] -= $options['indent_increment'];
            }
            $issueParentKey = [] !== $ancestors ? 'issue-'.end($ancestors)->id : $parentKey;
            $row = ['gantt_row_key' => 'issue-'.$issue->id, 'gantt_row_type' => 'issue'];
            if (null !== $issueParentKey) {
                $row['gantt_parent_row_key'] = $issueParentKey;
            }
            $this->renderObjectRow($issue, $options, $row);
            if (!$issue->isLeaf()) {
                $ancestors[] = $issue;
                $options['indent'] += $options['indent_increment'];
            }
        }
        $options['indent'] -= $options['indent_increment'] * \count($ancestors);
    }

    /** Gantt.sort_issue_logic: [[start_date, id], ...] from the root ancestor */
    private static function sortKey(Issue $issue): array
    {
        $key = [];
        $current = $issue;
        do {
            array_unshift($key, [$current->startDate?->format('Y-m-d') ?? '-4712-01-01', (int) $current->id]);
            $current = $current->parent;
        } while (null !== $current);

        return $key;
    }

    private static function compareIssues(Issue $a, Issue $b): int
    {
        $ka = self::sortKey($a);
        $kb = self::sortKey($b);
        $n = min(\count($ka), \count($kb));
        for ($i = 0; $i < $n; ++$i) {
            $c = strcmp($ka[$i][0], $kb[$i][0]) <=> 0;
            if (0 === $c) {
                $c = $ka[$i][1] <=> $kb[$i][1];
            }
            if (0 !== $c) {
                return $c;
            }
        }

        return \count($ka) <=> \count($kb);
    }

    /** Version#<=> */
    private static function compareVersions(Version $a, Version $b): int
    {
        if (null !== $a->effectiveDate) {
            if (null === $b->effectiveDate) {
                return -1;
            }
            if ($a->effectiveDate == $b->effectiveDate) {
                return $a->name === $b->name ? $a->id <=> $b->id : strcmp((string) $a->name, (string) $b->name);
            }

            return $a->effectiveDate <=> $b->effectiveDate;
        }
        if (null !== $b->effectiveDate) {
            return 1;
        }

        return $a->name === $b->name ? $a->id <=> $b->id : strcmp((string) $a->name, (string) $b->name);
    }

    private function renderObjectRow(Project|Version|Issue $object, array &$options, array $row): void
    {
        $this->htmlSubject($options, $object, $row);
        $line = match (true) {
            $object instanceof Project => $this->lineForProject($object, $options),
            $object instanceof Version => $this->lineForVersion($object, $options),
            default => $this->lineForIssue($object, $options),
        };
        $this->lines .= '<div class="gantt-row" style="'.self::cssVariables(['gantt-row-top' => $options['top'].'px']).'"'.self::dataAttrs($row).'>'.$line.'</div>';
        foreach ($this->selectedColumns() as $column) {
            $value = $object instanceof Issue && null !== $this->query ? $this->qx->columnContent($this->query, $column, $object) : '';
            $this->columns[$column->name] .= '<div style="'.self::cssVariables(['gantt-row-top' => $options['top'].'px']).'" class="gantt-row"'.self::dataAttrs($row).'>'.$value.'</div>';
        }
        $options['top'] += $options['top_increment'];
        ++$this->numberOfRows;
        if (null !== $this->maxRows && $this->numberOfRows >= $this->maxRows) {
            throw new MaxLinesLimitReached();
        }
    }

    private static function h(?string $s): string
    {
        return htmlspecialchars((string) $s, \ENT_QUOTES);
    }

    /** @param array<string, string> $data */
    private static function dataAttrs(array $data): string
    {
        $s = '';
        foreach ($data as $k => $v) {
            $s .= ' data-'.str_replace('_', '-', $k).'="'.self::h($v).'"';
        }

        return $s;
    }

    public static function cssVariables(array $vars): string
    {
        $out = [];
        foreach ($vars as $name => $value) {
            $out[] = '--'.$name.':'.$value;
        }

        return implode(';', $out);
    }

    private static function taskStyle(int $start, int $width): string
    {
        return self::cssVariables(['gantt-task-start' => $start.'px', 'gantt-task-width' => $width.'px']);
    }

    // ------------------------------------------------------------------------------------------
    // Subjects (left pane)
    // ------------------------------------------------------------------------------------------

    private function htmlSubject(array $params, Project|Version|Issue $object, array $row): void
    {
        $classes = [];
        $title = null;
        if ($object instanceof Issue) {
            $classes[] = 'hascontextmenu';
            $title = $object->subject;
            $hasChildren = false;
            if (!$object->isLeaf()) {
                $inProject = array_map(static fn (Issue $i) => $i->id, $this->projectIssues($object->project));
                foreach ($object->children as $child) {
                    if (\in_array($child->id, $inProject, true) && $child->fixedVersion?->id === $object->fixedVersion?->id) {
                        $hasChildren = true;
                        break;
                    }
                }
            }
        } elseif ($object instanceof Version) {
            $hasChildren = (int) $this->ctx->conn()->fetchOne('SELECT COUNT(*) FROM issues WHERE fixed_version_id = ?', [$object->id]) > 0;
        } else {
            $hasChildren = true;
        }
        $content = $this->subjectContent($object);
        $indent = $params['indent'];
        if ($hasChildren) {
            $content = '<span class="icon icon-expanded expander" data-action="click-&gt;gantt--subjects#handleEntryClick">'.$this->app->spriteIcon('angle-down', null, 18, false, 'icon-rtl').'</span>'.$content;
            $classes[] = 'is-expanded';
        } else {
            $indent += 18;
        }
        $classes[] = 'gantt-row';
        $attrs = ' class="'.implode(' ', $classes).'"';
        if (null !== $title) {
            $attrs .= ' title="'.self::h($title).'"';
        }
        $attrs .= self::dataAttrs($row + ['gantt--subjects-target' => 'row']);
        $attrs .= ' style="'.self::cssVariables(['gantt-row-top' => $params['top'].'px', 'gantt-row-indent' => $indent.'px']).'"';
        $this->subjects .= '<div'.$attrs.'>'.$content.'</div>';
    }

    private function subjectContent(Project|Version|Issue $object): string
    {
        if ($object instanceof Issue) {
            $css = '';
            if ($object->isOverdue($this->today)) {
                $css .= ' issue-overdue';
            }
            if ($object->isBehindSchedule($this->today)) {
                $css .= ' issue-behind-schedule';
            }
            if (null === $object->assignedTo) {
                $css .= ' icon icon-issue';
            }
            if ($object->isClosed()) {
                $css .= ' issue-closed';
            }
            $due = $this->dueBefore($object);
            if (null !== $object->startDate && null !== $due) {
                $progress = $this->progressDate(self::day($object->startDate), self::day($due), $object->doneRatio);
                if ($progress < self::day($this->dateFrom)) {
                    $css .= ' behind-start-date';
                }
                if ($progress > self::day($this->dateTo) && $object->doneRatio > 0) {
                    $css .= ' over-end-date';
                }
            }
            $s = '';
            if (null === $object->assignedTo) {
                $s .= $this->app->spriteIcon('issue');
            }
            $s .= $this->assigneeAvatar($object->assignedTo);
            $s .= $this->app->linkToIssue($object);
            $s .= '<input type="checkbox" name="ids[]" value="'.$object->id.'" class="toggle-selection"></input>';

            return '<span class="'.$css.'">'.$s.'</span>';
        }
        if ($object instanceof Version) {
            $class = 'icon icon-package ';
            $class .= ($this->versions->isBehindSchedule($object) ? 'version-behind-schedule' : '').' ';
            $class .= $this->versions->isOverdue($object) ? 'version-overdue' : '';
            if (!$object->isOpen()) {
                $class .= ' version-closed';
            }
            $start = $this->versions->startDate($object);
            $pct = $this->completedPercent($object);
            if (null !== $object->effectiveDate && null !== $start) {
                $progress = $this->progressDate(self::day($start), self::day($object->effectiveDate), $pct);
                if ($progress < self::day($this->dateFrom)) {
                    $class .= ' behind-start-date';
                }
                if ($progress > self::day($this->dateTo) && $pct > 0) {
                    $class .= ' over-end-date';
                }
            }

            return '<span class="'.$class.'">'.$this->app->spriteIcon('package').$this->app->linkToVersion($object).'</span>';
        }
        $class = 'icon icon-projects '.($this->isProjectOverdue($object) ? 'project-overdue' : '');

        return '<span class="'.$class.'">'.$this->app->spriteIcon('projects').$this->app->linkToProject($object).'</span>';
    }

    /** AvatarsHelper#assignee_avatar (size 13, class icon-avatar, title "Assignee: name") */
    private function assigneeAvatar(?Principal $user): string
    {
        if (null === $user) {
            return '';
        }
        $html = $this->app->avatar($user, 13, 'icon-avatar');
        $title = ' title="'.self::h($this->ctx->l('field_assigned_to').': '.$user->getName()).'"';
        $html = preg_replace('/ title="[^"]*"/', '', $html) ?? $html;

        return preg_replace('/^<(img|span)/', '<$1'.$title, $html) ?? $html;
    }

    // ------------------------------------------------------------------------------------------
    // Lines (timeline)
    // ------------------------------------------------------------------------------------------

    private function lineForProject(Project $project, array $options): string
    {
        [$start, $due] = $this->projectDates($project);
        if (null === $start || null === $due) {
            return '';
        }

        return $this->line(self::day($start), self::day($due), null, true, self::h($project->name), $options, $project);
    }

    private function lineForVersion(Version $version, array $options): string
    {
        $start = $this->versions->startDate($version);
        if (null === $version->effectiveDate || null === $start) {
            return '';
        }
        $pct = $this->completedPercent($version);
        $label = self::h((string) $version->name).' '.self::h((string) (int) round($pct)).'%';
        if (null === $this->project || $this->project->id !== $version->project->id) {
            $label = self::h($version->project->name.' -').$label;
        }

        return $this->line(self::day($start), self::day($version->effectiveDate), $pct, true, $label, $options, $version);
    }

    private function lineForIssue(Issue $issue, array $options): string
    {
        $due = $this->dueBefore($issue);
        if (null === $due) {
            return '';
        }
        $label = self::h($issue->status->name);
        if (!\in_array('done_ratio', $issue->tracker->getDisabledCoreFields(), true)) {
            $label .= ' '.$issue->doneRatio.'%';
        }

        return $this->line(null !== $issue->startDate ? self::day($issue->startDate) : null, self::day($due), (float) $issue->doneRatio, !$issue->isLeaf(), $label, $options, $issue);
    }

    private function dueBefore(Issue $issue): ?\DateTimeImmutable
    {
        return $issue->dueDate ?? $issue->fixedVersion?->effectiveDate;
    }

    private function completedPercent(Version $version): float
    {
        return (float) $this->versions->stats($version, true)['completed_percent'];
    }

    /** @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} Project#start_date / due_date */
    private function projectDates(Project $project): array
    {
        return $this->projectDates[(int) $project->id] ??= $this->loadProjectDates($project);
    }

    /** @var array<int, array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}> dates of the projects of this chart */
    private array $projectDates = [];

    /** @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} */
    private function loadProjectDates(Project $project): array
    {
        $shared = array_map(static fn (Version $v) => (int) $v->id, $this->ctx->projects->sharedVersions($project));
        $conn = $this->ctx->conn();
        $starts = [$conn->fetchOne('SELECT MIN(start_date) FROM issues WHERE project_id = ?', [$project->id])];
        $dues = [$conn->fetchOne('SELECT MAX(due_date) FROM issues WHERE project_id = ?', [$project->id])];
        if ([] !== $shared) {
            $in = implode(',', $shared);
            $starts[] = $conn->fetchOne("SELECT MIN(effective_date) FROM versions WHERE id IN ($in)");
            $starts[] = $conn->fetchOne("SELECT MIN(start_date) FROM issues WHERE fixed_version_id IN ($in)");
            $dues[] = $conn->fetchOne("SELECT MAX(effective_date) FROM versions WHERE id IN ($in)");
            $dues[] = $conn->fetchOne("SELECT MAX(due_date) FROM issues WHERE fixed_version_id IN ($in)");
        }
        $norm = static fn (array $l) => array_values(array_filter(array_map(static fn ($d) => $d ? substr((string) $d, 0, 10) : null, $l)));
        $s = $norm($starts);
        $d = $norm($dues);

        return [[] !== $s ? new \DateTimeImmutable(min($s)) : null, [] !== $d ? new \DateTimeImmutable(max($d)) : null];
    }

    private function isProjectOverdue(Project $project): bool
    {
        [, $due] = $this->projectDates($project);

        return $project->isActive() && null !== $due && $due < $this->today;
    }

    private static function day(\DateTimeInterface $d): int
    {
        return intdiv((new \DateTimeImmutable($d->format('Y-m-d'), new \DateTimeZone('UTC')))->getTimestamp(), 86400);
    }

    private function progressDate(int $start, int $end, float $progress): float
    {
        return $start + ($end - $start + 1) * ($progress / 100.0);
    }

    /** Gantt#coordinates (in pixels, or in $zoom units per day for the PDF/PNG exports) */
    public function coordinates(?int $start, ?int $end, ?float $progress, int|float|null $zoom = null): array
    {
        $zoom ??= $this->zoomPx;
        $from = self::day($this->dateFrom);
        $to = self::day($this->dateTo);
        $today = self::day($this->today);
        $c = [];
        if (null !== $start && null !== $end && $start <= $to && $end >= $from) {
            if ($start >= $from) {
                $c['start'] = $start - $from;
                $c['bar_start'] = $start - $from;
            } else {
                $c['bar_start'] = 0;
            }
            if ($end <= $to) {
                $c['end'] = $end - $from + 1;
                $c['bar_end'] = $end - $from + 1;
            } else {
                $c['bar_end'] = $to - $from + 1;
            }
            if (null !== $progress) {
                $pd = $this->progressDate($start, $end, $progress);
                if ($pd > $from && $pd > $start) {
                    $c['bar_progress_end'] = $pd < $to ? $pd - $from : $to - $from + 1;
                }
                if ($pd <= $today) {
                    $late = min($today, $end) + 1;
                    if ($late > $from && $late > $start) {
                        $c['bar_late_end'] = $late < $to ? $late - $from : $to - $from + 1;
                    }
                }
            }
        }
        foreach ($c as $k => $v) {
            $c[$k] = (int) floor($v * $zoom);
        }

        return $c;
    }

    private function line(?int $start, int $end, ?float $done, bool $markers, string $label, array $options, Project|Version|Issue $object): string
    {
        return $this->htmlTask($this->coordinates($start, $end, $done), $markers, $label, $object);
    }

    private function issueRelations(Issue $issue): array
    {
        return $this->relations()[$issue->id] ?? [];
    }

    private function htmlTask(array $coords, bool $markers, string $label, Project|Version|Issue $object): string
    {
        $out = '';
        $classes = ['gantt-task'];
        if ($object instanceof Issue && !$object->isLeaf()) {
            $classes[] = 'gantt-task-parent';
        }
        $cls = static fn (array $extra) => implode(' ', array_merge($classes, $extra));
        if (isset($coords['bar_start'], $coords['bar_end'])) {
            $data = '';
            if ($object instanceof Issue) {
                $data .= ' data-gantt-issue-id="'.$object->id.'"';
                $rels = $this->issueRelations($object);
                if ([] !== $rels) {
                    $data .= ' data-gantt-relations="'.self::h(json_encode($rels)).'"';
                }
            }
            $out .= '<div style="'.self::taskStyle($coords['bar_start'], $coords['bar_end'] - $coords['bar_start']).'" class="'.$cls(['gantt-task-todo']).'"'.$data.'>&nbsp;</div>';
            if (isset($coords['bar_late_end'])) {
                $out .= '<div style="'.self::taskStyle($coords['bar_start'], $coords['bar_late_end'] - $coords['bar_start']).'" class="'.$cls(['gantt-task-late']).'">&nbsp;</div>';
            }
            if (isset($coords['bar_progress_end'])) {
                $out .= '<div style="'.self::taskStyle($coords['bar_start'], $coords['bar_progress_end'] - $coords['bar_start']).'" class="'.$cls(['gantt-task-done']).'">&nbsp;</div>';
            }
        }
        if ($markers) {
            if (isset($coords['start'])) {
                $out .= '<div style="'.self::taskStyle($coords['start'], 15).'" class="'.$cls(['gantt-task-marker', 'gantt-task-start']).'">&nbsp;</div>';
            }
            if (isset($coords['end'])) {
                $out .= '<div style="'.self::taskStyle($coords['end'], 15).'" class="'.$cls(['gantt-task-marker', 'gantt-task-end']).'">&nbsp;</div>';
            }
        }
        $out .= '<div style="'.self::taskStyle(($coords['bar_end'] ?? 0) + 8, 15).'" class="'.$cls(['gantt-task-label']).'">'.$label.'</div>';
        if ($object instanceof Issue && isset($coords['bar_start'], $coords['bar_end'])) {
            $s = '<span class="tip">'.$this->issueTooltip($object).'</span>';
            $s .= '<input type="checkbox" name="ids[]" value="'.$object->id.'" class="toggle-selection"></input>';
            $out .= '<div style="'.self::taskStyle($coords['bar_start'], $coords['bar_end'] - $coords['bar_start']).'" class="tooltip hascontextmenu">'.$s.'</div>';
        }

        return $out;
    }

    /** IssuesHelper#render_issue_tooltip */
    private function issueTooltip(Issue $issue): string
    {
        $l = $this->ctx->l(...);
        $i18n = $this->ctx->i18n;
        $status = self::h($issue->status->name).($issue->isClosed() ? ' ('.self::h($i18n->formatDate($issue->closedOn)).')' : '');
        $assignee = null !== $issue->assignedTo
            ? preg_replace('/^<(img|span)/', '<$1 title="'.self::h($l('field_assigned_to')).'"', preg_replace('/ title="[^"]*"/', '', $this->app->avatar($issue->assignedTo, 13)) ?? '').' '.self::h($issue->assignedTo->getName())
            : ' ';

        return $this->app->linkToIssue($issue).'<br /><br />'
            .'<strong>'.self::h($l('field_project')).'</strong>: '.$this->app->linkToProject($issue->project).'<br />'
            .'<strong>'.self::h($l('field_status')).'</strong>: '.$status.'<br />'
            .'<strong>'.self::h($l('field_start_date')).'</strong>: '.self::h($i18n->formatDate($issue->startDate)).'<br />'
            .'<strong>'.self::h($l('field_due_date')).'</strong>: '.self::h($i18n->formatDate($issue->dueDate)).'<br />'
            .'<strong>'.self::h($l('field_assigned_to')).'</strong>: '.$assignee.'<br />'
            .'<strong>'.self::h($l('field_priority')).'</strong>: '.self::h($issue->priority->name);
    }

    // ------------------------------------------------------------------------------------------
    // Plain rows (used by the PDF/PNG exports, App\Pdf\GanttExporter)
    // ------------------------------------------------------------------------------------------

    /**
     * Rows in render order with their depth (indent = base + depth * indent_increment), limited
     * to gantt_items_limit like render().
     *
     * @return list<array{0: Project|Version|Issue, 1: int}>
     */
    public function rows(): array
    {
        $rows = [];
        $add = function (Project|Version|Issue $o, int $depth) use (&$rows): void {
            $rows[] = [$o, $depth];
            if (null !== $this->maxRows && \count($rows) >= $this->maxRows) {
                throw new MaxLinesLimitReached();
            }
        };
        $issueRows = function (array $issues, int $depth) use ($add): void {
            usort($issues, self::compareIssues(...));
            $ancestors = [];
            foreach ($issues as $issue) {
                while ([] !== $ancestors && !$issue->isDescendantOf(end($ancestors))) {
                    array_pop($ancestors);
                }
                $add($issue, $depth + \count($ancestors));
                if (!$issue->isLeaf()) {
                    $ancestors[] = $issue;
                }
            }
        };
        try {
            foreach ($this->projectTree($this->projects()) as [$project, $level]) {
                $add($project, $level);
                $issueRows(array_values(array_filter($this->projectIssues($project), static fn (Issue $i) => null === $i->fixedVersion)), $level + 1);
                $versions = $this->projectVersions($project);
                usort($versions, self::compareVersions(...));
                foreach ($versions as $version) {
                    $add($version, $level + 1);
                    $issueRows($this->versionIssues($project, $version), $level + 2);
                }
            }
        } catch (MaxLinesLimitReached) {
            $this->truncated = true;
        }

        return $rows;
    }

    /** Subject of a row as plain text (subject_for_project / version / issue) */
    public function plainSubject(Project|Version|Issue $object): string
    {
        return match (true) {
            $object instanceof Project => (string) $object->name,
            $object instanceof Version => $object->project->name.' - '.$object->name,
            default => (string) $object->subject,
        };
    }

    /**
     * Data of the timeline line of a row (line_for_project / version / issue), null when the row has no bar.
     *
     * @return array{start: ?int, end: int, done: ?float, markers: bool, label: string}|null day numbers
     */
    public function lineData(Project|Version|Issue $object): ?array
    {
        if ($object instanceof Project) {
            [$start, $due] = $this->projectDates($object);
            if (null === $start || null === $due) {
                return null;
            }

            return ['start' => self::day($start), 'end' => self::day($due), 'done' => null, 'markers' => true, 'label' => (string) $object->name];
        }
        if ($object instanceof Version) {
            $start = $this->versions->startDate($object);
            if (null === $object->effectiveDate || null === $start) {
                return null;
            }
            $pct = $this->completedPercent($object);
            $label = $object->name.' '.(int) round($pct).'%';
            if (null === $this->project || $this->project->id !== $object->project->id) {
                $label = $object->project->name.' -'.$label;
            }

            return ['start' => self::day($start), 'end' => self::day($object->effectiveDate), 'done' => $pct, 'markers' => true, 'label' => $label];
        }
        $due = $this->dueBefore($object);
        if (null === $due) {
            return null;
        }
        $label = (string) $object->status->name;
        if (!\in_array('done_ratio', $object->tracker->getDisabledCoreFields(), true)) {
            $label .= ' '.$object->doneRatio.'%';
        }

        return ['start' => null !== $object->startDate ? self::day($object->startDate) : null, 'end' => self::day($due), 'done' => (float) $object->doneRatio, 'markers' => !$object->isLeaf(), 'label' => $label];
    }

    /** Number of days since 1970-01-01 */
    public static function dayNumber(\DateTimeInterface $d): int
    {
        return self::day($d);
    }

    public function isNonWorkingDay(\DateTimeInterface $d): bool
    {
        return \in_array((int) $d->format('N'), $this->nonWorkingWeekDays, true);
    }

    public function today(): \DateTimeImmutable
    {
        return $this->today;
    }

    // ------------------------------------------------------------------------------------------
    // Layout (GanttHelper::ChartLayout)
    // ------------------------------------------------------------------------------------------

    public function showWeeks(): bool
    {
        return $this->zoom > 1;
    }

    public function showDays(): bool
    {
        return $this->zoom > 2;
    }

    public function showDayNumbers(): bool
    {
        return $this->zoom > 3;
    }

    public function headersHeight(): int
    {
        return (1 + (int) $this->showWeeks() + (int) $this->showDays() + (int) $this->showDayNumbers()) * $this->headerHeight;
    }

    public function chartWidth(): int
    {
        return (self::day($this->dateTo) - self::day($this->dateFrom) + 1) * $this->zoomPx;
    }

    public function contentHeight(): int
    {
        return max(20 * ($this->numberOfRows() + 6) + 150, 206);
    }

    public function paneHeight(): int
    {
        return $this->headersHeight() + $this->contentHeight();
    }

    /** @return list<array{date: \DateTimeImmutable, width: int, spans_body: bool, last: bool}> */
    public function monthPeriods(): array
    {
        $out = [];
        $date = $this->dateFrom;
        for ($i = 0; $i < $this->months; ++$i) {
            $next = $date->modify('+1 month');
            $out[] = ['date' => $date, 'width' => (self::day($next) - self::day($date)) * $this->zoomPx, 'spans_body' => !$this->showWeeks(), 'last' => $i === $this->months - 1];
            $date = $next;
        }

        return $out;
    }

    /** @return list<array{date: ?\DateTimeImmutable, width: int, spans_body: bool, last: bool}> */
    public function weekPeriods(): array
    {
        if (!$this->showWeeks()) {
            return [];
        }
        $out = [];
        $date = $this->dateFrom;
        $to = $this->dateTo;
        if (1 !== (int) $date->format('N')) {
            $weekEnd = $date->modify('+'.(7 - (int) $date->format('N')).' days');
            $out[] = ['date' => null, 'width' => (self::day($weekEnd) - self::day($date) + 1) * $this->zoomPx, 'spans_body' => !$this->showDays(), 'last' => $weekEnd >= $to];
            $date = $weekEnd->modify('+1 day');
        }
        while ($date <= $to) {
            $weekEnd = $date->modify('+6 days');
            if ($weekEnd > $to) {
                $weekEnd = $to;
            }
            $out[] = ['date' => $date, 'width' => (self::day($weekEnd) - self::day($date) + 1) * $this->zoomPx, 'spans_body' => !$this->showDays(), 'last' => $weekEnd == $to];
            $date = $date->modify('+7 days');
        }

        return $out;
    }

    /** @return list<array{date: \DateTimeImmutable, width: int, non_working: bool, spans_body: bool, last: bool}> */
    public function dayPeriods(bool $spansBody = false): array
    {
        $out = [];
        for ($d = $this->dateFrom; $d <= $this->dateTo; $d = $d->modify('+1 day')) {
            $out[] = ['date' => $d, 'width' => $this->zoomPx, 'non_working' => \in_array((int) $d->format('N'), $this->nonWorkingWeekDays, true), 'spans_body' => $spansBody, 'last' => $d == $this->dateTo];
        }

        return $out;
    }

    public function dayNumberPeriods(): array
    {
        return $this->showDayNumbers() ? $this->dayPeriods() : [];
    }

    public function dayLetterPeriods(): array
    {
        return $this->showDays() ? $this->dayPeriods(true) : [];
    }

    public function todayStart(): ?int
    {
        if ($this->today < $this->dateFrom || $this->today > $this->dateTo) {
            return null;
        }

        return (self::day($this->today) - self::day($this->dateFrom) + 1) * $this->zoomPx - 1;
    }

    /** I18n#day_letter (cwday 1..7) */
    public function dayLetter(\DateTimeImmutable $d): string
    {
        return mb_substr($this->ctx->l('date.abbr_day_names.'.((int) $d->format('N') % 7)), 0, 1);
    }

    public function chartStyle(): string
    {
        return self::cssVariables([
            'gantt-subject-width' => ($this->subjectWidth + 1).'px',
            'gantt-header-height' => $this->headerHeight.'px',
            'gantt-headers-height' => $this->headersHeight().'px',
            'gantt-chart-width' => $this->chartWidth().'px',
            'gantt-content-top' => $this->contentTop.'px',
            'gantt-content-height' => $this->contentHeight().'px',
            'gantt-pane-height' => $this->paneHeight().'px',
        ]);
    }

    public static function drawTypesJson(): string
    {
        return (string) json_encode(self::DRAW_TYPES);
    }
}

