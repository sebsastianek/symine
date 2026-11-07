<?php

declare(strict_types=1);

namespace App\I18n;

use App\Entity\User;
use App\Security\CurrentUser;
use App\Service\Settings;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Port of Redmine::I18n: translations (Redmine keys and %{param} interpolation),
 * date/time/hours formatting according to settings and user preferences.
 */
final class Localizer
{
    private ?string $locale = null;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly Settings $settings,
        private readonly CurrentUser $currentUser,
        private readonly RequestStack $requests,
        private readonly string $projectDir,
    ) {
    }

    /** @return list<string> */
    public function availableLocales(): array
    {
        static $locales = null;
        if (null === $locales) {
            $locales = [];
            foreach (glob($this->projectDir.'/translations/messages.*.yaml') ?: [] as $f) {
                $locales[] = substr(basename($f, '.yaml'), \strlen('messages.'));
            }
            sort($locales);
        }

        return $locales;
    }

    /** @return array<string, string> locale => language name */
    public function languageOptions(): array
    {
        $out = [];
        foreach ($this->availableLocales() as $loc) {
            $out[$loc] = $this->translator->trans('general_lang_name', [], 'messages', $loc);
        }
        asort($out);

        return $out;
    }

    public function locale(): string
    {
        if (null !== $this->locale) {
            return $this->locale;
        }
        $user = $this->currentUser->get();
        $candidates = [];
        if ($user->isLogged() && $user->language) {
            $candidates[] = $user->language;
        }
        if (!$this->settings->bool('force_default_language_for_anonymous') || $user->isLogged()) {
            if (!$this->settings->bool('force_default_language_for_loggedin') || !$user->isLogged()) {
                if ($req = $this->requests->getMainRequest()) {
                    foreach ($req->getLanguages() as $lang) {
                        $candidates[] = str_replace('_', '-', $lang);
                        $candidates[] = substr($lang, 0, 2);
                    }
                }
            }
        }
        $candidates[] = $this->settings->string('default_language') ?: 'en';
        $available = $this->availableLocales();
        foreach ($candidates as $c) {
            foreach ($available as $a) {
                if (0 === strcasecmp($a, $c)) {
                    return $this->locale = $a;
                }
            }
        }

        return $this->locale = 'en';
    }

    public function setLocale(?string $locale): void
    {
        $this->locale = $locale;
    }

    /**
     * Translates a Redmine key. Params are given without the %{} wrapper: l('label_x_issues', ['count' => 3]).
     *
     * @param array<string, mixed> $params
     */
    public function l(string $key, array $params = [], ?string $locale = null): string
    {
        $locale ??= $this->locale();
        if (\array_key_exists('count', $params)) {
            $count = (int) $params['count'];
            $suffixes = 0 === $count ? ['zero', 'other'] : (1 === $count ? ['one', 'other'] : ['other']);
            foreach ($suffixes as $suffix) {
                $t = $this->raw($key.'.'.$suffix, $locale);
                if (null !== $t) {
                    return $this->interpolate($t, $params);
                }
            }
        }
        $t = $this->raw($key, $locale) ?? $this->raw($key, 'en') ?? $this->humanize($key);

        return $this->interpolate($t, $params);
    }

    public function has(string $key, ?string $locale = null): bool
    {
        return null !== $this->raw($key, $locale ?? $this->locale());
    }

    private function raw(string $key, string $locale): ?string
    {
        $catalogue = method_exists($this->translator, 'getCatalogue') ? $this->translator->getCatalogue($locale) : null;
        if (null !== $catalogue) {
            if ($catalogue->defines($key)) {
                return $catalogue->get($key);
            }
            $fallback = $catalogue->getFallbackCatalogue();
            while (null !== $fallback) {
                if ($fallback->defines($key)) {
                    return $fallback->get($key);
                }
                $fallback = $fallback->getFallbackCatalogue();
            }

            return null;
        }
        $t = $this->translator->trans($key, [], 'messages', $locale);

        return $t === $key ? null : $t;
    }

    /** @param array<string, mixed> $params */
    private function interpolate(string $text, array $params): string
    {
        if ([] === $params) {
            return $text;
        }
        $map = [];
        foreach ($params as $k => $v) {
            $map['%{'.$k.'}'] = \is_scalar($v) || $v instanceof \Stringable ? (string) $v : '';
        }

        return strtr($text, $map);
    }

    private function humanize(string $key): string
    {
        $key = preg_replace('/^(label|field|button|text|notice|error|setting|permission|project_module|status)_/', '', $key) ?? $key;

        return ucfirst(str_replace('_', ' ', $key));
    }

    // --- Dates -----------------------------------------------------------------

    public function userTimezone(?User $user = null): \DateTimeZone
    {
        $user ??= $this->currentUser->get();
        $tz = $user->isLogged() ? $user->getPref()->timeZone : null;
        $tz = $tz ?: $this->settings->string('default_users_time_zone');
        if ($tz) {
            $iana = RailsTimeZones::toIana($tz);
            if (null !== $iana) {
                return new \DateTimeZone($iana);
            }
        }

        return new \DateTimeZone(date_default_timezone_get());
    }

    public function today(?User $user = null): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', $this->userTimezone($user));
    }

    public function formatDate(?\DateTimeInterface $date): string
    {
        if (null === $date) {
            return '';
        }
        $format = $this->settings->string('date_format') ?: $this->l('date.formats.default');

        return $this->strftime($date, $format);
    }

    public function formatTime(?\DateTimeInterface $time, bool $includeDate = true): string
    {
        if (null === $time) {
            return '';
        }
        $local = \DateTimeImmutable::createFromInterface($time)->setTimezone($this->userTimezone());
        $format = $this->settings->string('time_format') ?: $this->l('time.formats.time');

        return ($includeDate ? $this->formatDate($local).' ' : '').$this->strftime($local, $format);
    }

    /** Ruby strftime subset used by Redmine locales and settings */
    public function strftime(\DateTimeInterface $date, string $format): string
    {
        return preg_replace_callback('/%([-]?)([a-zA-Z%])/', function (array $m) use ($date) {
            $noPad = '-' === $m[1];
            $r = match ($m[2]) {
                'Y' => $date->format('Y'),
                'y' => $date->format('y'),
                'm' => $noPad ? $date->format('n') : $date->format('m'),
                'd' => $noPad ? $date->format('j') : $date->format('d'),
                'e' => $date->format('j'),
                'H' => $noPad ? $date->format('G') : $date->format('H'),
                'I' => $noPad ? $date->format('g') : $date->format('h'),
                'l' => $date->format('g'),
                'M' => $date->format('i'),
                'S' => $date->format('s'),
                'p' => $date->format('A'),
                'P' => $date->format('a'),
                'b', 'h' => $this->l('date.abbr_month_names.'.$date->format('n')),
                'B' => $this->l('date.month_names.'.$date->format('n')),
                'a' => $this->l('date.abbr_day_names.'.$date->format('w')),
                'A' => $this->l('date.day_names.'.$date->format('w')),
                'j' => $date->format('z') + 1,
                'Z' => $date->format('T'),
                'z' => $date->format('O'),
                '%' => '%',
                default => $m[0],
            };

            return (string) $r;
        }, $format) ?? $format;
    }

    public function formatHours(?float $hours): string
    {
        if (null === $hours) {
            return '';
        }
        $minutes = (int) round($hours * 60);
        if ('minutes' === $this->settings->string('timespan_format')) {
            return \sprintf('%s%d:%02d', $minutes < 0 ? '-' : '', intdiv(abs($minutes), 60), abs($minutes) % 60);
        }

        return number_format($minutes / 60, 2, '.', '');
    }

    /** Rails distance_of_time_in_words, as used in "Added by X 3 days ago" */
    public function distanceOfTimeInWords(\DateTimeInterface $from, ?\DateTimeInterface $to = null): string
    {
        $to ??= new \DateTimeImmutable();
        $minutes = (int) round(abs($to->getTimestamp() - $from->getTimestamp()) / 60);
        $p = 'datetime.distance_in_words.';

        return match (true) {
            $minutes <= 1 => 0 === $minutes ? $this->l($p.'less_than_x_minutes', ['count' => 1]) : $this->l($p.'x_minutes', ['count' => 1]),
            $minutes < 45 => $this->l($p.'x_minutes', ['count' => $minutes]),
            $minutes < 90 => $this->l($p.'about_x_hours', ['count' => 1]),
            $minutes < 1440 => $this->l($p.'about_x_hours', ['count' => (int) round($minutes / 60)]),
            $minutes < 2520 => $this->l($p.'x_days', ['count' => 1]),
            $minutes < 43200 => $this->l($p.'x_days', ['count' => (int) round($minutes / 1440)]),
            $minutes < 86400 => $this->l($p.'about_x_months', ['count' => (int) round($minutes / 43200)]),
            $minutes < 525600 => $this->l($p.'x_months', ['count' => (int) round($minutes / 43200)]),
            default => $this->l($p.'about_x_years', ['count' => intdiv($minutes, 525600)]),
        };
    }

    public function dayName(int $wday): string
    {
        return $this->l('date.day_names.'.($wday % 7));
    }

    public function monthName(int $month): string
    {
        return $this->l('date.month_names.'.$month);
    }

    /** First day of week: setting start_of_week ('' = locale default, 1 = monday, 6 = saturday, 7 = sunday) */
    public function startOfWeek(): int
    {
        $s = $this->settings->string('start_of_week');
        if ('' !== $s) {
            return (int) $s % 7;
        }
        $l = $this->l('general_first_day_of_week');

        return ctype_digit($l) ? (int) $l % 7 : 1;
    }
}
