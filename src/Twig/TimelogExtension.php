<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\CustomField;
use App\Entity\Issue;
use App\Entity\IssueCategory;
use App\Entity\IssueStatus;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\TimeEntry;
use App\Entity\TimeEntryActivity;
use App\Entity\Tracker;
use App\Entity\Version;
use App\I18n\Localizer;
use App\Query\QueryColumn;
use App\Query\TimeEntry\TimeEntryQuery;
use App\Query\TimeEntry\TimeReport;
use App\Service\CustomFieldService;
use App\Service\IssueService;
use App\Service\TimeEntryService;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Port of the timelog parts of QueriesHelper (column_content, csv_value, grouped results) and TimelogHelper. */
final class TimelogExtension extends AbstractExtension
{
    public function __construct(
        private readonly AppExtension $app,
        private readonly Localizer $i18n,
        private readonly CustomFieldService $customFields,
        private readonly IssueService $issues,
        private readonly TimeEntryService $timeEntries,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('te_column_content', $this->columnContent(...), $safe),
            new TwigFunction('te_group_label', $this->groupLabel(...), $safe),
            new TwigFunction('te_editable', fn (TimeEntry $e) => $this->timeEntries->isEditable($e)),
            new TwigFunction('te_criteria_value', $this->criteriaValue(...), $safe),
            new TwigFunction('te_issue_visible', fn (?Issue $i) => null !== $i && $this->issues->isVisible($i)),
        ];
    }

    private static function h(?string $s): string
    {
        return htmlspecialchars((string) $s, \ENT_QUOTES);
    }

    /** QueriesHelper#column_content for time entries */
    public function columnContent(TimeEntryQuery $query, QueryColumn $column, TimeEntry $entry): string
    {
        $value = $query->columnValue($column, $entry);
        if ($column->isCustomField() && null !== $column->customField) {
            // values of fields with a URL pattern and link fields are links; the customized object is the
            // time entry, or its issue / project for "issue.cf_N" / "project.cf_N" columns
            $customized = str_starts_with($column->name, 'issue.') ? $entry->issue : (str_starts_with($column->name, 'project.') ? $entry->project : $entry);

            return $this->customFields->linkedValueHtml($column->customField, $value, $customized)
                ?? self::h($this->customFields->formatValue($column->customField, $value));
        }

        return match ($column->name) {
            'issue.parent' => $value instanceof Issue ? ($this->issues->isVisible($value) ? $this->app->linkToIssue($value, ['subject' => false]) : '#'.$value->id) : '',
            'hours' => self::h($this->i18n->formatHours((float) $value)),
            default => $this->formatObject($value, true),
        };
    }

    /** ApplicationHelper#format_object */
    public function formatObject(mixed $v, bool $html = true): string
    {
        return match (true) {
            null === $v => '',
            $v instanceof Project => $html ? $this->app->linkToProject($v) : $v->name,
            $v instanceof Principal => $html ? $this->app->linkToUser($v) : $v->getName(),
            $v instanceof Version => $html ? $this->app->linkToVersion($v) : (string) $v->name,
            $v instanceof Issue => $this->issues->isVisible($v) ? ($html ? $this->app->linkToIssue($v) : $v->tracker->name.' #'.$v->id.': '.$v->subject) : '#'.$v->id,
            $v instanceof TimeEntryActivity, $v instanceof IssueStatus, $v instanceof Tracker => $html ? self::h($v->name) : $v->name,
            $v instanceof IssueCategory => $html ? self::h($v->name) : $v->name,
            $v instanceof \DateTimeInterface => self::h('00:00:00.000000' === $v->format('H:i:s.u') ? $this->i18n->formatDate($v) : $this->i18n->formatTime($v)),
            \is_float($v) => number_format($v, 2, '.', ''),
            \is_bool($v) => $this->i18n->l($v ? 'general_text_Yes' : 'general_text_No'),
            default => $html ? self::h((string) $v) : (string) $v,
        };
    }

    /** QueriesHelper#csv_value for time entries */
    public function csvValue(TimeEntryQuery $query, QueryColumn $column, TimeEntry $entry): string
    {
        $value = $query->columnValue($column, $entry);
        if ($column->isCustomField() && null !== $column->customField) {
            return $this->customFields->formatValue($column->customField, $value);
        }
        if ('hours' === $column->name) {
            return str_replace('.', $this->i18n->l('general_csv_decimal_separator'), \sprintf('%.2f', (float) $value));
        }
        if (\is_float($value)) {
            return str_replace('.', $this->i18n->l('general_csv_decimal_separator'), \sprintf('%.2f', $value));
        }

        return $this->formatObject($value, false);
    }

    /** Group label of a row in a grouped list */
    public function groupLabel(TimeEntryQuery $query, TimeEntry $entry): string
    {
        $column = $query->groupByColumn();
        if (null === $column) {
            return '';
        }
        $value = $query->columnValue($column, $entry);
        if (null === $value || '' === $value || [] === $value) {
            return '('.self::h($this->i18n->l('label_blank_value')).')';
        }
        if ($column->isCustomField() && null !== $column->customField) {
            return self::h($this->customFields->formatValue($column->customField, $value));
        }
        if (QueryColumn::TIMESTAMP === $column->kind && $value instanceof \DateTimeInterface) {
            return self::h($this->i18n->formatDate(\DateTimeImmutable::createFromInterface($value)->setTimezone($this->i18n->userTimezone())));
        }

        return $this->formatObject($value, true);
    }

    /** TimelogHelper#format_criteria_value */
    public function criteriaValue(TimeReport $report, string $criteria, ?string $value, bool $html = true): string
    {
        if (null === $value || '' === $value) {
            return '['.$this->i18n->l('label_none').']';
        }
        $opts = $report->availableCriteria()[$criteria] ?? null;
        if (null === $opts) {
            return $html ? self::h($value) : $value;
        }
        if (isset($opts['klass'])) {
            $obj = $this->em->find($opts['klass'], (int) $value);
            if (null === $obj) {
                return $html ? self::h($value) : $value;
            }

            return $this->formatObject($obj, $html);
        }
        if (isset($opts['custom_field']) && $opts['custom_field'] instanceof CustomField) {
            $s = $this->customFields->formatValue($opts['custom_field'], $value);

            return $html ? self::h($s) : $s;
        }

        return $html ? self::h($value) : $value;
    }
}
