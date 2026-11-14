<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Api\IssueApiPresenter;
use App\Entity\Attachment;
use App\Entity\Changeset;
use App\Entity\CustomField;
use App\Entity\Issue;
use App\Entity\IssueRelation;
use App\Entity\Journal;
use App\Entity\Principal;
use App\Entity\Project;
use App\Query\IssueQuery;
use App\Query\QueryColumn;
use App\Security\Authorization;
use App\Service\AttachmentService;
use App\Service\IssueService;
use App\Service\Scm\RepositoryService;
use App\Twig\IssuesExtension;
use App\Twig\QueryExtension;

/** Port of Redmine::Export::PDF::IssuesPdfHelper (issue_to_pdf, issues_to_pdf). */
final class IssuePdfExporter
{
    public function __construct(
        private readonly PdfSupport $pdf,
        private readonly IssueService $issues,
        private readonly IssueApiPresenter $presenter,
        private readonly IssuesExtension $helpers,
        private readonly QueryExtension $qx,
        private readonly AttachmentService $attachments,
        private readonly Authorization $auth,
    ) {
    }

    // ------------------------------------------------------------------------------------------
    // Single issue
    // ------------------------------------------------------------------------------------------

    /** @param list<Journal>|null $journals visible journals (with indice), in display order */
    public function issueToPdf(Issue $issue, ?array $journals = null): string
    {
        $journals ??= $this->presenter->visibleJournals($issue);
        $l = $this->pdf->l(...);
        $project = $issue->project;
        $attachments = $this->attachments->forContainer('Issue', $issue->id);
        $disabled = $issue->tracker->getDisabledCoreFields();

        $pdf = $this->pdf->newPdf();
        $pdf->setTitle($project->name.' - '.$issue->tracker->name.' #'.$issue->id);
        $pdf->AddPage();
        $pdf->setFontStyle('B', 11);
        $pdf->rdmMultiCell(190, 5, $project->name.' - '.$issue->tracker->name.' #'.$issue->id);
        $pdf->setFontStyle('', 8);
        $baseX = $pdf->GetX();
        $i = 1;
        foreach ($issue->getAncestors() as $ancestor) {
            if (!$this->issues->isVisible($ancestor)) {
                continue;
            }
            $pdf->setX($baseX + $i);
            $pdf->rdmMultiCell(190 - $i, 5, $ancestor->tracker->name.' # '.$ancestor->id.' ('.$ancestor->status->name.'): '.$ancestor->subject);
            if ($i < 35) {
                ++$i;
            }
        }
        $pdf->setFontStyle('B', 11);
        $pdf->rdmMultiCell(190 - $i, 5, $issue->subject);
        $pdf->setFontStyle('', 8);
        $pdf->rdmMultiCell(190, 5, $this->pdf->formatTime($issue->createdOn).' - '.$issue->author->getName());
        $pdf->Ln();

        $left = [];
        $left[] = [$l('field_status'), $issue->status->name];
        $left[] = [$l('field_priority'), $issue->priority->name];
        if (!\in_array('assigned_to_id', $disabled, true)) {
            $left[] = [$l('field_assigned_to'), $issue->assignedTo?->getName() ?? ''];
        }
        if (!\in_array('category_id', $disabled, true)) {
            $left[] = [$l('field_category'), $issue->category?->name ?? ''];
        }
        if (!\in_array('fixed_version_id', $disabled, true)) {
            $left[] = [$l('field_fixed_version'), null !== $issue->fixedVersion ? (string) $issue->fixedVersion : ''];
        }
        $right = [];
        if (!\in_array('start_date', $disabled, true)) {
            $right[] = [$l('field_start_date'), $this->pdf->formatDate($issue->startDate)];
        }
        if (!\in_array('due_date', $disabled, true)) {
            $right[] = [$l('field_due_date'), $this->pdf->formatDate($issue->dueDate)];
        }
        if (!\in_array('done_ratio', $disabled, true)) {
            $right[] = [$l('field_done_ratio'), $issue->doneRatio.'%'];
        }
        if (!\in_array('estimated_hours', $disabled, true)) {
            $right[] = [$l('field_estimated_hours'), $this->pdf->lHours($issue->estimatedHours)];
        }
        if ($this->auth->allowedTo('view_time_entries', $project)) {
            $right[] = [$l('label_spent_time'), $this->pdf->lHours($this->issues->totalSpentHours($issue))];
        }
        $rows = max(\count($left), \count($right));
        $left = array_pad($left, $rows, null);
        $right = array_pad($right, $rows, null);

        $half = $this->helpers->customValuesForDisplay($issue, 'half');
        $halfCount = (int) ceil(\count($half) / 2);
        foreach ($half as $n => $cv) {
            $item = [$cv['field']->name, $this->showValue($cv['field'], $cv['value'], false)];
            if ($n < $halfCount) {
                $left[] = $item;
            } else {
                $right[] = $item;
            }
        }

        if ($pdf->isRtl()) {
            [$borderFirstTop, $borderLastTop, $borderFirst, $borderLast] = ['RT', 'LT', 'R', 'L'];
        } else {
            [$borderFirstTop, $borderLastTop, $borderFirst, $borderLast] = ['LT', 'RT', 'L', 'R'];
        }

        $rows = max(\count($left), \count($right));
        for ($r = 0; $r < $rows; ++$r) {
            $li = $left[$r] ?? null;
            $ri = $right[$r] ?? null;
            $heights = [];
            $pdf->setFontStyle('B', 9);
            $heights[] = $pdf->getStringHeight(35, null !== $li ? $li[0].':' : '');
            $heights[] = $pdf->getStringHeight(35, null !== $ri ? $ri[0].':' : '');
            $pdf->setFontStyle('', 9);
            $heights[] = $pdf->getStringHeight(60, null !== $li ? (string) $li[1] : '');
            $heights[] = $pdf->getStringHeight(60, null !== $ri ? (string) $ri[1] : '');
            $height = max($heights);

            $pdf->setFontStyle('B', 9);
            $pdf->rdmMultiCell(35, $height, null !== $li ? $li[0].':' : '', 0 === $r ? $borderFirstTop : $borderFirst, '', 0, 0);
            $pdf->setFontStyle('', 9);
            $pdf->rdmMultiCell(60, $height, null !== $li ? (string) $li[1] : '', 0 === $r ? $borderLastTop : $borderLast, '', 0, 0);
            $pdf->setFontStyle('B', 9);
            $pdf->rdmMultiCell(35, $height, null !== $ri ? $ri[0].':' : '', 0 === $r ? $borderFirstTop : $borderFirst, '', 0, 0);
            $pdf->setFontStyle('', 9);
            $pdf->rdmMultiCell(60, $height, null !== $ri ? (string) $ri[1] : '', 0 === $r ? $borderLastTop : $borderLast, '', 0, 2);
            $pdf->setX($baseX);
        }

        $pdf->setFontStyle('B', 9);
        $pdf->rdmCell(35 + 155, 5, $l('field_description'), 'LRT', 1);
        $pdf->setFontStyle('', 9);
        // Set resize image scale
        $pdf->setImageScale(1.6);
        $text = $this->pdf->formatText($issue->description, $project, ['object' => $issue, 'attachments' => $attachments]);
        $pdf->rdmWriteFormattedCell(35 + 155, 5, '', '', $text, $attachments, 'LRB');

        foreach ($this->helpers->customValuesForDisplay($issue, 'full') as $cv) {
            $cf = $cv['field'];
            $isHtml = self::fullTextFormatting($cf);
            $text = $this->showValue($cf, $cv['value'], $isHtml, $project, $issue);
            if ('' === trim($text)) {
                continue;
            }
            $pdf->setFontStyle('B', 9);
            $pdf->rdmCell(35 + 155, 5, $cf->name, 'LRT', 1);
            $pdf->setFontStyle('', 9);
            $html = $isHtml ? $text : $this->pdf->formattedText($text);
            $pdf->rdmWriteFormattedCell(35 + 155, 5, '', '', $html, $attachments, 'LRB');
        }

        if (!$issue->isLeaf()) {
            $truncate = $this->pdf->isCjk() ? 65 : 90;
            $pdf->setFontStyle('B', 9);
            $pdf->rdmCell(35 + 155, 5, $l('label_subtask_plural').':', 'LTR');
            $pdf->Ln();
            $descendants = array_values(array_filter($issue->getDescendants(), fn (Issue $d) => $this->issues->isVisible($d)));
            usort($descendants, static fn (Issue $a, Issue $b) => ($a->lft ?? 0) <=> ($b->lft ?? 0));
            foreach (self::issueList($descendants) as [$child, $level]) {
                $buf = PdfSupport::truncate($child->tracker->name.' # '.$child->id.': '.$child->subject, $truncate);
                $level = min($level, 10);
                $pdf->setFontStyle('', 8);
                $pdf->rdmCell(35 + 135, 5, ($level >= 1 ? str_repeat('  ', $level) : '').$buf, $borderFirst);
                $pdf->setFontStyle('B', 8);
                $pdf->rdmCell(20, 5, $child->status->name, $borderLast);
                $pdf->Ln();
            }
        }

        $relations = $this->presenter->visibleRelations($issue);
        if ([] !== $relations) {
            $truncate = $this->pdf->isCjk() ? 60 : 80;
            $pdf->setFontStyle('B', 9);
            $pdf->rdmCell(35 + 155, 5, $l('label_related_issues').':', 'LTR');
            $pdf->Ln();
            $cross = $this->pdf->settings->bool('cross_project_issue_relations');
            foreach ($relations as $relation) {
                $other = $relation->otherIssue($issue);
                $text = '';
                if ($cross) {
                    $text .= $other->project->name.' - ';
                }
                $text .= $other->tracker->name.' #'.$other->id.': '.$other->subject;
                $buf = PdfSupport::truncate($this->relationToS($relation, $issue, $text), $truncate);
                $pdf->setFontStyle('', 8);
                $pdf->rdmCell(35 + 155 - 60, 5, $buf, $borderFirst);
                $pdf->setFontStyle('B', 8);
                $pdf->rdmCell(20, 5, $other->status->name, '');
                $pdf->rdmCell(20, 5, $this->pdf->formatDate($other->startDate), '');
                $pdf->rdmCell(20, 5, $this->pdf->formatDate($other->dueDate), $borderLast);
                $pdf->Ln();
            }
        }
        $pdf->rdmCell(190, 5, '', 'T');
        $pdf->Ln();

        $changesets = $this->auth->allowedTo('view_changesets', $project) ? $this->presenter->changesets($issue) : [];
        if ([] !== $changesets) {
            $pdf->setFontStyle('B', 9);
            $pdf->rdmCell(190, 5, $l('label_associated_revisions'), 'B');
            $pdf->Ln();
            foreach ($changesets as $changeset) {
                $pdf->setFontStyle('B', 8);
                $cs = $l('label_revision').' '.RepositoryService::formatIdentifier($changeset).' - ';
                $cs .= $this->pdf->formatTime($changeset->committedOn).' - '.self::changesetAuthor($changeset);
                $pdf->rdmCell(190, 5, $cs);
                $pdf->Ln();
                if (null !== $changeset->comments && '' !== trim($changeset->comments)) {
                    $pdf->setFontStyle('', 8);
                    $html = $this->pdf->formatText($changeset->comments, $changeset->repository->project);
                    $pdf->rdmWriteFormattedCell(190, 5, '', '', $html, $attachments, '');
                }
                $pdf->Ln();
            }
        }

        if ([] !== $journals) {
            $pdf->setFontStyle('B', 9);
            $pdf->rdmCell(190, 5, $l('label_history'), 'B');
            $pdf->Ln();
            foreach ($journals as $journal) {
                $pdf->setFontStyle('B', 8);
                $title = '#'.$journal->indice.' - '.$this->pdf->formatTime($journal->createdOn).' - '.$journal->user->getName();
                if ($journal->privateNotes) {
                    $title .= ' ('.$l('field_private_notes').')';
                }
                $pdf->rdmCell(190, 5, $title);
                $pdf->Ln();
                $pdf->setFontStyle('I', 8);
                foreach ($this->helpers->detailsToStrings($this->helpers->visibleDetails($journal), true) as $string) {
                    $pdf->rdmMultiCell(190, 5, '- '.html_entity_decode(strip_tags($string), \ENT_QUOTES | \ENT_HTML5));
                }
                if ($journal->hasNotes()) {
                    if ($journal->details->count() > 0) {
                        $pdf->Ln();
                    }
                    $pdf->setFontStyle('', 8);
                    $text = $this->pdf->formatText($journal->notes, $project, ['object' => $issue, 'attachments' => $attachments]);
                    $pdf->rdmWriteFormattedCell(190, 5, '', '', $text, $attachments, '');
                }
                $pdf->Ln();
            }
        }

        if ([] !== $attachments) {
            $pdf->setFontStyle('B', 9);
            $pdf->rdmCell(190, 5, $l('label_attachment_plural'), 'B');
            $pdf->Ln();
            foreach ($attachments as $attachment) {
                $this->attachmentRow($pdf, $attachment);
            }
        }

        return $pdf->render();
    }

    public function attachmentRow(ITCPDF $pdf, Attachment $attachment): void
    {
        $pdf->setFontStyle('', 8);
        $pdf->rdmCell(80, 5, $attachment->filename);
        $pdf->rdmCell(20, 5, PdfSupport::humanSize($attachment->getFilesize()), 0, 0, 'R');
        $pdf->rdmCell(25, 5, $this->pdf->formatDate($attachment->createdOn), 0, 0, 'R');
        $pdf->rdmCell(65, 5, $attachment->author->getName(), 0, 0, 'R');
        $pdf->Ln();
    }

    private static function changesetAuthor(Changeset $c): string
    {
        if (null !== $c->user) {
            return $c->user->getName();
        }

        return trim(explode('<', (string) $c->committer)[0]);
    }

    /** IssueRelation#to_s(issue) { other text } */
    private function relationToS(IssueRelation $relation, Issue $issue, string $issueText): string
    {
        $s = [$this->pdf->l($relation->labelFor($issue))];
        if (null !== $relation->delay && 0 !== $relation->delay) {
            $s[] = '('.$this->pdf->l('datetime.distance_in_words.x_days', ['count' => $relation->delay]).')';
        }
        $s[] = $issueText;

        return implode(' ', $s);
    }

    private static function fullTextFormatting(CustomField $cf): bool
    {
        return 'full' === (string) $cf->getFormatOption('text_formatting');
    }

    /** CustomFieldsHelper#show_value(custom_value, html) */
    private function showValue(CustomField $cf, mixed $value, bool $html, ?Project $project = null, ?object $customized = null): string
    {
        if ($html) {
            return $this->pdf->absoluteLinks($this->helpers->customFieldValueHtml($cf, $value, $project, $customized));
        }

        return html_entity_decode(strip_tags($this->qx->formatObject(['custom_field' => $cf, 'value' => $value], false)), \ENT_QUOTES | \ENT_HTML5);
    }

    /**
     * IssuesHelper#issue_list: yields [issue, level] where level is the depth among the listed issues.
     *
     * @param list<Issue> $issues
     *
     * @return list<array{0: Issue, 1: int}>
     */
    public static function issueList(array $issues): array
    {
        $ancestors = [];
        $out = [];
        foreach ($issues as $issue) {
            while ([] !== $ancestors && !$issue->isDescendantOf(end($ancestors))) {
                array_pop($ancestors);
            }
            $out[] = [$issue, \count($ancestors)];
            if (!$issue->isLeaf()) {
                $ancestors[] = $issue;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------
    // Issue list
    // ------------------------------------------------------------------------------------------

    /** @param list<Issue> $issues */
    public function issuesToPdf(array $issues, ?Project $project, IssueQuery $query): string
    {
        $pdf = $this->pdf->newPdf('L');
        $title = $query->isNew() ? $this->pdf->l('label_issue_plural') : $query->name();
        if (null !== $project) {
            $title = $project->name.' - '.$title;
        }
        $pdf->setTitle($title);
        $pdf->setAutoPageBreak(false);
        $pdf->AddPage('L');

        // Landscape A4 = 210 x 297 mm
        $pageHeight = $pdf->getPageHeight();
        $pageWidth = $pdf->getPageWidth();
        $margins = $pdf->getOriginalMargins();
        $leftMargin = $margins['left'];
        $rightMargin = $margins['right'];
        $bottomMargin = $pdf->getFooterMargin();
        $rowHeight = 4;

        $inline = $query->inlineColumns();
        $block = $query->blockColumns();
        $list = self::issueList($issues);

        // column widths
        $tableWidth = $pageWidth - $rightMargin - $leftMargin;
        $colWidth = [];
        if ([] !== $inline) {
            $colWidth = $this->calcColWidth($list, $query, $tableWidth, $pdf);
            $tableWidth = array_sum($colWidth);
        }
        // use full width if the query has block columns (description, last_notes or full width custom fieds)
        if ($tableWidth > 0 && [] !== $block) {
            $full = $pageWidth - $rightMargin - $leftMargin;
            $colWidth = array_map(static fn ($w) => $w * $full / $tableWidth, $colWidth);
            $tableWidth = array_sum($colWidth);
        }

        // title
        $pdf->setFontStyle('B', 11);
        $pdf->rdmCell(190, 8, $title);
        $pdf->Ln();

        // totals
        $totals = array_map(static fn (array $t) => $t[0]->caption.': '.self::rubyNumber($t[1]), $query->totals());
        if ([] !== $totals) {
            $pdf->setFontStyle('B', 10);
            $pdf->rdmCell($tableWidth, 6, implode('  ', $totals), 0, 1, 'R');
        }

        $totalsByGroup = [];
        if ($query->isGrouped()) {
            foreach ($query->totalableColumns() as $c) {
                $totalsByGroup[] = [$c, $query->totalByGroupFor($c) ?? []];
            }
        }
        $this->renderTableHeader($pdf, $query, $colWidth);
        $previousGroup = null;
        $first = true;
        $countByGroup = $query->isGrouped() ? ($query->resultCountByGroup() ?? []) : [];

        foreach ($list as [$issue, $level]) {
            if ($query->isGrouped()) {
                $key = $query->groupKey($issue);
                if ($first || $key !== $previousGroup) {
                    $pdf->setFontStyle('B', 10);
                    $label = $this->groupLabel($query->groupValue($issue));
                    $label .= ' ('.($countByGroup[$key] ?? '').')';
                    $pdf->Bookmark($label, 0, -1);
                    $pdf->rdmCell($tableWidth, $rowHeight * 2, $label, 'LR', 1, 'L');
                    $pdf->setFontStyle('', 8);
                    $gt = implode('  ', array_map(static fn (array $ct) => $ct[0]->caption.': '.(isset($ct[1][$key]) ? self::rubyNumber($ct[1][$key]) : ''), $totalsByGroup));
                    if ('' !== $gt) {
                        $pdf->rdmCell($tableWidth, $rowHeight, $gt, 'LR', 1, 'L');
                    }
                    $previousGroup = $key;
                }
            }
            $first = false;

            // fetch row values
            $values = $this->fetchRowValues($issue, $query, $level);

            // make new page if it doesn't fit on the current one
            $baseY = $pdf->GetY();
            $maxHeight = self::cellsHeight($pdf, $values, $colWidth);
            $spaceLeft = $pageHeight - $baseY - $bottomMargin;
            if ($maxHeight > $spaceLeft) {
                $pdf->AddPage('L');
                $this->renderTableHeader($pdf, $query, $colWidth);
                $baseY = $pdf->GetY();
            }

            // write the cells on page
            self::writeCells($pdf, $values, $colWidth, $maxHeight);
            $pdf->setY($baseY + $maxHeight);

            foreach ($block as $column) {
                [$text, $isHtml] = $this->blockColumnText($query, $column, $issue);
                if ('' === trim($text)) {
                    continue;
                }
                $pdf->setX(10);
                $pdf->setAutoPageBreak(true, $bottomMargin);
                $pdf->setFontStyle('B', 9);
                $pdf->rdmCell(0, 5, $column->caption, 'LRT', 1);
                $pdf->setFontStyle('', 9);
                if ($isHtml) {
                    $pdf->rdmWriteFormattedCell(0, 5, '', '', $text, $this->attachments->forContainer('Issue', $issue->id), 'LRB');
                } else {
                    $pdf->rdmWriteFormattedCell(0, 5, '', '', $this->pdf->formattedText($text), [], 'LRB');
                }
                $pdf->setAutoPageBreak(false);
            }
        }

        if (\count($issues) === $this->pdf->settings->int('issues_export_limit')) {
            $pdf->setFontStyle('B', 10);
            $pdf->rdmCell(0, $rowHeight, '...');
        }

        return $pdf->render();
    }

    /** @return array{0: string, 1: bool} text and whether it is HTML */
    private function blockColumnText(IssueQuery $query, QueryColumn $column, Issue $issue): array
    {
        if ($column->isCustomField() && null !== $column->customField) {
            $value = $query->columnValue($column, $issue);
            if (!\is_array($value) || !isset($value['custom_field'])) {
                return ['', false];
            }
            $isHtml = self::fullTextFormatting($column->customField);

            return [$this->showValue($column->customField, $value['value'], $isHtml, $issue->project, $issue), $isHtml];
        }
        $text = 'description' === $column->name ? $issue->description : (string) $query->columnValue($column, $issue);

        return [$this->pdf->formatText($text, $issue->project, ['object' => $issue]), true];
    }

    /** group.blank? ? 'None' : group.to_s */
    private function groupLabel(mixed $value): string
    {
        if (\is_array($value) && isset($value['custom_field'])) {
            $value = $value['value'];
            if (\is_array($value)) {
                $value = [] === $value ? null : implode(', ', $value);
            }
        }

        return match (true) {
            null === $value, false === $value, '' === $value, \is_string($value) && '' === trim($value) => 'None',
            true === $value => 'true',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            \is_float($value) => self::rubyNumber($value),
            \is_object($value) => self::objectToS($value),
            default => (string) $value,
        };
    }

    private static function objectToS(object $o): string
    {
        if ($o instanceof Principal) {
            return $o->getName();
        }
        if (method_exists($o, '__toString')) {
            return (string) $o;
        }

        return property_exists($o, 'name') ? (string) $o->name : '';
    }

    /** Ruby Integer/Float#to_s ("14.0", "2.5", "3") */
    public static function rubyNumber(mixed $n): string
    {
        if (null === $n) {
            return '';
        }
        if (\is_int($n)) {
            return (string) $n;
        }
        $f = (float) $n;
        if (floor($f) === $f && abs($f) < 1e16) {
            return number_format($f, 1, '.', '');
        }

        return rtrim(rtrim(\sprintf('%.10F', $f), '0'), '.');
    }

    /**
     * IssuesPdfHelper#fetch_row_values
     *
     * @return list<string>
     */
    public function fetchRowValues(Issue $issue, IssueQuery $query, int $level): array
    {
        $out = [];
        foreach ($query->inlineColumns() as $column) {
            $value = $query->columnValue($column, $issue);
            if ($column->isCustomField()) {
                $out[] = \is_array($value) && isset($value['custom_field']) ? $this->showValue($value['custom_field'], $value['value'], false) : '';
                continue;
            }
            switch ($column->name) {
                case 'subject':
                    $value = str_repeat('  ', $level).$value;
                    break;
                case 'attachments':
                    $value = implode("\n", array_map(static fn (Attachment $a) => $a->filename, (array) $value));
                    break;
                case 'watcher_users':
                    $value = implode("\n", array_map(static fn ($u) => $u instanceof Principal ? $u->getName() : (string) $u, (array) $value));
                    break;
                case 'relations':
                    $value = implode(', ', array_map(fn (IssueRelation $r) => $this->relationToS($r, $issue, '#'.$r->otherIssue($issue)->id), (array) $value));
                    break;
            }
            $out[] = match (true) {
                null === $value => '',
                $value instanceof \DateTimeInterface => QueryColumn::TIMESTAMP === $column->kind || \in_array($column->name, ['created_on', 'updated_on', 'closed_on'], true)
                    ? $this->pdf->formatTime($value) : $this->pdf->formatDate($value),
                \is_float($value) => \sprintf('%.2f', $value),
                \is_bool($value) => $value ? 'true' : 'false',
                \is_object($value) => self::objectToS($value),
                \is_array($value) => implode(', ', array_map(static fn ($v) => \is_object($v) ? self::objectToS($v) : (string) $v, $value)),
                default => (string) $value,
            };
        }

        return $out;
    }

    /**
     * IssuesPdfHelper#calc_col_width
     *
     * @param list<array{0: Issue, 1: int}> $list
     *
     * @return list<float>
     */
    private function calcColWidth(array $list, IssueQuery $query, float $tableWidth, ITCPDF $pdf): array
    {
        $columns = $query->inlineColumns();
        // calculate statistics
        //  by captions
        $pdf->setFontStyle('B', 8);
        $padding = (float) $pdf->getMargins()['padding_left'];
        $colWidthMin = array_map(static fn (QueryColumn $c) => $pdf->GetStringWidth($c->caption) + $padding, $columns);
        $colWidthMax = $colWidthMin;
        $colWidthAvg = $colWidthMin;
        $n = \count($colWidthAvg);
        $colMin = $pdf->GetStringWidth('OO') + $padding * 2;
        if ($tableWidth > $colMin * $n) {
            $tableWidth -= $colMin * $n;
        } else {
            $colMin = $pdf->GetStringWidth('O') + $padding * 2;
            if ($tableWidth > $colMin * $n) {
                $tableWidth -= $colMin * $n;
            } else {
                $ratio = $tableWidth / array_sum($colWidthAvg);

                return array_map(static fn ($w) => $w * $ratio, $colWidthAvg);
            }
        }
        $wordWidthMax = [];
        foreach ($columns as $c) {
            $m = 10.0;
            foreach (preg_split('/\s+/u', trim($c->caption)) ?: [] as $w) {
                $x = $pdf->GetStringWidth($w) + $padding;
                if ($m < $x) {
                    $m = $x;
                }
            }
            $wordWidthMax[] = $m;
        }

        //  by properties of issues
        $pdf->setFontStyle('', 8);
        $k = 1;
        foreach ($list as [$issue, $level]) {
            ++$k;
            foreach ($this->fetchRowValues($issue, $query, $level) as $i => $v) {
                $w = $pdf->GetStringWidth($v) + $padding * 2;
                if ($colWidthMax[$i] < $w) {
                    $colWidthMax[$i] = $w;
                }
                if ($colWidthMin[$i] > $w) {
                    $colWidthMin[$i] = $w;
                }
                $colWidthAvg[$i] += $w;
                foreach (preg_split('/\s+/u', trim($v)) ?: [] as $word) {
                    if ('' === $word) {
                        continue;
                    }
                    $x = $pdf->GetStringWidth($word) + $padding;
                    if ($wordWidthMax[$i] < $x) {
                        $wordWidthMax[$i] = $x;
                    }
                }
            }
        }
        $colWidthAvg = array_map(static fn ($x) => $x / $k, $colWidthAvg);

        // calculate columns width
        $ratio = $tableWidth / array_sum($colWidthAvg);
        $colWidth = array_map(static fn ($w) => $w * $ratio, $colWidthAvg);

        // correct max word width if too many columns
        $ratio = $tableWidth / array_sum($wordWidthMax);
        if ($ratio < 1) {
            $wordWidthMax = array_map(static fn ($v) => $v * $ratio, $wordWidthMax);
        }

        // correct and lock width of some columns
        $done = 1;
        $colFix = [];
        foreach ($colWidth as $i => $w) {
            if ($w > $colWidthMax[$i]) {
                $colWidth[$i] = $colWidthMax[$i];
                $colFix[$i] = 1;
                $done = 0;
            } elseif ($w < $wordWidthMax[$i]) {
                $colWidth[$i] = $wordWidthMax[$i];
                $colFix[$i] = 1;
                $done = 0;
            } else {
                $colFix[$i] = 0;
            }
        }

        // iterate while need to correct and lock coluns width
        $guard = 0;
        while (0 === $done && $guard++ < 1000) {
            $done = 1;
            $ratio = $tableWidth / array_sum($colWidth);
            foreach ($colWidth as $i => $w) {
                if (0 === $colFix[$i]) {
                    $colWidth[$i] = $w * $ratio;
                    // check if column width less then max word width
                    if ($colWidth[$i] < $wordWidthMax[$i]) {
                        $colWidth[$i] = $wordWidthMax[$i];
                        $colFix[$i] = 1;
                        $done = 0;
                    } elseif ($colWidth[$i] > $colWidthMax[$i]) {
                        $colWidth[$i] = $colWidthMax[$i];
                        $colFix[$i] = 1;
                        $done = 0;
                    }
                }
            }
        }

        $ratio = $tableWidth / array_sum($colWidth);

        return array_map(static fn ($v) => $v * $ratio + $colMin, $colWidth);
    }

    /** @param list<float> $colWidth */
    private function renderTableHeader(ITCPDF $pdf, IssueQuery $query, array $colWidth): void
    {
        // headers
        $pdf->setFontStyle('B', 8);
        $pdf->setFillColor(230, 230, 230);
        $baseX = $pdf->GetX();
        $baseY = $pdf->GetY();
        $captions = array_map(static fn (QueryColumn $c) => $c->caption, $query->inlineColumns());
        $maxHeight = self::cellsHeight($pdf, $captions, $colWidth);
        // write the cells on page
        self::writeCells($pdf, $captions, $colWidth, $maxHeight);
        $pdf->setXY($baseX, $baseY + $maxHeight);
        // rows
        $pdf->setFontStyle('', 8);
        $pdf->setFillColor(255, 255, 255);
    }

    /**
     * Maximum height of the MultiCells (get_issues_to_pdf_write_cells)
     *
     * @param list<string> $values
     * @param list<float>  $widths
     */
    private static function cellsHeight(ITCPDF $pdf, array $values, array $widths): float
    {
        $heights = [0.0];
        foreach ($values as $i => $v) {
            $heights[] = $pdf->getStringHeight($widths[$i], $v);
        }

        return max($heights);
    }

    /**
     * issues_to_pdf_write_cells
     *
     * @param list<string> $values
     * @param list<float>  $widths
     */
    private static function writeCells(ITCPDF $pdf, array $values, array $widths, float $height): void
    {
        foreach ($values as $i => $v) {
            $pdf->rdmMultiCell($widths[$i], $height, trim($v), 1, '', 1, 0);
        }
    }
}
