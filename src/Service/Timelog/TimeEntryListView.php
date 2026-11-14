<?php

declare(strict_types=1);

namespace App\Service\Timelog;

use App\Entity\Project;
use App\Entity\TimeEntry;
use App\I18n\Localizer;
use App\Query\QueryColumn;
use App\Query\TimeEntry\TimeEntryQuery;
use App\Security\CurrentUser;
use App\Service\AccountService;
use App\Twig\TimelogExtension;

/**
 * What the spent time list shows (timelog/index): the page of entries of the query, with the heading of each
 * group and its totals when the list is grouped.
 */
final class TimeEntryListView
{
    public function __construct(
        private readonly TimelogQueries $queries,
        private readonly TimelogExtension $helper,
        private readonly AccountService $accounts,
        private readonly CurrentUser $currentUser,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * @param array{offset: int, limit: int} $pager
     *
     * @return array<string, mixed>
     */
    public function view(TimeEntryQuery $query, ?Project $project, array $pager): array
    {
        $entries = $query->isValid() ? $query->entries($pager['offset'], $pager['limit']) : [];
        $user = $this->currentUser->get();

        return [
            'entries' => $entries,
            'rows' => $this->groupRows($query, $entries),
            'pager' => $pager,
            'totals' => $query->isValid() ? $query->totals() : [],
            'atom_key' => $user->isLogged() ? $this->accounts->atomKey($user) : null,
            'body_css' => 'controller-timelog action-index',
        ] + $this->queries->view($query, $project);
    }

    /**
     * @param list<TimeEntry> $entries
     *
     * @return list<array{entry: TimeEntry, group_name: ?string, group_count: ?int, group_totals: ?string}>
     */
    private function groupRows(TimeEntryQuery $query, array $entries): array
    {
        $rows = [];
        $counts = $query->resultCountByGroup();
        $totalsByGroup = [];
        if ($query->isGrouped()) {
            foreach ($query->totalableColumns() as $c) {
                $totalsByGroup[] = [$c, $query->totalByGroupFor($c)];
            }
        }
        $previous = null;
        foreach ($entries as $e) {
            $row = ['entry' => $e, 'group_name' => null, 'group_count' => null, 'group_totals' => null];
            if (null !== $counts) {
                $key = $query->groupKey($e);
                if ($key !== $previous) {
                    $row['group_name'] = $this->helper->groupLabel($query, $e);
                    $row['group_count'] = $counts[$key] ?? null;
                    $totals = [];
                    foreach ($totalsByGroup as [$c, $byGroup]) {
                        $totals[] = $this->totalTag($c, $byGroup[$key] ?? 0);
                    }
                    $row['group_totals'] = implode(' ', $totals);
                    $previous = $key;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function totalTag(QueryColumn $c, float|int $value): string
    {
        $v = 'hours' === $c->name ? $this->i18n->formatHours((float) $value) : (\is_int($value) ? (string) $value : number_format($value, 2, '.', ''));

        return '<span class="total-for-'.str_replace('_', '-', $c->name).'"><span>'.htmlspecialchars($c->caption).':</span> <span class="value">'.htmlspecialchars($v).'</span></span>';
    }
}
