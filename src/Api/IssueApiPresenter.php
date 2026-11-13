<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Attachment;
use App\Entity\Changeset;
use App\Entity\Issue;
use App\Entity\IssueRelation;
use App\Entity\IssueStatus;
use App\Entity\Journal;
use App\Entity\User;
use App\Security\Authorization;
use App\Service\CustomFieldService;
use App\Service\IssueService;
use App\Service\WatcherService;
use App\Twig\IssuesExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the REST API structures of app/views/issues/show.api.rsb, issue_relations/*.api.rsb and
 * attachments/show.api.rsb (render_api_attachment).
 */
final class IssueApiPresenter
{
    /** Elements rendered with XML attributes in issues/show.api.rsb */
    public const array ISSUE_XML_ATTRIBUTES = [
        'custom_field' => ['id', 'name', 'multiple'],
        'relation' => ['id', 'issue_id', 'issue_to_id', 'relation_type', 'delay'],
        'journal' => ['id'],
        'detail' => ['property', 'name'],
        'issue' => ['id'],
        'changeset' => ['revision'],
    ];

    public function __construct(
        private readonly IssueService $issues,
        private readonly CustomFieldService $customFields,
        private readonly WatcherService $watchers,
        private readonly Authorization $auth,
        private readonly UrlGeneratorInterface $urls,
        private readonly EntityManagerInterface $em,
        private readonly IssuesExtension $helpers,
        private readonly \App\Service\ReactionService $reactions,
    ) {
    }

    /**
     * @param list<string>            $include
     * @param list<IssueRelation>|null $relations
     */
    public function issue(Issue $issue, array $include = []): array
    {
        $out = ['id' => $issue->id];
        $out['project'] = ['id' => $issue->project->id, 'name' => $issue->project->name];
        $out['tracker'] = ['id' => $issue->tracker->id, 'name' => $issue->tracker->name];
        $out['status'] = ['id' => $issue->status->id, 'name' => $issue->status->name, 'is_closed' => $issue->status->isClosed];
        $out['priority'] = ['id' => $issue->priority->id, 'name' => $issue->priority->name];
        $out['author'] = ['id' => $issue->author->id, 'name' => $issue->author->getName()];
        if ($issue->assignedTo) {
            $out['assigned_to'] = ['id' => $issue->assignedTo->id, 'name' => $issue->assignedTo->getName()];
        }
        if ($issue->category) {
            $out['category'] = ['id' => $issue->category->id, 'name' => $issue->category->name];
        }
        if ($issue->fixedVersion) {
            $out['fixed_version'] = ['id' => $issue->fixedVersion->id, 'name' => $issue->fixedVersion->name];
        }
        if ($issue->parent) {
            $out['parent'] = ['id' => $issue->parent->id];
        }
        $out['subject'] = $issue->subject;
        $out['description'] = $issue->description;
        $out['start_date'] = ApiRenderer::date($issue->startDate);
        $out['due_date'] = ApiRenderer::date($issue->dueDate);
        $out['done_ratio'] = $issue->doneRatio;
        $out['is_private'] = $issue->isPrivate;
        $out['estimated_hours'] = $issue->estimatedHours;
        $total = $this->issues->totalEstimatedHours($issue);
        $out['total_estimated_hours'] = (null === $issue->estimatedHours && $issue->isLeaf()) ? null : $total;
        if ($this->auth->allowedTo('view_time_entries', $issue->project)) {
            // Issue.load_visible_spent_hours / load_visible_total_spent_hours: only time entries visible to the user
            $out['spent_hours'] = $this->visibleSpentHours([$issue->id]);
            $out['total_spent_hours'] = $issue->isLeaf() ? $out['spent_hours']
                : $this->visibleSpentHours([$issue->id, ...array_map(static fn (Issue $d) => $d->id, $issue->getDescendants())]);
        }
        $cfs = $this->customValues($issue);
        if ([] !== $cfs) {
            $out['custom_fields'] = $cfs;
        }
        $out['created_on'] = ApiRenderer::time($issue->createdOn);
        $out['updated_on'] = ApiRenderer::time($issue->updatedOn);
        $out['closed_on'] = ApiRenderer::time($issue->closedOn);
        $withReactions = \in_array('reactions', $include, true);
        if ($withReactions && null !== ($reactions = $this->reactions->apiReactions($issue))) {
            $out['reactions'] = $reactions;
        }

        if (\in_array('children', $include, true) && !$issue->isLeaf()) {
            $out['children'] = $this->children($issue);
        }
        if (\in_array('attachments', $include, true)) {
            $out['attachments'] = array_map($this->attachment(...), $this->em->getRepository(Attachment::class)->findBy(['containerType' => 'Issue', 'containerId' => $issue->id], ['createdOn' => 'ASC', 'id' => 'ASC']));
        }
        if (\in_array('relations', $include, true)) {
            $relations = $this->visibleRelations($issue);
            if ([] !== $relations) {
                $out['relations'] = array_map($this->relation(...), $relations);
            }
        }
        if (\in_array('changesets', $include, true)) {
            $out['changesets'] = array_map(fn (Changeset $c) => array_filter([
                'revision' => $c->revision,
                'user' => $c->user ? ['id' => $c->user->id, 'name' => $c->user->getName()] : null,
                'comments' => $c->comments,
                'committed_on' => ApiRenderer::time($c->committedOn),
            ], static fn ($v, $k) => 'user' !== $k || null !== $v, \ARRAY_FILTER_USE_BOTH), $this->changesets($issue));
        }
        if (\in_array('journals', $include, true)) {
            $journals = $this->visibleJournals($issue);
            if ($withReactions) {
                $this->reactions->preload($journals);
            }
            $out['journals'] = array_map(fn (Journal $j) => $this->journal($j, $withReactions), $journals);
        }
        if (\in_array('watchers', $include, true) && $this->auth->allowedTo('view_issue_watchers', $issue->project)) {
            $out['watchers'] = array_map(static fn ($u) => ['id' => $u->id, 'name' => $u->getName()], $this->watchers->watchers('Issue', (int) $issue->id));
        }
        if (\in_array('allowed_statuses', $include, true)) {
            $out['allowed_statuses'] = array_map(static fn (IssueStatus $s) => ['id' => $s->id, 'name' => $s->name, 'is_closed' => $s->isClosed], $this->issues->newStatusesAllowedTo($issue));
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function customValues(Issue $issue): array
    {
        $values = $this->customFields->values('Issue', $issue->id);
        $out = [];
        foreach ($this->customFields->visibleIssueFields($issue) as $cf) {
            $row = ['id' => $cf->id, 'name' => $cf->name];
            if ($cf->isMultiple()) {
                $row['multiple'] = true;
                $row['value'] = array_values(array_filter((array) ($values[$cf->id] ?? []), static fn ($v) => null !== $v && '' !== $v));
            } else {
                $v = $values[$cf->id] ?? null;
                $row['value'] = \is_array($v) ? ($v[0] ?? null) : $v;
            }
            $out[] = $row;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function children(Issue $issue): array
    {
        $out = [];
        foreach ($issue->children as $child) {
            if (!$this->issues->isVisible($child)) {
                continue;
            }
            $c = ['id' => $child->id, 'tracker' => ['id' => $child->tracker->id, 'name' => $child->tracker->name], 'subject' => $child->subject];
            if (!$child->isLeaf()) {
                $c['children'] = $this->children($child);
            }
            $out[] = $c;
        }

        return $out;
    }

    /** @return list<IssueRelation> */
    public function visibleRelations(Issue $issue): array
    {
        $rels = array_values(array_filter($issue->getRelations(), fn (IssueRelation $r) => $this->issues->isVisible($r->otherIssue($issue))));
        // IssueRelation#<=>: type order, then id
        usort($rels, static fn (IssueRelation $a, IssueRelation $b) => [IssueRelation::TYPES[$a->relationType][2] ?? 99, $a->id] <=> [IssueRelation::TYPES[$b->relationType][2] ?? 99, $b->id]);

        return $rels;
    }

    /** @return list<Journal> journals visible by the current user, with their index set */
    public function visibleJournals(Issue $issue): array
    {
        $canPrivate = $this->issues->canViewPrivateNotes($issue);
        $user = $this->auth->user();
        $out = [];
        $i = 0;
        $journals = $issue->journals->toArray();
        usort($journals, static fn (Journal $a, Journal $b) => [$a->createdOn, $a->id] <=> [$b->createdOn, $b->id]);
        foreach ($journals as $j) {
            ++$i;
            $j->indice = $i;
            if ($j->privateNotes && !$canPrivate && $j->user->id !== $user->id) {
                continue;
            }
            $out[] = $j;
        }

        return $out;
    }

    /** @return list<Changeset> */
    public function changesets(Issue $issue): array
    {
        $rows = $this->em->createQuery('SELECT c FROM App\Entity\Changeset c JOIN c.issues i WHERE i.id = :id ORDER BY c.committedOn ASC, c.id ASC')
            ->setParameter('id', $issue->id)->getResult();

        return array_values(array_filter($rows, fn (Changeset $c) => $this->auth->allowedTo('view_changesets', $c->repository->project)));
    }

    public function journal(Journal $j, bool $withReactions = false): array
    {
        $out = ['id' => $j->id];
        $out['user'] = ['id' => $j->user->id, 'name' => $j->user->getName()];
        $out['notes'] = $j->notes;
        $out['created_on'] = ApiRenderer::time($j->createdOn);
        $out['updated_on'] = ApiRenderer::time($j->updatedOn);
        if ($j->updatedBy) {
            $out['updated_by'] = ['id' => $j->updatedBy->id, 'name' => $j->updatedBy->getName()];
        }
        $out['private_notes'] = $j->privateNotes;
        if ($withReactions && null !== ($reactions = $this->reactions->apiReactions($j))) {
            $out['reactions'] = $reactions;
        }
        $out['details'] = array_map(static fn ($d) => ['property' => $d->property, 'name' => $d->propKey, 'old_value' => $d->oldValue, 'new_value' => $d->value], $this->helpers->visibleDetails($j));

        return $out;
    }

    /** @param list<int|null> $issueIds */
    private function visibleSpentHours(array $issueIds): float
    {
        $cond = $this->auth->allowedToCondition('view_time_entries', 'IDENTITY(t.project)', null, static function ($role, User $u) {
            if ('all' === $role->timeEntriesVisibility) {
                return null;
            }

            return 'own' === $role->timeEntriesVisibility && $u->id && $u->isLogged() ? 'IDENTITY(t.user) = '.(int) $u->id : '1=0';
        });

        return (float) $this->em->createQuery("SELECT COALESCE(SUM(t.hours), 0) FROM App\\Entity\\TimeEntry t WHERE IDENTITY(t.issue) IN (:ids) AND $cond")
            ->setParameter('ids', $issueIds)->getSingleScalarResult();
    }

    public function relation(IssueRelation $r): array
    {
        return ['id' => $r->id, 'issue_id' => $r->issueFrom->id, 'issue_to_id' => $r->issueTo->id, 'relation_type' => $r->relationType, 'delay' => $r->delay];
    }

    public function attachment(Attachment $a): array
    {
        $out = [
            'id' => $a->id,
            'filename' => $a->filename,
            'filesize' => (int) $a->filesize,
            'content_type' => $a->contentType,
            'description' => $a->description,
            'content_url' => $this->urls->generate('attachment_download', ['id' => $a->id, 'filename' => $a->filename], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
        if ($a->isImage()) {
            $out['thumbnail_url'] = $this->urls->generate('attachment_thumbnail', ['id' => $a->id], UrlGeneratorInterface::ABSOLUTE_URL);
        }
        $out['author'] = ['id' => $a->author->id, 'name' => $a->author->getName()];
        $out['created_on'] = ApiRenderer::time($a->createdOn);

        return $out;
    }
}
