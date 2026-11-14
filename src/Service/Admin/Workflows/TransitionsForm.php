<?php

declare(strict_types=1);

namespace App\Service\Admin\Workflows;

use App\Entity\IssueStatus;
use App\I18n\Localizer;
use App\Service\Admin\WorkflowService;

/**
 * The status transitions form of Administration > Workflow (workflows/edit): the tables of allowed
 * transitions of the selected roles and trackers, and their update.
 */
final class TransitionsForm
{
    public function __construct(private readonly WorkflowService $workflows, private readonly Localizer $i18n)
    {
    }

    /**
     * The transitions tables: always allowed, allowed to the author, allowed to the assignee.
     *
     * @return array<string, array{present: bool, rows: list<array<string, mixed>>}> empty when nothing is selected
     */
    public function tables(WorkflowSelection $selection): array
    {
        $tables = [];
        if ($selection->isComplete() && [] !== $selection->statuses) {
            $counts = $this->workflows->transitionCountsByRule($selection->trackers, $selection->roles);
            $max = \count($selection->roles) * \count($selection->trackers);
            foreach (['always', 'author', 'assignee'] as $name) {
                $tables[$name] = [
                    'present' => [] !== $counts[$name],
                    'rows' => $this->transitionRows($name, $selection->statuses, $counts[$name], $max),
                ];
            }
        }

        return $tables;
    }

    /**
     * Replaces the transitions of the selected roles and trackers; boxes left on "no change" keep what each
     * role and tracker has.
     *
     * @param array<int|string, mixed> $transitions old status id => new status id => rule => '0' | '1' | 'no_change'
     */
    public function submit(WorkflowSelection $selection, array $transitions): void
    {
        if (!$selection->isComplete()) {
            return;
        }
        foreach ($transitions as $old => $byNew) {
            foreach ((array) $byNew as $new => $byRule) {
                $transitions[$old][$new] = array_filter((array) $byRule, static fn ($v) => 'no_change' !== $v);
            }
        }
        $this->workflows->replaceTransitions($selection->trackers, $selection->roles, $transitions);
    }

    /**
     * Rows of a transitions table (workflows/_form + transition_tag).
     *
     * @param list<IssueStatus>  $statuses
     * @param array<string, int> $counts   "old-new" => count
     *
     * @return list<array<string, mixed>>
     */
    private function transitionRows(string $name, array $statuses, array $counts, int $max): array
    {
        $rows = [];
        $olds = 'always' === $name ? [null, ...$statuses] : $statuses;
        foreach ($olds as $old) {
            $oldId = $old?->id ?? 0;
            $oldName = null === $old ? $this->i18n->l('label_issue_new') : $old->name;
            $cells = [];
            foreach ($statuses as $new) {
                $w = $counts[$oldId.'-'.$new->id] ?? 0;
                $same = null !== $old && $old->id === $new->id;
                $cells[] = [
                    'new' => $new,
                    'checked' => $same || $w > 0,
                    'title' => $oldName.' » '.$new->name,
                    'kind' => $same ? 'disabled' : ((0 === $w || $w === $max) ? 'checkbox' : 'select'),
                    'value' => 0 !== $w,
                    'tag_name' => 'transitions['.$oldId.']['.$new->id.']['.$name.']',
                    'tag_id' => 'transitions_'.$oldId.'_'.$new->id.'_'.$name,
                    'css' => 'old-status-'.$oldId.' new-status-'.$new->id,
                ];
            }
            $rows[] = ['old_id' => $oldId, 'old_name' => $oldName, 'is_new' => null === $old, 'cells' => $cells];
        }

        return $rows;
    }
}
