<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Board;
use App\Entity\Message;
use App\Entity\Project;
use App\Service\AtomFeed;
use App\Service\ForumService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of BoardsController (forums of a project) */
final class BoardsController extends AbstractController
{
    /** sortable columns (SortHelper#sort_update) */
    private const array SORTABLE = [
        'created_on' => 'm.id',
        'replies' => 'm.repliesCount',
        'updated_on' => 'sort_updated',
    ];

    public function __construct(
        private readonly ForumService $forums,
        private readonly AtomFeed $atom,
    ) {
    }

    #[Route('/projects/{project_id}/boards.{_format}', name: 'boards_index', requirements: ['_format' => 'html|atom'], defaults: ['_format' => 'html'], methods: ['GET'])]
    public function index(Request $request, string $project_id, string $_format = 'html'): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('view_messages', $project);
        $boards = $this->forums->boards($project);
        // show the board if there is only one
        if (1 === \count($boards)) {
            return $this->renderShow($request, $project, $boards[0], $_format, 'index');
        }
        if ('atom' === $_format) {
            throw $this->createNotFoundException();
        }

        return $this->render('boards/index.html.twig', [
            'project' => $project,
            'tree' => $this->forums->boardTree($boards),
            'main_menu_selected' => 'boards',
            'page_title' => $this->l('label_board_plural'),
            'body_css' => 'controller-boards action-index',
        ]);
    }

    #[Route('/projects/{project_id}/boards/new', name: 'board_new', methods: ['GET'])]
    public function new(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_boards', $project);
        $board = new Board();
        $board->project = $project;
        $this->forums->assignBoard($board, $request->query->all('board'));

        return $this->renderForm('boards/new.html.twig', $project, $board, []);
    }

    #[Route('/projects/{project_id}/boards', name: 'board_create', methods: ['POST'])]
    public function create(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_boards', $project);
        $board = new Board();
        $board->project = $project;
        $this->forums->assignBoard($board, $request->request->all('board'));
        $errors = $this->forums->validateBoard($board);
        if ([] !== $errors) {
            return $this->renderForm('boards/new.html.twig', $project, $board, $errors);
        }
        $this->forums->saveBoard($board);
        $this->flashNotice('notice_successful_create');

        return $this->redirectToSettings($project);
    }

    #[Route('/projects/{project_id}/boards/{id}.{_format}', name: 'board_show', requirements: ['id' => '\d+', '_format' => 'html|atom'], defaults: ['_format' => 'html'], methods: ['GET'])]
    public function show(Request $request, string $project_id, int $id, string $_format = 'html'): Response
    {
        $project = $this->findProject($project_id);
        $board = $this->findBoard($project, $id);
        $this->authorize('view_messages', $project);

        return $this->renderShow($request, $project, $board, $_format, 'show');
    }

    #[Route('/projects/{project_id}/boards/{id}/edit', name: 'board_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(string $project_id, int $id): Response
    {
        $project = $this->findProject($project_id);
        $board = $this->findBoard($project, $id);
        $this->authorize('manage_boards', $project);

        return $this->renderForm('boards/edit.html.twig', $project, $board, []);
    }

    #[Route('/projects/{project_id}/boards/{id}', name: 'board_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, string $project_id, int $id): Response
    {
        $project = $this->findProject($project_id);
        $board = $this->findBoard($project, $id);
        $this->authorize('manage_boards', $project);
        $before = ['position' => $board->position, 'parent_id' => $board->parent?->id];
        $this->forums->assignBoard($board, $request->request->all('board'));
        $errors = $this->forums->validateBoard($board, $before['parent_id']);
        $xhr = $request->isXmlHttpRequest() || 'js' === $request->getRequestFormat(null) || str_contains((string) $request->headers->get('Accept'), 'javascript');
        if ([] !== $errors) {
            if ($xhr) {
                return new Response('', 422);
            }
            $response = $this->renderForm('boards/edit.html.twig', $project, $board, $errors);
            // revert the invalid in-memory changes
            $this->em->refresh($board);

            return $response;
        }
        $this->forums->saveBoard($board, $before);
        if ($xhr) {
            return new Response('', 200);
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectToSettings($project);
    }

    #[Route('/projects/{project_id}/boards/{id}', name: 'board_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(string $project_id, int $id): Response
    {
        $project = $this->findProject($project_id);
        $board = $this->findBoard($project, $id);
        $this->authorize('manage_boards', $project);
        $this->forums->destroyBoard($board);
        $this->flashNotice('notice_successful_delete');

        return $this->redirectToSettings($project);
    }

    private function findBoard(Project $project, int $id): Board
    {
        $board = $this->em->getRepository(Board::class)->findOneBy(['id' => $id, 'project' => $project]);
        if (null === $board) {
            throw $this->createNotFoundException();
        }

        return $board;
    }

    private function redirectToSettings(Project $project): Response
    {
        try {
            return $this->redirectToRoute('project_settings', ['id' => $project->identifier, 'tab' => 'boards']);
        } catch (\Throwable) {
            return $this->redirectToRoute('boards_index', ['project_id' => $project->identifier]);
        }
    }

    private function renderForm(string $template, Project $project, Board $board, array $errors): Response
    {
        return $this->render($template, [
            'project' => $project,
            'board' => $board,
            'errors' => $errors,
            'parent_options' => $this->forums->boardOptions($this->forums->validParents($board)),
            'main_menu_selected' => 'settings',
            'page_title' => $this->l('label_board'),
            'body_css' => 'controller-boards action-'.(null === $board->id ? 'new' : 'edit'),
        ], new Response('', [] === $errors ? 200 : 422));
    }

    private function renderShow(Request $request, Project $project, Board $board, string $format, string $action): Response
    {
        if ('atom' === $format) {
            $messages = $this->em->createQuery('SELECT m, a FROM App\Entity\Message m LEFT JOIN m.author a WHERE m.board = :b ORDER BY m.id DESC')
                ->setParameter('b', $board)->setMaxResults(max(1, $this->settings->int('feeds_limit') ?: 15))->getResult();
            $items = array_map(fn (Message $m) => [
                'title' => $board->name.': '.$m->subject,
                'url' => $this->generateUrl('board_message_show', ['board_id' => $board->id, 'id' => $m->parent?->id ?? $m->id] + (null !== $m->parent ? ['r' => $m->id] : [])).(null !== $m->parent ? '#message-'.$m->id : ''),
                'updated' => $m->createdOn,
                'author' => $m->author,
                'content' => $m->content,
                'project' => $project,
            ], $messages);

            return $this->atom->render($request, $project->name.': '.$board->name, $items, $project);
        }

        // sort_init 'updated_on', 'desc' + sort_update
        $session = $request->hasSession() ? $request->getSession() : null;
        $sortParam = $request->query->get('sort');
        $criteria = $this->parseSort(\is_string($sortParam) ? $sortParam : (string) ($session?->get('boards_show_sort') ?? ''));
        if ([] === $criteria) {
            $criteria = [['updated_on', false]];
        }
        $session?->set('boards_show_sort', $this->sortToParam($criteria));

        $count = (int) $this->em->createQuery('SELECT COUNT(m.id) FROM App\Entity\Message m WHERE m.board = :b AND m.parent IS NULL')->setParameter('b', $board)->getSingleScalarResult();
        $pager = $this->paginate($request, $count);
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
                'caption' => $this->l($caption),
                'css' => $css,
                'icon' => $icon,
                'query' => array_merge($request->query->all(), ['sort' => $this->sortToParam(\array_slice($new, 0, 3))]),
            ];
        }
        $message = new Message();
        $message->board = $board;

        return $this->render('boards/show.html.twig', [
            'project' => $project,
            'board' => $board,
            'ancestors' => $this->forums->ancestors($board),
            'topics' => $topics,
            'pager' => $pager,
            'sort_headers' => $headers,
            'message' => $message,
            'errors' => [],
            'can_sticky' => $this->forums->safeAttribute($message, 'sticky', $this->user()),
            'main_menu_selected' => 'boards',
            'page_title' => $board->name,
            'body_css' => 'controller-boards action-'.$action,
        ]);
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

    private function sortToParam(array $criteria): string
    {
        return implode(',', array_map(static fn ($c) => $c[0].($c[1] ? '' : ':desc'), $criteria));
    }
}
