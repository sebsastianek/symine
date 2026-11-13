<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Entity\Project;
use App\I18n\Localizer;
use App\Service\Settings;
use App\Service\TextFormatter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared pieces of the PDF exports: document creation for the current language (ITCPDF.new(current_language)),
 * text formatting for PDF (pdf_format_text: absolute links, no headings anchors), filenames and responses.
 */
final class PdfSupport
{
    /** Tests switch compression off to inspect the content streams */
    public static bool $compress = true;

    public function __construct(
        public readonly Localizer $i18n,
        public readonly Settings $settings,
        private readonly TextFormatter $formatter,
        private readonly PdfImageResolver $images,
        private readonly RequestStack $requests,
    ) {
    }

    public function newPdf(string $orientation = 'P'): ITCPDF
    {
        $pdf = new ITCPDF([
            'fontname' => $this->i18n->l('general_pdf_fontname'),
            'monospaced' => $this->i18n->l('general_pdf_monospaced_fontname'),
            'rtl' => 'rtl' === $this->i18n->l('direction'),
            'language' => $this->i18n->locale(),
        ], $orientation, $this->images, self::$compress);
        $pdf->rdmFooterDate = $this->i18n->formatDate($this->i18n->today());

        return $pdf;
    }

    public function l(string $key, array $params = []): string
    {
        return $this->i18n->l($key, $params);
    }

    /** IssuesPdfHelper#is_cjk? */
    public function isCjk(): bool
    {
        return \in_array(strtolower($this->i18n->locale()), ['ja', 'zh-tw', 'zh', 'ko'], true);
    }

    /**
     * textilizable(..., only_path: false, edit_section_links: false, headings: false, inline_attachments: false)
     *
     * @param array<string, mixed> $options extra TextFormatter options (object, attachments, ...)
     */
    public function formatText(?string $text, ?Project $project, array $options = []): string
    {
        $html = $this->formatter->format($text, $project, ['headings' => false] + $options);

        return $this->absoluteLinks($html);
    }

    /** Setting.text_formatting rendering of a plain text (ITCPDF#formatted_text) */
    public function formattedText(?string $text): string
    {
        return $this->absoluteLinks($this->formatter->format($text));
    }

    /** only_path: false - relative links are made absolute (ITCPDF#get_sever_url) */
    public function absoluteLinks(string $html): string
    {
        $base = $this->baseUrl();

        return preg_replace_callback('/(<a\b[^>]*\shref=")(\/[^"]*)"/i', static fn (array $m) => $m[1].$base.$m[2].'"', $html) ?? $html;
    }

    public function baseUrl(): string
    {
        $request = $this->requests->getCurrentRequest();
        if (null !== $request) {
            return $request->getSchemeAndHttpHost().$request->getBasePath();
        }
        $host = $this->settings->string('host_name') ?: 'localhost';

        return ($this->settings->string('protocol') ?: 'http').'://'.rtrim($host, '/');
    }

    public function formatDate(?\DateTimeInterface $d): string
    {
        return $this->i18n->formatDate($d);
    }

    public function formatTime(?\DateTimeInterface $t): string
    {
        return $this->i18n->formatTime($t);
    }

    /** l_hours: "%.2f hours" (or h:mm with the minutes timespan format) */
    public function lHours(?float $hours): string
    {
        $hours = (float) $hours;

        return $this->i18n->l($hours < 2.0 ? 'label_f_hour' : 'label_f_hour_plural', ['value' => $this->i18n->formatHours($hours)]);
    }

    /** ActionView number_to_human_size */
    public static function humanSize(int $size): string
    {
        if ($size < 1024) {
            return 1 === $size ? '1 Byte' : $size.' Bytes';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = (float) $size;
        $i = -1;
        while ($value >= 1024 && $i < 3) {
            $value /= 1024;
            ++$i;
        }
        $s = rtrim(rtrim(number_format($value, $value >= 100 ? 0 : ($value >= 10 ? 1 : 2), '.', ''), '0'), '.');

        return $s.' '.$units[$i];
    }

    /** Ruby String#truncate (omission "...") */
    public static function truncate(string $s, int $length): string
    {
        if (mb_strlen($s) <= $length) {
            return $s;
        }

        return mb_substr($s, 0, max(0, $length - 3)).'...';
    }

    /** send_file_headers! / send_data with an attachment (or inline) disposition */
    public static function response(string $content, string $filename, string $type = 'application/pdf', string $disposition = 'attachment'): Response
    {
        $ascii = (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename);
        if ('' === $ascii) {
            $ascii = preg_replace('/[^\x20-\x7e]/', '?', $filename) ?? 'export';
        }
        $ascii = str_replace(['"', '\\'], ['%22', '\\\\'], $ascii);
        $header = $disposition.'; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($filename);

        return new Response($content, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => $header,
            'Content-Transfer-Encoding' => 'binary',
        ]);
    }
}
