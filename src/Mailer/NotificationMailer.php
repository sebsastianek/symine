<?php

declare(strict_types=1);

namespace App\Mailer;

use App\Entity\AnonymousUser;
use App\Entity\Attachment;
use App\Entity\AuthSource;
use App\Entity\Comment;
use App\Entity\Document;
use App\Entity\Group;
use App\Entity\Issue;
use App\Entity\Journal;
use App\Entity\Message;
use App\Entity\News;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\Token;
use App\Entity\User;
use App\Entity\Version;
use App\Entity\WikiPage;
use App\I18n\Localizer;
use App\Security\CurrentUser;
use App\Service\IssueService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Port of Redmine's Mailer (app/models/mailer.rb): one e-mail per recipient, rendered in the
 * recipient's language and as the recipient (User.current), with Redmine's headers
 * (X-Redmine-*, Message-ID/References for threading, List-Id, Auto-Submitted...).
 *
 * The build methods (issueAdd, issueEdit, ...) return the Email (or null when the recipient has
 * no address); the deliver* methods compute the recipients like Redmine and send the e-mails
 * (after the response when in an HTTP request).
 */
final class NotificationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly CurrentUser $currentUser,
        private readonly Security $security,
        private readonly NotificationRecipients $recipients,
        private readonly MailHelper $helper,
        private readonly IssueService $issues,
        private readonly EntityManagerInterface $em,
        private readonly DeferredTasks $deferred,
    ) {
    }

    // ==========================================================================================
    // Deliveries (Mailer.deliver_*)
    // ==========================================================================================

    /** @return int number of e-mails sent */
    public function deliverIssueAdd(Issue $issue): int
    {
        return $this->sendAll(array_map(fn (User $u) => $this->issueAdd($u, $issue), $this->recipients->issueAdded($issue)));
    }

    public function deliverIssueEdit(Journal $journal): int
    {
        return $this->sendAll(array_map(fn (User $u) => $this->issueEdit($u, $journal), $this->recipients->issueEdited($journal)));
    }

    public function deliverDocumentAdded(Document $document, User $author): int
    {
        return $this->sendAll(array_map(fn (User $u) => $this->documentAdded($u, $document, $author), $this->recipients->documentAdded($document)));
    }

    /** @param list<Attachment> $attachments attachments added to a Project/Version (files module) or a Document */
    public function deliverAttachmentsAdded(array $attachments, object $container): int
    {
        if ([] === $attachments) {
            return 0;
        }

        return $this->sendAll(array_map(fn (User $u) => $this->attachmentsAdded($u, $attachments, $container), $this->recipients->attachmentsAdded($container)));
    }

    public function deliverNewsAdded(News $news): int
    {
        return $this->sendAll(array_map(fn (User $u) => $this->newsAdded($u, $news), $this->recipients->newsAdded($news)));
    }

    public function deliverNewsCommentAdded(Comment $comment): int
    {
        $news = $this->em->find(News::class, $comment->commentedId);
        if (null === $news) {
            return 0;
        }

        return $this->sendAll(array_map(fn (User $u) => $this->newsCommentAdded($u, $comment, $news), $this->recipients->newsCommentAdded($news)));
    }

    public function deliverMessagePosted(Message $message): int
    {
        return $this->sendAll(array_map(fn (User $u) => $this->messagePosted($u, $message), $this->recipients->messagePosted($message)));
    }

    public function deliverWikiContentAdded(WikiPage $page): int
    {
        return $this->sendAll(array_map(fn (User $u) => $this->wikiContent($u, $page, false), $this->recipients->wikiContent($page, false)));
    }

    public function deliverWikiContentUpdated(WikiPage $page, ?string $previousText = null): int
    {
        return $this->sendAll(array_map(fn (User $u) => $this->wikiContent($u, $page, true), $this->recipients->wikiContent($page, true, $previousText)));
    }

    public function deliverAccountInformation(User $user, ?string $password): int
    {
        return $this->sendAll([$this->accountInformation($user, $password)]);
    }

    /** Notifies active administrators that an account activation request needs their approval */
    public function deliverAccountActivationRequest(User $newUser): int
    {
        return $this->sendAll(array_map(fn (User $u) => $this->accountActivationRequest($u, $newUser), $this->activeAdmins()));
    }

    public function deliverAccountActivated(User $user): int
    {
        return $this->sendAll([$this->accountActivated($user)]);
    }

    public function deliverLostPassword(User $user, Token $token, ?string $recipient = null): int
    {
        return $this->sendAll([$this->lostPassword($user, $token, $recipient)]);
    }

    public function deliverRegister(User $user, Token $token): int
    {
        return $this->sendAll([$this->register($user, $token)]);
    }

    public function deliverPasswordUpdated(User $user, User $sender, ?string $remoteIp = null): int
    {
        // no notification to the dummy address of the default admin account (first login)
        if ($user->admin && 'admin' === $user->login && 'admin@dummy.invalid' === $user->getMail()) {
            return 0;
        }

        return $this->deliverSecurityNotification([$user], $sender, [
            'message' => 'mail_body_password_updated',
            'title' => 'button_change_password',
            'url' => '/my/password',
            'remote_ip' => $remoteIp,
        ]);
    }

    /**
     * Mailer.deliver_security_notification. Options: message (key), field (key), value, title (key),
     * url (path or absolute URL), remote_ip, recipients (additional e-mail addresses).
     *
     * @param list<User>           $users
     * @param array<string, mixed> $options
     */
    public function deliverSecurityNotification(array $users, User $sender, array $options = []): int
    {
        return $this->sendAll(array_map(fn (User $u) => $this->securityNotification($u, $sender, $options), $users));
    }

    /** @param list<string> $changes setting names */
    public function deliverSettingsUpdated(User $sender, array $changes, ?string $remoteIp = null): int
    {
        if ([] === $changes) {
            return 0;
        }

        return $this->sendAll(array_map(fn (User $u) => $this->settingsUpdated($u, $sender, $changes, $remoteIp), $this->activeAdmins()));
    }

    /** Sends a test e-mail synchronously; delivery errors are thrown (Mailer.deliver_test_email) */
    public function deliverTestEmail(User $user): void
    {
        $email = $this->testEmail($user);
        if (null === $email) {
            throw new \RuntimeException('The user has no e-mail address.');
        }
        $this->mailer->send($email);
    }

    /**
     * Mailer.reminders: reminds assignees of their open issues due in the next $days days.
     *
     * @param list<int> $userIds
     *
     * @return int number of reminders sent
     */
    public function reminders(int $days = 7, ?int $trackerId = null, ?int $projectId = null, array $userIds = [], ?string $version = null): int
    {
        $limit = $this->i18n->today()->modify('+'.$days.' days')->format('Y-m-d');
        $qb = $this->em->createQueryBuilder()->select('i')->from(Issue::class, 'i')
            ->join('i.status', 's')->join('i.project', 'p')
            ->where('s.isClosed = false AND i.assignedTo IS NOT NULL AND p.status = :active AND i.dueDate <= :limit')
            ->setParameter('active', Project::STATUS_ACTIVE)->setParameter('limit', $limit);
        if ([] !== $userIds) {
            $qb->andWhere('IDENTITY(i.assignedTo) IN (:users)')->setParameter('users', $userIds);
        }
        if (null !== $projectId) {
            $qb->andWhere('p.id = :project')->setParameter('project', $projectId);
        }
        if (null !== $trackerId) {
            $qb->andWhere('IDENTITY(i.tracker) = :tracker')->setParameter('tracker', $trackerId);
        }
        if (null !== $version) {
            // Version.named: case-insensitive, surrounding spaces ignored
            $ids = $this->em->getConnection()->fetchFirstColumn('SELECT id FROM versions WHERE LOWER(name) = LOWER(?)', [trim($version)]);
            if ([] === $ids) {
                throw new \InvalidArgumentException(\sprintf("Couldn't find Version named %s", $version));
            }
            $qb->andWhere('IDENTITY(i.fixedVersion) IN (:versions)')->setParameter('versions', array_map('intval', $ids));
        }
        /** @var array<int, array{0: Principal, 1: list<Issue>}> $byAssignee */
        $byAssignee = [];
        foreach ($qb->getQuery()->getResult() as $issue) {
            $a = $issue->assignedTo;
            $byAssignee[$a->id] ??= [$a, []];
            $byAssignee[$a->id][1][] = $issue;
        }
        foreach ($byAssignee as [$assignee, $list]) {
            if ($assignee instanceof Group) {
                foreach ($assignee->users as $u) {
                    $byAssignee[$u->id] ??= [$u, []];
                    $byAssignee[$u->id][1] = [...$byAssignee[$u->id][1], ...$list];
                }
            }
        }
        $emails = [];
        foreach ($byAssignee as [$assignee, $list]) {
            if (!$assignee instanceof User || $assignee instanceof AnonymousUser || !$assignee->isActive() || [] === $list) {
                continue;
            }
            $visible = [];
            foreach ($list as $i) {
                if ($this->issues->isVisible($i, $assignee)) {
                    $visible[$i->id] = $i;
                }
            }
            $visible = array_values($visible);
            usort($visible, static fn (Issue $a, Issue $b) => [$a->dueDate, $a->id] <=> [$b->dueDate, $b->id]);
            if ([] !== $visible) {
                $emails[] = $this->reminder($assignee, $visible, $days);
            }
        }

        return $this->sendAll($emails);
    }

    // ==========================================================================================
    // Builders (Mailer#issue_add, ...)
    // ==========================================================================================

    public function issueAdd(User $user, Issue $issue): ?Email
    {
        return $this->as($user, function () use ($user, $issue) {
            $url = $this->helper->issueUrl($issue);
            $subject = '['.$issue->project->name.' - '.$issue->tracker->name.' #'.$issue->id.']';
            if ($this->settings->bool('show_status_changes_in_mail_subject')) {
                $subject .= ' ('.$issue->status->name.')';
            }
            $subject .= ' '.$issue->subject;

            return $this->build($user, 'issue_add', $subject, [
                'issue' => $issue, 'issue_url' => $url, 'author' => $issue->author,
            ], $issue->author, $this->issueHeaders($issue), $issue, [$issue]);
        });
    }

    public function issueEdit(User $user, Journal $journal): ?Email
    {
        $issue = $journal->issue;

        return $this->as($user, function () use ($user, $journal, $issue) {
            $statusChanged = false;
            foreach ($journal->details as $d) {
                if ('attr' === $d->property && 'status_id' === $d->propKey && null !== $d->value && '' !== $d->value) {
                    $statusChanged = true;
                }
            }
            $subject = '['.$issue->project->name.' - '.$issue->tracker->name.' #'.$issue->id.'] ';
            if ($statusChanged && $this->settings->bool('show_status_changes_in_mail_subject')) {
                $subject .= '('.$issue->status->name.') ';
            }
            $subject .= $issue->subject;
            $details = $this->recipients->visibleDetails($journal, $user);

            return $this->build($user, 'issue_edit', $subject, [
                'issue' => $issue,
                'journal' => $journal,
                'journal_details' => $details,
                'details_html' => $this->helper->detailsToStrings($details, true),
                'details_text' => $this->helper->detailsToStrings($details, false),
                'issue_url' => $this->helper->issueUrl($issue, 'change-'.$journal->id),
                'author' => $journal->user,
            ], $journal->user, $this->issueHeaders($issue), $journal, [$issue]);
        });
    }

    public function documentAdded(User $user, Document $document, User $author): ?Email
    {
        return $this->as($user, fn () => $this->build($user, 'document_added',
            '['.$document->project->name.'] '.$this->i18n->l('label_document_new').': '.$document->title,
            ['document' => $document, 'document_url' => $this->helper->url('/documents/'.$document->id)],
            $author, ['Project' => $document->project->identifier]));
    }

    /** @param list<Attachment> $attachments */
    public function attachmentsAdded(User $user, array $attachments, object $container): ?Email
    {
        return $this->as($user, function () use ($user, $attachments, $container) {
            $project = $container instanceof Project ? $container : $container->project;
            [$addedTo, $addedToUrl] = match (true) {
                $container instanceof Project => [$this->i18n->l('label_project').': '.$container->name, $this->helper->url('/projects/'.$container->identifier.'/files')],
                $container instanceof Version => [$this->i18n->l('label_version').': '.$container->name, $this->helper->url('/projects/'.$container->project->identifier.'/files')],
                $container instanceof Document => [$this->i18n->l('label_document').': '.$container->title, $this->helper->url('/documents/'.$container->id)],
                default => ['', ''],
            };
            $summary = $this->i18n->l('label_attachment_summary', ['filename' => $attachments[0]->filename, 'count' => \count($attachments) - 1]);

            return $this->build($user, 'attachments_added', '['.$project->name.'] '.$this->i18n->l('label_attachment_new').': '.$summary,
                ['attachments' => $attachments, 'added_to' => $addedTo, 'added_to_url' => $addedToUrl],
                $attachments[0]->author, ['Project' => $project->identifier]);
        });
    }

    public function newsAdded(User $user, News $news): ?Email
    {
        return $this->as($user, fn () => $this->build($user, 'news_added',
            '['.$news->project->name.'] '.$this->i18n->l('label_news').': '.$news->title,
            ['news' => $news, 'news_url' => $this->helper->url('/news/'.$news->id)],
            $news->author, ['Project' => $news->project->identifier], $news, [$news]));
    }

    public function newsCommentAdded(User $user, Comment $comment, ?News $news = null): ?Email
    {
        $news ??= $this->em->find(News::class, $comment->commentedId);

        return $this->as($user, fn () => $this->build($user, 'news_comment_added',
            'Re: ['.$news->project->name.'] '.$this->i18n->l('label_news').': '.$news->title,
            ['news' => $news, 'comment' => $comment, 'news_url' => $this->helper->url('/news/'.$news->id)],
            $comment->author, ['Project' => $news->project->identifier], $comment, [$news]));
    }

    public function messagePosted(User $user, Message $message): ?Email
    {
        $root = $message->parent ?? $message;

        return $this->as($user, function () use ($user, $message, $root) {
            $board = $message->board;
            $url = '/boards/'.$board->id.'/topics/'.$root->id.(null !== $message->parent ? '?r='.$message->id.'#message-'.$message->id : '');

            return $this->build($user, 'message_posted',
                '['.$board->project->name.' - '.$board->name.' - msg'.$root->id.'] '.$message->subject,
                ['message' => $message, 'message_url' => $this->helper->url($url)],
                $message->author, ['Project' => $board->project->identifier, 'Topic-Id' => $root->id], $message, [$root]);
        });
    }

    public function wikiContent(User $user, WikiPage $page, bool $updated): ?Email
    {
        $content = $page->content;
        if (null === $content) {
            return null;
        }

        return $this->as($user, function () use ($user, $page, $content, $updated) {
            $project = $page->wiki->project;
            $base = '/projects/'.$project->identifier.'/wiki/'.rawurlencode($page->title);
            $vars = [
                'page' => $page,
                'wiki_content' => $content,
                'wiki_content_url' => $this->helper->url($base),
                'wiki_diff_url' => $this->helper->url($base.'/diff?version='.$content->version),
            ];
            $key = $updated ? 'mail_subject_wiki_content_updated' : 'mail_subject_wiki_content_added';

            return $this->build($user, $updated ? 'wiki_content_updated' : 'wiki_content_added',
                '['.$project->name.'] '.$this->i18n->l($key, ['id' => $page->prettyTitle()]),
                $vars, $content->author, ['Project' => $project->identifier, 'Wiki-Page-Id' => $page->id], $content);
        });
    }

    public function accountInformation(User $user, ?string $password): ?Email
    {
        return $this->as($user, function () use ($user, $password) {
            $authSource = null !== $user->authSourceId ? $this->em->find(AuthSource::class, $user->authSourceId) : null;

            return $this->build($user->getMail() ?? '', 'account_information', $this->i18n->l('mail_subject_register', ['value' => $this->settings->appTitle()]), [
                'user' => $user, 'password' => $password, 'auth_source' => $authSource, 'login_url' => $this->helper->url('/login'),
            ]);
        });
    }

    public function accountActivationRequest(User $admin, User $newUser): ?Email
    {
        return $this->as($admin, fn () => $this->build($admin, 'account_activation_request',
            $this->i18n->l('mail_subject_account_activation_request', ['value' => $this->settings->appTitle()]),
            ['new_user' => $newUser, 'url' => $this->helper->url('/users?sort_key=created_on&sort_order=desc&status='.Principal::STATUS_REGISTERED)]));
    }

    public function accountActivated(User $user): ?Email
    {
        return $this->as($user, fn () => $this->build($user->getMail() ?? '', 'account_activated',
            $this->i18n->l('mail_subject_register', ['value' => $this->settings->appTitle()]),
            ['user' => $user, 'login_url' => $this->helper->url('/login')]));
    }

    public function lostPassword(User $user, Token $token, ?string $recipient = null): ?Email
    {
        return $this->as($user, fn () => $this->build($recipient ?? $user->getMail() ?? '', 'lost_password',
            $this->i18n->l('mail_subject_lost_password', ['value' => $this->settings->appTitle()]),
            ['token' => $token, 'login' => $user->login, 'url' => $this->helper->url('/account/lost_password?token='.rawurlencode($token->value))]));
    }

    public function register(User $user, Token $token): ?Email
    {
        return $this->as($user, fn () => $this->build($user->getMail() ?? '', 'register',
            $this->i18n->l('mail_subject_register', ['value' => $this->settings->appTitle()]),
            ['token' => $token, 'url' => $this->helper->url('/account/activate?token='.rawurlencode($token->value))]));
    }

    /** @param array<string, mixed> $options see deliverSecurityNotification() */
    public function securityNotification(User $user, User $sender, array $options = []): ?Email
    {
        return $this->as($user, function () use ($user, $sender, $options) {
            $message = $this->i18n->l((string) ($options['message'] ?? ''), [
                'field' => isset($options['field']) ? $this->i18n->l((string) $options['field']) : '',
                'value' => (string) ($options['value'] ?? ''),
            ]);
            $title = isset($options['title']) ? $this->i18n->l((string) $options['title']) : null;
            $url = $options['url'] ?? null;
            if (\is_string($url) && str_starts_with($url, '/')) {
                $url = $this->helper->url($url);
            }
            $to = [$user, ...array_values(array_filter((array) ($options['recipients'] ?? [])))];

            return $this->build($to, 'security_notification', '['.$this->settings->appTitle().'] '.$this->i18n->l('mail_subject_security_notification'), [
                'message' => $message, 'title' => $title, 'url' => $url, 'sender' => $sender,
                'remote_ip' => $options['remote_ip'] ?? null, 'now' => new \DateTimeImmutable(),
            ], null, ['Sender' => $sender->login, 'Url' => $url]);
        });
    }

    /** @param list<string> $changes */
    public function settingsUpdated(User $user, User $sender, array $changes, ?string $remoteIp = null): ?Email
    {
        return $this->as($user, fn () => $this->build($user, 'settings_updated',
            '['.$this->settings->appTitle().'] '.$this->i18n->l('mail_subject_security_notification'),
            ['changes' => $changes, 'sender' => $sender, 'remote_ip' => $remoteIp, 'url' => $this->helper->url('/settings'), 'now' => new \DateTimeImmutable()],
            null, ['Sender' => $sender->login]));
    }

    public function testEmail(User $user): ?Email
    {
        return $this->as($user, fn () => $this->build($user, 'test_email', $this->i18n->l('mail_subject_test_email'), ['url' => $this->helper->url('/')]));
    }

    /** @param list<Issue> $issues */
    public function reminder(User $user, array $issues, int $days): ?Email
    {
        return $this->as($user, function () use ($user, $issues, $days) {
            $openUrl = $this->helper->url('/issues?assigned_to_id=me&set_filter=1&sort=due_date%3Aasc');
            $reminderUrl = $this->helper->url('/issues?'.http_build_query([
                'f' => ['status_id', 'assigned_to_id', 'due_date'],
                'op' => ['assigned_to_id' => '=', 'due_date' => '<t+', 'status_id' => 'o'],
                'set_filter' => 1,
                'sort' => 'due_date:asc',
                'v' => ['assigned_to_id' => ['me'], 'due_date' => [$days]],
            ], '', '&', \PHP_QUERY_RFC3986));
            $reminderUrl = preg_replace('/%5B\d+%5D/', '%5B%5D', $reminderUrl) ?? $reminderUrl;
            $ids = [$user->id, ...$user->getGroupIds()];
            $count = (int) $this->em->createQuery('SELECT COUNT(i.id) FROM App\Entity\Issue i JOIN i.status s WHERE s.isClosed = false AND IDENTITY(i.assignedTo) IN (:ids) AND '.$this->issues->visibleCondition($user, 'i'))
                ->setParameter('ids', $ids)->getSingleScalarResult();

            return $this->build($user, 'reminder', $this->i18n->l('mail_subject_reminder', ['count' => \count($issues), 'days' => $days]), [
                'issues' => $issues, 'days' => $days, 'open_issues_url' => $openUrl, 'reminder_issues_url' => $reminderUrl, 'open_issues_count' => $count,
            ]);
        });
    }

    // ==========================================================================================
    // Internals
    // ==========================================================================================

    /** @return array<string, mixed> */
    private function issueHeaders(Issue $issue): array
    {
        $assignee = match (true) {
            $issue->assignedTo instanceof Group => 'Group ('.$issue->assignedTo->getName().')',
            $issue->assignedTo instanceof User => $issue->assignedTo->login,
            default => null,
        };

        return [
            'Project' => $issue->project->identifier,
            'Issue-Tracker' => $issue->tracker->name,
            'Issue-Id' => $issue->id,
            'Issue-Author' => isset($issue->author) ? $issue->author->login : null,
            'Issue-Assignee' => $assignee,
            'Issue-Priority' => $issue->priority->name ?? null,
        ];
    }

    /** @return list<User> */
    private function activeAdmins(): array
    {
        return $this->em->getRepository(User::class)->findBy(['admin' => true, 'status' => Principal::STATUS_ACTIVE], ['id' => 'ASC']);
    }

    /**
     * Runs $build with the recipient as current user and his language as locale (Mailer#process).
     *
     * @template T
     *
     * @param \Closure(): T $build
     *
     * @return T
     */
    private function as(User $user, \Closure $build): mixed
    {
        $prevUser = $this->currentUser->get();
        $prevLocale = $this->i18n->locale();
        $lang = $user->isLogged() && $user->language ? $user->language : null;
        $available = $this->i18n->availableLocales();
        $locale = null;
        foreach ([$lang, $this->settings->string('default_language'), 'en'] as $candidate) {
            if (null !== $candidate && '' !== $candidate && \in_array($candidate, $available, true)) {
                $locale = $candidate;
                break;
            }
        }
        $this->currentUser->set($user);
        $this->i18n->setLocale($locale ?? 'en');
        try {
            return $build();
        } finally {
            $securityUser = $this->security->getUser();
            $this->currentUser->set($prevUser === $securityUser || $prevUser instanceof AnonymousUser ? null : $prevUser);
            $this->i18n->setLocale($prevLocale);
        }
    }

    /**
     * Mailer#mail: renders the text (+ html) parts in the layout and sets Redmine's headers.
     *
     * @param User|string|list<User|string> $to
     * @param array<string, mixed>          $vars
     * @param array<string, mixed>          $redmineHeaders X-Redmine-* headers (without prefix)
     * @param list<object>                  $references
     */
    private function build(User|string|array $to, string $template, string $subject, array $vars, ?User $author = null, array $redmineHeaders = [], ?object $messageIdObject = null, array $references = []): ?Email
    {
        $recipientUser = \is_array($to) ? ($to[0] instanceof User ? $to[0] : null) : ($to instanceof User ? $to : null);
        $addresses = [];
        foreach (\is_array($to) ? $to : [$to] as $t) {
            foreach ($t instanceof User ? $this->recipients->addresses($t) : [$t] as $a) {
                if ('' !== trim((string) $a)) {
                    $addresses[strtolower(trim((string) $a))] = trim((string) $a);
                }
            }
        }
        // the author does not want to be notified about what he does
        $authorLogged = null !== $author && !$author instanceof AnonymousUser && null !== $author->id;
        if ($authorLogged && $this->recipients->noSelfNotified($author)) {
            foreach ($this->recipients->addresses($author) as $a) {
                unset($addresses[strtolower($a)]);
            }
        }
        if ([] === $addresses) {
            return null;
        }

        // From (with a display name) and List-Id
        $mailFrom = trim($this->settings->string('mail_from')) ?: 'redmine@example.net';
        try {
            $from = Address::create($mailFrom);
            if ('' === $from->getName()) {
                $from = new Address($from->getAddress(), $authorLogged ? $author->getName() : $this->settings->appTitle());
            }
            $fromAddr = $from->getAddress();
        } catch (\Throwable) {
            $from = null;
            $fromAddr = $mailFrom;
        }
        $project = $redmineHeaders['Project'] ?? null;
        $listId = null !== $project && '' !== (string) $project ? '<'.$project.'.'.str_replace('@', '.', $fromAddr).'>' : '<'.str_replace('@', '.', $fromAddr).'>';

        $vars += ['user' => $recipientUser, 'author' => $author, 'mail' => $this->helper];
        $text = $this->renderPart('mailer/'.$template.'.txt.twig', $vars);
        $email = (new Email())->subject($subject)->text($text);
        if (!$this->settings->bool('plain_text_mail')) {
            $email->html($this->renderPart('mailer/'.$template.'.html.twig', $vars));
        }
        null !== $from ? $email->from($from) : $email->getHeaders()->addTextHeader('From', $fromAddr);
        $email->to(...array_values($addresses));

        $headers = $email->getHeaders();
        $headers->addTextHeader('X-Mailer', 'Redmine');
        $headers->addTextHeader('X-Redmine-Host', $this->settings->string('host_name'));
        $headers->addTextHeader('X-Redmine-Site', $this->settings->appTitle());
        $headers->addTextHeader('X-Auto-Response-Suppress', 'All');
        $headers->addTextHeader('Auto-Submitted', 'auto-generated');
        $headers->addTextHeader('List-Id', $listId);
        foreach ($redmineHeaders as $k => $v) {
            if (null !== $v && '' !== (string) $v) {
                $headers->addTextHeader('X-Redmine-'.$k, (string) $v);
            }
        }
        if ($authorLogged && !isset($redmineHeaders['Sender'])) {
            $headers->addTextHeader('X-Redmine-Sender', $author->login);
        }
        if (null !== $messageIdObject) {
            $headers->addIdHeader('Message-ID', self::tokenFor($messageIdObject, $recipientUser, $mailFrom));
        }
        if ([] !== $references) {
            $headers->addIdHeader('References', array_map(fn (object $o) => self::tokenFor($o, $recipientUser, $mailFrom), $references));
        }

        return $email;
    }

    /** @param array<string, mixed> $vars */
    private function renderPart(string $template, array $vars): string
    {
        return $this->twig->render($template, $vars);
    }

    /**
     * Mailer.token_for: "redmine.<class>-<id>.<YmdHis created_on/updated_on UTC>[.<user id>]@<mail_from host>".
     */
    public static function tokenFor(object $object, ?User $user, string $mailFrom): string
    {
        $class = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', (new \ReflectionClass($object))->getShortName()) ?? '');
        $timestamp = property_exists($object, 'createdOn') && isset($object->createdOn) ? $object->createdOn : ($object->updatedOn ?? null);
        $parts = ['redmine', $class.'-'.($object->id ?? 0), $timestamp instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($timestamp)->setTimezone(new \DateTimeZone('UTC'))->format('YmdHis') : ''];
        if (null !== $user && null !== $user->id) {
            $parts[] = (string) $user->id;
        }
        $host = preg_replace('/^.*@|>/', '', trim($mailFrom)) ?? '';
        if ('' === $host) {
            $host = gethostname().'.redmine';
        }

        return implode('.', $parts).'@'.$host;
    }

    /** @param list<Email|null> $emails */
    private function sendAll(array $emails): int
    {
        $n = 0;
        foreach ($emails as $email) {
            if (null === $email) {
                continue;
            }
            ++$n;
            $this->deferred->defer('Email delivery', fn () => $this->mailer->send($email));
        }

        return $n;
    }
}
