<?php

declare(strict_types=1);

namespace App\Service;

use App\I18n\Localizer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Port of Redmine::Export::CSV.generate (Ruby CSV semantics): BOM for UTF-8, configurable field
 * separator / encoding, fields quoted only when needed, empty strings written as "" and nil as nothing.
 */
final class CsvExport
{
    public function __construct(private readonly Localizer $i18n)
    {
    }

    /** @param list<list<string|int|float|null>> $rows */
    public function generate(array $rows, ?string $separator = null, ?string $encoding = null): string
    {
        $sep = null !== $separator && '' !== $separator ? $separator : $this->i18n->l('general_csv_separator');
        $encoding = null !== $encoding && '' !== $encoding ? $encoding : $this->i18n->l('general_csv_encoding');
        if (!\in_array(strtoupper($encoding), array_map('strtoupper', mb_list_encodings()), true)) {
            $encoding = $this->i18n->l('general_csv_encoding');
        }
        $csv = '';
        foreach ($rows as $row) {
            $csv .= implode($sep, array_map(static function ($v) use ($sep): string {
                if (null === $v) {
                    return '';
                }
                if (\is_float($v)) {
                    $v = \sprintf('%.2f', $v);
                }
                $v = (string) $v;
                if ('' === $v || str_contains($v, $sep) || str_contains($v, '"') || str_contains($v, "\n") || str_contains($v, "\r")) {
                    return '"'.str_replace('"', '""', $v).'"';
                }

                return $v;
            }, $row))."\n";
        }
        if (0 === strcasecmp($encoding, 'UTF-8')) {
            return "\xEF\xBB\xBF".$csv;
        }
        $converted = @mb_convert_encoding($csv, $encoding, 'UTF-8');

        return false !== $converted ? $converted : $csv;
    }

    /** send_data(..., type: 'text/csv; header=present', filename:) */
    public function response(Request $request, array $rows, string $filename): Response
    {
        $content = $this->generate($rows, (string) $request->query->get('field_separator', ''), (string) $request->query->get('encoding', ''));

        return new Response($content, 200, [
            'Content-Type' => 'text/csv; header=present',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"; filename*=UTF-8\'\''.rawurlencode($filename),
            'Content-Transfer-Encoding' => 'binary',
        ]);
    }
}
