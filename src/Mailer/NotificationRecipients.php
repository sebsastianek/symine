<?php

declare(strict_types=1);

namespace App\Mailer;

use App\Entity\Board;
use App\Entity\CustomField;
use App\Entity\Document;
use App\Entity\Group;
use App\Entity\Issue;
use App\Entity\IssuePriority;
use App\Entity\IssueRelation;
use App\Entity\Journal;
use App\Entity\JournalDetail;
use App\Entity\Message;
use App\Entity\News;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Watcher;
use App\Entity\WikiPage;
use App\Security\Authorization;
use App\Service\IssueService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Who gets notified - a port of the recipient logic spread over Redmine's models:
 * Issue#notified_users, Journal#notified_users, Project#notified_users, User#notify_about?,
 * acts_as_watchable#notified_watchers, Mentionable#notified_mentions, News/Message/WikiContent/Document#notified_users.
 *
 * All methods return active Users (never groups), unique by id, in a stable order.
 */
final class NotificationRecipients
{
    /** @var array<int, list<User>> project id => notified users (per request cache) */
    private array $projectNotified = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly IssueService $issues,
        private readonly Settings $settings,
    ) {
    }

    public function reset(): void
    {
        $this->projectNotified = [];
    }

    // ------------------------------------------------------------------------------------------
    // Users
    // ------------------------------------------------------------------------------------------

    /** User#notify_about? */
    public function notifyAbout(User $user, object $object, ?Principal $previousAssignee = null): bool
    {
        $option = $user->mailNotification;
        if ('all' === $option) {
            return true;
        }
        if ('' === $option || 'none' === $option) {
            return false;
        }
        if ($object instanceof Issue) {
            return match ($option) {
                'selected', 'only_my_events' => (isset($object->author) && $object->author->id === $user->id)
                    || $user->isOrBelongsTo($object->assignedTo) || $user->isOrBelongsTo($previousAssignee),
                'only_assigned' => $user->isOrBelongsTo($object->assignedTo) || $user->isOrBelongsTo($previousAssignee),
                'only_owner' => isset($object->author) && $object->author->id === $user->id,
                'only_my_watches' => $this->isWatching('Issue', (int) $object->id, $user),
                default => false,
            };
        }

        // News: always sent to project members unless mail_notification is "none"
        return $object instanceof News;
    }

    /** UserPreference#no_self_notified (default from setting for users without preferences) */
    public function noSelfNotified(User $user): bool
    {
        // Redmine initializes new preferences with the default; rows created lazily by Symine may lack the key
        if (null === $user->preference || null === $user->preference->id || !\is_array($user->preference->others) || !\array_key_exists('no_self_notified', $user->preference->others)) {
            return $this->settings->bool('default_users_no_self_notified');
        }

        return \in_array($user->preference->get('no_self_notified'), [true, 1, '1', 'true'], true);
    }

    /** Addresses that receive notifications (EmailAddress is_default OR notify) */
    public function addresses(User $user): array
    {
        if (null === $user->id) {
            return [];
        }
        $rows = $this->em->getConnection()->fetchFirstColumn(
            'SELECT address FROM email_addresses WHERE user_id = ? AND (is_default = ? OR notify = ?) ORDER BY is_default DESC, id ASC',
            [$user->id, true, true],
            [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::BOOLEAN]
        );

        return array_values(array_unique(array_filter(array_map('strval', $rows))));
    }

    // ------------------------------------------------------------------------------------------
    // Projects
    // ------------------------------------------------------------------------------------------

    /**
     * Project#notified_users: active user members with members.mail_notification or users.mail_notification = 'all'.
     *
     * @return list<User>
     */
    public function projectNotifiedUsers(Project $project): array
    {
        if (null === $project->id) {
            return [];
        }
        if (!isset($this->projectNotified[$project->id])) {
            $ids = $this->em->getConnection()->fetchFirstColumn(
                "SELECT u.id FROM members m JOIN users u ON u.id = m.user_id WHERE m.project_id = ? AND u.type = 'User' AND u.status = ? AND (m.mail_notification = ? OR u.mail_notification = 'all') ORDER BY u.id",
                [$project->id, Principal::STATUS_ACTIVE, true],
                [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::BOOLEAN]
            );
            $this->projectNotified[$project->id] = $this->usersByIds($ids);
        }

        return $this->projectNotified[$project->id];
    }

    /** @return list<User> Project#users (active users members of the project) */
    public function projectUsers(Project $project): array
    {
        $ids = $this->em->getConnection()->fetchFirstColumn(
            "SELECT u.id FROM members m JOIN users u ON u.id = m.user_id WHERE m.project_id = ? AND u.type = 'User' AND u.status = ? ORDER BY u.id",
            [$project->id, Principal::STATUS_ACTIVE]
        );

        return $this->usersByIds($ids);
    }

    // ------------------------------------------------------------------------------------------
    // Watchers & mentions
    // ------------------------------------------------------------------------------------------

    /**
     * acts_as_watchable#notified_watchers: active watchers (groups expanded to their active users),
     * with a mail and mail_notification != none, able to view the object ($visible).
     *
     * @param (callable(User): bool)|null $visible
     *
     * @return list<User>
     */
    public function notifiedWatchers(string $type, ?int $id, ?callable $visible = null): array
    {
        if (null === $id) {
            return [];
        }
        $out = [];
        foreach ($this->em->getRepository(Watcher::class)->findBy(['watchableType' => $type, 'watchableId' => $id], ['id' => 'ASC']) as $w) {
            $p = $w->user;
            if (null === $p || !$p->isActive()) {
                continue;
            }
            foreach ($this->expand($p) as $u) {
                $out[$u->id] = $u;
            }
        }
        $out = array_filter($out, fn (User $u) => null !== $this->mailOf($u) && 'none' !== $u->mailNotification);
        if (null !== $visible) {
            $out = array_filter($out, $visible);
        }

        return array_values($out);
    }

    private function isWatching(string $type, int $id, User $user): bool
    {
        $ids = [$user->id, ...$user->getGroupIds()];

        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM watchers WHERE watchable_type = ? AND watchable_id = ? AND user_id IN (?)',
            [$type, $id, $ids],
            [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER]
        ) > 0;
    }

    /**
     * Logins mentioned in $new but not in $old (Redmine::Acts::Mentionable#get_mentioned_users).
     *
     * @return list<string>
     */
    public function newMentions(?string $old, ?string $new): array
    {
        return array_values(array_diff($this->scanMentions($new), $this->scanMentions($old)));
    }

    /** @return list<string> */
    public function scanMentions(?string $content): array
    {
        if (null === $content || '' === $content) {
            return [];
        }
        // remove quoted text
        $content = preg_replace('/\r\n(?:\>\s)+(.*?)\r\n/s', '', $content) ?? $content;
        $content = match ($this->settings->textFormatting()) {
            'textile' => preg_replace('#<pre>(.*?)</pre>#s', '', $content) ?? $content,
            'common_mark' => preg_replace('/(~~~|```)(.*?)(~~~|```)/s', '', $content) ?? $content,
            default => $content,
        };
        preg_match_all('/(?:^|\W)@([A-Za-z0-9_\-@\.]*?)(?=(?=[[:punct:]][^A-Za-z0-9_\/])|\s|[[:punct:]]?$)/im', $content, $m);

        return array_values(array_unique(array_filter($m[1], static fn ($s) => '' !== $s)));
    }

    /**
     * Mentionable#notified_mentions.
     *
     * @param list<string>               $logins
     * @param (callable(User): bool)|null $visible
     *
     * @return list<User>
     */
    public function mentionedUsers(array $logins, ?callable $visible = null): array
    {
        if ([] === $logins) {
            return [];
        }
        $users = $this->em->createQuery('SELECT u FROM App\Entity\User u WHERE u.login IN (:l) AND u.status = :s ORDER BY u.id')
            ->setParameter('l', $logins)->setParameter('s', Principal::STATUS_ACTIVE)->getResult();
        $users = array_filter($users, fn (User $u) => null !== $this->mailOf($u) && 'none' !== $u->mailNotification);
        if (null !== $visible) {
            $users = array_filter($users, $visible);
        }

        return array_values($users);
    }

    // ------------------------------------------------------------------------------------------
    // Issues
    // ------------------------------------------------------------------------------------------

    /**
     * Issue#notified_users.
     *
     * @return list<User>
     */
    public function issueNotifiedUsers(Issue $issue, ?Principal $previousAssignee = null): array
    {
        $out = [];
        $direct = [];
        foreach ([$issue->author ?? null, $issue->assignedTo, $previousAssignee] as $p) {
            if (null === $p) {
                continue;
            }
            foreach ($this->expand($p, false) as $u) {
                $direct[$u->id] = $u;
            }
        }
        foreach ($direct as $u) {
            if ($u->isActive() && $this->notifyAbout($u, $issue, $previousAssignee)) {
                $out[$u->id] = $u;
            }
        }
        foreach ($this->projectNotifiedUsers($issue->project) as $u) {
            $out[$u->id] = $u;
        }
        if ($this->isHighPriority($issue->priority ?? null)) {
            foreach ($this->projectUsers($issue->project) as $u) {
                if (null !== $u->preference && \in_array($u->preference->get('notify_about_high_priority_issues'), [true, 1, '1', 'true'], true)) {
                    $out[$u->id] = $u;
                }
            }
        }

        return array_values(array_filter($out, fn (User $u) => $this->issues->isVisible($issue, $u)));
    }

    /** @return list<User> Issue#notified_watchers */
    public function issueNotifiedWatchers(Issue $issue): array
    {
        return $this->notifiedWatchers('Issue', $issue->id, fn (User $u) => $this->issues->isVisible($issue, $u));
    }

    /**
     * Recipients of issue_add: notified_users | notified_watchers | notified_mentions.
     *
     * @return list<User>
     */
    public function issueAdded(Issue $issue): array
    {
        $mentions = $this->mentionedUsers($this->newMentions(null, $issue->description), fn (User $u) => $this->issues->isVisible($issue, $u));

        return self::union($this->issueNotifiedUsers($issue), $this->issueNotifiedWatchers($issue), $mentions);
    }

    /**
     * Recipients of issue_edit (Mailer.deliver_issue_edit): users that can see the journal, and either
     * a note or at least one visible detail.
     *
     * @return list<User>
     */
    public function issueEdited(Journal $journal): array
    {
        $issue = $journal->issue;
        $previous = null;
        $descriptionOld = null;
        $descriptionChanged = false;
        foreach ($journal->details as $d) {
            if ('attr' === $d->property && 'assigned_to_id' === $d->propKey && null !== $d->oldValue && '' !== $d->oldValue) {
                $previous = $this->em->find(Principal::class, (int) $d->oldValue);
            }
            if ('attr' === $d->property && 'description' === $d->propKey) {
                $descriptionChanged = true;
                $descriptionOld = $d->oldValue;
            }
        }
        $privateFilter = function (array $users) use ($journal, $issue): array {
            if (!$journal->privateNotes) {
                return $users;
            }

            return array_values(array_filter($users, fn (User $u) => $this->auth->allowedTo('view_private_notes', $issue->project, $u)));
        };
        $visible = fn (User $u) => $this->issues->isVisible($issue, $u);
        $users = self::union(
            $privateFilter($this->issueNotifiedUsers($issue, $previous)),
            $privateFilter($this->issueNotifiedWatchers($issue)),
            $privateFilter($this->mentionedUsers($this->newMentions(null, $journal->notes), $visible)),
            $descriptionChanged ? $this->mentionedUsers($this->newMentions($descriptionOld, $issue->description), $visible) : [],
        );
        $hasNotes = null !== $journal->notes && '' !== trim($journal->notes);

        return array_values(array_filter($users, fn (User $u) => $hasNotes || [] !== $this->visibleDetails($journal, $u)));
    }

    /**
     * Journal#visible_details.
     *
     * @return list<JournalDetail>
     */
    public function visibleDetails(Journal $journal, User $user): array
    {
        $out = [];
        foreach ($journal->details as $d) {
            if ('cf' === $d->property) {
                $cf = $this->em->find(CustomField::class, (int) $d->propKey);
                if (null === $cf || !$cf->isVisibleBy($journal->issue->project, $user, fn ($p) => $this->auth->rolesForProject($p, $user))) {
                    continue;
                }
            } elseif ('relation' === $d->property) {
                $other = $this->em->find(Issue::class, (int) ($d->value ?? $d->oldValue));
                if (null === $other || !$this->issues->isVisible($other, $user)) {
                    continue;
                }
            }
            $out[] = $d;
        }

        return $out;
    }

    /** IssuePriority#high? */
    public function isHighPriority(?IssuePriority $priority): bool
    {
        if (null === $priority) {
            return false;
        }
        $default = $this->em->getRepository(IssuePriority::class)->findOneBy(['isDefault' => true]);
        if (null === $default) {
            $active = $this->em->getRepository(IssuePriority::class)->findBy(['active' => true], ['position' => 'ASC']);
            $default = $active[intdiv(\count($active) - 1, 2)] ?? null;
        }
        if (null === $default || null === $default->position) {
            return false;
        }

        return (int) $priority->position > $default->position;
    }

    // ------------------------------------------------------------------------------------------
    // Other content
    // ------------------------------------------------------------------------------------------

    /** @return list<User> News#notified_users | News#notified_watchers_for_added_news */
    public function newsAdded(News $news): array
    {
        $project = $news->project;
        if (null === $project) {
            return [];
        }
        $users = array_values(array_filter($this->projectUsers($project), fn (User $u) => $this->notifyAbout($u, $news) && $this->auth->allowedTo('view_news', $project, $u)));
        $watchers = [];
        $moduleId = $this->em->getConnection()->fetchOne("SELECT id FROM enabled_modules WHERE project_id = ? AND name = 'news'", [$project->id]);
        if (false !== $moduleId && null !== $moduleId) {
            $watchers = $this->notifiedWatchers('EnabledModule', (int) $moduleId);
            if (!$project->isPublic) {
                $memberIds = array_map(static fn (User $u) => $u->id, $this->projectUsers($project));
                $watchers = array_values(array_filter($watchers, static fn (User $u) => \in_array($u->id, $memberIds, true)));
            }
        }

        return self::union($users, $watchers);
    }

    /** @return list<User> News#notified_users | News#notified_watchers */
    public function newsCommentAdded(News $news): array
    {
        $project = $news->project;
        if (null === $project) {
            return [];
        }
        $users = array_values(array_filter($this->projectUsers($project), fn (User $u) => $this->notifyAbout($u, $news) && $this->auth->allowedTo('view_news', $project, $u)));

        return self::union($users, $this->notifiedWatchers('News', $news->id, fn (User $u) => $this->auth->allowedTo('view_news', $project, $u)));
    }

    /** @return list<User> Message#notified_users | root watchers | board watchers */
    public function messagePosted(Message $message): array
    {
        $project = $message->board->project;
        $visible = fn (User $u) => $this->auth->allowedTo('view_messages', $project, $u);
        $root = $message->parent ?? $message;

        return self::union(
            array_values(array_filter($this->projectNotifiedUsers($project), $visible)),
            $this->notifiedWatchers('Message', $root->id, $visible),
            $this->notifiedWatchers('Board', $message->board->id, $visible),
        );
    }

    /** @return list<User> WikiContent#notified_users | wiki watchers (| page watchers when updated) | mentions */
    public function wikiContent(WikiPage $page, bool $updated, ?string $oldText = null): array
    {
        $project = $page->wiki->project;
        $visible = fn (User $u) => $this->auth->allowedTo('view_wiki_pages', $project, $u);

        return self::union(
            array_values(array_filter($this->projectNotifiedUsers($project), $visible)),
            $updated ? $this->notifiedWatchers('WikiPage', $page->id, $visible) : [],
            $this->notifiedWatchers('Wiki', $page->wiki->id, $visible),
            $this->mentionedUsers($this->newMentions($oldText, $page->content?->text), $visible),
        );
    }

    /** @return list<User> Document#notified_users */
    public function documentAdded(Document $document): array
    {
        return array_values(array_filter($this->projectNotifiedUsers($document->project), fn (User $u) => $this->auth->allowedTo('view_documents', $document->project, $u)));
    }

    /** @return list<User> recipients of attachments_added for a Project/Version (files) or a Document container */
    public function attachmentsAdded(object $container): array
    {
        if ($container instanceof Document) {
            return $this->documentAdded($container);
        }
        $project = $container instanceof Project ? $container : ($container->project ?? null);
        if (!$project instanceof Project) {
            return [];
        }

        return array_values(array_filter($this->projectNotifiedUsers($project), fn (User $u) => $this->auth->allowedTo('view_files', $project, $u)));
    }

    // ------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------

    /** @return list<User> a user, or the (active) users of a group */
    private function expand(Principal $p, bool $activeOnly = true): array
    {
        if ($p instanceof Group) {
            return array_values(array_filter($p->users->toArray(), static fn ($u) => $u instanceof User && (!$activeOnly || $u->isActive())));
        }

        return $p instanceof User && $p->isLogged() ? [$p] : [];
    }

    private function mailOf(User $u): ?string
    {
        return $u->getMail();
    }

    /** @return list<User> */
    private function usersByIds(array $ids): array
    {
        $ids = array_map('intval', $ids);
        if ([] === $ids) {
            return [];
        }
        $users = $this->em->getRepository(User::class)->findBy(['id' => $ids]);
        usort($users, static fn (User $a, User $b) => $a->id <=> $b->id);

        return $users;
    }

    /**
     * Ruby's Array#| on users.
     *
     * @param list<User> ...$lists
     *
     * @return list<User>
     */
    public static function union(array ...$lists): array
    {
        $out = [];
        foreach ($lists as $list) {
            foreach ($list as $u) {
                $out[$u->id] ??= $u;
            }
        }

        return array_values($out);
    }

    /** Relation detail helper kept here to avoid loading IssueRelation types in templates */
    public static function relationLabelKey(string $type): string
    {
        return IssueRelation::TYPES[$type][0] ?? 'label_related_issues';
    }

    /** Board helper (kept for symmetry with Redmine's Board#notified_watchers) */
    public function boardWatchers(Board $board): array
    {
        return $this->notifiedWatchers('Board', $board->id);
    }
}
