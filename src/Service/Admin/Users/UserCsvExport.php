<?php

declare(strict_types=1);

namespace App\Service\Admin\Users;

use App\I18n\Localizer;
use App\Query\QueryColumn;
use App\Query\UserQuery;
use App\Twig\UserQueryExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** The CSV export of the users list (QueriesHelper#query_to_csv for a UserQuery). */
final class UserCsvExport
{
    public function __construct(
        private readonly UserQueryExtension $userQueryHelper,
        private readonly Localizer $i18n,
    ) {
    }

    /** The file with every user of the query, in the columns of the query */
    public function response(Request $request, UserQuery $query): Response
    {
        $users = $query->results();
        $columns = $query->columns();
        $sep = (string) ($request->query->get('field_separator') ?: $this->i18n->l('general_csv_separator'));
        $encoding = (string) ($request->query->get('encoding') ?: $this->i18n->l('general_csv_encoding'));
        $rows = [array_map(static fn (QueryColumn $c) => $c->caption, $columns)];
        foreach ($users as $u) {
            $rows[] = array_map(fn (QueryColumn $c) => $this->userQueryHelper->csvValue($query, $c, $u), $columns);
        }
        $csv = self::csvString($rows, $sep);
        if (0 === strcasecmp($encoding, 'UTF-8')) {
            $csv = "\xEF\xBB\xBF".$csv;
        } else {
            $converted = @mb_convert_encoding($csv, $encoding, 'UTF-8');
            $csv = false === $converted ? $csv : $converted;
        }
        // filename_for_export(@query, 'users')
        $name = (string) ($request->query->get('query_name') ?: $query->name());
        if ('_' === $name || '' === trim($name)) {
            $name = 'users';
        }
        $title = trim((string) preg_replace('/[\s,.\/\?\;\|\:]+/u', '_', $name));
        $title = (string) preg_replace('/[^\p{L}\p{N}_\-]/u', '', $title);
        $filename = mb_strtolower($title).'.csv';

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; header=present',
            'Content-Disposition' => 'attachment; filename="'.addcslashes($filename, '"\\').'"; filename*=UTF-8\'\''.rawurlencode($filename),
        ]);
    }

    /**
     * Ruby CSV generation: quotes empty strings and fields with separators/quotes/newlines; null gives an empty field
     *
     * @param list<list<?string>> $rows
     */
    private static function csvString(array $rows, string $sep): string
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
}
