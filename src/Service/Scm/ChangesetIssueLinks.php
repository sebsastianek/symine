<?php

declare(strict_types=1);

namespace App\Service\Scm;

use App\Api\ApiRenderer;
use App\Entity\Changeset;
use App\Entity\Issue;
use App\Entity\Repository;
use App\Service\IssueService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The issues related to a changeset by hand (repositories#add_related_issue, #remove_related_issue), besides
 * those its comment refers to.
 */
final class ChangesetIssueLinks
{
    public function __construct(
        private readonly RepositoryService $repos,
        private readonly IssueService $issues,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @throws ScmNotFound when the repository has no such changeset */
    public function changeset(Repository $repository, string $rev): Changeset
    {
        return $this->repos->findChangesetByName($repository, $rev) ?? throw new ScmNotFound();
    }

    /**
     * Relates the issue of params[:issue_id] ("#123" or "123") to the changeset.
     *
     * @return ?Issue the issue now related; null when there is no such issue, the user may not see it or it
     *                was related already
     */
    public function add(Changeset $changeset, Request $request): ?Issue
    {
        $issueId = ltrim(trim((string) ($request->request->get('issue_id') ?? ApiRenderer::payload($request)['issue_id'] ?? '')), '#');
        $issue = '' !== $issueId ? $this->repos->findReferencedIssue($changeset, $issueId) : null;
        if (null !== $issue && (!$this->issues->isVisible($issue) || $changeset->issues->contains($issue))) {
            $issue = null;
        }
        if (null !== $issue) {
            $changeset->issues->add($issue);
            $this->em->flush();
        }

        return $issue;
    }

    /** Nothing happens when there is no such issue or the user may not see it */
    public function remove(Changeset $changeset, int $issueId): void
    {
        $issue = $this->em->find(Issue::class, $issueId);
        if (null !== $issue && $this->issues->isVisible($issue)) {
            $changeset->issues->removeElement($issue);
            $this->em->flush();
        }
    }
}
