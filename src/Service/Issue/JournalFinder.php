<?php

declare(strict_types=1);

namespace App\Service\Issue;

use App\Entity\Journal;
use App\Entity\JournalDetail;
use App\Security\AccessGuard;
use App\Security\CurrentUser;
use App\Service\IssueService;
use App\Twig\IssuesExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads the issue journals the user may see (Journal.visible): 404 for a journal that does not exist, of an
 * issue the user cannot see, or whose notes are private and hidden from him.
 */
final class JournalFinder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurrentUser $currentUser,
        private readonly AccessGuard $guard,
        private readonly IssueService $issues,
        private readonly IssuesExtension $helpers,
    ) {
    }

    /** Journal.visible: the issue is visible and private notes are visible by the user */
    public function find(int $id): Journal
    {
        $journal = $this->em->find(Journal::class, $id);
        if (null === $journal || 'Issue' !== $journal->journalizedType) {
            throw new NotFoundHttpException();
        }
        $user = $this->currentUser->get();
        if (!$this->issues->isVisible($journal->issue, $user)) {
            throw new NotFoundHttpException();
        }
        if ($journal->privateNotes && !$this->issues->canViewPrivateNotes($journal->issue, $user) && $journal->user->id !== $user->id) {
            throw new NotFoundHttpException();
        }

        return $journal;
    }

    /**
     * The change of the journal whose diff is shown: the one asked for, else the change of the description
     * (404 when there is none, access denied for a custom field the user cannot see).
     */
    public function diffDetail(Journal $journal, mixed $detailId): JournalDetail
    {
        $detail = null;
        foreach ($journal->details as $d) {
            if (null !== $detailId && '' !== $detailId ? $d->id === (int) $detailId : ('attr' === $d->property && 'description' === $d->propKey)) {
                $detail = $d;
                break;
            }
        }
        if (null === $detail) {
            throw new NotFoundHttpException();
        }
        if ('cf' === $detail->property && !\in_array($detail, $this->helpers->visibleDetails($journal), true)) {
            $this->guard->deny();
        }

        return $detail;
    }
}
