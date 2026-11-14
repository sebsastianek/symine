<?php

declare(strict_types=1);

namespace App\Service\Content\Attachments;

use App\Entity\Attachment;
use App\Entity\Issue;
use App\Entity\Journal;
use App\Entity\Project;
use App\Event\IssueEvent;
use App\Security\CurrentUser;
use App\Service\AttachmentService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Deletes an attachment; the removal of a file of an issue is recorded in the history of the issue.
 */
final class AttachmentRemover
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly AttachmentContainers $containers,
        private readonly CurrentUser $currentUser,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /** @return Project|null the project the attachment belonged to */
    public function remove(Attachment $a): ?Project
    {
        $project = $this->containers->projectOf($a);
        $container = $this->containers->of($a);
        $j = null;
        if ($container instanceof Issue) {
            $j = new Journal();
            $j->issue = $container;
            $j->user = $this->currentUser->get();
            $j->addDetail('attachment', (string) $a->id, $a->filename, null);
            $this->em->persist($j);
        }
        $this->attachments->delete($a);
        $this->em->flush();
        if ($container instanceof Issue && null !== $j) {
            // Issue#attachment_removed: the journal is notified (Journal#send_notification) and the
            // issue.updated webhook fires, like for any other update of the issue
            $this->events->dispatch(new IssueEvent($container, IssueEvent::UPDATED, $this->currentUser->get(), $j));
        }

        return $project;
    }
}
