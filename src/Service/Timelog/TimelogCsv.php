<?php

declare(strict_types=1);

namespace App\Service\Timelog;

use App\I18n\Localizer;
use App\Query\QueryColumn;
use App\Query\TimeEntry\TimeEntryQuery;
use App\Query\TimeEntry\TimeReport;
use App\Twig\TimelogExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The CSV exports of the spent time list (query_to_csv) and of the report (report_to_csv), sent as a file
 * with the separator and the encoding chosen in the export dialog.
 */
final class TimelogCsv
{
    public function __construct(
        private readonly Localizer $i18n,
        private readonly TimelogExtension $helper,
    ) {
    }

    /** All the entries of the query, with its columns (or every column: c[]=all_inline) */
    public function entries(Request $request, TimeEntryQuery $query): Response
    {
        $entries = $query->isValid() ? $query->entries() : [];
        $c = (array) ($request->query->all()['c'] ?? []);
        if (\in_array('all_inline', $c, true)) {
            $query->setColumnNames(['all_inline']);
        }
        $columns = $query->columns();
        $rows = [array_map(static fn (QueryColumn $c) => $c->caption, $columns)];
        foreach ($entries as $e) {
            $rows[] = array_map(fn (QueryColumn $c) => $this->helper->csvValue($query, $c, $e), $columns);
        }

        return $this->response($request, $rows, $this->filename($request, $query, 'timelog'));
    }

    public function report(Request $request, TimeReport $report): Response
    {
        $fmt = fn (float $h) => str_replace('.', $this->i18n->l('general_csv_decimal_separator'), \sprintf('%.2f', $h));
        $available = $report->availableCriteria();
        $headers = array_map(static fn ($c) => $available[$c]['label'], $report->criteria);
        $headers = array_merge($headers, $report->periods, [$this->i18n->l('label_total_time')]);
        $rows = [$headers];
        foreach ($report->criteria ? $report->rows() : [] as $r) {
            $row = array_fill(0, $r['level'], '');
            $row[] = html_entity_decode(strip_tags($this->helper->criteriaValue($report, $r['criteria'], $r['value'], false)));
            $row = array_merge($row, array_fill(0, \count($report->criteria) - $r['level'] - 1, ''));
            foreach ($r['periods'] as $p) {
                $row[] = $p > 0 ? $fmt($p) : '';
            }
            $row[] = $fmt($r['total']);
            $rows[] = $row;
        }
        $total = 0.0;
        $row = array_merge([$this->i18n->l('label_total_time')], array_fill(0, max(0, \count($report->criteria) - 1), ''));
        foreach ($report->periodTotals() as $p) {
            $total += $p;
            $row[] = $p > 0 ? $fmt($p) : '';
        }
        $row[] = $fmt($total);
        $rows[] = $row;

        return $this->response($request, $rows, 'timelog.csv');
    }

    /** @param list<list<string|float|int>> $rows */
    private function response(Request $request, array $rows, string $filename): Response
    {
        $sep = (string) ($request->query->get('field_separator') ?: $this->i18n->l('general_csv_separator'));
        $encoding = (string) ($request->query->get('encoding') ?: $this->i18n->l('general_csv_encoding'));
        // Ruby CSV: fields quoted only when needed, empty strings as ""
        $csv = '';
        foreach ($rows as $row) {
            $csv .= implode($sep, array_map(static function ($v) use ($sep): string {
                $v = (string) $v;
                if ('' === $v || str_contains($v, $sep) || str_contains($v, '"') || str_contains($v, "\n") || str_contains($v, "\r")) {
                    return '"'.str_replace('"', '""', $v).'"';
                }

                return $v;
            }, $row))."\n";
        }
        if (0 === strcasecmp($encoding, 'UTF-8')) {
            $csv = "\xEF\xBB\xBF".$csv;
        } else {
            $converted = @mb_convert_encoding($csv, $encoding, 'UTF-8');
            $csv = false !== $converted ? $converted : $csv;
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; header=present',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** The name of the saved query (or query_name), as a file name */
    private function filename(Request $request, TimeEntryQuery $query, string $default): string
    {
        $name = (string) ($request->query->get('query_name') ?: $query->name());
        if ('_' === $name || '' === trim($name)) {
            $name = $default;
        }
        $name = mb_strtolower(preg_replace('/[^\w\-]+/u', '_', $name) ?? $default);

        return $name.'.csv';
    }
}
