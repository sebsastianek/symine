<?php

declare(strict_types=1);

namespace App\Service\Content\Attachments;

use App\Entity\Attachment;
use App\Entity\Issue;
use App\Entity\Journal;
use App\Security\AccessGuard;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\IssueService;

/**
 * Who may see, edit and delete an attachment (Attachment#visible?, #editable?, #deletable?): the permissions
 * of what it is attached to, or its author as long as it is attached to nothing.
 */
final class AttachmentAccess
{
    /** container_type => [view, edit, delete] permissions (acts_as_attachable options) */
    private const array PERMISSIONS = [
        'Issue' => ['view_issues', 'edit_issues', 'edit_issues'],
        'WikiPage' => ['view_wiki_pages', 'edit_wiki_pages', 'delete_wiki_pages_attachments'],
        'Document' => ['view_documents', 'edit_documents', 'delete_documents'],
        'Message' => ['view_messages', 'edit_messages', 'delete_messages'],
        'News' => ['view_news', 'manage_news', 'manage_news'],
        'Project' => ['view_files', 'manage_files', 'manage_files'],
        'Version' => ['view_files', 'manage_files', 'manage_files'],
        'Journal' => ['view_issues', 'edit_issues', 'edit_issues'],
    ];

    public function __construct(
        private readonly AttachmentContainers $containers,
        private readonly IssueService $issues,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly AccessGuard $guard,
    ) {
    }

    public function isVisible(Attachment $a): bool
    {
        $c = $this->containers->of($a);
        if (null === $c) {
            return $a->author->id === $this->currentUser->get()->id;
        }
        if ($c instanceof Issue && !$this->issues->isVisible($c)) {
            return false;
        }
        if ($c instanceof Journal && !$this->issues->isVisible($c->issue)) {
            return false;
        }
        $perm = self::PERMISSIONS[$a->containerType][0] ?? null;

        return null === $perm || $this->auth->allowedTo($perm, $this->containers->projectOf($a));
    }

    public function isEditable(Attachment $a): bool
    {
        $c = $this->containers->of($a);
        if (null === $c) {
            return $a->author->id === $this->currentUser->get()->id;
        }
        if ($c instanceof Issue) {
            return $this->issues->attachmentsDeletable($c);
        }

        return $this->isVisible($a) && $this->auth->allowedTo(self::PERMISSIONS[$a->containerType][1] ?? 'admin', $this->containers->projectOf($a));
    }

    public function isDeletable(Attachment $a): bool
    {
        $c = $this->containers->of($a);
        if (null === $c) {
            return $a->author->id === $this->currentUser->get()->id;
        }
        if ($c instanceof Issue) {
            return $this->issues->attachmentsDeletable($c);
        }

        return $this->isVisible($a) && $this->auth->allowedTo(self::PERMISSIONS[$a->containerType][2] ?? 'admin', $this->containers->projectOf($a));
    }

    /** 403 (or the login page for an anonymous user) unless the attachment is visible */
    public function requireVisible(Attachment $a): void
    {
        if (!$this->isVisible($a)) {
            $this->guard->deny();
        }
    }

    public function requireEditable(Attachment $a): void
    {
        if (!$this->isEditable($a)) {
            $this->guard->deny();
        }
    }

    public function requireDeletable(Attachment $a): void
    {
        if (!$this->isDeletable($a)) {
            $this->guard->deny();
        }
    }
}
