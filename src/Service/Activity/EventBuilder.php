<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\Attachment;
use App\Entity\Changeset;
use App\Entity\Document;
use App\Entity\Issue;
use App\Entity\IssueStatus;
use App\Entity\Journal;
use App\Entity\Message;
use App\Entity\News;
use App\Entity\Project;
use App\Entity\TimeEntry;
use App\Entity\WikiContentVersion;
use App\Entity\WikiPage;
use App\I18n\Localizer;
use App\Service\IssueService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds ActivityEvent objects from records (the acts_as_event options of each Redmine model).
 * URLs are Redmine's paths (relative to the application base URL).
 */
final class EventBuilder
{
    /** @var array<int, ?IssueStatus> */
    private array $statuses = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Localizer $i18n,
        private readonly IssueService $issues,
    ) {
    }

    private static function seg(string $s): string
    {
        return rawurlencode($s);
    }

    public function issueTitle(Issue $issue): string
    {
        return $issue->tracker->name.' #'.$issue->id.' ('.$issue->status->name.'): '.$issue->subject;
    }

    public function issue(Issue $issue): ActivityEvent
    {
        return new ActivityEvent(
            'issue'.($issue->isClosed() ? '-closed' : ''),
            $issue->createdOn ?? new \DateTimeImmutable(),
            $this->issueTitle($issue),
            '/issues/'.$issue->id,
            $issue->description,
            $issue->author,
            $issue->project,
            'issue-'.$issue->id,
            (int) $issue->id,
        );
    }

    private function status(int $id): ?IssueStatus
    {
        if (!\array_key_exists($id, $this->statuses)) {
            $this->statuses[$id] = $this->em->find(IssueStatus::class, $id);
        }

        return $this->statuses[$id];
    }

    /** Journal#new_status */
    public function journalNewStatus(Journal $journal): ?IssueStatus
    {
        foreach ($journal->details as $d) {
            if ('attr' === $d->property && 'status_id' === $d->propKey && null !== $d->value && '' !== $d->value) {
                return $this->status((int) $d->value);
            }
        }

        return null;
    }

    public function journal(Journal $journal): ActivityEvent
    {
        $issue = $journal->issue;
        $status = $this->journalNewStatus($journal);
        $title = $issue->tracker->name.' #'.$issue->id.(null !== $status ? ' ('.$status->name.')' : '').': '.$issue->subject;
        $type = null !== $status ? ($status->isClosed ? 'issue-closed' : 'issue-edit') : 'issue-note';

        return new ActivityEvent(
            $type,
            $journal->createdOn,
            $title,
            '/issues/'.$issue->id.'#change-'.$journal->id,
            $journal->notes,
            $journal->user,
            $issue->project,
            'issue-'.$issue->id,
            (int) $journal->id,
        );
    }

    public function changesetTitle(Changeset $c): string
    {
        [$short] = $this->splitComments($c->comments);
        $repo = $c->repository->identifier ? ' ('.$c->repository->identifier.')' : '';
        $comm = '' === trim((string) $short) ? '' : ': '.$short;

        return $this->i18n->l('label_revision').' '.$this->formatChangesetIdentifier($c).$repo.$comm;
    }

    /** @return array{0: string, 1: string} short and long comments */
    private function splitComments(?string $comments): array
    {
        $comments = (string) $comments;
        if (preg_match('/\A(.+?)\r?\n(.*)$/s', $comments, $m)) {
            return [$m[1], trim($m[2])];
        }

        return [$comments, ''];
    }

    private function isGit(Changeset $c): bool
    {
        return str_contains((string) $c->repository->type, 'Git');
    }

    private function formatChangesetIdentifier(Changeset $c): string
    {
        return $this->isGit($c) ? substr($c->revision, 0, 8) : $c->revision;
    }

    public function changeset(Changeset $c): ActivityEvent
    {
        $repo = $c->repository;
        $project = $repo->project;
        $identifier = $this->isGit($c) ? (string) ($c->scmid ?? $c->revision) : $c->revision;
        $repoParam = $repo->identifier ?: (string) $repo->id;
        [, $long] = $this->splitComments($c->comments);
        $authorName = null === $c->user ? trim(explode('<', (string) $c->committer)[0]) : null;

        return new ActivityEvent(
            'changeset',
            $c->committedOn,
            $this->changesetTitle($c),
            '/projects/'.self::seg((string) $project->identifier).'/repository/'.self::seg($repoParam).'/revisions/'.self::seg($identifier),
            $long,
            $c->user,
            $project,
            'changeset-'.$c->id,
            (int) $c->id,
            $authorName,
        );
    }

    public function news(News $n): ActivityEvent
    {
        return new ActivityEvent('news', $n->createdOn ?? new \DateTimeImmutable(), $n->title, '/news/'.$n->id, $n->description, $n->author, $n->project, 'news-'.$n->id, (int) $n->id);
    }

    public function document(Document $d): ActivityEvent
    {
        // author: author of the oldest attachment
        $author = $this->em->createQuery("SELECT a FROM App\Entity\Attachment a WHERE a.containerType = 'Document' AND a.containerId = :id ORDER BY a.createdOn ASC")
            ->setParameter('id', $d->id)->setMaxResults(1)->getOneOrNullResult()?->author;

        return new ActivityEvent(
            'document',
            $d->createdOn ?? new \DateTimeImmutable(),
            $this->i18n->l('label_document').': '.$d->title,
            '/documents/'.$d->id,
            $d->description,
            $author,
            $d->project,
            'document-'.$d->id,
            (int) $d->id,
        );
    }

    public function attachment(Attachment $a, ?Project $project): ActivityEvent
    {
        return new ActivityEvent(
            'attachment',
            $a->createdOn ?? new \DateTimeImmutable(),
            $a->filename,
            '/attachments/'.$a->id.'/'.self::seg($a->filename),
            $a->description,
            $a->author,
            $project,
            'attachment-'.$a->id,
            (int) $a->id,
        );
    }

    public function wikiContentVersion(WikiContentVersion $v, WikiPage $page): ActivityEvent
    {
        $project = $page->wiki->project;

        return new ActivityEvent(
            'wiki-page',
            $v->updatedOn,
            $this->i18n->l('label_wiki_edit').': '.$page->title.' (#'.$v->version.')',
            '/projects/'.self::seg((string) $project->identifier).'/wiki/'.self::seg($page->title).'/'.$v->version,
            $v->comments,
            $v->author,
            $project,
            'wikipage-'.$page->id,
            (int) $v->id,
        );
    }

    public function wikiPage(WikiPage $page): ActivityEvent
    {
        $project = $page->wiki->project;

        return new ActivityEvent(
            'wiki-page',
            $page->createdOn,
            $this->i18n->l('label_wiki').': '.$page->title,
            '/projects/'.self::seg((string) $project->identifier).'/wiki/'.self::seg($page->title),
            $page->content?->text,
            $page->content?->author,
            $project,
            'wikipage-'.$page->id,
            (int) $page->id,
        );
    }

    public function message(Message $m): ActivityEvent
    {
        $url = null === $m->parent
            ? '/boards/'.$m->board->id.'/topics/'.$m->id
            : '/boards/'.$m->board->id.'/topics/'.$m->parent->id.'?r='.$m->id.'#message-'.$m->id;

        return new ActivityEvent(
            null === $m->parent ? 'message' : 'reply',
            $m->createdOn,
            $m->board->name.': '.$m->subject,
            $url,
            $m->content,
            $m->author,
            $m->board->project,
            'message-'.($m->parent?->id ?? $m->id),
            (int) $m->id,
        );
    }

    public function projectTitle(Project $p): string
    {
        return $this->i18n->l('label_project').': '.$p->name;
    }

    public function project(Project $p): ActivityEvent
    {
        return new ActivityEvent(
            'project',
            $p->createdOn ?? new \DateTimeImmutable(),
            $this->projectTitle($p),
            '/projects/'.self::seg((string) $p->identifier),
            $p->description,
            null,
            $p,
            'project-'.$p->id,
            (int) $p->id,
        );
    }

    /** l_hours */
    public function hours(float $hours): string
    {
        return $this->i18n->l($hours < 2.0 ? 'label_f_hour' : 'label_f_hour_plural', ['value' => $this->i18n->formatHours($hours)]);
    }

    public function timeEntry(TimeEntry $t): ActivityEvent
    {
        $related = (null !== $t->issue && $this->issues->isVisible($t->issue)) ? $this->issueTitle($t->issue) : $this->projectTitle($t->project);
        $url = '/projects/'.self::seg((string) $t->project->identifier).'/time_entries'.(null !== $t->issue ? '?issue_id='.$t->issue->id : '');

        return new ActivityEvent(
            'time-entry',
            $t->createdOn,
            $this->hours($t->hours).' ('.$related.')',
            $url,
            $t->comments,
            $t->user,
            $t->project,
            null !== $t->issue ? 'issue-'.$t->issue->id : 'timeentry-'.$t->id,
            (int) $t->id,
        );
    }
}
