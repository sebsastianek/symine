<?php

declare(strict_types=1);

namespace App\Query\TimeEntry;

use App\Entity\CustomField;
use App\Entity\IssueCustomField;
use App\Entity\Project;
use App\Entity\ProjectCustomField;
use App\Entity\TimeEntryActivityCustomField;
use App\Entity\TimeEntryCustomField;
use App\Query\QueryContext;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Port of Redmine::Helpers::TimeReport: hours grouped by up to 3 criteria and by period (year/month/week/day).
 */
#[Exclude]
final class TimeReport
{
    /** @var list<string> */
    public array $criteria = [];
    public string $columns = 'month';
    /** @var list<array<string, mixed>> rows: criteria => value, tyear, tmonth, tweek, spent_on, hours, <columns> => period */
    public array $hours = [];
    public float $totalHours = 0.0;
    /** @var list<string> */
    public array $periods = [];
    /** @var array<string, array{sql: string, label: string, klass?: string, joins?: string, custom_field?: CustomField, format?: string}>|null */
    private ?array $availableCriteria = null;

    /**
     * @param list<string>|null $criteria
     */
    public function __construct(
        private readonly QueryContext $ctx,
        private readonly TimeEntryQuery $query,
        private readonly ?Project $project,
        ?array $criteria,
        ?string $columns,
    ) {
        $available = $this->availableCriteria();
        $c = [];
        foreach ((array) $criteria as $k) {
            $k = (string) $k;
            if (isset($available[$k]) && !\in_array($k, $c, true)) {
                $c[] = $k;
            }
        }
        $this->criteria = \array_slice($c, 0, 3);
        $this->columns = \in_array($columns, ['year', 'month', 'week', 'day'], true) ? $columns : 'month';
        $this->run();
    }

    /** @return array<string, array{sql: string, label: string, klass?: string, joins?: string, custom_field?: CustomField, format?: string}> */
    public function availableCriteria(): array
    {
        if (null !== $this->availableCriteria) {
            return $this->availableCriteria;
        }
        $l = $this->ctx->l(...);
        $criteria = [
            'project' => ['sql' => 'time_entries.project_id', 'klass' => \App\Entity\Project::class, 'label' => $l('label_project')],
            'status' => ['sql' => 'issues.status_id', 'klass' => \App\Entity\IssueStatus::class, 'label' => $l('field_status')],
            'version' => ['sql' => 'issues.fixed_version_id', 'klass' => \App\Entity\Version::class, 'label' => $l('label_version')],
            'category' => ['sql' => 'issues.category_id', 'klass' => \App\Entity\IssueCategory::class, 'label' => $l('field_category')],
            'user' => ['sql' => 'time_entries.user_id', 'klass' => \App\Entity\User::class, 'label' => $l('label_user')],
            'tracker' => ['sql' => 'issues.tracker_id', 'klass' => \App\Entity\Tracker::class, 'label' => $l('label_tracker')],
            'activity' => ['sql' => 'COALESCE(enumerations.parent_id, enumerations.id)', 'klass' => \App\Entity\TimeEntryActivity::class, 'label' => $l('field_activity')],
            'issue' => ['sql' => 'time_entries.issue_id', 'klass' => \App\Entity\Issue::class, 'label' => $l('label_issue')],
        ];
        $em = $this->ctx->em;
        $fields = [];
        foreach ($em->getRepository(TimeEntryCustomField::class)->findBy([], ['position' => 'ASC']) as $f) {
            $fields[] = [$f, 'TimeEntry', 'time_entries.id'];
        }
        foreach ($em->getRepository(ProjectCustomField::class)->findBy([], ['position' => 'ASC']) as $f) {
            $fields[] = [$f, 'Project', 'projects.id'];
        }
        $issueFields = $em->getRepository(IssueCustomField::class)->findBy([], ['position' => 'ASC']);
        if (null === $this->project) {
            $issueFields = array_filter($issueFields, static fn ($f) => $f->isForAll);
        } else {
            $issueFields = $this->query->issueCustomFields();
        }
        foreach ($issueFields as $f) {
            $fields[] = [$f, 'Issue', 'issues.id'];
        }
        foreach ($em->getRepository(TimeEntryActivityCustomField::class)->findBy([], ['position' => 'ASC']) as $f) {
            $fields[] = [$f, 'Enumeration', 'enumerations.id'];
        }
        foreach ($fields as [$cf, $type, $key]) {
            if (!$this->query->isCustomFieldVisible($cf) || !\in_array($cf->fieldFormat, ['list', 'bool'], true) || $cf->isMultiple()) {
                continue;
            }
            $alias = 'cf_'.$cf->id;
            $criteria[$alias] = [
                'sql' => "COALESCE($alias.value, '')",
                'joins' => "LEFT OUTER JOIN custom_values $alias ON $alias.customized_type = '$type' AND $alias.customized_id = $key AND $alias.custom_field_id = {$cf->id}"
                    ." AND $alias.value <> '' AND $alias.id = (SELECT max({$alias}_2.id) FROM custom_values {$alias}_2 WHERE {$alias}_2.customized_type = $alias.customized_type"
                    ." AND {$alias}_2.customized_id = $alias.customized_id AND {$alias}_2.custom_field_id = $alias.custom_field_id)",
                'format' => $cf->fieldFormat,
                'custom_field' => $cf,
                'label' => $cf->name,
            ];
        }

        return $this->availableCriteria = $criteria;
    }

    private function run(): void
    {
        if ([] === $this->criteria) {
            return;
        }
        $available = $this->availableCriteria();
        $timeColumns = ['time_entries.tyear', 'time_entries.tmonth', 'time_entries.tweek', 'time_entries.spent_on'];
        $exprs = array_merge(array_map(static fn ($c) => $available[$c]['sql'], $this->criteria), $timeColumns);
        $joins = implode(' ', array_filter(array_map(static fn ($c) => $available[$c]['joins'] ?? null, $this->criteria)));
        $rows = $this->query->reportRows($exprs, $joins);
        $names = array_merge($this->criteria, ['tyear', 'tmonth', 'tweek', 'spent_on']);
        $this->hours = [];
        foreach ($rows as $r) {
            $h = ['hours' => (float) $r['hours']];
            foreach ($names as $i => $name) {
                $v = $r['g'.$i] ?? null;
                $h[$name] = null === $v ? null : (string) $v;
            }
            $h['spent_on'] = substr((string) $h['spent_on'], 0, 10);
            $date = new \DateTimeImmutable($h['spent_on']);
            $h[$this->columns] = match ($this->columns) {
                'year' => (string) (int) $h['tyear'],
                'month' => (int) $h['tyear'].'-'.(int) $h['tmonth'],
                'week' => $date->format('o').'-'.(int) $h['tweek'],
                default => $date->format('Y-m-d'),
            };
            $this->hours[] = $h;
        }
        $dates = array_column($this->hours, 'spent_on');
        $today = $this->ctx->i18n->today();
        $from = [] !== $dates ? new \DateTimeImmutable(min($dates)) : $today;
        $to = [] !== $dates ? new \DateTimeImmutable(max($dates)) : $today;
        $this->totalHours = array_sum(array_column($this->hours, 'hours'));
        $this->periods = [];
        $d = new \DateTimeImmutable($from->format('Y-m-d'));
        $end = new \DateTimeImmutable($to->format('Y-m-d'));
        while ($d <= $end && \count($this->periods) < 100) {
            switch ($this->columns) {
                case 'year':
                    $this->periods[] = $d->format('Y');
                    $d = $d->setDate((int) $d->format('Y') + 1, 1, 1);
                    break;
                case 'month':
                    $this->periods[] = $d->format('Y').'-'.(int) $d->format('n');
                    $d = $d->modify('first day of next month');
                    break;
                case 'week':
                    $this->periods[] = $d->format('o').'-'.(int) $d->format('W');
                    $d = $d->modify('+7 days');
                    $d = $d->modify('-'.((int) $d->format('N') - 1).' days');
                    break;
                default:
                    $this->periods[] = $d->format('Y-m-d');
                    $d = $d->modify('+1 day');
            }
        }
    }

    /**
     * select_hours
     *
     * @param list<array<string, mixed>> $data
     *
     * @return list<array<string, mixed>>
     */
    public static function selectHours(array $data, string $criteria, ?string $value): array
    {
        if (null === $value || '' === $value) {
            return array_values(array_filter($data, static fn ($r) => null === ($r[$criteria] ?? null) || '' === (string) $r[$criteria]));
        }

        return array_values(array_filter($data, static fn ($r) => (string) ($r[$criteria] ?? '') === $value));
    }

    /** @param list<array<string, mixed>> $data */
    public static function sumHours(array $data): float
    {
        return array_sum(array_map(static fn ($r) => (float) $r['hours'], $data));
    }

    /**
     * Flattened report rows (port of the recursive _report_criteria partial / report_criteria_to_csv).
     *
     * @return list<array{level: int, criteria: string, value: ?string, last: bool, periods: list<float>, total: float}>
     */
    public function rows(): array
    {
        $out = [];
        $this->collect($this->hours, 0, $out);

        return $out;
    }

    private function collect(array $hours, int $level, array &$out): void
    {
        $criteria = $this->criteria[$level];
        $values = [];
        foreach ($hours as $h) {
            $v = null === $h[$criteria] ? '' : (string) $h[$criteria];
            if (!\in_array($v, $values, true)) {
                $values[] = $v;
            }
        }
        foreach ($values as $value) {
            $forValue = self::selectHours($hours, $criteria, $value);
            if ([] === $forValue) {
                continue;
            }
            $periods = [];
            $total = 0.0;
            foreach ($this->periods as $p) {
                $sum = self::sumHours(self::selectHours($forValue, $this->columns, $p));
                $periods[] = $sum;
                $total += $sum;
            }
            $last = \count($this->criteria) <= $level + 1;
            $out[] = ['level' => $level, 'criteria' => $criteria, 'value' => '' === $value ? null : $value, 'last' => $last, 'periods' => $periods, 'total' => $total];
            if (!$last) {
                $this->collect($forValue, $level + 1, $out);
            }
        }
    }

    /** @return list<float> totals by period */
    public function periodTotals(): array
    {
        return array_map(fn ($p) => self::sumHours(self::selectHours($this->hours, $this->columns, $p)), $this->periods);
    }
}
