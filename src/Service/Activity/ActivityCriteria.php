<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\User;

/** What an activity page or feed was asked for: the period, whose activity, and the kinds of events. */
final readonly class ActivityCriteria
{
    /**
     * @param int                $days       length of the period (Setting.activity_days_default)
     * @param \DateTimeImmutable $dateFrom   first day of the period
     * @param \DateTimeImmutable $dateTo     the day after the last day of the period
     * @param ?User              $author     only the activity of this user
     * @param list<string>       $eventTypes the kinds of events the user may see here
     * @param list<string>       $scope      the kinds of events shown
     */
    public function __construct(
        public int $days,
        public \DateTimeImmutable $today,
        public \DateTimeImmutable $dateFrom,
        public \DateTimeImmutable $dateTo,
        public bool $withSubprojects,
        public ?User $author,
        public array $eventTypes,
        public array $scope,
    ) {
    }
}
