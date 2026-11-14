<?php

declare(strict_types=1);

namespace App\Service\Content\Forum;

use App\Entity\Board;
use App\Entity\Message;
use App\I18n\Localizer;
use App\Security\CurrentUser;
use App\Service\ForumService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What the page of a forum shows: its topics, sorted as the user asked (the choice is kept in the session)
 * and paginated, and the latest messages of its Atom feed.
 */
final class BoardTopics
{
    /** sortable columns (SortHelper#sort_update) */
    private const array SORTABLE = [
        'created_on' => 'm.id',
        'replies' => 'm.repliesCount',
        'updated_on' => 'sort_updated',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ForumService $forums,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly CurrentUser $currentUser,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** @return list<array<string, mixed>> the entries given to App\Service\AtomFeed: the latest messages of the forum */
    public function feedItems(Board $board): array
    {
        $messages = $this->em->createQuery('SELECT m, a FROM App\Entity\Message m LEFT JOIN m.author a WHERE m.board = :b ORDER BY m.id DESC')
            ->setParameter('b', $board)->setMaxResults(max(1, $this->settings->int('feeds_limit') ?: 15))->getResult();

        return array_map(fn (Message $m) => [
            'title' => $board->name.': '.$m->subject,
            'url' => $this->urls->generate('board_message_show', ['board_id' => $board->id, 'id' => $m->parent?->id ?? $m->id] + (null !== $m->parent ? ['r' => $m->id] : [])).(null !== $m->parent ? '#message-'.$m->id : ''),
            'updated' => $m->createdOn,
            'author' => $m->author,
            'content' => $m->content,
            'project' => $board->project,
            'object' => $m,
        ], $messages);
    }

    /** Number of topics of the forum */
    public function count(Board $board): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(m.id) FROM App\Entity\Message m WHERE m.board = :b AND m.parent IS NULL')->setParameter('b', $board)->getSingleScalarResult();
    }

    /**
     * @param array{offset: int, limit: int} $pager the pager of the topics (see count())
     *
     * @return array<string, mixed>
     */
    public function view(Board $board, Request $request, array $pager): array
    {
        // sort_init 'updated_on', 'desc' + sort_update
        $session = $request->hasSession() ? $request->getSession() : null;
        $sortParam = $request->query->get('sort');
        $criteria = $this->parseSort(\is_string($sortParam) ? $sortParam : (string) ($session?->get('boards_show_sort') ?? ''));
        if ([] === $criteria) {
            $criteria = [['updated_on', false]];
        }
        $session?->set('boards_show_sort', $this->sortToParam($criteria));

        $orders = ['m.sticky DESC'];
        foreach ($criteria as [$key, $asc]) {
            $orders[] = self::SORTABLE[$key].($asc ? ' ASC' : ' DESC');
        }
        $topics = $this->em->createQuery('SELECT m, a, lr, COALESCE(IDENTITY(m.lastReply), m.id) AS HIDDEN sort_updated FROM App\Entity\Message m LEFT JOIN m.author a LEFT JOIN m.lastReply lr WHERE m.board = :b AND m.parent IS NULL ORDER BY '.implode(', ', $orders))
            ->setParameter('b', $board)->setFirstResult($pager['offset'])->setMaxResults($pager['limit'])->getResult();

        $headers = [];
        foreach (['created_on' => 'field_created_on', 'replies' => 'label_reply_plural', 'updated_on' => 'label_message_last'] as $col => $caption) {
            $css = null;
            $icon = '';
            $order = 'asc';
            if ($criteria[0][0] === $col) {
                [$css, $icon, $order] = $criteria[0][1] ? ['sort asc icon icon-sorted-desc', 'angle-up', 'desc'] : ['sort desc icon icon-sorted-asc', 'angle-down', 'asc'];
            }
            $new = array_values(array_filter($criteria, static fn ($c) => $c[0] !== $col));
            array_unshift($new, [$col, 'asc' === $order]);
            $headers[] = [
                'caption' => $this->i18n->l($caption),
                'css' => $css,
                'icon' => $icon,
                'query' => array_merge($request->query->all(), ['sort' => $this->sortToParam(\array_slice($new, 0, 3))]),
            ];
        }
        $message = new Message();
        $message->board = $board;

        return [
            'project' => $board->project,
            'board' => $board,
            'ancestors' => $this->forums->ancestors($board),
            'topics' => $topics,
            'pager' => $pager,
            'sort_headers' => $headers,
            'message' => $message,
            'errors' => [],
            'can_sticky' => $this->forums->safeAttribute($message, 'sticky', $this->currentUser->get()),
        ];
    }

    /** @return list<array{0: string, 1: bool}> */
    private function parseSort(string $param): array
    {
        $out = [];
        foreach (explode(',', $param) as $part) {
            [$key, $dir] = array_pad(explode(':', trim($part), 2), 2, 'asc');
            if (isset(self::SORTABLE[$key]) && !\in_array($key, array_column($out, 0), true)) {
                $out[] = [$key, 'desc' !== $dir];
            }
        }

        return \array_slice($out, 0, 3);
    }

    /** @param list<array{0: string, 1: bool}> $criteria */
    private function sortToParam(array $criteria): string
    {
        return implode(',', array_map(static fn ($c) => $c[0].($c[1] ? '' : ':desc'), $criteria));
    }
}
