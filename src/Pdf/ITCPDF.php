<?php

declare(strict_types=1);

namespace App\Pdf;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

if (!\defined('TCPDF_SILENCE_DEPRECATION')) {
    \define('TCPDF_SILENCE_DEPRECATION', true);
}

/**
 * Port of Redmine::Export::PDF::ITCPDF (lib/redmine/export/pdf/itcpdf.rb) on top of classic TCPDF:
 * A4 document without header, fonts taken from the locale (general_pdf_fontname /
 * general_pdf_monospaced_fontname), footer with the date and "page/pages".
 *
 * Images of formatted cells are resolved through PdfImageResolver (attachments only, embedded as data).
 */
#[Exclude]
class ITCPDF extends \TCPDF
{
    public string $rdmFooterDate = '';

    private string $rdmFontForContent;
    private string $rdmMonospacedFont;
    private string $rdmFontForFooter;

    /** @param array{fontname: string, monospaced: string, rtl: bool, language: string} $locale */
    public function __construct(
        private readonly array $rdmLocale,
        string $orientation = 'P',
        private readonly ?PdfImageResolver $rdmImages = null,
        bool $compress = true,
    ) {
        parent::__construct($orientation, 'mm', 'A4', true, 'UTF-8', false);
        $this->setCompression($compress && \function_exists('gzcompress'));
        // no hidden "Powered by TCPDF" link (RBPDF does not add one)
        $this->tcpdflink = false;
        $this->setPrintHeader(false);
        $this->setRTL($rdmLocale['rtl']);
        $this->setFooterMargin(10);
        // RBPDF (TCPDF 5) pads cells on all sides with the same cell margin
        $c = $this->getCellPaddings()['L'];
        $this->setCellPaddings($c, $c, $c, $c);

        $this->rdmFontForContent = self::availableFont($rdmLocale['fontname']);
        $this->rdmMonospacedFont = self::availableFont($rdmLocale['monospaced'], 'dejavusansmono');
        $this->rdmFontForFooter = $this->rdmFontForContent;
        $this->setCreator('Redmine');
        $this->setFont($this->rdmFontForContent);

        $this->setHeaderFont([$this->rdmFontForContent, '', 10]);
        $this->setFooterFont([$this->rdmFontForContent, '', 8]);
        $this->setDefaultMonospacedFont($this->rdmMonospacedFont);
        $this->setDisplayMode('default', 'OneColumn');
    }

    /** Font name if TCPDF ships it, DejaVu otherwise (e.g. a locale font that is not installed) */
    public static function availableFont(string $name, string $fallback = 'dejavusans'): string
    {
        $file = strtolower(str_replace([' ', '-'], '', $name));
        if ('' !== $file && preg_match('/^[a-z0-9_]+$/', $file) && is_file(K_PATH_FONTS.$file.'.php')) {
            return $file;
        }

        return $fallback;
    }

    public function isRtl(): bool
    {
        return $this->rdmLocale['rtl'];
    }

    public function language(): string
    {
        return $this->rdmLocale['language'];
    }

    public function setFontStyle(string $style, float $size): void
    {
        $this->setFont($this->rdmFontForContent, $style, $size);
    }

    public function setFont($family, $style = '', $size = null, $fontfile = '', $subset = 'default', $out = true)
    {
        $style = (string) $style;
        $f = strtolower((string) $family);
        // FreeSerif Bold Thai font has problem.
        if ('freeserif' === $f) {
            $style = str_replace('B', '', $style);
        }
        // DejaVuSans Italic Arabic and Persian font has problem.
        if ('dejavusans' === $f && 'vi' !== strtolower($this->rdmLocale['language'])) {
            $style = str_replace('I', '', $style);
        }
        // DejaVuSansMono Italic Arabic font has problem
        if ('dejavusansmono' === $f) {
            $style = str_replace('I', '', $style);
        }
        parent::setFont($family, $style, $size, $fontfile, $subset, $out);
    }

    public function rdmCell(float $w, float $h = 0, string $txt = '', int|string $border = 0, int $ln = 0, string $align = '', bool|int $fill = 0, string $link = ''): void
    {
        // RBPDF minimum cell height is the font line height, without the vertical paddings
        $h = max($h, $this->FontSize * $this->cell_height_ratio);
        $this->Cell($w, $h, $txt, $border, $ln, $align, (bool) $fill, $link, 0, true);
    }

    public function rdmMultiCell(float $w, float $h = 0, string $txt = '', int|string $border = 0, string $align = '', bool|int $fill = 0, int $ln = 1): void
    {
        $this->MultiCell($w, $h, $txt, $border, $align, (bool) $fill, $ln);
        // RBPDF keeps the line height of the font (without the cell paddings) for a following Ln()
        $this->lasth = $this->FontSize * $this->cell_height_ratio;
    }

    /**
     * RDMwriteFormattedCell: writes formatted HTML (output of the text formatter).
     *
     * @param list<\App\Entity\Attachment> $attachments attachments used to resolve images
     */
    public function rdmWriteFormattedCell(float $w, float $h, float|string $x, float|string $y, string $txt = '', array $attachments = [], int|string $border = 0, int $ln = 1, bool|int $fill = 0): void
    {
        $css = ' <style>
          table, td {
            border: 2px #ff0000 solid;
          }
          th {  background-color:#EEEEEE; padding: 4px; white-space:nowrap; text-align: center;  font-style: bold;}
          pre {
            background-color: #fafafa;
          }
          </style>';
        // Strip {{toc}} tags
        $txt = preg_replace('#<p>\{\{((<|&lt;)|(>|&gt;))?toc\}\}</p>#i', '', $txt) ?? $txt;
        $txt = $this->rdmImages?->embed($txt, $attachments) ?? PdfImageResolver::stripImages($txt);
        $this->writeHTMLCell($w, $h, $x, $y, $css.$txt, $border, $ln, (bool) $fill);
        $this->lasth = $this->FontSize * $this->cell_height_ratio;
    }

    public function Footer()
    {
        $this->setFont($this->rdmFontForFooter, 'I', 8);
        $this->setX(15);
        $this->rdmCell(0, 5, $this->rdmFooterDate, 0, 0, $this->getRTL() ? 'R' : 'L');
        $this->setX(-30);
        $this->rdmCell(0, 5, $this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, 0, 'C');
    }

    /** Returns the document as a string */
    public function render(): string
    {
        return $this->Output('', 'S');
    }
}
