<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\Attachment;
use App\Entity\Issue;
use App\Entity\IssueRelation;
use App\Entity\Principal;
use App\I18n\Localizer;
use App\Query\IssueQuery;
use App\Query\QueryColumn;
use App\Service\Settings;
use App\Twig\QueryExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The CSV export of an issue list (issues#index.csv, QueriesHelper#query_to_csv): the columns of the query
 * for the issues it finds, with the separator and the encoding the user chose.
 */
final class IssueCsvExport
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly QueryExtension $helpers,
    ) {
    }

    public function response(Request $request, IssueQuery $query): Response
    {
        $issues = $query->issues(null, $this->settings->int('issues_export_limit') ?: 500);
        $columns = $query->columns();
        $sep = (string) ($request->query->get('field_separator') ?: $this->i18n->l('general_csv_separator'));
        $encoding = (string) ($request->query->get('encoding') ?: $this->i18n->l('general_csv_encoding'));
        $rows = [array_map(static fn ($c) => $c->caption, $columns)];
        foreach ($issues as $issue) {
            $rows[] = array_map(fn ($c) => $this->csvContent($query, $c, $issue), $columns);
        }
        $csv = self::csvString($rows, $sep);
        $isUtf8 = 0 === strcasecmp($encoding, 'UTF-8');
        if ($isUtf8) {
            $csv = "\xEF\xBB\xBF".$csv;
        } else {
            $converted = @mb_convert_encoding($csv, $encoding, 'UTF-8');
            $csv = false === $converted ? $csv : $converted;
        }
        $name = (string) ($request->query->get('query_name') ?: $query->name());
        if ('_' === $name || '' === trim($name)) {
            $name = 'issues';
        }
        $filename = mb_strtolower(self::titleize($name)).'.csv';

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; header=present',
            'Content-Disposition' => 'attachment; filename="'.addcslashes($filename, '"\\').'"; filename*=UTF-8\'\''.rawurlencode($filename),
        ]);
    }

    /**
     * Ruby CSV generation: quotes empty strings and fields with separators/quotes/newlines; null gives an empty field
     *
     * @param list<list<string|int|float|null>> $rows
     */
    public static function csvString(array $rows, string $sep): string
    {
        $out = '';
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $v) {
                if (null === $v) {
                    $cells[] = '';
                    continue;
                }
                $v = (string) $v;
                if ('' === $v || str_contains($v, $sep) || str_contains($v, '"') || str_contains($v, "\n") || str_contains($v, "\r")) {
                    $v = '"'.str_replace('"', '""', $v).'"';
                }
                $cells[] = $v;
            }
            $out .= implode($sep, $cells)."\n";
        }

        return $out;
    }

    /** Wiki.titleize */
    private static function titleize(string $title): string
    {
        $title = trim(preg_replace('/[\s,.\/\?\;\|\:]+/u', '_', $title) ?? $title);
        $title = preg_replace('/[^\p{L}\p{N}_\-]/u', '', $title) ?? $title;

        return mb_strtoupper(mb_substr($title, 0, 1)).mb_substr($title, 1);
    }

    /** QueriesHelper#csv_content: null => empty cell, '' => "" */
    private function csvContent(IssueQuery $query, QueryColumn $column, Issue $issue): ?string
    {
        $value = $query->columnValue($column, $issue);
        if (\is_array($value) && !isset($value['custom_field'])) {
            $parts = array_filter(array_map(fn ($v) => $this->csvValue($query, $column, $issue, $v), $value), static fn ($p) => null !== $p);
            if (\in_array($column->name, ['attachments', 'watcher_users'], true)) {
                return implode("\n", array_map(fn ($v) => $v instanceof Attachment ? $v->filename : ($v instanceof Principal ? $v->getName() : (string) $v), $value));
            }

            return implode(', ', $parts);
        }

        return $this->csvValue($query, $column, $issue, $value);
    }

    private function csvValue(IssueQuery $query, QueryColumn $column, Issue $issue, mixed $value): ?string
    {
        $dec = $this->i18n->l('general_csv_decimal_separator');
        if (\is_array($value) && isset($value['custom_field'])) {
            $cf = $value['custom_field'];
            $raw = array_values(array_filter((array) $value['value'], static fn ($v) => null !== $v && '' !== (string) $v));
            if ([] === $raw) {
                return null;
            }
            if ('float' === $cf->fieldFormat) {
                return implode(', ', array_map(static fn ($v) => str_replace('.', $dec, \sprintf('%.2f', (float) $v)), $raw));
            }

            return html_entity_decode(strip_tags($this->helpers->formatObject($value, false)), \ENT_QUOTES);
        }

        return match (true) {
            null === $value => '',
            \is_float($value) => str_replace('.', $dec, \sprintf('%.2f', $value)),
            \is_int($value) => (string) $value,
            \is_bool($value) => $this->i18n->l($value ? 'general_text_Yes' : 'general_text_No'),
            $value instanceof IssueRelation => html_entity_decode(strip_tags($this->helpers->relationToS($value, $issue, false)), \ENT_QUOTES),
            $value instanceof Issue => (string) $value->id,
            $value instanceof Principal => $value->getName(),
            $value instanceof \DateTimeInterface => html_entity_decode($this->helpers->formatObject($value, false), \ENT_QUOTES),
            \is_object($value) => method_exists($value, '__toString') ? (string) $value : ((property_exists($value, 'name') ? (string) $value->name : '')),
            default => (string) $value,
        };
    }
}
