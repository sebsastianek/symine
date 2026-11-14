<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Api\IssueApiPresenter;
use App\Entity\Issue;
use App\Entity\Journal;
use App\Entity\TimeEntry;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\AttachmentService;
use App\Service\IssueService;
use App\Service\ProjectService;
use App\Service\WatcherService;
use App\Twig\IssuesExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the variables of the issue page (issues/show): the issue with what the user may see and do, its
 * history and the tabs under it.
 */
final class IssueShowView
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurrentUser $currentUser,
        private readonly Authorization $auth,
        private readonly IssueService $issues,
        private readonly ProjectService $projects,
        private readonly AttachmentService $attachments,
        private readonly WatcherService $watchers,
        private readonly IssueApiPresenter $presenter,
        private readonly IssuesExtension $helpers,
        private readonly UrlGeneratorInterface $urls,
        private readonly ?\App\Doctrine\BatchLoader $batch = null,
    ) {
    }

    /**
     * The history the user may see, in the order he prefers: oldest first, unless he wants the comments in
     * reverse order (also the order of the Atom feed, journals/index.builder with @journals).
     *
     * @return list<Journal>
     */
    public function journals(Issue $issue): array
    {
        $journals = $this->presenter->visibleJournals($issue);

        return 'desc' === $this->currentUser->get()->getPref()->commentsSorting() ? array_reverse($journals) : $journals;
    }

    /**
     * @param list<Journal> $journals
     *
     * @return array<string, mixed>
     */
    public function vars(Request $request, Issue $issue, array $journals): array
    {
        $project = $issue->project;
        $timeEntries = [];
        $canViewTime = $this->auth->allowedTo('view_time_entries', $project);
        $spent = $canViewTime ? $this->issues->spentHours($issue) : 0.0;
        $hasChangesets = [] !== $this->presenter->changesets($issue);
        $attachments = $this->attachments->forContainer('Issue', $issue->id);
        $journalsByAttachment = [];
        $this->batch?->collections($journals, 'details');
        foreach ($journals as $j) {
            foreach ($j->details as $d) {
                if ('attachment' === $d->property && null !== $d->value) {
                    $journalsByAttachment[(int) $d->propKey] = $j;
                }
            }
        }
        $tabs = [];
        if ([] !== $journals) {
            $tabs[] = ['name' => 'history', 'label' => 'label_history', 'onclick' => 'showIssueHistory("history", this.href)'];
            if (array_filter($journals, static fn (Journal $j) => $j->hasNotes())) {
                $tabs[] = ['name' => 'notes', 'label' => 'label_issue_history_notes', 'onclick' => 'showIssueHistory("notes", this.href)'];
            }
            if (array_filter($journals, static fn (Journal $j) => $j->details->count() > 0)) {
                $tabs[] = ['name' => 'properties', 'label' => 'label_issue_history_properties', 'onclick' => 'showIssueHistory("properties", this.href)'];
            }
        }
        if ($canViewTime && $spent > 0) {
            $tabs[] = ['name' => 'time_entries', 'label' => 'label_time_entry_plural', 'remote' => true,
                'onclick' => "getRemoteTab('time_entries', '".$this->urls->generate('issue_tab', ['id' => $issue->id, 'name' => 'time_entries'])."', '".$this->urls->generate('issue_show', ['id' => $issue->id, 'tab' => 'time_entries'])."')"];
        }
        if ($hasChangesets) {
            $tabs[] = ['name' => 'changesets', 'label' => 'label_associated_revisions', 'remote' => true,
                'onclick' => "getRemoteTab('changesets', '".$this->urls->generate('issue_tab', ['id' => $issue->id, 'name' => 'changesets'])."', '".$this->urls->generate('issue_show', ['id' => $issue->id, 'tab' => 'changesets'])."')"];
        }
        $selectedTab = (string) $request->query->get('tab', '');
        if ('' === $selectedTab) {
            $pref = $this->currentUser->get()->getPref()->get('history_default_tab');
            $selectedTab = match ($pref) {
                'last_tab_visited' => (string) ($request->cookies->get('history_last_tab') ?: 'notes'),
                '' => 'notes',
                null => '',
                default => (string) $pref,
            };
        }
        if (!array_filter($tabs, static fn ($t) => $t['name'] === $selectedTab)) {
            $selectedTab = $tabs[0]['name'] ?? '';
        }
        if ('time_entries' === $selectedTab) {
            $timeEntries = $this->timeEntries($issue);
        }
        $watchers = $this->watchers->watchers('Issue', (int) $issue->id);

        return [
            'project' => $project,
            'issue' => $issue,
            'journals' => $journals,
            'relations' => $this->presenter->visibleRelations($issue),
            'attachments' => $attachments,
            'journals_by_attachment' => $journalsByAttachment,
            'tabs' => $tabs,
            'selected_tab' => $selectedTab,
            'time_entries' => $timeEntries,
            'changesets' => 'changesets' === $selectedTab ? $this->presenter->changesets($issue) : [],
            'can_view_time' => $canViewTime,
            'issue_watchers' => $watchers,
            'is_editable' => $this->issues->isEditable($issue),
            'is_deletable' => $this->issues->isDeletable($issue),
            'is_copyable' => $this->auth->allowedTo('copy_issues', $project) && [] !== $this->projects->allowedTargetProjects(),
            'is_time_loggable' => $this->issues->isTimeLoggable($issue),
            'notes_addable' => $this->issues->isNotesAddable($issue),
            'attachments_deletable' => $this->issues->attachmentsDeletable($issue),
            'can_view_private_notes' => $this->issues->canViewPrivateNotes($issue),
            'issue_url' => $this->urls->generate('issue_show', ['id' => $issue->id], UrlGeneratorInterface::ABSOLUTE_URL),
            'half_custom_values' => $this->helpers->customValuesForDisplay($issue, 'half'),
            'full_custom_values' => $this->helpers->customValuesForDisplay($issue, 'full'),
            'has_assignable_versions' => [] !== $this->issues->assignableVersions($issue),
            'main_menu_selected' => 'issues',
            'page_title' => $issue->tracker->name.' #'.$issue->id.': '.$issue->subject,
            'body_css' => 'controller-issues action-show',
        ];
    }

    /**
     * The time entries of the issue the user may see ("Spent time" tab), most recent first.
     *
     * @return list<TimeEntry>
     */
    public function timeEntries(Issue $issue): array
    {
        $cond = $this->auth->allowedToCondition('view_time_entries', 'IDENTITY(t.project)');

        return $this->em->createQuery("SELECT t FROM App\Entity\TimeEntry t WHERE t.issue = :i AND ($cond) ORDER BY t.spentOn DESC, t.createdOn DESC")
            ->setParameter('i', $issue->id)->getResult();
    }
}
