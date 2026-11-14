<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\CustomField;
use App\Entity\Project;
use App\I18n\Localizer;
use App\I18n\RailsTimeZones;
use App\Service\CustomFieldService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** Helpers used by the My page / My account / account / timelog templates. */
final class MyAccountExtension extends AbstractExtension
{
    public function __construct(
        private readonly CustomFieldService $customFields,
        private readonly Localizer $i18n,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cf_options', fn (CustomField $f, ?Project $p = null) => $this->customFields->possibleValuesOptions($f, $p)),
            new TwigFunction('cf_format', $this->customFields->formatValue(...)),
            new TwigFunction('time_zone_options', self::timeZoneOptions(...)),
            new TwigFunction('l_hours', fn (?float $h) => $this->i18n->l(($h ?? 0) < 2.0 ? 'label_f_hour' : 'label_f_hour_plural', ['value' => $this->i18n->formatHours($h ?? 0.0)])),
            new TwigFunction('my_sort_add', static fn (array $sort, string $column) => \App\Service\MyPageService::sortToString(\App\Service\MyPageService::sortAdd($sort, $column))),
            new TwigFunction('l_hours_short', fn (?float $h) => $this->i18n->l('label_f_hour_short', ['value' => $this->i18n->formatHours($h ?? 0.0)])),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('html_hours', static fn (?string $t) => preg_replace('/(\d+)([.,:])(\d+)/', '<span class="hours hours-int">$1</span><span class="hours hours-dec">$2$3</span>', htmlspecialchars((string) $t)) ?? '', ['is_safe' => ['html']]),
            new TwigFilter('account_js', self::escapeJavascript(...), ['is_safe' => ['all']]),
        ];
    }

    /**
     * escape_javascript of the *.js.twig views of the account area (my/add_block.js.twig...): makes a string safe
     * between the quotes of a JavaScript string.
     */
    public static function escapeJavascript(mixed $s): string
    {
        return strtr((string) $s, ['\\' => '\\\\', '</' => '<\/', "\r\n" => '\n', "\n" => '\n', "\r" => '\n', '"' => '\"', "'" => "\\'"]);
    }

    /**
     * Rails time_zone_select options: [[name, "(GMT+01:00) Name"], ...] sorted by offset and name.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function timeZoneOptions(): array
    {
        static $options = null;
        if (null !== $options) {
            return $options;
        }
        $rows = [];
        $year = (int) date('Y');
        foreach (RailsTimeZones::MAP as $name => $iana) {
            try {
                $tz = new \DateTimeZone($iana);
            } catch (\Throwable) {
                continue;
            }
            // standard (non DST) offset, like ActiveSupport::TimeZone#utc_offset
            $offset = null;
            foreach ([new \DateTimeImmutable("$year-01-15", $tz), new \DateTimeImmutable("$year-07-15", $tz)] as $d) {
                if ('0' === $d->format('I')) {
                    $offset = $d->getOffset();
                    break;
                }
            }
            $offset ??= (new \DateTimeImmutable("$year-01-15", $tz))->getOffset();
            $sign = $offset < 0 ? '-' : '+';
            $abs = abs($offset);
            $label = \sprintf('(GMT%s%02d:%02d) %s', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60), $name);
            $rows[] = [$offset, $name, $label];
        }
        usort($rows, static fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $options = array_map(static fn ($r) => [$r[1], $r[2]], $rows);
    }
}
