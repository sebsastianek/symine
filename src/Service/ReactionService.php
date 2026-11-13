<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Comment;
use App\Entity\Issue;
use App\Entity\Journal;
use App\Entity\Message;
use App\Entity\News;
use App\Entity\Project;
use App\Entity\Reaction;
use App\Entity\User;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Port of Redmine::Reaction (lib/redmine/reaction.rb), Reaction.build_detail_map_for and
 * Redmine::Reaction::Reactable (reaction_detail preloading).
 */
final class ReactionService
{
    /** Redmine::Reaction::REACTABLE_TYPES */
    public const array REACTABLE_TYPES = ['Journal', 'Issue', 'Message', 'News', 'Comment'];

    private const array CLASSES = [
        'Journal' => Journal::class,
        'Issue' => Issue::class,
        'Message' => Message::class,
        'News' => News::class,
        'Comment' => Comment::class,
    ];

    /** @var array<string, array{users: list<User>, user_reaction: ?Reaction}> reaction details by "Type#id" */
    private array $details = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly Settings $settings,
        private readonly IssueService $issues,
        private readonly Localizer $i18n,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->bool('reactions_enabled');
    }

    public static function typeOf(object $object): ?string
    {
        foreach (self::CLASSES as $type => $class) {
            if ($object instanceof $class) {
                return $type;
            }
        }

        return null;
    }

    public function find(string $type, int $id): ?object
    {
        $class = self::CLASSES[$type] ?? null;

        return null === $class ? null : $this->em->find($class, $id);
    }

    public function projectOf(object $object): ?Project
    {
        return match (true) {
            $object instanceof Issue => $object->project,
            $object instanceof Journal => $object->issue->project,
            $object instanceof Message => $object->board->project,
            $object instanceof News => $object->project,
            $object instanceof Comment => $this->commented($object)?->project,
            default => null,
        };
    }

    private function commented(Comment $comment): ?News
    {
        return 'News' === $comment->commentedType ? $this->em->find(News::class, $comment->commentedId) : null;
    }

    /** object.visible?(user) of the reactable types */
    public function isObjectVisible(object $object, ?User $user = null): bool
    {
        $user ??= $this->currentUser->get();

        return match (true) {
            $object instanceof Issue => $this->issues->isVisible($object, $user),
            $object instanceof Journal => $this->issues->isVisible($object->issue, $user),
            $object instanceof Message => $this->auth->allowedTo('view_messages', $object->board->project, $user),
            $object instanceof News => null !== $object->project && $this->auth->allowedTo('view_news', $object->project, $user),
            $object instanceof Comment => null !== ($news = $this->commented($object)) && $this->isObjectVisible($news, $user),
            default => false,
        };
    }

    /** Redmine::Reaction.visible? */
    public function isVisible(object $object, ?User $user = null): bool
    {
        return $this->enabled() && $this->isObjectVisible($object, $user);
    }

    /** Redmine::Reaction.editable? */
    public function isEditable(object $object, ?User $user = null): bool
    {
        $user ??= $this->currentUser->get();
        $project = $this->projectOf($object);

        return $user->isLogged() && $this->isVisible($object, $user) && null !== $project && Project::STATUS_ACTIVE === $project->status;
    }

    /**
     * Reactable.preload_reaction_details: loads the details of several objects of the same type at once.
     *
     * @param list<object> $objects
     */
    public function preload(array $objects): void
    {
        if (!$this->enabled() || [] === $objects) {
            return;
        }
        $type = self::typeOf($objects[0]);
        if (null === $type) {
            return;
        }
        $ids = [];
        foreach ($objects as $o) {
            $ids[] = (int) $o->id;
            $this->details[$type.'#'.$o->id] = ['users' => [], 'user_reaction' => null];
        }
        $user = $this->currentUser->get();
        $qb = $this->em->createQueryBuilder()->select('r', 'u')->from(Reaction::class, 'r')->join('r.user', 'u')
            ->where('r.reactableType = :type')->andWhere('r.reactableId IN (:ids)')
            ->setParameter('type', $type)->setParameter('ids', $ids)
            ->orderBy('r.id', 'DESC');
        $this->applyUserVisibility($qb, $user);
        /** @var Reaction $r */
        foreach ($qb->getQuery()->getResult() as $r) {
            $key = $type.'#'.$r->reactableId;
            $this->details[$key]['users'][] = $r->user;
            if ($r->user->id === $user->id) {
                $this->details[$key]['user_reaction'] = $r;
            }
        }
    }

    /** @return array{users: list<User>, user_reaction: ?Reaction} Reaction::Detail (visible_users, user_reaction) */
    public function detail(object $object): array
    {
        $key = self::typeOf($object).'#'.$object->id;
        if (!isset($this->details[$key])) {
            $this->preload([$object]);
        }

        return $this->details[$key] ?? ['users' => [], 'user_reaction' => null];
    }

    /** Reaction.visible(user): reactions of users visible by $user (Principal.visible) */
    private function applyUserVisibility(\Doctrine\ORM\QueryBuilder $qb, User $user): void
    {
        if ($user->admin) {
            return;
        }
        $memberships = $this->auth->membershipsOf($user);
        $viewAllActive = false;
        if ([] !== $memberships) {
            foreach ($memberships as $m) {
                foreach ($m->getRoles() as $role) {
                    if ('all' === $role->usersVisibility) {
                        $viewAllActive = true;
                        break 2;
                    }
                }
            }
        } else {
            $viewAllActive = 'all' === $this->auth->builtinRole($user)->usersVisibility;
        }
        $qb->andWhere('u.status = :active')->setParameter('active', User::STATUS_ACTIVE);
        if (!$viewAllActive) {
            $projectIds = $this->auth->visibleProjectIds($user);
            $sub = [] === $projectIds ? '' : ' OR u.id IN (SELECT IDENTITY(m2.principal) FROM App\Entity\Member m2 WHERE IDENTITY(m2.project) IN (:visible_pids))';
            $qb->andWhere('u.id = :me'.$sub)->setParameter('me', (int) $user->id);
            if ([] !== $projectIds) {
                $qb->setParameter('visible_pids', $projectIds);
            }
        }
    }

    /** reactions.find_or_create_by!(user:) */
    public function react(object $object, User $user): Reaction
    {
        $type = self::typeOf($object) ?? throw new \InvalidArgumentException('Not reactable');
        $existing = $this->em->getRepository(Reaction::class)->findOneBy(['reactableType' => $type, 'reactableId' => $object->id, 'user' => $user]);
        if ($existing instanceof Reaction) {
            return $existing;
        }
        $r = new Reaction();
        $r->reactableType = $type;
        $r->reactableId = (int) $object->id;
        $r->user = $user;
        $this->em->persist($r);
        $this->em->flush();
        unset($this->details[$type.'#'.$object->id]);

        return $r;
    }

    /** reactions.by(user).find_by(id:)&.destroy */
    public function unreact(object $object, User $user, int $id): void
    {
        $type = self::typeOf($object);
        $r = $this->em->getRepository(Reaction::class)->findOneBy(['id' => $id, 'reactableType' => $type, 'reactableId' => $object->id, 'user' => $user]);
        if ($r instanceof Reaction) {
            $this->em->remove($r);
            $this->em->flush();
        }
        unset($this->details[$type.'#'.$object->id]);
    }

    /** has_many :reactions, dependent: :delete_all */
    public function deleteFor(string $type, array $ids): void
    {
        if ([] === $ids) {
            return;
        }
        $this->em->createQuery('DELETE FROM App\Entity\Reaction r WHERE r.reactableType = :t AND r.reactableId IN (:ids)')
            ->setParameter('t', $type)->setParameter('ids', array_map('intval', $ids))->execute();
    }

    /**
     * ReactionsHelper#render_api_reactions: null when reactions are not visible.
     *
     * @return list<array{user: array{id: int, name: string}}>|null
     */
    public function apiReactions(object $object): ?array
    {
        if (!$this->isVisible($object)) {
            return null;
        }

        return array_map(static fn (User $u) => ['user' => ['id' => (int) $u->id, 'name' => $u->getName()]], $this->detail($object)['users']);
    }

    /** ReactionsHelper#build_reaction_tooltip */
    public function tooltip(array $userNames, int $count): ?string
    {
        if (0 === $count) {
            return null;
        }
        $names = $userNames;
        $others = $count - \count($userNames);
        if ($others > 0) {
            $names[] = $this->i18n->l('reaction_text_x_other_users', ['count' => $others]);
        }

        return $this->toSentence($names);
    }

    /** Array#to_sentence(locale:) */
    public function toSentence(array $words): string
    {
        $n = \count($words);

        return match ($n) {
            0 => '',
            1 => (string) $words[0],
            2 => $words[0].$this->i18n->l('support.array.two_words_connector').$words[1],
            default => implode($this->i18n->l('support.array.words_connector'), \array_slice($words, 0, -1)).$this->i18n->l('support.array.last_word_connector').$words[$n - 1],
        };
    }
}
