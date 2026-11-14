<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Attachment;
use App\Entity\CustomField;
use App\Entity\Group;
use App\Entity\Issue;
use App\Entity\IssueCategory;
use App\Entity\IssueRelation;
use App\Entity\IssueStatus;
use App\Entity\Journal;
use App\Entity\JournalDetail;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Version;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\CustomFieldService;
use App\Service\IssueService;
use App\Service\Settings;
use App\Service\WatcherService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Port of Redmine's IssuesHelper, JournalsHelper, WatchersHelper, AttachmentsHelper and
 * the custom field form helpers used by the issue screens.
 */
final class IssuesExtension extends AbstractExtension
{
    /** Watchable class => [object_type param, label key suffix, project accessor] */
    public const array WATCHABLE_TYPES = [
        'issue' => \App\Entity\Issue::class,
        'wiki_page' => \App\Entity\WikiPage::class,
        'wiki' => \App\Entity\Wiki::class,
        'board' => \App\Entity\Board::class,
        'message' => \App\Entity\Message::class,
        'news' => \App\Entity\News::class,
    ];

    public function __construct(
        private readonly Localizer $i18n,
        private readonly Settings $settings,
        private readonly CurrentUser $currentUser,
        private readonly Authorization $auth,
        private readonly UrlGeneratorInterface $urls,
        private readonly IssueService $issues,
        private readonly CustomFieldService $customFields,
        private readonly WatcherService $watchers,
        private readonly EntityManagerInterface $em,
        private readonly AppExtension $app,
        private readonly \App\Service\TextFormatter $formatter,
        private readonly ?\App\Doctrine\BatchLoader $batch = null,
    ) {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('issue_heading', fn (Issue $i) => $i->tracker->name.' #'.$i->id),
            new TwigFunction('issue_subject_with_tree', $this->renderIssueSubjectWithTree(...), $safe),
            new TwigFunction('issue_details_to_strings', $this->detailsToStrings(...), $safe),
            new TwigFunction('issue_estimated_hours_details', $this->estimatedHoursDetails(...), $safe),
            new TwigFunction('issue_spent_hours_details', $this->spentHoursDetails(...), $safe),
            new TwigFunction('issue_due_date_details', $this->dueDateDetails(...)),
            new TwigFunction('issue_descendants_tree', $this->renderDescendantsTree(...), $safe),
            new TwigFunction('issue_descendants_stats', $this->renderDescendantsStats(...), $safe),
            new TwigFunction('issue_relations_stats', $this->renderRelationsStats(...), $safe),
            new TwigFunction('issue_relations_table', $this->renderIssueRelations(...), $safe),
            new TwigFunction('issue_relation_text', $this->relationText(...), $safe),
            new TwigFunction('issue_relation_types', $this->relationTypeOptions(...)),
            new TwigFunction('issue_custom_values', $this->customValuesForDisplay(...)),
            new TwigFunction('issue_cf_value_html', fn (array $context, CustomField $cf, mixed $value, ?Project $project = null, ?object $customized = null) => $this->customFieldValueHtml($cf, $value, $project, $customized ?? (($context['issue'] ?? null) instanceof Issue ? $context['issue'] : null)), $safe + ['needs_context' => true]),
            new TwigFunction('issue_cf_tag', $this->customFieldTag(...), $safe),
            new TwigFunction('issue_cf_bulk_tag', $this->customFieldBulkTag(...), $safe),
            new TwigFunction('issue_principals_options', $this->principalsOptionsForSelect(...), $safe),
            new TwigFunction('issue_project_tree_options', $this->projectTreeOptions(...), $safe),
            new TwigFunction('issue_version_options', $this->versionOptions(...), $safe),
            new TwigFunction('issue_destroy_confirmation', $this->destroyConfirmationMessage(...)),
            new TwigFunction('issue_new_subtask_url', $this->newSubtaskUrl(...)),
            new TwigFunction('issue_form_update_url', $this->updateIssueFormPath(...)),
            new TwigFunction('issue_journal_css', $this->journalCssClasses(...)),
            new TwigFunction('issue_journal_editable', fn (Journal $j) => $this->issues->isJournalEditable($j)),
            new TwigFunction('l_hours_short', $this->hoursShort(...)),
            new TwigFunction('watcher_link_for', $this->watcherLink(...), $safe),
            new TwigFunction('watcher_css', $this->watcherCss(...)),
            new TwigFunction('watchers_list_for', $this->watchersList(...), $safe),
            new TwigFunction('watchable_type_of', $this->watchableType(...)),
            new TwigFunction('watchable_project', $this->watchableProject(...)),
            new TwigFunction('watchable_watchers', fn (object $o) => $this->watchers->watchers(self::watchableClassName($o), (int) $o->id)),
            new TwigFunction('attachment_link', $this->linkToAttachment(...), $safe),
            new TwigFunction('attachment_thumbnail', $this->thumbnailTag(...), $safe),
            new TwigFunction('attachment_token', static fn (Attachment $a) => $a->id.'.'.$a->digest),
            new TwigFunction('attachment_thumbnailable', static fn (Attachment $a) => $a->isImage()),
            new TwigFunction('actions_dropdown', $this->actionsDropdown(...), $safe),
            new TwigFunction('copy_object_url_link', $this->copyObjectUrlLink(...), $safe),
            new TwigFunction('quote_reply_button', $this->quoteReplyButton(...), $safe),
            new TwigFunction('principal_name', fn (?Principal $p) => $p ? $p->getName($this->settings->string('user_format') ?: null) : ''),
        ];
    }

    // ------------------------------------------------------------------------------------------
    // Issue show helpers
    // ------------------------------------------------------------------------------------------

    public function renderIssueSubjectWithTree(Issue $issue): string
    {
        $s = '';
        $ancestors = array_values(array_filter($issue->getAncestors(), fn (Issue $a) => $this->issues->isVisible($a)));
        foreach ($ancestors as $a) {
            $s .= '<div><p>'.$this->app->linkToIssue($a, ['project' => $issue->project->id !== $a->project->id]).'</p>';
        }
        $s .= '<div><h3>'.htmlspecialchars($issue->subject).'</h3>';

        return $s.str_repeat('</div>', \count($ancestors) + 1);
    }

    public function hoursShort(?float $hours): string
    {
        return null === $hours ? '' : $this->i18n->formatHours($hours).' h';
    }

    public function estimatedHoursDetails(Issue $issue): string
    {
        $total = $this->issues->totalEstimatedHours($issue);
        if (null === $issue->estimatedHours && 0.0 === $total && $issue->isLeaf()) {
            return '';
        }
        if (abs($total - (float) $issue->estimatedHours) < 0.00001) {
            return htmlspecialchars($this->hoursShort($issue->estimatedHours));
        }
        $s = null !== $issue->estimatedHours ? $this->hoursShort($issue->estimatedHours) : '';

        return htmlspecialchars($s.' ('.$this->i18n->l('label_total').': '.$this->hoursShort($total).')');
    }

    public function spentHoursDetails(Issue $issue): string
    {
        $total = $this->issues->totalSpentHours($issue);
        if ($total <= 0) {
            return '';
        }
        $spent = $this->issues->spentHours($issue);
        $path = $this->route('project_time_entries', ['project_id' => $issue->project->identifier, 'issue_id' => '~'.$issue->id]);
        if (abs($total - $spent) < 0.00001) {
            return $this->linkOrText($this->hoursShort($spent), $path);
        }
        if (\in_array($this->settings->string('cross_project_subtasks'), ['system', 'tree', 'hierarchy'], true)) {
            $path = $this->route('time_entries_index', ['issue_id' => '~'.$issue->id]);
        }
        $s = $spent > 0 ? htmlspecialchars($this->hoursShort($spent)) : '';

        return $s.' ('.htmlspecialchars($this->i18n->l('label_total')).': '.$this->linkOrText($this->hoursShort($total), $path).')';
    }

    public function dueDateDetails(Issue $issue): string
    {
        if (null === $issue->dueDate) {
            return '';
        }
        $s = $this->i18n->formatDate($issue->dueDate);
        if (!$issue->isClosed()) {
            $today = $this->i18n->today();
            $days = (int) $today->diff($issue->dueDate)->format('%r%a');
            $distance = $this->distanceOfDateInWords($today, $issue->dueDate);
            $s .= ' ('.$this->i18n->l($days < 0 ? 'label_roadmap_overdue' : 'label_roadmap_due_in', ['value' => $distance]).')';
        }

        return $s;
    }

    private function distanceOfDateInWords(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $days = abs((int) $from->diff($to)->format('%r%a'));
        $p = 'datetime.distance_in_words.';
        if ($days < 1) {
            return $this->i18n->l($p.'x_days', ['count' => 0]);
        }
        if ($days < 30) {
            return $this->i18n->l($p.'x_days', ['count' => $days]);
        }
        if ($days < 60) {
            return $this->i18n->l($p.'about_x_months', ['count' => 1]);
        }
        if ($days < 365) {
            return $this->i18n->l($p.'x_months', ['count' => (int) round($days / 30)]);
        }

        return $this->i18n->l($p.'about_x_years', ['count' => intdiv($days, 365)]);
    }

    /** @return list<string> related issues columns (Setting.related_issues_default_columns without tracker/subject) */
    private function relatedColumns(): array
    {
        $cols = array_map('strval', $this->settings->array('related_issues_default_columns'));

        return array_values(array_diff($cols, ['tracker', 'subject']));
    }

    private function columnCaption(string $col): string
    {
        return $this->i18n->l('field_'.$col);
    }

    private function columnContent(string $col, Issue $issue): string
    {
        return match ($col) {
            'status' => htmlspecialchars($issue->status->name),
            'priority' => htmlspecialchars($issue->priority->name),
            'assigned_to' => $this->app->linkToUser($issue->assignedTo),
            'author' => $this->app->linkToUser($issue->author),
            'category' => htmlspecialchars((string) $issue->category?->name),
            'fixed_version' => $this->app->linkToVersion($issue->fixedVersion),
            'start_date' => $this->i18n->formatDate($issue->startDate),
            'due_date' => $this->i18n->formatDate($issue->dueDate),
            'done_ratio' => $this->app->progressBar($issue->doneRatio, $this->settings->bool('display_related_issues_table_headers') ? null : ($issue->doneRatio > 0 ? $issue->doneRatio.'%' : '')),
            'estimated_hours' => htmlspecialchars($this->i18n->formatHours($issue->estimatedHours)),
            'project' => $this->app->linkToProject($issue->project),
            'created_on' => htmlspecialchars($this->i18n->formatTime($issue->createdOn)),
            'updated_on' => htmlspecialchars($this->i18n->formatTime($issue->updatedOn)),
            'closed_on' => htmlspecialchars($this->i18n->formatTime($issue->closedOn)),
            default => '',
        };
    }

    private function headers(): string
    {
        if (!$this->settings->bool('display_related_issues_table_headers')) {
            return '';
        }
        $h = '<th>'.htmlspecialchars($this->i18n->l('field_subject')).'</th>';
        foreach ($this->relatedColumns() as $c) {
            $h .= '<th>'.htmlspecialchars($this->columnCaption($c)).'</th>';
        }

        return '<thead class="related-issues"><tr><th></th>'.$h.'<th></th></tr></thead>';
    }

    private function contextMenuLink(): string
    {
        return '<a title="'.htmlspecialchars($this->i18n->l('button_actions')).'" class="icon-only icon-actions js-contextmenu" href="#">'.$this->app->spriteIcon('3-bullets', $this->i18n->l('button_actions')).'</a>';
    }

    public function renderDescendantsTree(Issue $issue): string
    {
        $manage = $this->auth->allowedTo('manage_subtasks', $issue->project);
        $s = '<table class="list issues odd-even">'.$this->headers();
        $descendants = array_values(array_filter($issue->getDescendants(), fn (Issue $i) => $this->issues->isVisible($i)));
        usort($descendants, static fn (Issue $a, Issue $b) => ($a->lft ?? 0) <=> ($b->lft ?? 0));
        $ancestors = [];
        foreach ($descendants as $child) {
            while ([] !== $ancestors && !$child->isDescendantOf(end($ancestors))) {
                array_pop($ancestors);
            }
            $level = \count($ancestors);
            $css = 'issue issue-'.$child->id.' hascontextmenu '.$this->app->issueCssClasses($child).($level > 0 ? ' idnt idnt-'.$level : '');
            $buttons = '';
            if ($manage) {
                $url = $this->urls->generate('issue_update', ['id' => $child->id, 'issue' => ['parent_issue_id' => ''], 'back_url' => $this->urls->generate('issue_show', ['id' => $issue->id]), 'no_flash' => '1']);
                $buttons .= '<a data-confirm="'.htmlspecialchars($this->i18n->l('text_are_you_sure')).'" title="'.htmlspecialchars($this->i18n->l('label_subtask_remove')).'" class="icon-only icon-link-break" rel="nofollow" data-method="put" href="'.htmlspecialchars($url).'">'.$this->app->spriteIcon('link-break', $this->i18n->l('label_subtask_remove')).'</a>';
            }
            $buttons .= $this->contextMenuLink();
            $row = '<td class="checkbox"><input type="checkbox" name="ids[]" value="'.$child->id.'"></td>'
                .'<td class="subject">'.$this->app->linkToIssue($child, ['project' => $issue->project->id !== $child->project->id]).'</td>';
            foreach ($this->relatedColumns() as $c) {
                $row .= '<td class="'.$c.'">'.$this->columnContent($c, $child).'</td>';
            }
            $row .= '<td class="buttons">'.$buttons.'</td>';
            $s .= '<tr class="'.$css.'" id="issue-'.$child->id.'">'.$row.'</tr>';
            if (!$child->isLeaf()) {
                $ancestors[] = $child;
            }
        }

        return $s.'</table>';
    }

    public function renderDescendantsStats(Issue $issue): string
    {
        $open = $closed = 0;
        foreach ($issue->getDescendants() as $d) {
            if ($this->issues->isVisible($d)) {
                $d->isClosed() ? ++$closed : ++$open;
            }
        }

        return $this->renderIssuesStats($open, $closed, ['parent_id' => '~'.$issue->id]);
    }

    /** @param list<IssueRelation> $relations */
    public function renderRelationsStats(Issue $issue, array $relations): string
    {
        $open = $closed = 0;
        $ids = [];
        foreach ($relations as $r) {
            $o = $r->otherIssue($issue);
            $o->isClosed() ? ++$closed : ++$open;
            $ids[] = $o->id;
        }

        return $this->renderIssuesStats($open, $closed, ['issue_id' => implode(',', $ids)]);
    }

    private function renderIssuesStats(int $open, int $closed, array $params): string
    {
        $total = $open + $closed;
        if (0 === $total) {
            return '';
        }
        $url = fn (string $status) => $this->route('issues_index', $params + ['set_filter' => 'true', 'status_id' => $status]);
        $all = '<span class="badge badge-issues-count">'.$this->linkOrText((string) $total, $url('*')).'</span>';
        $closedS = '<span class="closed">'.($closed > 0 ? $this->linkOrText($this->i18n->l('label_x_closed_issues_abbr', ['count' => $closed]), $url('c')) : htmlspecialchars($this->i18n->l('label_x_closed_issues_abbr', ['count' => $closed]))).'</span>';
        $openS = '<span class="open">'.($open > 0 ? $this->linkOrText($this->i18n->l('label_x_open_issues_abbr', ['count' => $open]), $url('o')) : htmlspecialchars($this->i18n->l('label_x_open_issues_abbr', ['count' => $open]))).'</span>';

        return '<span class="issues-stat">'.$all.' ('.$openS.' &#8212; '.$closedS.')</span>';
    }

    /** "Related to Project - Bug #2: subject" */
    public function relationText(IssueRelation $relation, Issue $issue): string
    {
        $other = $relation->otherIssue($issue);
        $label = $this->i18n->l($relation->labelFor($issue));
        // IssueRelation#to_s: "Follows (2 days) Project - Feature #24: subject"
        $s = htmlspecialchars($label);
        if ($relation->delay) {
            $s .= ' '.htmlspecialchars('('.$this->i18n->l('datetime.distance_in_words.x_days', ['count' => $relation->delay]).')');
        }

        return $s.' '.$this->app->linkToIssue($other, ['project' => $this->settings->bool('cross_project_issue_relations')]);
    }

    /** @param list<IssueRelation> $relations */
    public function renderIssueRelations(Issue $issue, array $relations): string
    {
        $manage = $this->auth->allowedTo('manage_issue_relations', $issue->project);
        $s = $this->headers();
        foreach ($relations as $relation) {
            $other = $relation->otherIssue($issue);
            // css_classes_for(other_issue): the relation type seen from the other issue
            $relCss = 'rel-'.($relation->issueFrom->id === $other->id ? $relation->relationType : (IssueRelation::TYPES[$relation->relationType][3] ?? $relation->relationType));
            $css = 'issue hascontextmenu '.$this->app->issueCssClasses($other).' '.$relCss;
            $buttons = '';
            if ($manage) {
                $url = $this->urls->generate('relation_destroy', ['id' => $relation->id, 'issue_id' => $issue->id]);
                $buttons .= '<a data-confirm="'.htmlspecialchars($this->i18n->l('text_are_you_sure')).'" title="'.htmlspecialchars($this->i18n->l('label_relation_remove')).'" class="icon-only icon-link-break" data-remote="true" rel="nofollow" data-method="delete" href="'.htmlspecialchars($url).'">'.$this->app->spriteIcon('link-break', $this->i18n->l('label_relation_remove')).'</a>';
            }
            $buttons .= $this->contextMenuLink();
            $row = '<td class="checkbox"><input type="checkbox" name="ids[]" value="'.$other->id.'"></td>'
                .'<td class="subject">'.$this->relationText($relation, $issue).'</td>';
            foreach ($this->relatedColumns() as $c) {
                $row .= '<td class="'.$c.'">'.$this->columnContent($c, $other).'</td>';
            }
            $row .= '<td class="buttons">'.$buttons.'</td>';
            $s .= '<tr id="relation-'.$relation->id.'" class="'.$css.'">'.$row.'</tr>';
        }

        return '<table class="list issues odd-even">'.$s.'</table>';
    }

    /** @return list<array{0: string, 1: string}> */
    public function relationTypeOptions(): array
    {
        $types = IssueRelation::TYPES;
        uasort($types, static fn ($a, $b) => $a[2] <=> $b[2]);
        $out = [];
        foreach ($types as $k => $t) {
            $out[] = [$k, $this->i18n->l($t[0])];
        }

        return $out;
    }

    public function newSubtaskUrl(Issue $issue, bool $backToIssue = true): string
    {
        $attrs = ['parent_issue_id' => $issue->id];
        if ($issue->tracker->isCoreFieldEnabled('parent_issue_id')) {
            $attrs['tracker_id'] = $issue->tracker->id;
        }
        $params = ['project_id' => $issue->project->identifier, 'issue' => $attrs];
        if ($backToIssue) {
            $params['back_url'] = $this->urls->generate('issue_show', ['id' => $issue->id]);
        }

        return $this->urls->generate('issue_new', $params);
    }

    public function updateIssueFormPath(?Project $project, Issue $issue): string
    {
        if ($issue->isNew()) {
            return $project ? $this->urls->generate('issue_form_new', ['project_id' => $project->identifier]) : $this->urls->generate('issues_form_new');
        }

        return $this->urls->generate('issue_form_edit', ['id' => $issue->id]);
    }

    /** @param list<Issue>|Issue $issues */
    public function destroyConfirmationMessage(array|Issue $issues): string
    {
        $issues = \is_array($issues) ? $issues : [$issues];
        $msg = $this->i18n->l('text_issues_destroy_confirmation');
        $ids = [];
        foreach ($issues as $i) {
            foreach ($i->getDescendants() as $d) {
                $ids[$d->id] = true;
            }
        }
        foreach ($issues as $i) {
            unset($ids[$i->id]);
        }
        if ([] !== $ids) {
            $msg .= "\n".$this->i18n->l('text_issues_destroy_descendants_confirmation', ['count' => \count($ids)]);
        }

        return $msg;
    }

    // ------------------------------------------------------------------------------------------
    // Journals
    // ------------------------------------------------------------------------------------------

    public function journalCssClasses(Journal $journal): string
    {
        $s = 'journal';
        if ($journal->hasNotes()) {
            $s .= ' has-notes';
        }
        if ($journal->details->count() > 0) {
            $s .= ' has-details';
        }
        if ($journal->privateNotes) {
            $s .= ' private-notes';
        }

        return $s;
    }

    /** Details the user is allowed to see (Journal#visible_details) @return list<JournalDetail> */
    public function visibleDetails(Journal $journal, ?User $user = null): array
    {
        $user = $this->auth->user($user);
        $visibleCf = null;
        $out = [];
        // asked for each journal of an issue in turn: the details of all of them come in one query
        $this->batch?->collection($journal, 'details');
        foreach ($journal->details as $d) {
            if ('cf' === $d->property) {
                if (null === $visibleCf) {
                    $visibleCf = array_map(static fn ($f) => $f->id, $this->customFields->visibleIssueFields($journal->issue, $user));
                }
                if (!\in_array((int) $d->propKey, $visibleCf, true)) {
                    continue;
                }
            } elseif ('relation' === $d->property) {
                $other = $this->em->find(Issue::class, (int) ($d->value ?? $d->oldValue));
                if ($other && !$this->issues->isVisible($other, $user)) {
                    continue;
                }
            }
            $out[] = $d;
        }

        return $out;
    }

    /**
     * Port of IssuesHelper#details_to_strings.
     *
     * @param iterable<JournalDetail>|Journal $details
     *
     * @return list<string>
     */
    public function detailsToStrings(iterable|Journal $details, bool $noHtml = false, bool $absolute = false): array
    {
        if ($details instanceof Journal) {
            $details = $this->visibleDetails($details);
        }
        $strings = [];
        $multi = [];
        foreach ($details as $d) {
            if ('cf' === $d->property) {
                $cf = $this->em->find(CustomField::class, (int) $d->propKey);
                if ($cf && $cf->isMultiple()) {
                    $multi[$cf->id] ??= ['field' => $cf, 'added' => [], 'deleted' => [], 'journal' => $d->journal];
                    if (null !== $d->oldValue) {
                        $multi[$cf->id]['deleted'][] = $d->oldValue;
                    }
                    if (null !== $d->value) {
                        $multi[$cf->id]['added'][] = $d->value;
                    }
                    continue;
                }
            }
            $strings[] = $this->showDetail($d, $noHtml, $absolute);
        }
        foreach ($multi as $m) {
            if ([] !== $m['added']) {
                $d = new JournalDetail();
                $d->journal = $m['journal'];
                $d->property = 'cf';
                $d->propKey = (string) $m['field']->id;
                $d->value = '__multi__';
                $strings[] = $this->showDetail($d, $noHtml, $absolute, $m['added'], null);
            }
            if ([] !== $m['deleted']) {
                $d = new JournalDetail();
                $d->journal = $m['journal'];
                $d->property = 'cf';
                $d->propKey = (string) $m['field']->id;
                $d->oldValue = '__multi__';
                $strings[] = $this->showDetail($d, $noHtml, $absolute, null, $m['deleted']);
            }
        }

        return $strings;
    }

    /** Port of IssuesHelper#show_detail */
    public function showDetail(JournalDetail $detail, bool $noHtml = false, bool $absolute = false, ?array $multiAdded = null, ?array $multiDeleted = null): string
    {
        $multiple = false;
        $showDiff = false;
        $noDetails = false;
        $label = null;
        $value = null;
        $oldValue = null;
        $rawValue = null !== $multiAdded ? implode(', ', $multiAdded) : $detail->value;
        $rawOld = null !== $multiDeleted ? implode(', ', $multiDeleted) : $detail->oldValue;
        $find = function (string $class, ?string $id): ?string {
            if (null === $id || '' === $id) {
                return null;
            }
            $o = $this->em->find($class, (int) $id);
            if (null === $o) {
                return null;
            }
            if ($o instanceof Project && !$this->auth->isProjectVisible($o)) {
                return null;
            }

            return $o instanceof Principal ? $o->getName() : (string) ($o->name ?? '');
        };

        switch ($detail->property) {
            case 'attr':
                $field = preg_replace('/_id$/', '', $detail->propKey) ?? $detail->propKey;
                $label = $this->i18n->l('field_'.$field);
                switch ($detail->propKey) {
                    case 'due_date':
                    case 'start_date':
                        $value = $detail->value ? $this->i18n->formatDate(IssueService::parseDate(substr($detail->value, 0, 10))) : null;
                        $oldValue = $detail->oldValue ? $this->i18n->formatDate(IssueService::parseDate(substr($detail->oldValue, 0, 10))) : null;
                        break;
                    case 'project_id':
                        $value = $find(Project::class, $detail->value);
                        $oldValue = $find(Project::class, $detail->oldValue);
                        break;
                    case 'status_id':
                        $value = $find(IssueStatus::class, $detail->value);
                        $oldValue = $find(IssueStatus::class, $detail->oldValue);
                        break;
                    case 'tracker_id':
                        $value = $find(Tracker::class, $detail->value);
                        $oldValue = $find(Tracker::class, $detail->oldValue);
                        break;
                    case 'assigned_to_id':
                        $value = $find(Principal::class, $detail->value);
                        $oldValue = $find(Principal::class, $detail->oldValue);
                        break;
                    case 'priority_id':
                        $value = $find(\App\Entity\IssuePriority::class, $detail->value);
                        $oldValue = $find(\App\Entity\IssuePriority::class, $detail->oldValue);
                        break;
                    case 'category_id':
                        $value = $find(IssueCategory::class, $detail->value);
                        $oldValue = $find(IssueCategory::class, $detail->oldValue);
                        break;
                    case 'fixed_version_id':
                        $value = $find(Version::class, $detail->value);
                        $oldValue = $find(Version::class, $detail->oldValue);
                        break;
                    case 'estimated_hours':
                        $value = (null !== $detail->value && '' !== $detail->value) ? $this->hoursShort((float) $detail->value) : null;
                        $oldValue = (null !== $detail->oldValue && '' !== $detail->oldValue) ? $this->hoursShort((float) $detail->oldValue) : null;
                        break;
                    case 'parent_id':
                        $label = $this->i18n->l('field_parent_issue');
                        $value = (null !== $detail->value && '' !== $detail->value) ? '#'.$detail->value : null;
                        $oldValue = (null !== $detail->oldValue && '' !== $detail->oldValue) ? '#'.$detail->oldValue : null;
                        break;
                    case 'child_id':
                        $label = $this->i18n->l('label_subtask');
                        $value = (null !== $detail->value && '' !== $detail->value) ? '#'.$detail->value : null;
                        $oldValue = (null !== $detail->oldValue && '' !== $detail->oldValue) ? '#'.$detail->oldValue : null;
                        $multiple = true;
                        break;
                    case 'is_private':
                        $value = (null !== $detail->value && '' !== $detail->value) ? $this->i18n->l('0' === $detail->value || 'f' === $detail->value ? 'general_text_No' : 'general_text_Yes') : null;
                        $oldValue = (null !== $detail->oldValue && '' !== $detail->oldValue) ? $this->i18n->l('0' === $detail->oldValue || 'f' === $detail->oldValue ? 'general_text_No' : 'general_text_Yes') : null;
                        break;
                    case 'description':
                        $showDiff = true;
                        break;
                }
                break;
            case 'cf':
                $cf = $this->em->find(CustomField::class, (int) $detail->propKey);
                if ($cf) {
                    $label = $cf->name;
                    if ('attachment' === $cf->fieldFormat) {
                        $noDetails = true;
                    } elseif ('text' === $cf->fieldFormat) {
                        $showDiff = true;
                    } else {
                        $multiple = $cf->isMultiple();
                        $value = null !== $rawValue ? $this->customFields->formatValue($cf, null !== $multiAdded ? $multiAdded : $detail->value) : null;
                        $oldValue = null !== $rawOld ? $this->customFields->formatValue($cf, null !== $multiDeleted ? $multiDeleted : $detail->oldValue) : null;
                    }
                }
                break;
            case 'attachment':
                $label = $this->i18n->l('label_attachment');
                break;
            case 'relation':
                if (null !== $detail->value && null === $detail->oldValue) {
                    $rel = $this->em->find(Issue::class, (int) $detail->value);
                    $value = (null === $rel || !$this->issues->isVisible($rel)) ? $this->i18n->l('label_issue').' #'.$detail->value : ($noHtml ? (string) $rel : null);
                    if (null === $value) {
                        $valueHtml = $this->app->linkToIssue($rel);
                    }
                } elseif (null !== $detail->oldValue && null === $detail->value) {
                    $rel = $this->em->find(Issue::class, (int) $detail->oldValue);
                    $oldValue = (null === $rel || !$this->issues->isVisible($rel)) ? $this->i18n->l('label_issue').' #'.$detail->oldValue : ($noHtml ? (string) $rel : null);
                    if (null === $oldValue) {
                        $oldHtml = $this->app->linkToIssue($rel);
                    }
                }
                if (isset(IssueRelation::TYPES[$detail->propKey])) {
                    $label = $this->i18n->l(IssueRelation::TYPES[$detail->propKey][0]);
                }
                break;
        }
        $label ??= $detail->propKey;
        $value ??= $rawValue;
        $oldValue ??= $rawOld;

        if (!$noHtml) {
            $label = '<strong>'.htmlspecialchars((string) $label).'</strong>';
            if (isset($oldHtml)) {
                $oldValue = '<i>'.$oldHtml.'</i>';
            } elseif (null !== $rawOld) {
                $oldValue = '<i>'.htmlspecialchars((string) $oldValue).'</i>';
            }
            if (null !== $rawOld && (null === $rawValue || '' === $rawValue) && 'relation' !== $detail->property) {
                $oldValue = '<del>'.$oldValue.'</del>';
            }
            if ('attachment' === $detail->property && null !== $rawValue && '' !== $rawValue
                && ($att = $this->em->find(Attachment::class, (int) $detail->propKey)) && 'Issue' === $att->containerType && $att->containerId === $detail->journal->issue->id) {
                $value = $this->linkToAttachment($att, ['absolute' => $absolute]);
                if (!$absolute) {
                    $value .= ' '.$this->linkToAttachment($att, ['class' => 'icon-only icon-download', 'title' => $this->i18n->l('button_download'), 'download' => true, 'icon' => 'download']);
                }
            } elseif (isset($valueHtml)) {
                $value = '<i>'.$valueHtml.'</i>';
            } elseif (null !== $value) {
                $value = '<i>'.htmlspecialchars((string) $value).'</i>';
            }
        }

        if ($noDetails) {
            return $this->i18n->l('text_journal_changed_no_detail', ['label' => $label]);
        }
        if ($showDiff) {
            $s = $this->i18n->l('text_journal_changed_no_detail', ['label' => $label]);
            if (!$noHtml && null !== $detail->id) {
                $url = $this->urls->generate('journal_diff', ['id' => $detail->journal->id, 'detail_id' => $detail->id], $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH);
                $s .= ' (<a href="'.htmlspecialchars($url).'" title="'.htmlspecialchars($this->i18n->l('label_view_diff')).'">'.htmlspecialchars($this->i18n->l('label_diff')).'</a>)';
            }

            return $s;
        }
        if (null !== $rawValue && '' !== $rawValue) {
            if (\in_array($detail->property, ['attr', 'cf'], true)) {
                if (null !== $rawOld && '' !== $rawOld) {
                    return $this->i18n->l('text_journal_changed', ['label' => $label, 'old' => $oldValue, 'new' => $value]);
                }
                if ($multiple) {
                    return $this->i18n->l('text_journal_added', ['label' => $label, 'value' => $value]);
                }

                return $this->i18n->l('text_journal_set_to', ['label' => $label, 'value' => $value]);
            }

            return $this->i18n->l('text_journal_added', ['label' => $label, 'value' => $value]);
        }

        return $this->i18n->l('text_journal_deleted', ['label' => $label, 'old' => $oldValue]);
    }

    // ------------------------------------------------------------------------------------------
    // Custom fields
    // ------------------------------------------------------------------------------------------

    /**
     * Visible custom values of an issue: list of [field, value].
     *
     * @return list<array{field: CustomField, value: mixed}>
     */
    public function customValuesForDisplay(Issue $issue, ?string $layout = null): array
    {
        $values = $this->customFields->values('Issue', $issue->id);
        $out = [];
        foreach ($this->customFields->visibleIssueFields($issue) as $cf) {
            $full = $this->isFullWidth($cf);
            if ('half' === $layout && $full || 'full' === $layout && !$full) {
                continue;
            }
            $out[] = ['field' => $cf, 'value' => $values[$cf->id] ?? ($cf->isMultiple() ? [] : null)];
        }

        return $out;
    }

    public function isFullWidth(CustomField $cf): bool
    {
        return 'text' === $cf->fieldFormat && \in_array($cf->getFormatOption('full_width_layout'), ['1', 1, true], true);
    }

    /**
     * Custom field value as HTML (FieldFormat#formatted_value with html = true, then format_object).
     *
     * @param object|null $customized the object holding the value (issue, project, user...), for the
     *                                %id%, %project_id% and %project_identifier% tokens of URL patterns
     */
    public function customFieldValueHtml(CustomField $cf, mixed $value, ?Project $project = null, ?object $customized = null): string
    {
        $values = array_values(array_filter((array) $value, static fn ($v) => null !== $v && '' !== (string) $v));
        if ([] === $values) {
            return '';
        }
        $format = (string) $cf->fieldFormat;
        // Base#formatted_value / LinkFormat#formatted_value: values rendered as links (URL pattern, link fields)
        if (null !== ($links = $this->customFields->linkedValueHtml($cf, $values, $customized, $project))) {
            return $links;
        }
        $delimiter = '1' === (string) $cf->getFormatOption('thousands_delimiter') ? $this->i18n->l('number.format.delimiter') : '';
        $out = [];
        foreach ($values as $v) {
            $v = (string) $v;
            $out[] = match ($format) {
                'text' => \in_array($cf->getFormatOption('text_formatting'), ['full'], true) ? '<div class="wiki">'.$this->formatter->format($v, $project).'</div>' : nl2br(htmlspecialchars($v)),
                'string' => \in_array($cf->getFormatOption('text_formatting'), ['full'], true) ? $this->formatter->format($v, $project) : htmlspecialchars($v),
                'user' => ($u = $this->em->find(Principal::class, (int) $v)) ? $this->app->linkToUser($u) : htmlspecialchars($v),
                'version' => ($ver = $this->em->find(Version::class, (int) $v)) ? $this->app->linkToVersion($ver) : htmlspecialchars($v),
                'progressbar' => $this->app->progressBar((float) $v, $v.'%'),
                // format_object(Integer|Float, thousands_delimiter: custom_field.thousands_delimiter?)
                'int' => preg_match('/^\s*[+-]?\d+\s*$/', $v) ? htmlspecialchars(number_format((int) $v, 0, '.', $delimiter)) : htmlspecialchars($v),
                'float' => is_numeric(trim($v)) ? htmlspecialchars(number_format((float) $v, 2, '.', $delimiter)) : htmlspecialchars($v),
                default => htmlspecialchars($this->customFields->formatValue($cf, $v)),
            };
        }

        return implode(', ', $out);
    }

    /**
     * Form field for a custom field value (CustomFieldsHelper#custom_field_tag_with_label).
     */
    public function customFieldTag(string $objectName, CustomField $cf, mixed $value, ?Project $project = null, bool $required = false, bool $withLabel = true, ?Issue $issue = null): string
    {
        // sanitize_to_id: "enumerations[3]" => "enumerations_3"
        $id = preg_replace('/[^-a-zA-Z0-9:.]/', '_', str_replace(']', '', $objectName)).'_custom_field_values_'.$cf->id;
        $name = $objectName.'[custom_field_values]['.$cf->id.']';
        $css = $cf->fieldFormat.'_cf cf_'.$cf->id;
        $required = $required || $cf->isRequired;
        $style = (string) $cf->getFormatOption('edit_tag_style', '');
        // List#select_edit_tag: no blank option for a required field with a default value, "--- Please select ---" for other required fields
        $blankOption = $cf->isRequired
            ? (null !== $cf->defaultValue && '' !== $cf->defaultValue ? '' : '<option value="">--- '.htmlspecialchars($this->i18n->l('actionview_instancetag_blank_option')).' ---</option>')
            : '<option value="">&nbsp;</option>';
        $labelText = '<span'.($cf->description ? ' title="'.htmlspecialchars($cf->description).'"' : '').'>'.htmlspecialchars($cf->name).'</span>'.($required ? ' <span class="required">*</span>' : '');
        $multipleOrChecks = $cf->isMultiple() || \in_array($style, ['check_box', 'radio'], true);
        $label = $withLabel ? '<label'.($multipleOrChecks && '' !== $style && !('bool' === $cf->fieldFormat && 'check_box' === $style) ? '' : ' for="'.$id.'"').'>'.$labelText.'</label>' : '';
        $values = array_map('strval', array_filter((array) $value, static fn ($v) => null !== $v));
        $single = (string) ($values[0] ?? '');
        $h = htmlspecialchars(...);

        switch ($cf->fieldFormat) {
            case 'text':
                $tag = '<textarea rows="'.($this->isFullWidth($cf) ? 4 : 3).'" class="'.$css.($cf->getFormatOption('text_formatting') === 'full' ? ' wiki-edit' : '').'" name="'.$h($name).'" id="'.$id.'">'."\n".$h($single).'</textarea>';
                break;
            case 'date':
                $tag = '<input size="10" type="date" class="'.$css.'" value="'.$h($single).'" name="'.$h($name).'" id="'.$id.'">';
                break;
            case 'bool':
                if ('check_box' === $style) {
                    $tag = '<input type="hidden" name="'.$h($name).'" value="0"><input type="checkbox" value="1" class="'.$css.'" name="'.$h($name).'" id="'.$id.'"'.('1' === $single ? ' checked' : '').'>';
                } elseif ('radio' === $style) {
                    $tag = $this->radioOrChecks($name, [['', $this->i18n->l('label_none')], ['1', $this->i18n->l('general_text_Yes')], ['0', $this->i18n->l('general_text_No')]], [$single], false, $css);
                } else {
                    $tag = '<select class="'.$css.'" name="'.$h($name).'" id="'.$id.'">'.$blankOption
                        .'<option value="1"'.('1' === $single ? ' selected="selected"' : '').'>'.$h($this->i18n->l('general_text_Yes')).'</option>'
                        .'<option value="0"'.('0' === $single ? ' selected="selected"' : '').'>'.$h($this->i18n->l('general_text_No')).'</option></select>';
                }
                break;
            case 'list':
            case 'enumeration':
            case 'user':
            case 'version':
                // possible_custom_value_options: the possible values plus the current values that are no longer possible
                $principals = 'user' === $cf->fieldFormat ? $this->customFields->possiblePrincipals($cf, $project) : [];
                foreach ('user' === $cf->fieldFormat ? $values : [] as $v) {
                    if ('' !== $v && !\in_array((int) $v, array_map(static fn (Principal $pr) => (int) $pr->id, $principals), true) && null !== ($missing = $this->em->find(Principal::class, (int) $v))) {
                        $principals[] = $missing;
                    }
                }
                $options = 'user' === $cf->fieldFormat ? $this->customFields->principalsOptions($cf, $principals) : $this->customFields->possibleValuesOptions($cf, $project, $issue);
                foreach ('user' === $cf->fieldFormat ? [] : $values as $v) {
                    if ('' !== $v && !\in_array($v, array_map(static fn (array $o) => (string) $o[0], $options), true)) {
                        $options[] = [$v, $this->customFields->formatValue($cf, $v)];
                    }
                }
                if (\in_array($style, ['check_box', 'radio'], true)) {
                    $tag = $this->radioOrChecks($name.($cf->isMultiple() ? '[]' : ''), $cf->isMultiple() ? $options : [['', $this->i18n->l('label_none')], ...$options], $values, $cf->isMultiple(), $css);
                    if ($cf->isMultiple()) {
                        $tag .= '<input type="hidden" name="'.$h($name).'[]" value="">';
                    }
                } else {
                    $tag = '<select name="'.$h($name).($cf->isMultiple() ? '[]' : '').'" id="'.$id.'" class="'.$css.'"'.($cf->isMultiple() ? ' multiple="multiple"' : '').'>';
                    if (!$cf->isMultiple()) {
                        $tag .= $blankOption;
                    }
                    if ('user' === $cf->fieldFormat) {
                        $tag .= $this->customFieldPrincipalsOptionTags($cf, $principals, $values);
                    } else {
                        foreach ($options as [$v, $text]) {
                            $tag .= '<option value="'.$h((string) $v).'"'.(\in_array((string) $v, $values, true) ? ' selected="selected"' : '').'>'.$h((string) $text).'</option>';
                        }
                    }
                    $tag .= '</select>';
                    if ($cf->isMultiple()) {
                        $tag .= '<input type="hidden" name="'.$h($name).'[]" id="'.$id.'_" value="">';
                    }
                }
                break;
            case 'progressbar':
                // ProgressbarFormat#edit_tag: 0..100 % by steps of the ratio interval of the field
                $tag = '<select name="'.$h($name).'" id="'.$id.'" class="'.$css.'" style="width: 75px;">'.$this->progressbarOptions($cf, $single).'</select>';
                break;
            case 'attachment':
                $tag = '';
                break;
            default:
                $tag = '<input type="text" class="'.$css.'" value="'.$h($single).'" name="'.$h($name).'" id="'.$id.'">';
        }

        return $label.$tag;
    }

    /** Options of a progress bar custom field: "0 %", "10 %"... (ProgressbarFormat#edit_tag) */
    private function progressbarOptions(CustomField $cf, ?string $selected): string
    {
        $interval = (int) $cf->getFormatOption('ratio_interval', 0);
        if ($interval <= 0) {
            $interval = max(1, $this->settings->int('issue_done_ratio_interval') ?: 10);
        }
        $s = '';
        foreach (range(0, 100, $interval) as $r) {
            $s .= '<option value="'.$r.'"'.(null !== $selected && '' !== $selected && (string) $r === trim($selected) ? ' selected="selected"' : '').'>'.$r.' %</option>';
        }

        return $s;
    }

    /**
     * UserFormat#principals_option_tags: "<< me >>", then the principals; users and groups are
     * separated in option groups when both are present.
     *
     * @param list<Principal> $principals
     * @param list<string>    $selected
     */
    private function customFieldPrincipalsOptionTags(CustomField $cf, array $principals, array $selected): string
    {
        $opt = static fn (string $value, string $text): string => '<option value="'.htmlspecialchars($value).'"'.(\in_array($value, $selected, true) ? ' selected="selected"' : '').'>'.htmlspecialchars($text).'</option>';
        $tags = fn (array $list): string => implode('', array_map(static fn (Principal $pr) => $opt((string) $pr->id, $pr->getName()), $list));
        $s = null !== ($me = $this->customFields->meOption($cf, $principals)) ? $opt($me[0], $me[1]) : '';
        $users = array_values(array_filter($principals, static fn (Principal $pr) => $pr instanceof User));
        $groups = array_values(array_filter($principals, static fn (Principal $pr) => !$pr instanceof User));
        if ([] !== $users && [] !== $groups) {
            return $s.'<optgroup label="'.htmlspecialchars($this->i18n->l('label_user_plural')).'">'.$tags($users).'</optgroup>'
                .'<optgroup label="'.htmlspecialchars($this->i18n->l('label_group_plural')).'">'.$tags($groups).'</optgroup>';
        }

        return $s.$tags($principals);
    }

    /** Custom field tag for the bulk edit form (no value, with "none" option) */
    public function customFieldBulkTag(CustomField $cf, mixed $value, ?Project $project = null, string $objectName = 'issue'): string
    {
        $id = $objectName.'_custom_field_values_'.$cf->id;
        $name = $objectName.'[custom_field_values]['.$cf->id.']';
        $css = $cf->fieldFormat.'_cf cf_'.$cf->id;
        $h = htmlspecialchars(...);
        $values = array_map('strval', array_filter((array) $value, static fn ($v) => null !== $v && '' !== $v));
        $label = '<label for="'.$id.'">'.$h($cf->name).'</label>';
        $noChange = '<option value="">'.$h($this->i18n->l('label_no_change_option')).'</option>';
        $none = $cf->isRequired ? '' : '<option value="__none__"'.(\in_array('__none__', $values, true) ? ' selected="selected"' : '').'>'.$h($this->i18n->l('label_none')).'</option>';
        switch ($cf->fieldFormat) {
            case 'bool':
                $tag = '<select class="'.$css.'" name="'.$h($name).'" id="'.$id.'">'.$noChange.$none
                    .'<option value="1"'.(\in_array('1', $values, true) ? ' selected="selected"' : '').'>'.$h($this->i18n->l('general_text_Yes')).'</option>'
                    .'<option value="0"'.(\in_array('0', $values, true) ? ' selected="selected"' : '').'>'.$h($this->i18n->l('general_text_No')).'</option></select>';
                break;
            case 'list':
            case 'enumeration':
            case 'user':
            case 'version':
                $tag = '<select class="'.$css.'" name="'.$h($name).($cf->isMultiple() ? '[]' : '').'" id="'.$id.'"'.($cf->isMultiple() ? ' multiple="multiple"' : '').'>'.($cf->isMultiple() ? '' : $noChange).$none;
                if ('user' === $cf->fieldFormat) {
                    $tag .= $this->customFieldPrincipalsOptionTags($cf, $this->customFields->possiblePrincipals($cf, $project), $values);
                } else {
                    foreach ($this->customFields->possibleValuesOptions($cf, $project) as [$v, $text]) {
                        $tag .= '<option value="'.$h((string) $v).'"'.(\in_array((string) $v, $values, true) ? ' selected="selected"' : '').'>'.$h((string) $text).'</option>';
                    }
                }
                $tag .= '</select>';
                break;
            case 'progressbar':
                // ProgressbarFormat#bulk_edit_tag
                $tag = '<select class="'.$css.'" name="'.$h($name).'" id="'.$id.'" style="width: 75px;">'.$noChange.$this->progressbarOptions($cf, null).'</select>'
                    .($cf->isRequired ? '' : '<label class="inline"><input type="checkbox" name="'.$h($name).'" value="__none__" data-disables="#'.$id.'">'.$h($this->i18n->l('button_clear')).'</label>');
                break;
            case 'date':
                $tag = '<input size="10" type="date" class="'.$css.'" value="'.$h($values[0] ?? '').'" name="'.$h($name).'" id="'.$id.'">'
                    .($cf->isRequired ? '' : '<label class="inline"><input type="checkbox" name="'.$h($name).'" value="__none__" data-disables="#'.$id.'">'.$h($this->i18n->l('button_clear')).'</label>');
                break;
            default:
                $tag = '<input type="text" class="'.$css.'" value="'.$h($values[0] ?? '').'" name="'.$h($name).'" id="'.$id.'">'
                    .($cf->isRequired ? '' : '<label class="inline"><input type="checkbox" name="'.$h($name).'" value="__none__" data-disables="#'.$id.'">'.$h($this->i18n->l('button_clear')).'</label>');
        }

        return $label.$tag;
    }

    /** @param list<array{0: string, 1: string}> $options */
    private function radioOrChecks(string $name, array $options, array $selected, bool $multiple, string $css): string
    {
        $s = '<span class="check_box_group '.$css.'">';
        foreach ($options as [$v, $text]) {
            $s .= '<label><input type="'.($multiple ? 'checkbox' : 'radio').'" name="'.htmlspecialchars($name).'" value="'.htmlspecialchars((string) $v).'"'
                .(\in_array((string) $v, $selected, true) || ('' === (string) $v && [] === array_filter($selected, static fn ($x) => '' !== $x) && !$multiple) ? ' checked="checked"' : '').'> '.htmlspecialchars((string) $text).'</label>';
        }

        return $s.'</span>';
    }

    // ------------------------------------------------------------------------------------------
    // Select options
    // ------------------------------------------------------------------------------------------

    /**
     * ApplicationHelper#principals_options_for_select.
     *
     * @param list<Principal> $collection
     */
    public function principalsOptionsForSelect(array $collection, ?Principal $selected = null, ?Issue $issue = null): string
    {
        $user = $this->currentUser->get();
        $h = htmlspecialchars(...);
        $s = '';
        $ids = array_map(static fn (Principal $p) => $p->id, $collection);
        if (\in_array($user->id, $ids, true)) {
            $s .= '<option value="'.$user->id.'">&lt;&lt; '.$h($this->i18n->l('label_me')).' &gt;&gt;</option>';
        }
        $opt = fn (Principal $p, bool $disabled = false) => '<option value="'.$p->id.'"'.($selected && $selected->id === $p->id ? ' selected="selected"' : '').($disabled ? ' disabled="disabled"' : '').'>'.$h($p->getName()).'</option>';
        $involved = [];
        if ($issue && !$issue->isNew()) {
            $lastNotesAuthor = null;
            foreach (array_reverse($issue->journals->toArray()) as $j) {
                if ($j->hasNotes() && (!$j->privateNotes || $this->issues->canViewPrivateNotes($issue))) {
                    $lastNotesAuthor = $j->user;
                    break;
                }
            }
            $prior = null;
            foreach (array_reverse($issue->journals->toArray()) as $j) {
                foreach ($j->details as $d) {
                    if ('attr' === $d->property && 'assigned_to_id' === $d->propKey && $d->oldValue) {
                        $prior = $this->em->find(Principal::class, (int) $d->oldValue);
                        break 2;
                    }
                }
            }
            foreach ([$issue->author, $prior, $lastNotesAuthor] as $p) {
                if ($p && !isset($involved[$p->id])) {
                    $involved[$p->id] = $p;
                }
            }
        }
        $sorted = $collection;
        usort($sorted, static fn (Principal $a, Principal $b) => strcasecmp($a->getName(), $b->getName()));
        $users = array_values(array_filter($sorted, static fn ($p) => !$p instanceof Group));
        $groups = array_values(array_filter($sorted, static fn ($p) => $p instanceof Group));
        if ([] === $involved && [] === $groups) {
            foreach ($users as $u) {
                $s .= $opt($u);
            }

            return $s;
        }
        if ([] !== $involved) {
            $s .= '<optgroup label="'.$h($this->i18n->l('label_involved_principals')).'">';
            foreach ($involved as $p) {
                $s .= $opt($p, !\in_array($p->id, $ids, true));
            }
            $s .= '</optgroup>';
        }
        // Setting.assignee_dropdown_display_format (ApplicationHelper#principals_options_for_select)
        $usersLabel = $this->i18n->l('label_user_plural');
        $groupsLabel = $this->i18n->l('label_group_plural');
        switch ($this->settings->string('assignee_dropdown_display_format')) {
            case 'groups_then_users':
                $optgroups = [[$groupsLabel, $groups], [$usersLabel, $users]];
                break;
            case 'users_by_group':
                // principal_users_by_group_optgroups_for_select: the groups, each group's users, the ungrouped users
                $optgroups = [[$groupsLabel, $groups]];
                $grouped = [];
                foreach ($groups as $g) {
                    $memberIds = array_map(static fn ($u) => $u->id, $g->users->toArray());
                    $members = array_values(array_filter($users, static fn ($u) => \in_array($u->id, $memberIds, true)));
                    if ([] !== $members) {
                        $optgroups[] = [$g->getName(), $members];
                        $grouped += array_fill_keys(array_map(static fn ($u) => $u->id, $members), true);
                    }
                }
                $optgroups[] = [$usersLabel, array_values(array_filter($users, static fn ($u) => !isset($grouped[$u->id])))];
                break;
            default:
                $optgroups = [[$usersLabel, $users], [$groupsLabel, $groups]];
        }
        foreach ($optgroups as [$label, $principals]) {
            if ([] !== $principals) {
                $s .= '<optgroup label="'.$h($label).'">'.implode('', array_map(static fn ($p) => $opt($p), $principals)).'</optgroup>';
            }
        }

        return $s;
    }

    /** @param list<Project> $projects */
    public function projectTreeOptions(array $projects, ?Project $selected = null, bool|string $includeBlank = false): string
    {
        $s = '';
        if (false !== $includeBlank) {
            $s .= '<option value="">'.(true === $includeBlank ? '&nbsp;' : htmlspecialchars($includeBlank)).'</option>';
        }
        usort($projects, static fn (Project $a, Project $b) => ($a->lft ?? 0) <=> ($b->lft ?? 0));
        $ancestors = [];
        foreach ($projects as $p) {
            while ([] !== $ancestors && !$p->isDescendantOf(end($ancestors))) {
                array_pop($ancestors);
            }
            $level = \count($ancestors);
            $prefix = $level > 0 ? str_repeat('&nbsp;', 2 * $level).'&#187; ' : '';
            $s .= '<option value="'.$p->id.'"'.($selected && $selected->id === $p->id ? ' selected="selected"' : '').'>'.$prefix.htmlspecialchars($p->name).'</option>';
            $ancestors[] = $p;
        }

        return $s;
    }

    /** @param list<Version> $versions */
    public function versionOptions(array $versions, ?Version $selected = null, ?Project $project = null): string
    {
        $grouped = [];
        foreach ($versions as $v) {
            $grouped[$v->project->name][] = $v;
        }
        $h = htmlspecialchars(...);
        $opt = fn (Version $v) => '<option value="'.$v->id.'"'.($selected && $selected->id === $v->id ? ' selected="selected"' : '').'>'.$h($v->name).'</option>';
        if (\count($grouped) <= 1) {
            return implode('', array_map($opt, $versions));
        }
        $s = '';
        foreach ($grouped as $name => $vs) {
            $s .= '<optgroup label="'.$h((string) $name).'">'.implode('', array_map($opt, $vs)).'</optgroup>';
        }

        return $s;
    }

    // ------------------------------------------------------------------------------------------
    // Watchers
    // ------------------------------------------------------------------------------------------

    public static function watchableClassName(object $o): string
    {
        $class = $o::class;
        $short = substr($class, (int) strrpos($class, '\\') + 1);
        // strip doctrine proxies
        return str_replace(['Proxies\\__CG__\\App\\Entity\\'], '', $short);
    }

    public function watchableType(object $o): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', self::watchableClassName($o)));
    }

    public function watchableProject(object $o): ?Project
    {
        return match (true) {
            $o instanceof Issue => $o->project,
            property_exists($o, 'project') && isset($o->project) => $o->project,
            property_exists($o, 'board') && isset($o->board) => $o->board->project,
            property_exists($o, 'wiki') && isset($o->wiki) => $o->wiki->project,
            default => null,
        };
    }

    /** @param object|list<object> $objects */
    public function watcherCss(object|array $objects): string
    {
        $objects = \is_array($objects) ? $objects : [$objects];
        $id = 1 === \count($objects) ? (string) $objects[0]->id : 'bulk';

        return $this->watchableType($objects[0]).'-'.$id.'-watcher';
    }

    /** WatchersHelper#watcher_link @param object|list<object> $objects */
    public function watcherLink(object|array $objects, ?User $user = null): string
    {
        $user ??= $this->currentUser->get();
        if (!$user->isLogged()) {
            return '';
        }
        $objects = \is_array($objects) ? array_values($objects) : [$objects];
        if ([] === $objects) {
            return '';
        }
        $type = self::watchableClassName($objects[0]);
        $watched = false;
        foreach ($objects as $o) {
            if ($this->watchers->isWatching($type, (int) $o->id, $user)) {
                $watched = true;
                break;
            }
        }
        $css = $this->watcherCss($objects).' '.($watched ? 'icon icon-fav' : 'icon icon-fav-off');
        $ids = array_map(static fn ($o) => (int) $o->id, $objects);
        sort($ids);
        $url = $this->urls->generate('watch', ['object_type' => $this->watchableType($objects[0]), 'object_id' => 1 === \count($ids) ? $ids[0] : $ids]);
        $text = $this->i18n->l($watched ? 'button_unwatch' : 'button_watch');

        return '<a class="'.$css.'" data-remote="true" rel="nofollow" data-method="'.($watched ? 'delete' : 'post').'" href="'.htmlspecialchars($url).'">'.$this->app->spriteIcon($watched ? 'unwatch' : 'watch', $text).'</a>';
    }

    /** WatchersHelper#watchers_list */
    public function watchersList(object $object): string
    {
        $type = $this->watchableType($object);
        $project = $this->watchableProject($object);
        $removeAllowed = $this->auth->allowedTo('delete_'.$type.'_watchers', $project);
        $lis = '';
        foreach ($this->watchers->watchers(self::watchableClassName($object), (int) $object->id) as $u) {
            $s = $u instanceof User ? $this->app->avatar($u, 16) : '';
            $s .= $this->app->linkToUser($u, $u instanceof Group ? 'group' : 'user');
            if ($object instanceof Issue && $u instanceof User && !$this->issues->isVisible($object, $u)) {
                $s .= '<span class="icon-only icon-warning" title="'.htmlspecialchars($this->i18n->l('notice_invalid_watcher')).'">'.$this->app->spriteIcon('warning', $this->i18n->l('notice_invalid_watcher')).'</span>';
            }
            if ($removeAllowed) {
                $url = $this->urls->generate('watchers_destroy', ['object_type' => $type, 'object_id' => $object->id, 'user_id' => $u->id]);
                $s .= ' <a class="delete icon-only icon-link-break" title="'.htmlspecialchars($this->i18n->l('button_remove')).'" data-remote="true" rel="nofollow" data-method="delete" href="'.htmlspecialchars($url).'">'.$this->app->spriteIcon('link-break', $this->i18n->l('button_remove')).'</a>';
            }
            $lis .= '<li class="user-'.$u->id.'">'.$s.'</li>';
        }

        return '' === $lis ? '' : '<ul class="watchers">'.$lis.'</ul>';
    }

    // ------------------------------------------------------------------------------------------
    // Attachments
    // ------------------------------------------------------------------------------------------

    /** @param array{download?: bool, class?: string, title?: string, icon?: string, text?: string, absolute?: bool} $options */
    public function linkToAttachment(Attachment $a, array $options = []): string
    {
        $text = $options['text'] ?? $a->filename;
        $ref = ($options['absolute'] ?? false) ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH;
        $url = ($options['download'] ?? false)
            ? $this->urls->generate('attachment_download', ['id' => $a->id, 'filename' => $a->filename], $ref)
            : $this->urls->generate('attachment_show', ['id' => $a->id], $ref);
        $label = isset($options['icon']) ? $this->app->spriteIcon($options['icon'], $text) : htmlspecialchars($text);
        $attrs = isset($options['class']) ? ' class="'.htmlspecialchars($options['class']).'"' : '';
        $attrs .= isset($options['title']) ? ' title="'.htmlspecialchars($options['title']).'"' : '';

        return '<a'.$attrs.' href="'.htmlspecialchars($url).'">'.$label.'</a>';
    }

    public function thumbnailTag(Attachment $a): string
    {
        $size = $this->settings->int('thumbnails_size') ?: 100;
        $src = $this->urls->generate('attachment_thumbnail', ['id' => $a->id, 'size' => $size * 2]);

        return '<div class="thumbnail" title="'.htmlspecialchars($a->filename).'"><a href="'.htmlspecialchars($this->urls->generate('attachment_show', ['id' => $a->id])).'">'
            .'<img src="'.htmlspecialchars($src).'" srcset="'.htmlspecialchars($src).' 2x" style="max-width: '.$size.'px; max-height: '.$size.'px;" alt="'.htmlspecialchars($a->filename).'" loading="lazy"></a></div>';
    }

    // ------------------------------------------------------------------------------------------
    // Misc ApplicationHelper ports
    // ------------------------------------------------------------------------------------------

    public function actionsDropdown(string $content): string
    {
        if ('' === trim($content)) {
            return '';
        }
        $label = htmlspecialchars($this->i18n->l('button_actions'));

        return '<span class="dropdown" data-controller="dropdown" data-action="clipboard:copied->dropdown#hide">'
            .'<span class="icon-only icon-actions dropdown-trigger" title="'.$label.'" data-action="click->dropdown#toggle">'.$this->app->spriteIcon('3-bullets', $this->i18n->l('button_actions')).'</span>'
            .'<span class="dropdown-content hidden" data-dropdown-target="content"><span class="dropdown-items">'.$content.'</span></span></span>';
    }

    public function copyObjectUrlLink(string $url): string
    {
        return '<a class="icon icon-copy-link" data-clipboard-text="'.htmlspecialchars($url).'" data-controller="clipboard" data-action="clipboard#copyText" href="#">'.$this->app->spriteIcon('copy-link', $this->i18n->l('button_copy_link')).'</a>';
    }

    public function quoteReplyButton(string $url, bool $iconOnly = false): string
    {
        return '<a data-action="quote-reply#quote" data-quote-reply-url-param="'.htmlspecialchars($url).'" data-quote-reply-text-formatting-param="'.htmlspecialchars($this->settings->textFormatting()).'" class="'.($iconOnly ? 'icon-only icon-quote' : 'icon icon-quote').'"'.($iconOnly ? ' title="'.htmlspecialchars($this->i18n->l('button_quote')).'"' : '').' href="#">'.$this->app->spriteIcon('quote', $this->i18n->l('button_quote'), 18, $iconOnly).'</a>';
    }

    private function route(string $name, array $params = []): ?string
    {
        try {
            return $this->urls->generate($name, $params);
        } catch (\Symfony\Component\Routing\Exception\RouteNotFoundException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function linkOrText(string $text, ?string $url): string
    {
        return null === $url ? htmlspecialchars($text) : '<a href="'.htmlspecialchars($url).'">'.htmlspecialchars($text).'</a>';
    }
}
