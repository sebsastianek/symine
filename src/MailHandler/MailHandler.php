<?php

declare(strict_types=1);

namespace App\MailHandler;

use App\Entity\AnonymousUser;
use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\CustomField;
use App\Entity\Group;
use App\Entity\Issue;
use App\Entity\IssueCategory;
use App\Entity\IssuePriority;
use App\Entity\IssueStatus;
use App\Entity\Journal;
use App\Entity\Message;
use App\Entity\News;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\User;
use App\Event\ContentEvent;
use App\Event\IssueEvent;
use App\I18n\Localizer;
use App\Mailer\NotificationMailer;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\AccountService;
use App\Service\AttachmentService;
use App\Service\CustomFieldService;
use App\Service\ForumService;
use App\Service\IssueService;
use App\Service\MembershipService;
use App\Service\ProjectService;
use App\Service\Settings;
use App\Service\TimeEntryService;
use App\Service\WatcherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Port of Redmine's MailHandler (app/models/mail_handler.rb): creates issues from incoming e-mails,
 * adds notes to issues (replies identified by In-Reply-To/References or "[... #123]" in the subject),
 * replies to forum messages and news. Attributes can be set with keywords in the body
 * ("Project: foo", "Tracker: Bug", "Status: ...", custom field names...) when allowed by allow_override.
 *
 * Options (same as Redmine, see MailHandler.receive / rdm-mailhandler.rb):
 *   issue: {project, status, tracker, category, priority, assigned_to, fixed_version, is_private} defaults
 *   allow_override: "tracker,priority" | "all" (comma separated attribute names)
 *   unknown_user: ignore (default) | accept | create
 *   no_permission_check, no_account_notice, no_notification: "1"
 *   default_group: comma separated group names for created users
 *   project_from_subaddress: "redmine@example.net" (redmine+project@example.net selects the project)
 */
final class MailHandler implements EventSubscriberInterface
{
    public const string MESSAGE_ID_RE = '/^<?redmine\.([a-z0-9_]+)\-(\d+)\.\d+(\.[a-f0-9]+)?@/';
    public const string ISSUE_REPLY_SUBJECT_RE = '/\[(?:[^\]]*\s+)?#(\d+)\]/';
    public const string MESSAGE_REPLY_SUBJECT_RE = '/\[[^\]]*msg(\d+)\]/';

    /** Auto generated e-mails are ignored (MailHandler.ignored_emails_headers) */
    public const array IGNORED_HEADERS = ['Auto-Submitted' => '/\Aauto-(replied|generated)/', 'X-Autoreply' => 'yes'];

    private MimePart $email;
    private User $user;
    /** @var array<string, mixed> */
    private array $options = [];
    /** @var array<string, mixed> */
    private array $keywords = [];
    private ?string $body = null;
    private ?string $plainBody = null;
    /** @var array{0: int, 1: list<int>}|null issue object id => watchers to add before notifications */
    private ?array $pendingWatchers = null;
    /** Last processing log line (for the command/endpoint) */
    private ?string $lastError = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly Security $security,
        private readonly IssueService $issues,
        private readonly ProjectService $projects,
        private readonly CustomFieldService $customFields,
        private readonly AttachmentService $attachments,
        private readonly WatcherService $watchers,
        private readonly ForumService $forums,
        private readonly AccountService $accounts,
        private readonly MembershipService $memberships,
        private readonly NotificationMailer $mailer,
        private readonly EventDispatcherInterface $events,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // add To/Cc watchers of a new issue before notifications are sent (Redmine adds them before save)
        return [IssueEvent::class => ['onIssueCreated', 100]];
    }

    public function onIssueCreated(IssueEvent $event): void
    {
        if (null === $this->pendingWatchers || IssueEvent::CREATED !== $event->action || $this->pendingWatchers[0] !== spl_object_id($event->issue)) {
            return;
        }
        foreach ($this->pendingWatchers[1] as $uid) {
            if ($u = $this->em->find(User::class, $uid)) {
                $this->watchers->add('Issue', (int) $event->issue->id, $u);
            }
        }
        $this->pendingWatchers = null;
        $this->em->flush();
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * MailHandler.receive: processes a raw e-mail.
     *
     * @param array<string, mixed> $options
     *
     * @return Issue|Journal|Message|Comment|false the created object, or false when the e-mail was ignored/rejected
     */
    public function receive(string $raw, array $options = []): Issue|Journal|Message|Comment|false
    {
        $this->lastError = null;
        $options['issue'] = \is_array($options['issue'] ?? null) ? $options['issue'] : [];
        $override = $options['allow_override'] ?? [];
        if (\is_string($override)) {
            $override = explode(',', $override);
        }
        $override = array_values(array_filter(array_map(static fn ($s) => preg_replace('/\s+/', '_', strtolower(trim((string) $s))) ?? '', (array) $override)));
        // project needs to be overridable if not specified
        if (!\array_key_exists('project', $options['issue'])) {
            $override[] = 'project';
        }
        $options['allow_override'] = $override;
        foreach (['no_account_notice', 'no_notification', 'no_permission_check'] as $k) {
            $options[$k] = '1' === (string) ($options[$k] ?? '');
        }

        $this->email = MimePart::parse($raw);
        $this->options = $options;
        $this->keywords = [];
        $this->body = null;
        $this->plainBody = null;

        $prevUser = $this->currentUser->get();
        try {
            return $this->doReceive();
        } finally {
            $this->currentUser->set($prevUser === $this->security->getUser() || $prevUser instanceof AnonymousUser ? null : $prevUser);
            $this->auth->reset();
        }
    }

    /** MailHandler.safe_receive: never throws */
    public function safeReceive(string $raw, array $options = []): Issue|Journal|Message|Comment|false
    {
        try {
            return $this->receive($raw, $options);
        } catch (\Throwable $e) {
            $this->error('an unexpected error occurred when receiving email: '.$e->getMessage());
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->getConnection()->rollBack();
            }

            return false;
        }
    }

    private function doReceive(): Issue|Journal|Message|Comment|false
    {
        $from = $this->email->addresses('from')[0] ?? null;
        $sender = trim((string) ($from['address'] ?? ''));
        $emission = trim((string) preg_replace('/(?:.*<|>.*|\(.*\))/', '', $this->settings->string('mail_from')));
        if ('' !== $sender && 0 === strcasecmp($sender, $emission)) {
            $this->info("ignoring email from Redmine emission address [$sender]");

            return false;
        }
        foreach (self::IGNORED_HEADERS as $key => $ignored) {
            $value = $this->email->header($key);
            if (null !== $value) {
                $value = strtolower(trim($value));
                if (str_starts_with($ignored, '/') ? 1 === preg_match($ignored, $value) : $value === $ignored) {
                    $this->info("ignoring email with $key:$value header");

                    return false;
                }
            }
        }
        $user = '' !== $sender ? $this->accounts->findByMail($sender) : null;
        if (null !== $user && !$user->isActive()) {
            $this->info("ignoring email from non-active user [{$user->login}]");

            return false;
        }
        if (null === $user) {
            switch ($this->options['unknown_user'] ?? null) {
                case 'accept':
                    $user = $this->currentUser->anonymous();
                    break;
                case 'create':
                    [$user, $password] = $this->createUserFromEmail($from);
                    if (null === $user) {
                        $this->error("could not create account for [$sender]");

                        return false;
                    }
                    $this->info("[{$user->login}] account created");
                    $this->addUserToGroups((string) ($this->options['default_group'] ?? ''), $user);
                    if (!$this->options['no_account_notice']) {
                        $this->mailer->deliverAccountInformation($user, $password);
                    }
                    break;
                default:
                    $this->info("ignoring email from unknown user [$sender]");

                    return false;
            }
        }
        $this->user = $user;
        $this->currentUser->set($user);
        $this->auth->reset();

        return $this->dispatch();
    }

    private function dispatch(): Issue|Journal|Message|Comment|false
    {
        $subject = (string) $this->email->header('subject');
        try {
            foreach ($this->email->referencedIds() as $id) {
                if (preg_match(self::MESSAGE_ID_RE, $id, $m)) {
                    return match ($m[1]) {
                        'issue' => $this->receiveIssueReply((int) $m[2]),
                        'journal' => $this->receiveJournalReply((int) $m[2]),
                        'message' => $this->receiveMessageReply((int) $m[2]),
                        'news' => $this->receiveNewsReply((int) $m[2]),
                        'comment' => $this->receiveCommentReply((int) $m[2]),
                        default => false, // ignoring it
                    };
                }
            }
            if (preg_match(self::ISSUE_REPLY_SUBJECT_RE, $subject, $m)) {
                return $this->receiveIssueReply((int) $m[1]);
            }
            if (preg_match(self::MESSAGE_REPLY_SUBJECT_RE, $subject, $m)) {
                return $this->receiveMessageReply((int) $m[1]);
            }

            return $this->receiveIssue();
        } catch (MailHandlerException $e) {
            $this->error(match ($e->kind) {
                MailHandlerException::MISSING_INFORMATION => "missing information from {$this->user}: ",
                MailHandlerException::MISSING_CONTAINER => "reply to nonexistent object from {$this->user}: ",
                MailHandlerException::INVALID => '',
                default => "unauthorized attempt from {$this->user}: ",
            }.$e->getMessage());

            return false;
        }
    }

    // ------------------------------------------------------------------------------------------
    // Receivers
    // ------------------------------------------------------------------------------------------

    /** Creates a new issue */
    private function receiveIssue(): Issue
    {
        $project = $this->targetProject();
        if (!$this->auth->projectAllowsTo($project, 'add_issues')) {
            throw new MailHandlerException("not possible to add issues to project [{$project->name}]", MailHandlerException::NOT_ALLOWED_IN_PROJECT);
        }
        $noPermissionCheck = $this->options['no_permission_check'];
        if (!$noPermissionCheck && !$this->auth->allowedTo('add_issues', $project, $this->user)) {
            throw new MailHandlerException("not allowed to add issues to project [{$project->name}]", MailHandlerException::INSUFFICIENT_PERMISSIONS);
        }
        $issue = $this->issues->newIssue($project, $this->user);
        $issue->author = $this->user;
        $attributes = $this->issueAttributesFromKeywords($issue);
        if ($noPermissionCheck) {
            $tracker = isset($attributes['tracker_id']) ? $this->em->find(\App\Entity\Tracker::class, (int) $attributes['tracker_id']) : null;
            $tracker ??= $issue->tracker ?? ($project->trackers->first() ?: null);
            if (null !== $tracker) {
                $issue->tracker = $tracker;
                if ($tracker->defaultStatus) {
                    $issue->status = $tracker->defaultStatus;
                }
            }
        }
        $this->issues->setSafeAttributes($issue, $attributes, $this->user);
        $cf = $this->customFieldValuesFromKeywords($issue);
        if ([] !== $cf) {
            $this->issues->setSafeAttributes($issue, ['custom_field_values' => $cf], $this->user);
        }
        $subject = $this->cleanedUpSubject();
        $issue->subject = '' === trim($subject) ? '('.$this->i18n->l('text_no_subject', [], $this->settings->string('default_language') ?: 'en').')' : $subject;
        $issue->description = str_replace("\n", "\r\n", $this->cleanedUpTextBody());
        if (null === $issue->startDate && $this->settings->bool('default_issue_start_date_to_creation_date')) {
            $issue->startDate = $this->i18n->today($this->user);
        }
        // Setting.default_issue_due_date_offset_in_days: blank means no default due date
        $offset = trim($this->settings->string('default_issue_due_date_offset'));
        if ('' !== $offset && preg_match('/\A[+-]?\d+\z/', $offset) && null === $issue->dueDate) {
            $issue->dueDate = $this->i18n->today($this->user)->modify(((int) $offset >= 0 ? '+' : '').(int) $offset.' days');
        }
        if ('1' === (string) ($this->options['issue']['is_private'] ?? '')) {
            $issue->isPrivate = true;
        }
        // To and Cc become watchers so they can reply (added right before notifications)
        $this->pendingWatchers = [spl_object_id($issue), array_map(static fn (User $u) => (int) $u->id, $this->watcherCandidates($project, 'Issue', null))];
        $errors = $this->issues->save($issue, $this->user);
        if ([] !== $errors) {
            $this->pendingWatchers = null;
            throw new MailHandlerException('Validation failed: '.implode(', ', $errors), MailHandlerException::INVALID);
        }
        // receive_issue: issue.save! (and its notification), then add_attachments(issue)
        $attachments = $this->buildAttachments();
        if ([] !== $attachments) {
            $this->attachments->attachTo($attachments, 'Issue', (int) $issue->id);
            $this->em->flush();
        }
        $this->info("issue #{$issue->id} created by {$this->user}");

        return $issue;
    }

    /** Adds a note to an existing issue */
    private function receiveIssueReply(int $issueId, ?Journal $fromJournal = null): Journal|false
    {
        $issue = $this->em->find(Issue::class, $issueId);
        if (null === $issue) {
            throw new MailHandlerException("reply to nonexistent issue [#$issueId]", MailHandlerException::MISSING_CONTAINER);
        }
        $project = $issue->project;
        if (!$this->auth->projectAllowsTo($project, 'add_issue_notes')) {
            throw new MailHandlerException("not possible to add notes to project [{$project->name}]", MailHandlerException::NOT_ALLOWED_IN_PROJECT);
        }
        if (!$this->options['no_permission_check'] && !($this->issues->isVisible($issue, $this->user) && $this->issues->isNotesAddable($issue, $this->user))) {
            throw new MailHandlerException("not allowed to add notes on issues to project [{$project->name}]", MailHandlerException::INSUFFICIENT_PERMISSIONS);
        }
        // ignore CLI-supplied defaults for new issues
        $this->options['issue'] = [];
        $this->issues->setSafeAttributes($issue, $this->issueAttributesFromKeywords($issue), $this->user);
        $cf = $this->customFieldValuesFromKeywords($issue);
        if ([] !== $cf) {
            $this->issues->setSafeAttributes($issue, ['custom_field_values' => $cf], $this->user);
        }
        $this->issues->setNotes($issue, str_replace("\n", "\r\n", $this->cleanedUpTextBody()), null !== $fromJournal && $fromJournal->privateNotes);
        foreach ($this->watcherCandidates($project, 'Issue', $issue->id) as $u) {
            $this->watchers->add('Issue', (int) $issue->id, $u);
        }
        $before = $issue->journals->count();
        $errors = $this->issues->save($issue, $this->user);
        if ([] !== $errors) {
            $this->em->refresh($issue);
            throw new MailHandlerException('Validation failed: '.implode(', ', $errors), MailHandlerException::INVALID);
        }
        $journal = $issue->journals->count() > $before ? $issue->journals->last() : null;
        // receive_issue_reply: issue.save! (the journal is created and notified), then add_attachments(issue):
        // the files are journalized in the journal of the reply, without another notification. When the reply
        // is nothing but attachments, they create the journal (and its notification).
        $attachments = $this->buildAttachments();
        if ([] !== $attachments) {
            if ($journal instanceof Journal) {
                $this->attachments->attachTo($attachments, 'Issue', (int) $issue->id);
                foreach ($attachments as $a) {
                    $this->em->persist($journal->addDetail('attachment', (string) $a->id, null, $a->filename));
                }
                $this->em->flush();
            } else {
                $this->issues->addAttachments($issue, $attachments);
                $errors = $this->issues->save($issue, $this->user);
                if ([] !== $errors) {
                    $this->discard($attachments);
                    $this->em->refresh($issue);
                    throw new MailHandlerException('Validation failed: '.implode(', ', $errors), MailHandlerException::INVALID);
                }
                $journal = $issue->journals->count() > $before ? $issue->journals->last() : null;
            }
        }
        $this->info("issue #{$issue->id} updated by {$this->user}");
        if (!$journal instanceof Journal) {
            // receive_issue_reply returns the journal even when it was not saved (empty note and no change):
            // the e-mail is processed, not rejected
            $journal = new Journal();
            $journal->issue = $issue;
            $journal->user = $this->user;
        }

        return $journal;
    }

    private function receiveJournalReply(int $journalId): Journal|false
    {
        $journal = $this->em->find(Journal::class, $journalId);
        if (null !== $journal && 'Issue' === $journal->journalizedType) {
            return $this->receiveIssueReply((int) $journal->issue->id, $journal);
        }
        if (preg_match(self::ISSUE_REPLY_SUBJECT_RE, (string) $this->email->header('subject'), $m)) {
            $this->info('reply to a nonexistent journal, calling receive_issue_reply with issue from subject');

            return $this->receiveIssueReply((int) $m[1]);
        }
        throw new MailHandlerException("reply to nonexistent journal [$journalId]", MailHandlerException::MISSING_CONTAINER);
    }

    /** Receives a reply to a forum message */
    private function receiveMessageReply(int $messageId): Message
    {
        $message = $this->em->find(Message::class, $messageId);
        $message = null === $message ? null : $this->forums->root($message);
        if (null === $message) {
            throw new MailHandlerException("reply to nonexistent message [$messageId]", MailHandlerException::MISSING_CONTAINER);
        }
        $project = $message->board->project;
        if (!$this->auth->projectAllowsTo($project, 'add_messages')) {
            throw new MailHandlerException("not possible to add messages to project [{$project->name}]", MailHandlerException::NOT_ALLOWED_IN_PROJECT);
        }
        if (!$this->options['no_permission_check'] && !$this->auth->allowedTo('add_messages', $project, $this->user)) {
            throw new MailHandlerException("not allowed to add messages to project [{$project->name}]", MailHandlerException::INSUFFICIENT_PERMISSIONS);
        }
        if (true === $message->locked) {
            throw new MailHandlerException("ignoring reply to a locked message [{$message->id} {$message->subject}]", MailHandlerException::LOCKED_TOPIC);
        }
        $reply = new Message();
        $reply->subject = trim((string) preg_replace('/^.*msg\d+\]/', '', $this->cleanedUpSubject()));
        $reply->content = str_replace("\n", "\r\n", $this->cleanedUpTextBody());
        $reply->author = $this->user;
        $reply->board = $message->board;
        $reply->parent = $message;
        if ('' === $reply->subject || '' === trim((string) $reply->content)) {
            // "message.children << reply" does not raise when the reply is invalid: the unsaved reply is
            // returned and the e-mail counts as processed
            $this->info("reply to message [{$message->id}] not saved: subject and content cannot be blank");

            return $reply;
        }
        $this->forums->createMessage($reply);
        $attachments = $this->buildAttachments();
        if ([] !== $attachments) {
            $this->attachments->attachTo($attachments, 'Message', (int) $reply->id);
            $this->em->flush();
        }
        $this->events->dispatch(new ContentEvent('message', 'created', $reply, $this->user));

        return $reply;
    }

    /** Receives a reply to a news entry */
    private function receiveNewsReply(int $newsId): Comment
    {
        $news = $this->em->find(News::class, $newsId);
        if (null === $news || null === $news->project) {
            throw new MailHandlerException("reply to nonexistent news [$newsId]", MailHandlerException::MISSING_CONTAINER);
        }
        $project = $news->project;
        if (!$this->auth->projectAllowsTo($project, 'comment_news')) {
            throw new MailHandlerException("not possible to add news comments to project [{$project->name}]", MailHandlerException::NOT_ALLOWED_IN_PROJECT);
        }
        if (!$this->options['no_permission_check'] && !$this->auth->allowedTo('comment_news', $project, $this->user)) {
            throw new MailHandlerException("not allowed to comment on news item [{$news->id} {$news->title}]", MailHandlerException::INSUFFICIENT_PERMISSIONS);
        }
        $content = str_replace("\n", "\r\n", $this->cleanedUpTextBody());
        if ('' === trim($content)) {
            throw new MailHandlerException("Validation failed: Comment can't be blank", MailHandlerException::INVALID);
        }
        $comment = new Comment();
        $comment->commentedType = 'News';
        $comment->commentedId = (int) $news->id;
        $comment->author = $this->user;
        $comment->content = $content;
        $this->em->persist($comment);
        ++$news->commentsCount;
        $this->em->flush();
        $this->events->dispatch(new ContentEvent('news_comment', 'created', $comment, $this->user, ['news' => $news]));

        return $comment;
    }

    /** Receives a reply to a comment to a news entry */
    private function receiveCommentReply(int $commentId): Comment
    {
        $comment = $this->em->find(Comment::class, $commentId);
        if (null !== $comment && 'News' === $comment->commentedType) {
            return $this->receiveNewsReply($comment->commentedId);
        }
        throw new MailHandlerException("reply to nonexistent comment [$commentId]", MailHandlerException::MISSING_CONTAINER);
    }

    // ------------------------------------------------------------------------------------------
    // Attachments & watchers
    // ------------------------------------------------------------------------------------------

    /** @return list<Attachment> persisted, container-less attachments built from the e-mail */
    private function buildAttachments(): array
    {
        $out = [];
        $author = $this->user;
        foreach ($this->email->attachments() as $part) {
            $filename = (string) $part->filename();
            if (!$this->acceptAttachment($filename)) {
                continue;
            }
            if ('' === trim($filename)) {
                // an attachment without a file name cannot be saved (Attachment validates the presence of the filename)
                continue;
            }
            $content = $part->decodedBody();
            if ('' === $content) {
                continue;
            }
            if (\strlen($content) > $this->attachments->maxSize() || !$this->attachments->isExtensionAllowed($filename)) {
                $this->info("ignoring attachment $filename (size or extension not allowed)");
                continue;
            }
            $out[] = $this->attachments->createFromContent($content, $filename, $author, $part->mimeType());
        }
        if ([] !== $out) {
            $this->em->flush();
        }

        return $out;
    }

    /** @param list<Attachment> $attachments */
    private function discard(array $attachments): void
    {
        foreach ($attachments as $a) {
            $this->attachments->delete($a);
        }
        $this->em->flush();
    }

    /** MailHandler#accept_attachment? (Setting.mail_handler_excluded_filenames) */
    public function acceptAttachment(string $filename): bool
    {
        $patterns = array_values(array_filter(array_map('trim', explode(',', $this->settings->string('mail_handler_excluded_filenames')))));
        foreach ($patterns as $pattern) {
            $regexp = $this->settings->bool('mail_handler_enable_regex_excluded_filenames')
                ? '/\A'.str_replace('/', '\/', $pattern).'\z/i'
                : '/\A'.str_replace('\*', '.*', preg_quote($pattern, '/')).'\z/i';
            if (@preg_match($regexp, $filename)) {
                $this->info("ignoring attachment $filename matching $pattern");

                return false;
            }
        }

        return true;
    }

    /**
     * Active users having one of the To/Cc addresses, if the sender can add watchers (MailHandler#add_watchers).
     *
     * @return list<User>
     */
    private function watcherCandidates(Project $project, string $type, ?int $id): array
    {
        if (!$this->options['no_permission_check'] && !$this->auth->allowedTo('add_issue_watchers', $project, $this->user)) {
            return [];
        }
        $addresses = [];
        foreach (['to', 'cc'] as $h) {
            foreach ($this->email->addresses($h) as $a) {
                $addresses[] = strtolower(trim($a['address']));
            }
        }
        $addresses = array_values(array_unique($addresses));
        if ([] === $addresses) {
            return [];
        }
        $users = $this->em->createQuery('SELECT DISTINCT u FROM App\Entity\User u JOIN u.emailAddresses e WHERE LOWER(e.address) IN (:a) AND u.status = :s')
            ->setParameter('a', $addresses)->setParameter('s', Principal::STATUS_ACTIVE)->getResult();
        if (null !== $id) {
            $current = array_map(static fn ($p) => $p->id, $this->watchers->watchers($type, $id));
            $users = array_filter($users, static fn (User $u) => !\in_array($u->id, $current, true));
        }

        return array_values(array_filter($users, static fn ($u) => !$u instanceof AnonymousUser));
    }

    // ------------------------------------------------------------------------------------------
    // Keywords
    // ------------------------------------------------------------------------------------------

    /** MailHandler#get_keyword */
    private function getKeyword(string $attr, ?string $format = null, bool $symbol = true): ?string
    {
        if (\array_key_exists($attr, $this->keywords)) {
            return $this->keywords[$attr];
        }
        $key = preg_replace('/\s+/', '_', strtolower($attr)) ?? $attr;
        $override = [] !== array_intersect($this->options['allow_override'], [$key, 'all']);
        $value = null;
        if ($override && null !== ($v = $this->extractKeyword($attr, $format, $symbol))) {
            $value = $v;
        } elseif (isset($this->options['issue'][$attr]) && '' !== (string) $this->options['issue'][$attr]) {
            $value = (string) $this->options['issue'][$attr];
        }

        return $this->keywords[$attr] = $value;
    }

    /** MailHandler#extract_keyword!: destructively extracts "Keyword: value" from the body */
    private function extractKeyword(string $attr, ?string $format, bool $symbol): ?string
    {
        $keys = [self::humanize($attr)];
        if ($symbol) {
            foreach ([$this->user->language ?? null, $this->settings->string('default_language')] as $lang) {
                if (null !== $lang && '' !== $lang && $this->i18n->has('field_'.$attr, $lang)) {
                    $keys[] = $this->i18n->l('field_'.$attr, [], $lang);
                }
            }
        }
        $keys = array_values(array_unique(array_filter($keys, static fn ($k) => '' !== trim((string) $k))));
        $keys = array_map(static fn ($k) => preg_quote((string) $k, '/'), $keys);
        $format ??= '.+';
        $regexp = '/^('.implode('|', $keys).')[ \t]*:[ \t]*('.$format.')\s*$/miu';
        $text = $this->cleanedUpTextBody();
        if (preg_match($regexp, $text, $m)) {
            $this->body = (string) preg_replace($regexp, '', $text, 1);

            return trim($m[2]);
        }

        return null;
    }

    /** ActiveSupport humanize */
    private static function humanize(string $s): string
    {
        $s = preg_replace('/_id$/', '', $s) ?? $s;
        $s = mb_strtolower(str_replace('_', ' ', $s));

        return mb_strtoupper(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }

    /** @return array<string, mixed> MailHandler#issue_attributes_from_keywords */
    private function issueAttributesFromKeywords(Issue $issue): array
    {
        $project = $issue->project;
        $named = static function (iterable $items, string $name, string $prop = 'name') {
            foreach ($items as $o) {
                if (0 === strcasecmp(trim((string) $o->{$prop}), trim($name))) {
                    return $o;
                }
            }

            return null;
        };
        $attrs = [];
        if (null !== ($k = $this->getKeyword('tracker'))) {
            $attrs['tracker_id'] = $named($project->trackers, $k)?->id;
        }
        if (null !== ($k = $this->getKeyword('status'))) {
            $attrs['status_id'] = $named($this->em->getRepository(IssueStatus::class)->findAll(), $k)?->id;
        }
        if (null !== ($k = $this->getKeyword('priority'))) {
            $attrs['priority_id'] = $named($this->em->getRepository(IssuePriority::class)->findBy([], ['position' => 'ASC']), $k)?->id;
        }
        if (null !== ($k = $this->getKeyword('category'))) {
            $attrs['category_id'] = $named($this->em->getRepository(IssueCategory::class)->findBy(['project' => $project]), $k)?->id;
        }
        if (null !== ($k = $this->getKeyword('assigned_to'))) {
            $attrs['assigned_to_id'] = self::detectByKeyword($this->issues->assignableUsers($issue), $k)?->id;
        }
        if (null !== ($k = $this->getKeyword('fixed_version'))) {
            $attrs['fixed_version_id'] = $named($this->projects->sharedVersions($project), $k)?->id;
        }
        $attrs['start_date'] = $this->getKeyword('start_date', '\d{4}-\d{2}-\d{2}');
        $attrs['due_date'] = $this->getKeyword('due_date', '\d{4}-\d{2}-\d{2}');
        $attrs['estimated_hours'] = $this->getKeyword('estimated_hours');
        // Issue#estimated_hours=: String#to_hours ("2h30", "1:30", "90m"...)
        if (null !== $attrs['estimated_hours'] && null !== ($hours = TimeEntryService::toHours($attrs['estimated_hours']))) {
            $attrs['estimated_hours'] = (string) $hours;
        }
        $attrs['done_ratio'] = $this->getKeyword('done_ratio', '(\d|10)?0');
        $private = $this->getKeywordBool('is_private');
        $attrs['is_private'] = null === $private ? null : ($private ? '1' : '0');
        $attrs['parent_issue_id'] = $this->getKeyword('parent_issue');

        return array_filter($attrs, static fn ($v) => null !== $v && '' !== $v);
    }

    private function getKeywordBool(string $attr): ?bool
    {
        $true = ['1'];
        $false = ['0'];
        foreach (array_unique(array_filter([$this->settings->string('default_language'), $this->user->language ?? null])) as $locale) {
            foreach (['general_text_yes', 'general_text_Yes'] as $key) {
                if ($this->i18n->has($key, $locale)) {
                    $true[] = $this->i18n->l($key, [], $locale);
                }
            }
            foreach (['general_text_no', 'general_text_No'] as $key) {
                if ($this->i18n->has($key, $locale)) {
                    $false[] = $this->i18n->l($key, [], $locale);
                }
            }
        }
        $values = array_values(array_filter(array_merge($true, $false), static fn ($v) => '' !== $v));
        $format = implode('|', array_map(static fn ($v) => preg_quote($v, '/'), $values));
        $value = $this->getKeyword($attr, $format);
        if (null === $value) {
            return null;
        }
        if (\in_array($value, $true, true)) {
            return true;
        }

        return \in_array($value, $false, true) ? false : null;
    }

    /** @return array<string, mixed> MailHandler#custom_field_values_from_keywords */
    private function customFieldValuesFromKeywords(Issue $issue): array
    {
        $out = [];
        foreach ($this->customFields->availableIssueFields($issue) as $cf) {
            $keyword = $this->getKeyword($cf->name, null, false);
            if (null !== $keyword) {
                $out[(string) $cf->id] = $this->valueFromKeyword($cf, $keyword, $issue);
            }
        }

        return $out;
    }

    /** CustomField#value_from_keyword */
    private function valueFromKeyword(CustomField $cf, string $keyword, Issue $issue): mixed
    {
        $find = function (string $token) use ($cf, $issue): ?string {
            return match ($cf->fieldFormat) {
                'list' => (function () use ($cf, $token) {
                    foreach ($cf->getPossibleValues() as $v) {
                        if (0 === strcasecmp((string) $v, $token)) {
                            return (string) $v;
                        }
                    }

                    return null;
                })(),
                'bool' => match (true) {
                    \in_array(strtolower($token), ['1', strtolower($this->i18n->l('general_text_yes')), strtolower($this->i18n->l('general_text_Yes'))], true) => '1',
                    \in_array(strtolower($token), ['0', strtolower($this->i18n->l('general_text_no')), strtolower($this->i18n->l('general_text_No'))], true) => '0',
                    default => null,
                },
                'enumeration' => (function () use ($cf, $token) {
                    foreach ($cf->enumerations as $e) {
                        if (0 === strcasecmp($e->name, $token)) {
                            return (string) $e->id;
                        }
                    }

                    return null;
                })(),
                'user' => (($u = self::detectByKeyword($this->projects->assignableUsers($issue->project), $token)) ? (string) $u->id : null),
                'version' => (function () use ($issue, $token) {
                    foreach ($this->projects->sharedVersions($issue->project) as $v) {
                        if (0 === strcasecmp((string) $v->name, $token)) {
                            return (string) $v->id;
                        }
                    }

                    return null;
                })(),
                default => $token,
            };
        };
        if (!$cf->isMultiple()) {
            return $find(trim($keyword)) ?? '';
        }
        // FieldFormat#parse_keyword: the longest comma separated prefixes are tried first, so that
        // values containing commas are found
        $values = [];
        while ('' !== $keyword) {
            $k = $keyword;
            while (true) {
                if (null !== ($v = $find(trim($k)))) {
                    $values[] = $v;
                    break;
                }
                $pos = strrpos($k, ',');
                if (false === $pos) {
                    break;
                }
                $k = substr($k, 0, $pos);
            }
            $keyword = substr($keyword, \strlen($k));
            if (str_starts_with($keyword, ',')) {
                $keyword = substr($keyword, 1);
            }
        }

        return $values;
    }

    /**
     * Principal.detect_by_keyword.
     *
     * @param list<Principal> $principals
     */
    public static function detectByKeyword(array $principals, string $keyword): ?Principal
    {
        $keyword = trim($keyword);
        if ('' === $keyword) {
            return null;
        }
        foreach ($principals as $p) {
            if (0 === strcasecmp($keyword, $p->login)) {
                return $p;
            }
        }
        foreach ($principals as $p) {
            if ($p instanceof User && 0 === strcasecmp($keyword, (string) $p->getMail())) {
                return $p;
            }
        }
        if (str_contains($keyword, ' ')) {
            [$first, $last] = array_pad(preg_split('/\s+/', $keyword) ?: [], 2, '');
            foreach ($principals as $p) {
                if ($p instanceof User && 0 === strcasecmp($first, $p->firstname) && 0 === strcasecmp($last, $p->lastname)) {
                    return $p;
                }
            }
        }
        foreach ($principals as $p) {
            if (0 === strcasecmp($keyword, $p->getName())) {
                return $p;
            }
        }

        return null;
    }

    private function targetProject(): Project
    {
        $repo = $this->em->getRepository(Project::class);
        $target = $this->projectFromReceiverAddresses();
        if (null === $target && null !== ($k = $this->getKeyword('project'))) {
            $target = $repo->findOneBy(['identifier' => $k]);
        }
        if (null === $target && '' !== (string) ($this->options['issue']['project'] ?? '')) {
            $target = $repo->findOneBy(['identifier' => (string) $this->options['issue']['project']]);
        }
        if (null === $target) {
            throw new MailHandlerException('Unable to determine target project', MailHandlerException::MISSING_INFORMATION);
        }

        return $target;
    }

    private function projectFromReceiverAddresses(): ?Project
    {
        $parts = explode('@', (string) ($this->options['project_from_subaddress'] ?? ''), 2);
        if (2 !== \count($parts) || '' === $parts[0] || '' === $parts[1]) {
            return null;
        }
        [$local, $domain] = $parts;
        foreach (['to', 'cc', 'bcc'] as $h) {
            foreach ($this->email->addresses($h) as $a) {
                [$l, $d] = array_pad(explode('@', $a['address'], 2), 2, '');
                if (0 === strcasecmp($d, $domain) && preg_match('/\A'.preg_quote($local, '/').'\+([^+]+)\z/', $l, $m)) {
                    if ($p = $this->em->getRepository(Project::class)->findOneBy(['identifier' => $m[1]])) {
                        return $p;
                    }
                }
            }
        }

        return null;
    }

    // ------------------------------------------------------------------------------------------
    // Body
    // ------------------------------------------------------------------------------------------

    /** MailHandler#plain_text_body */
    public function plainTextBody(): string
    {
        if (null !== $this->plainBody) {
            return $this->plainBody;
        }
        $order = 'html' === $this->settings->string('mail_handler_preferred_body_part') ? ['text/html', 'text/plain'] : ['text/plain', 'text/html'];
        $all = $this->email->allParts();
        foreach ($order as $type) {
            $text = $this->partsToText(array_values(array_filter($all, static fn (MimePart $p) => $p->mimeType() === $type)));
            if ('' !== trim($text)) {
                return $this->plainBody = $text;
            }
        }
        if ([] === $all) {
            $text = $this->partsToText([$this->email]);
            if ('' !== trim($text)) {
                return $this->plainBody = $text;
            }
        }

        return $this->plainBody = '';
    }

    /** @param list<MimePart> $parts */
    private function partsToText(array $parts): string
    {
        $out = [];
        foreach ($parts as $p) {
            // email_parts_to_text: parts.reject!(&:attachment?), also for a message that is itself a named file
            if ($p->isAttachment()) {
                continue;
            }
            $body = str_replace(["\r\n", "\r"], "\n", $p->text());
            $out[] = 'text/html' === $p->mimeType()
                ? HtmlToText::convert($body, $this->settings->textFormatting())
                : (preg_replace('/^ +(?![*#])/m', '', $body) ?? $body);
        }

        return implode("\n", $out);
    }

    public function cleanedUpTextBody(): string
    {
        return $this->body ??= $this->cleanupBody($this->plainTextBody());
    }

    private function cleanedUpSubject(): string
    {
        return mb_substr(trim((string) $this->email->header('subject')), 0, 255);
    }

    /** MailHandler#cleanup_body: truncates the body at the first delimiter (Setting.mail_handler_body_delimiters) */
    public function cleanupBody(string $body): string
    {
        $delimiters = array_values(array_filter(preg_split('/[\r\n]+/', $this->settings->string('mail_handler_body_delimiters')) ?: [], static fn ($s) => '' !== trim($s)));
        if ($this->settings->bool('mail_handler_enable_regex_delimiters')) {
            $parts = [];
            foreach ($delimiters as $d) {
                if (false === @preg_match('/'.str_replace('/', '\/', $d).'/u', '')) {
                    $this->error('invalid regexp delimiter found in mail_handler_body_delimiters setting');
                    $parts = array_map(static fn ($x) => preg_quote($x, '/'), $delimiters);
                    break;
                }
                $parts[] = str_replace('/', '\/', $d);
            }
        } else {
            $parts = array_map(static fn ($d) => preg_replace('/(\\\\ )+|( )+/', '\p{Zs}*(\s|[\r\n](\s|>)*)', preg_quote($d, '/')) ?? $d, $delimiters);
        }
        if ([] !== $parts) {
            $regex = '/^(\s|>)*(?:'.implode('|', array_map(static fn ($p) => '(?:'.$p.')', $parts)).')\s*[\r\n].*/msu';
            $body = preg_replace($regex, '', $body."\n") ?? $body;
        }

        return trim($body);
    }

    // ------------------------------------------------------------------------------------------
    // Users
    // ------------------------------------------------------------------------------------------

    /**
     * @param array{address: string, name: string|null}|null $from
     *
     * @return array{0: User|null, 1: string|null} the created user and his generated password
     */
    private function createUserFromEmail(?array $from): array
    {
        if (null === $from) {
            $this->error('failed to create User: no FROM address found');

            return [null, null];
        }
        $address = $from['address'];
        $name = $from['name'];
        $user = $this->accounts->newUser();
        $user->status = Principal::STATUS_ACTIVE;
        $user->setMail($address);
        $user->login = mb_substr($address, 0, 60);
        $names = null === $name || '' === trim($name) ? explode('.', (string) preg_replace('/@.*$/', '', $address)) : (preg_split('/\s+/', trim($name)) ?: []);
        $user->firstname = mb_substr((string) array_shift($names), 0, 30);
        $user->lastname = mb_substr(implode(' ', $names), 0, 30);
        if ('' === trim($user->lastname)) {
            $user->lastname = '-';
        }
        if ('' === trim($user->firstname)) {
            $user->firstname = '-';
        }
        $user->language = $this->settings->string('default_language') ?: 'en';
        $user->mailNotification = $this->options['no_notification'] ? 'none' : 'only_my_events';
        $repo = $this->em->getRepository(User::class);
        if (!preg_match('/\A[a-z0-9_\-@\.]*\z/i', $user->login) || null !== $repo->findOneBy(['login' => $user->login])) {
            $user->login = 'user'.bin2hex(random_bytes(3));
        }
        // create_user_from_email: the account is only created when the user is valid (user.save),
        // e.g. not when the sender's domain is denied by email_domains_denied / email_domains_allowed
        $errors = $this->accounts->userErrors($user);
        if ([] !== $errors) {
            $this->error('failed to create User: '.implode(', ', $errors));

            return [null, null];
        }
        $password = $this->accounts->randomPassword();
        $this->accounts->setPassword($user, $password);
        try {
            $this->em->persist($user);
            $this->em->persist($user->getPref());
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->error('failed to create User: '.$e->getMessage());

            return [null, null];
        }

        return [$user, $password];
    }

    private function addUserToGroups(string $groups, User $user): void
    {
        foreach (array_filter(array_map('trim', explode(',', $groups))) as $name) {
            // Group.named: case-insensitive match on the name
            $groupId = $this->em->getConnection()->fetchOne("SELECT id FROM users WHERE type = 'Group' AND LOWER(lastname) = LOWER(?) ORDER BY id", [$name]);
            $group = false !== $groupId && null !== $groupId ? $this->em->find(Group::class, (int) $groupId) : null;
            if (null !== $group) {
                $this->memberships->addUserToGroup($group, $user);
            } else {
                $this->logger?->warning("MailHandler: could not add user to [$name], group not found");
            }
        }
        $this->em->flush();
    }

    private function info(string $m): void
    {
        $this->logger?->info('MailHandler: '.$m);
    }

    private function error(string $m): void
    {
        $this->lastError = $m;
        $this->logger?->error('MailHandler: '.$m);
    }
}
