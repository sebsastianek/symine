<?php

declare(strict_types=1);

namespace App\Mailer;

use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Document;
use App\Entity\Journal;
use App\Entity\Message;
use App\Entity\News;
use App\Entity\WikiContent;
use App\Entity\WikiPage;
use App\Event\ContentEvent;
use App\Event\IssueEvent;
use App\Import\ImportNotificationSuppressor;
use App\Service\Settings;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sends Redmine's e-mail notifications when issues and contents change, honouring Setting.notified_events
 * (Issue#send_notification, Journal#send_notification, News/Comment/Message/WikiContent/Document callbacks,
 * FilesController/DocumentsController "file_added"/"document_added").
 *
 * ContentEvent contract (see docs/ARCHITECTURE.md):
 *   news/created (News), news_comment/created (Comment), message/created (Message),
 *   wiki_page/created|updated (WikiPage or WikiContent; context 'previous_text' optional),
 *   document/created (Document), attachment/created (subject = Project|Version|Document container,
 *   context 'attachments' => list<Attachment>; or subject = a single Attachment with context 'container').
 *   user/created (User) with context ['account_information' => true, 'password' => ?string] sends the account information.
 *   Any event with context ['notify' => false] is not notified (Issue#notify = false).
 */
final class NotificationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly NotificationMailer $mailer,
        private readonly NotificationRecipients $recipients,
        private readonly Settings $settings,
        /** issues and operations for which notifications are disabled; request-scoped (reset by the kernel between requests) */
        private readonly ImportNotificationSuppressor $suppressor,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            IssueEvent::class => ['onIssue', -10],
            ContentEvent::class => ['onContent', -10],
        ];
    }

    /** Disables notifications for an issue during the current request (Issue#notify = false) */
    public function suppress(int $issueId): void
    {
        $this->suppressor->suppress($issueId);
    }

    public function unsuppress(int $issueId): void
    {
        $this->suppressor->unsuppress($issueId);
    }

    public function onIssue(IssueEvent $event): void
    {
        $issue = $event->issue;
        if (null === $issue->id || $this->isSuppressed((int) $issue->id)) {
            return;
        }
        $this->recipients->reset();
        $events = $this->notifiedEvents();
        try {
            if (IssueEvent::CREATED === $event->action) {
                // Issue#notify = false (issues created by an import with notifications disabled)
                if (!$this->suppressor->areCreationsSilent() && \in_array('issue_added', $events, true)) {
                    $this->mailer->deliverIssueAdd($issue);
                }
            } elseif (IssueEvent::UPDATED === $event->action && null !== $event->journal) {
                if ($this->journalNotifiable($event->journal, $events)) {
                    $this->mailer->deliverIssueEdit($event->journal);
                }
            }
        } catch (\Throwable $e) {
            // notifications must never break the request
            $this->logger?->error('Issue notification failed: '.$e->getMessage(), ['exception' => $e]);
        }
    }

    public function onContent(ContentEvent $event): void
    {
        // Mailer.with_deliveries(false) (e.g. project copy without notifications): nothing is sent meanwhile
        if (false === ($event->context['notify'] ?? true) || $this->suppressor->isActive()) {
            return;
        }
        $this->recipients->reset();
        $events = $this->notifiedEvents();
        $s = $event->subject;
        try {
            match (true) {
                'news' === $event->type && 'created' === $event->action && $s instanceof News
                    => \in_array('news_added', $events, true) && $this->mailer->deliverNewsAdded($s),
                'news_comment' === $event->type && 'created' === $event->action && $s instanceof Comment
                    => \in_array('news_comment_added', $events, true) && $this->mailer->deliverNewsCommentAdded($s),
                'message' === $event->type && 'created' === $event->action && $s instanceof Message
                    => \in_array('message_posted', $events, true) && $this->mailer->deliverMessagePosted($s),
                'wiki_page' === $event->type && \in_array($event->action, ['created', 'updated'], true)
                    => $this->wiki($event, $events),
                'document' === $event->type && 'created' === $event->action && $s instanceof Document
                    => \in_array('document_added', $events, true) && $this->mailer->deliverDocumentAdded($s, $event->user),
                'attachment' === $event->type && 'created' === $event->action
                    => $this->attachments($event, $events),
                // user created by an admin/import with "send account information" (Mailer.deliver_account_information)
                'user' === $event->type && 'created' === $event->action && $s instanceof \App\Entity\User && true === ($event->context['account_information'] ?? false)
                    => $this->mailer->deliverAccountInformation($s, isset($event->context['password']) ? (string) $event->context['password'] : null),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->logger?->error(\sprintf('%s notification failed: %s', $event->type, $e->getMessage()), ['exception' => $e]);
        }
    }

    /** @param list<string> $events */
    private function wiki(ContentEvent $event, array $events): void
    {
        $s = $event->subject;
        $page = $s instanceof WikiContent ? $s->page : ($s instanceof WikiPage ? $s : null);
        if (null === $page) {
            return;
        }
        // attachments added to a page (no new content version) are not notified
        if ('updated' === $event->action && !\array_key_exists('content', $event->context) && \array_key_exists('attachments', $event->context)) {
            return;
        }
        // WikiContent#send_notification_update: only when the text changed (rename, protect, comment-only edits are not notified)
        if ('updated' === $event->action && false === ($event->context['text_changed'] ?? true)) {
            return;
        }
        if ('created' === $event->action) {
            if (\in_array('wiki_content_added', $events, true)) {
                $this->mailer->deliverWikiContentAdded($page);
            }
        } elseif (\in_array('wiki_content_updated', $events, true)) {
            $this->mailer->deliverWikiContentUpdated($page, $event->context['previous_text'] ?? null);
        }
    }

    /** @param list<string> $events */
    private function attachments(ContentEvent $event, array $events): void
    {
        $s = $event->subject;
        $attachments = array_values(array_filter((array) ($event->context['attachments'] ?? []), static fn ($a) => $a instanceof Attachment));
        if ($s instanceof Attachment) {
            $attachments = [] !== $attachments ? $attachments : [$s];
            $container = $event->context['container'] ?? null;
        } else {
            $container = $s;
        }
        if ([] === $attachments || !\is_object($container)) {
            return;
        }
        $setting = $container instanceof Document ? 'document_added' : 'file_added';
        if (\in_array($setting, $events, true)) {
            $this->mailer->deliverAttachmentsAdded($attachments, $container);
        }
    }

    /**
     * Journal#send_notification conditions.
     *
     * @param list<string> $events
     */
    private function journalNotifiable(Journal $journal, array $events): bool
    {
        $has = static fn (string $e) => \in_array($e, $events, true);
        $detail = static function (string $attr) use ($journal) {
            foreach ($journal->details as $d) {
                if ('attr' === $d->property && $attr === $d->propKey) {
                    return $d;
                }
            }

            return null;
        };
        $notes = null !== $journal->notes && '' !== trim($journal->notes);
        $attachmentAdded = false;
        foreach ($journal->details as $d) {
            if ('attachment' === $d->property && null !== $d->value) {
                $attachmentAdded = true;
            }
        }

        return $has('issue_updated')
            || ($has('issue_note_added') && $notes)
            || ($has('issue_status_updated') && null !== ($d = $detail('status_id')) && null !== $d->value && '' !== $d->value)
            || ($has('issue_assigned_to_updated') && null !== $detail('assigned_to_id'))
            || ($has('issue_priority_updated') && null !== ($d = $detail('priority_id')) && null !== $d->value && '' !== $d->value)
            || ($has('issue_fixed_version_updated') && null !== $detail('fixed_version_id'))
            || ($has('issue_attachment_added') && $attachmentAdded);
    }

    /** @return list<string> */
    private function notifiedEvents(): array
    {
        return array_map('strval', $this->settings->array('notified_events'));
    }

    private function isSuppressed(int $issueId): bool
    {
        return $this->suppressor->isSuppressed($issueId);
    }
}
