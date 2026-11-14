<?php

declare(strict_types=1);

namespace App\Import;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Equivalent of Redmine's Issue#notify = false (issues created by a CSV import with notifications
 * disabled) and of Mailer.with_deliveries(false) (project copy). IssueEvent has no "notify" flag, so
 * the mailer subscriber asks this registry whether an event must be ignored.
 *
 * While a copy runs with notifications off, every notification is suppressed (begin/end); an import with
 * notifications off only silences the creation of its issues (beginSilentCreations/endSilentCreations);
 * suppress() disables the notifications of one issue for the rest of the request.
 *
 * The state belongs to the current request: this is a regular service, reset by the kernel between two
 * requests (kernel.reset), so nothing leaks into the next request of a long-running worker.
 */
final class ImportNotificationSuppressor implements ResetInterface
{
    /** @var array<int, true> */
    private array $issueIds = [];
    private int $depth = 0;
    private int $silentCreations = 0;

    public function suppress(int $issueId): void
    {
        $this->issueIds[$issueId] = true;
    }

    public function unsuppress(int $issueId): void
    {
        unset($this->issueIds[$issueId]);
    }

    public function isSuppressed(int $issueId): bool
    {
        return $this->depth > 0 || isset($this->issueIds[$issueId]);
    }

    /** True while an import or a copy without notifications is running (all notifications suppressed) */
    public function isActive(): bool
    {
        return $this->depth > 0;
    }

    public function begin(): void
    {
        ++$this->depth;
    }

    public function end(): void
    {
        $this->depth = max(0, $this->depth - 1);
    }

    /**
     * Issue#notify = false for the issues created meanwhile (CSV import with notifications disabled): their
     * "issue added" notification is skipped. Like in Redmine nothing else is: the journals written on other
     * issues during the import (e.g. "Subtask added" on a parent) are notified as usual.
     */
    public function beginSilentCreations(): void
    {
        ++$this->silentCreations;
    }

    public function endSilentCreations(): void
    {
        $this->silentCreations = max(0, $this->silentCreations - 1);
    }

    public function areCreationsSilent(): bool
    {
        return $this->silentCreations > 0;
    }

    public function reset(): void
    {
        $this->issueIds = [];
        $this->depth = 0;
        $this->silentCreations = 0;
    }
}
