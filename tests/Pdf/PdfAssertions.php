<?php

declare(strict_types=1);

namespace App\Tests\Pdf;

/**
 * Helpers to inspect PDF documents generated with compression off (App\Pdf\PdfSupport::$compress = false):
 * text of the content streams (TCPDF writes Unicode fonts as UTF-16BE strings), page count and size.
 */
trait PdfAssertions
{
    protected static function assertPdf(string $pdf, ?int $pages = null): void
    {
        self::assertStringStartsWith('%PDF-', $pdf, 'PDF header');
        self::assertStringContainsString('%%EOF', $pdf);
        if (null !== $pages) {
            self::assertSame($pages, self::pageCount($pdf), 'page count');
        }
    }

    protected static function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page\b(?!s)#', $pdf);
    }

    /** @return array{0: float, 1: float} width and height (pt) of the first page */
    protected static function pageSize(string $pdf): array
    {
        self::assertSame(1, preg_match('#/MediaBox\s*\[\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\]#', $pdf, $m), 'MediaBox');

        return [(float) $m[3] - (float) $m[1], (float) $m[4] - (float) $m[2]];
    }

    /** Text drawn in the content streams, one chunk per text operator, separated by "|" */
    protected static function pdfText(string $pdf): string
    {
        $chunks = [];
        preg_match_all('#stream\r?\n(.*?)\r?\nendstream#s', $pdf, $streams);
        foreach ($streams[1] as $stream) {
            if (!str_contains($stream, 'BT')) {
                continue;
            }
            preg_match_all('#(\[(?:\((?:\\\\.|[^\\\\)])*\)|[^\]])*\]\s*TJ|\((?:\\\\.|[^\\\\)])*\)\s*Tj)#s', $stream, $ops);
            foreach ($ops[1] as $op) {
                preg_match_all('#\(((?:\\\\.|[^\\\\)])*)\)#s', $op, $strings);
                $s = '';
                foreach ($strings[1] as $str) {
                    $s .= self::decodePdfString($str);
                }
                if ('' !== trim($s)) {
                    $chunks[] = $s;
                }
            }
        }

        return implode('|', $chunks);
    }

    private static function decodePdfString(string $s): string
    {
        $raw = preg_replace_callback('#\\\\([nrtbf()\\\\]|[0-7]{1,3})#', static function (array $m) {
            return match ($m[1]) {
                'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f",
                '(' => '(', ')' => ')', '\\' => '\\',
                default => \chr(octdec($m[1]) & 0xFF),
            };
        }, $s) ?? $s;
        // Unicode fonts: UTF-16BE code units
        if (0 === \strlen($raw) % 2 && substr_count($raw, "\0") >= \strlen($raw) / 4) {
            return (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
        }

        return $raw;
    }

    /** Asserts every string appears in the text of the PDF (spaces normalized inside chunks) */
    protected static function assertPdfContains(string $pdf, string ...$needles): void
    {
        $text = preg_replace('/[ \x{00A0}]+/u', ' ', self::pdfText($pdf)) ?? '';
        foreach ($needles as $needle) {
            self::assertStringContainsString($needle, $text);
        }
    }

    protected static function assertPdfNotContains(string $pdf, string ...$needles): void
    {
        $text = preg_replace('/[ \x{00A0}]+/u', ' ', self::pdfText($pdf)) ?? '';
        foreach ($needles as $needle) {
            self::assertStringNotContainsString($needle, $text);
        }
    }
}
