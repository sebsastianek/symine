<?php

declare(strict_types=1);

namespace App\Helper;

use App\Entity\Issue;
use App\Entity\Version;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Port of Redmine::Helpers::Calendar (month view). */
#[Exclude]
final class Calendar
{
    public readonly \DateTimeImmutable $startdt;
    public readonly \DateTimeImmutable $enddt;
    /** @var array<string, list<Issue|Version>> */
    private array $ending = [];
    /** @var array<string, list<Issue>> */
    private array $starting = [];

    /**
     * @param int        $firstWday     1 = Monday ... 7 = Sunday
     * @param list<int>  $nonWorkingDays cwday numbers (Setting.non_working_week_days)
     */
    public function __construct(
        private readonly \DateTimeImmutable $date,
        public readonly int $firstWday,
        private readonly array $nonWorkingDays,
        private readonly \DateTimeImmutable $today,
    ) {
        $start = $date->setDate((int) $date->format('Y'), (int) $date->format('n'), 1)->setTime(0, 0);
        $end = $start->modify('last day of this month');
        $start = $start->modify('-'.((((int) $start->format('N')) - $firstWday + 7) % 7).' days');
        $end = $end->modify('+'.(($this->lastWday() - (int) $end->format('N') + 7) % 7).' days');
        $this->startdt = $start;
        $this->enddt = $end;
    }

    /** Setting.start_of_week (1, 6, 7) or the locale's general_first_day_of_week */
    public static function firstWdayFrom(string $setting, string $localeDefault): int
    {
        $s = (int) $setting;
        $v = \in_array($s, [1, 6, 7], true) ? $s : (int) $localeDefault;

        return (($v - 1) % 7 + 7) % 7 + 1;
    }

    public function lastWday(): int
    {
        return (($this->firstWday + 5) % 7) + 1;
    }

    public function month(): int
    {
        return (int) $this->date->format('n');
    }

    /** @return list<list<\DateTimeImmutable>> weeks of days */
    public function weeks(): array
    {
        $days = [];
        for ($d = $this->startdt; $d <= $this->enddt; $d = $d->modify('+1 day')) {
            $days[] = $d;
        }

        return array_chunk($days, 7);
    }

    public static function weekNumber(\DateTimeImmutable $day): int
    {
        return (int) $day->modify('+'.((11 - (int) $day->format('N')) % 7).' days')->format('W');
    }

    public function dayCssClasses(\DateTimeImmutable $day): string
    {
        $css = (int) $day->format('n') === $this->month() ? 'this-month' : 'other-month';
        if ($day->format('Y-m-d') === $this->today->format('Y-m-d')) {
            $css .= ' today';
        }
        if (\in_array((int) $day->format('N'), $this->nonWorkingDays, true)) {
            $css .= ' nwday';
        }

        return $css;
    }

    /** @param list<Issue|Version> $events */
    public function setEvents(array $events): void
    {
        $this->ending = [];
        $this->starting = [];
        foreach ($events as $e) {
            $due = $e instanceof Version ? $e->getDueDate() : $e->dueDate;
            if (null !== $due) {
                $this->ending[$due->format('Y-m-d')][] = $e;
            }
            if ($e instanceof Issue && null !== $e->startDate) {
                $this->starting[$e->startDate->format('Y-m-d')][] = $e;
            }
        }
    }

    /** @return list<Issue|Version> */
    public function eventsOn(\DateTimeImmutable $day): array
    {
        $k = $day->format('Y-m-d');
        $out = [];
        foreach (array_merge($this->ending[$k] ?? [], $this->starting[$k] ?? []) as $e) {
            if (!\in_array($e, $out, true)) {
                $out[] = $e;
            }
        }

        return $out;
    }
}
