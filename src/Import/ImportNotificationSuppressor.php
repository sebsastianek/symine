<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Equivalent of Redmine's Issue#notify = false for issues created by a CSV import with
 * notifications disabled. IssueEvent has no "notify" flag, so the mailer subscriber asks
 * this registry whether an issue event must be ignored.
 *
 * While an import runs with notifications off, every issue event is suppressed (begin/end);
 * the ids of the issues created are also remembered (suppress) for later events of the same request.
 */
final class ImportNotificationSuppressor
{
    /** @var array<int, true> */
    private static array $issueIds = [];
    private static int $depth = 0;

    public static function suppress(int $issueId): void
    {
        self::$issueIds[$issueId] = true;
    }

    public static function isSuppressed(int $issueId): bool
    {
        return self::$depth > 0 || isset(self::$issueIds[$issueId]);
    }

    /** True while an import without notifications is running (all issue notifications suppressed) */
    public static function isActive(): bool
    {
        return self::$depth > 0;
    }

    public static function begin(): void
    {
        ++self::$depth;
    }

    public static function end(): void
    {
        self::$depth = max(0, self::$depth - 1);
    }

    public static function reset(): void
    {
        self::$issueIds = [];
        self::$depth = 0;
    }
}
