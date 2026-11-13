<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Helper\Gantt;

/**
 * Port of Redmine::Helpers::Gantt#to_pdf and #to_image (lib/redmine/helpers/gantt.rb).
 * The PNG is drawn with GD instead of MiniMagick, with the same geometry and colors.
 */
final class GanttExporter
{
    // Gantt::PDF
    private const int MAX_CHARACTERS_FOR_SUBJECT = 45;
    private const int TOTAL_WIDTH = 280;
    private const int LEFT_PANE_WIDTH = 100;

    public function __construct(private readonly PdfSupport $pdf)
    {
    }

    // ------------------------------------------------------------------------------------------
    // PDF
    // ------------------------------------------------------------------------------------------

    public function toPdf(Gantt $gantt): string
    {
        $pdf = $this->pdf->newPdf();
        $project = $gantt->project;
        $pdf->setTitle($this->pdf->l('label_gantt').' '.($project?->name ?? ''));
        $pdf->AddPage('L');
        $pdf->setFontStyle('B', 12);
        $pdf->setX(15);
        $pdf->rdmCell(self::LEFT_PANE_WIDTH, 20, $project?->name ?? '');
        $pdf->Ln();
        $pdf->setFontStyle('B', 9);
        $subjectWidth = self::LEFT_PANE_WIDTH;
        $headerHeight = 5;
        $headersHeight = $headerHeight;
        $showWeeks = $showDays = $showDayNum = false;
        if ($gantt->months < 7) {
            $showWeeks = true;
            $headersHeight = 2 * $headerHeight;
            if ($gantt->months < 3) {
                $showDays = true;
                $headersHeight = 3 * $headerHeight;
                if ($gantt->months < 2) {
                    $showDayNum = true;
                    $headersHeight = 4 * $headerHeight;
                }
            }
        }
        $from = Gantt::dayNumber($gantt->dateFrom);
        $to = Gantt::dayNumber($gantt->dateTo);
        $gWidth = self::TOTAL_WIDTH - self::LEFT_PANE_WIDTH;
        $zoom = $gWidth / ($to - $from + 1);
        $yStart = $pdf->GetY();

        // Months headers
        $month = $gantt->dateFrom;
        $left = $subjectWidth;
        for ($i = 0; $i < $gantt->months; ++$i) {
            $next = $month->modify('+1 month');
            $width = (Gantt::dayNumber($next) - Gantt::dayNumber($month)) * $zoom;
            $pdf->setY($yStart);
            $pdf->setX($left);
            $pdf->rdmCell($width, $headerHeight, $month->format('Y').'-'.$month->format('n'), 'LTR', 0, 'C');
            $left += $width;
            $month = $next;
        }

        // Weeks headers
        if ($showWeeks) {
            $left = $subjectWidth;
            $cwday = (int) $gantt->dateFrom->format('N');
            if (1 === $cwday) {
                $week = $from;
            } else {
                // find next monday after date_from
                $week = $from + (7 - $cwday + 1);
                $width = (7 - $cwday + 1) * $zoom - 1;
                $pdf->setY($yStart + $headerHeight);
                $pdf->setX($left);
                $pdf->rdmCell($width + 1, $headerHeight, '', 'LTR');
                $left += $width + 1;
            }
            while ($week <= $to) {
                $width = $week + 6 <= $to ? 7 * $zoom : ($to - $week + 1) * $zoom;
                $pdf->setY($yStart + $headerHeight);
                $pdf->setX($left);
                $pdf->rdmCell($width, $headerHeight, $width >= 5 ? (string) (int) self::date($week)->format('W') : '', 'LTR', 0, 'C');
                $left += $width;
                $week += 7;
            }
        }

        // Day numbers headers
        if ($showDayNum) {
            $left = $subjectWidth;
            $pdf->setFontStyle('B', 7);
            for ($d = $from; $d <= $to; ++$d) {
                $date = self::date($d);
                $pdf->setY($yStart + $headerHeight * 2);
                $pdf->setX($left);
                $this->dayTextColor($pdf, $gantt, $date);
                $pdf->rdmCell($zoom, $headerHeight, $date->format('j'), 'LTR', 0, 'C');
                $left += $zoom;
            }
        }

        // Days headers
        if ($showDays) {
            $left = $subjectWidth;
            $pdf->setFontStyle('B', 7);
            for ($d = $from; $d <= $to; ++$d) {
                $date = self::date($d);
                $pdf->setY($yStart + $headerHeight * ($showDayNum ? 3 : 2));
                $pdf->setX($left);
                $this->dayTextColor($pdf, $gantt, $date);
                $pdf->rdmCell($zoom, $headerHeight, mb_substr($this->pdf->i18n->dayName((int) $date->format('N')), 0, 1), 'LTR', 0, 'C');
                $left += $zoom;
            }
        }

        $pdf->setY($yStart);
        $pdf->setX(15);
        $pdf->setTextColor(0);
        $pdf->rdmCell($subjectWidth + $gWidth - 15, $headersHeight, '', 1);

        // Tasks
        $top = $headersHeight + $yStart;
        $indentIncrement = 5;
        foreach ($gantt->rows() as [$object, $depth]) {
            $indent = $depth * $indentIncrement;
            // pdf_subject
            if ($top > 180) {
                $pdf->Line(15, $top, self::TOTAL_WIDTH, $top);
                $pdf->AddPage('L');
                $top = 15;
                $pdf->Line(15, $top - 0.1, self::TOTAL_WIDTH, $top - 0.1);
            }
            $pdf->setY($top);
            $pdf->setX(15);
            $pdf->rdmCell($subjectWidth - 15, 5, str_repeat(' ', $indent).self::truncateSubject($gantt->plainSubject($object), self::MAX_CHARACTERS_FOR_SUBJECT - $indent), 'LR');
            $pdf->setY($top);
            $pdf->setX($subjectWidth);
            $pdf->rdmCell($gWidth, 5, '', 'LR');
            // pdf_task
            $line = $gantt->lineData($object);
            if (null !== $line) {
                $coords = $gantt->coordinates($line['start'], $line['end'], $line['done'], $zoom);
                $this->pdfTask($pdf, $top, $subjectWidth, $coords, $line['markers'], $line['label']);
            }
            $top += 5;
        }
        $pdf->Line(15, $top, self::TOTAL_WIDTH, $top);

        return $pdf->render();
    }

    private function dayTextColor(ITCPDF $pdf, Gantt $gantt, \DateTimeImmutable $date): void
    {
        if ($gantt->isNonWorkingDay($date)) {
            $pdf->setTextColor(134, 142, 150); // oc-gray-6
        } else {
            $pdf->setTextColor(0);
        }
    }

    /** subject.sub(/^(.{limit}[^\s]*\s).*$/, '\1 (...)') */
    private static function truncateSubject(string $subject, int $limit): string
    {
        if ($limit < 0) {
            return $subject;
        }

        return preg_replace('/^(.{'.$limit.'}[^\s]*\s).*$/u', '$1 (...)', $subject) ?? $subject;
    }

    /** @param array<string, int> $coords */
    private function pdfTask(ITCPDF $pdf, float $top, float $subjectWidth, array $coords, bool $markers, string $label): void
    {
        $ratio = $pdf->getCellHeightRatio();
        $pdf->setCellHeightRatio(0.1);
        $height = $markers ? 1 : 2;
        // Renders the task bar, with progress and late
        if (isset($coords['bar_start'], $coords['bar_end'])) {
            $bars = [['bar_end', [206, 212, 218]], ['bar_late_end', [255, 107, 107]], ['bar_progress_end', [81, 207, 102]]];
            foreach ($bars as [$key, $color]) {
                if (!isset($coords[$key])) {
                    continue;
                }
                $width = max(1, $coords[$key] - $coords['bar_start']);
                $pdf->setY($top + 1.5);
                $pdf->setX($subjectWidth + $coords['bar_start']);
                $pdf->setFillColor(...$color);
                $pdf->rdmCell($width, $height, '', 0, 0, '', 1);
            }
        }
        // Renders the markers
        if ($markers) {
            foreach (['start', 'end'] as $key) {
                if (isset($coords[$key])) {
                    $pdf->setY($top + 1);
                    $pdf->setX($subjectWidth + $coords[$key] - 1);
                    $pdf->setFillColor(54, 79, 199); // oc-indigo-9
                    $pdf->rdmCell(2, 2, '', 0, 0, '', 1);
                }
            }
        }
        // Renders the label on the right
        $pdf->setX($subjectWidth + ($coords['bar_end'] ?? 0) + 5);
        $pdf->rdmCell(30, 2, $label);
        $pdf->setCellHeightRatio($ratio);
    }

    private static function date(int $day): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@'.($day * 86400)))->setTimezone(new \DateTimeZone('UTC'));
    }

    // ------------------------------------------------------------------------------------------
    // PNG (GD)
    // ------------------------------------------------------------------------------------------

    public function toImage(Gantt $gantt): string
    {
        $from = Gantt::dayNumber($gantt->dateFrom);
        $to = Gantt::dayNumber($gantt->dateTo);
        $showWeeks = $gantt->zoom > 1;
        $showDays = $gantt->zoom > 2;
        $subjectWidth = 400;
        $headerHeight = 18;
        // width of one day in pixels
        $zoom = $gantt->zoom * 2;
        $gWidth = ($to - $from + 1) * $zoom;
        $rows = $gantt->rows();
        $gHeight = 20 * \count($rows) + 30;
        $headersHeight = $showWeeks ? 2 * $headerHeight : $headerHeight;
        $height = $gHeight + $headersHeight;

        $img = imagecreatetruecolor($subjectWidth + $gWidth + 1, $height);
        if (false === $img) {
            throw new \RuntimeException('Cannot create the gantt image');
        }
        $color = static function (string $hex) use ($img): int {
            $c = imagecolorallocate($img, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));

            return false === $c ? 0 : $c;
        };
        $white = $color('#ffffff');
        $black = $color('#000000');
        $gray5 = $color('#adb5bd');
        imagefilledrectangle($img, 0, 0, $subjectWidth + $gWidth, $height - 1, $white);
        $font = self::ttfFont();
        $text = static function (int $x, int $y, string $s, int $c) use ($img, $font): void {
            if (null !== $font) {
                imagettftext($img, 9, 0, $x, $y, $c, $font, $s);
            } else {
                imagestring($img, 3, $x, $y - 11, $s, $c);
            }
        };
        $rect = static function (int $x1, int $y1, int $x2, int $y2, ?int $fill, ?int $stroke) use ($img): void {
            if (null !== $fill) {
                imagefilledrectangle($img, min($x1, $x2), min($y1, $y2), max($x1, $x2), max($y1, $y2), $fill);
            }
            if (null !== $stroke) {
                imagerectangle($img, min($x1, $x2), min($y1, $y2), max($x1, $x2), max($y1, $y2), $stroke);
            }
        };

        // Subjects
        $top = $headersHeight + 20;
        foreach ($rows as [$object, $depth]) {
            $text(4 + $depth * 20, $top + 2, $gantt->plainSubject($object), $black);
            $top += 20;
        }

        // Months headers
        $month = $gantt->dateFrom;
        $left = $subjectWidth;
        for ($i = 0; $i < $gantt->months; ++$i) {
            $next = $month->modify('+1 month');
            $width = (Gantt::dayNumber($next) - Gantt::dayNumber($month)) * $zoom;
            $rect($left, 0, $left + $width, $height, $white, $gray5);
            $text($left + 8, 14, $month->format('Y').'-'.$month->format('n'), $black);
            $left += $width;
            $month = $next;
        }

        // Weeks headers
        if ($showWeeks) {
            $left = $subjectWidth;
            $cwday = (int) $gantt->dateFrom->format('N');
            if (1 === $cwday) {
                $week = $from;
            } else {
                // find next monday after date_from
                $week = $from + (7 - $cwday + 1);
                $width = (7 - $cwday + 1) * $zoom;
                $rect($left, $headerHeight, $left + $width, 2 * $headerHeight + $gHeight - 1, $white, $gray5);
                $left += $width;
            }
            while ($week <= $to) {
                $width = $week + 6 <= $to ? 7 * $zoom : ($to - $week + 1) * $zoom;
                $rect($left, $headerHeight, $left + $width, 2 * $headerHeight + $gHeight - 1, $white, $gray5);
                $text($left + 2, $headerHeight + 14, (string) (int) self::date($week)->format('W'), $black);
                $left += $width;
                $week += 7;
            }
        }

        // Days details (week-end in grey)
        if ($showDays) {
            $left = $subjectWidth;
            $gray1 = $color('#f1f3f5');
            $gray3 = $color('#dee2e6');
            for ($d = $from; $d <= $to; ++$d) {
                $rect($left, 2 * $headerHeight, $left + $zoom, 2 * $headerHeight + $gHeight - 1, $gantt->isNonWorkingDay(self::date($d)) ? $gray1 : $white, $gray3);
                $left += $zoom;
            }
        }

        // border
        $rect(0, 0, $subjectWidth + $gWidth, $headersHeight, null, $gray5);
        $rect(0, 0, $subjectWidth + $gWidth, $gHeight + $headersHeight - 1, null, $black);

        // content
        $top = $headersHeight + 20;
        foreach ($rows as [$object]) {
            $line = $gantt->lineData($object);
            if (null !== $line) {
                $coords = $gantt->coordinates($line['start'], $line['end'], $line['done'], $zoom);
                $this->imageTask($img, $color, $text, $rect, $top, $subjectWidth, $coords, $line['markers'], $line['label']);
            }
            $top += 20;
        }

        // today red line
        $today = Gantt::dayNumber($gantt->today());
        if ($today >= $from && $today <= $to) {
            $x = ($today - $from + 1) * $zoom + $subjectWidth;
            imageline($img, $x, $headersHeight, $x, $headersHeight + $gHeight - 1, $color('#f03e3e'));
        }

        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();

        return $png;
    }

    /** @param array<string, int> $coords */
    private function imageTask(\GdImage $img, callable $color, callable $text, callable $rect, int $top, int $subjectWidth, array $coords, bool $markers, string $label): void
    {
        $height = $markers ? 3 : 6;
        // Renders the task bar, with progress and late
        if (isset($coords['bar_start'], $coords['bar_end'])) {
            $bars = [['bar_end', '#adb5bd'], ['bar_late_end', '#ff6b6b'], ['bar_progress_end', '#40c057']];
            foreach ($bars as [$key, $hex]) {
                if (isset($coords[$key])) {
                    $rect($subjectWidth + $coords['bar_start'], $top, $subjectWidth + $coords[$key], $top - $height, $color($hex), null);
                }
            }
        }
        // Renders the markers
        if ($markers) {
            foreach (['start', 'end'] as $key) {
                if (isset($coords[$key])) {
                    $x = $subjectWidth + $coords[$key];
                    $y = $top - intdiv($height, 2);
                    imagefilledpolygon($img, [$x - 4, $y, $x, $y - 4, $x + 4, $y, $x, $y + 4], $color('#364fc7'));
                }
            }
        }
        // Renders the label on the right
        $text($subjectWidth + ($coords['bar_end'] ?? 0) + 5, $top + 1, $label, $color('#000000'));
    }

    /** DejaVu Sans TrueType font extracted from TCPDF's compressed font file (cached in the temp dir) */
    private static function ttfFont(): ?string
    {
        if (!\function_exists('imagettftext') || !class_exists(\TCPDF::class)) {
            return null;
        }
        $source = K_PATH_FONTS.'dejavusans.z';
        if (!is_file($source)) {
            return null;
        }
        $target = sys_get_temp_dir().'/symine-gantt-'.md5($source.filemtime($source)).'.ttf';
        if (!is_file($target)) {
            $data = @gzuncompress((string) file_get_contents($source));
            if (false === $data) {
                return null;
            }
            $tmp = $target.'.'.getmypid();
            file_put_contents($tmp, $data);
            rename($tmp, $target);
        }

        return $target;
    }
}
