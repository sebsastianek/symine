<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AtomFeed;
use App\Service\Content\Forum\BoardForm;
use App\Service\Content\Forum\BoardTopics;
use App\Service\Content\Forum\ForumFinder;
use App\Service\ForumService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of BoardsController (forums of a project) */
final class BoardsController extends AbstractController
{
    public function __construct(
        private readonly ForumService $forums,
        private readonly AtomFeed $atom,
        private readonly ForumFinder $finder,
        private readonly BoardForm $form,
        private readonly BoardTopics $topics,
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
            $board = $boards[0];
            if ('atom' === $_format) {
                return $this->atom->render($request, $project->name.': '.$board->name, $this->topics->feedItems($board), $project);
            }

            return $this->render('boards/show.html.twig', $this->topics->view($board, $request, $this->paginate($request, $this->topics->count($board))) + [
                'main_menu_selected' => 'boards',
                'page_title' => $board->name,
                'body_css' => 'controller-boards action-index',
            ]);
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

        return $this->render('boards/new.html.twig', $this->form->view($this->form->build($project, $request)) + [
            'main_menu_selected' => 'settings',
            'page_title' => $this->l('label_board'),
            'body_css' => 'controller-boards action-new',
        ]);
    }

    #[Route('/projects/{project_id}/boards', name: 'board_create', methods: ['POST'])]
    public function create(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_boards', $project);
        [$board, $errors] = $this->form->create($project, $request);
        if ([] !== $errors) {
            return $this->render('boards/new.html.twig', $this->form->view($board, $errors) + [
                'main_menu_selected' => 'settings',
                'page_title' => $this->l('label_board'),
                'body_css' => 'controller-boards action-new',
            ], new Response('', 422));
        }
        $this->flashNotice('notice_successful_create');

        return $this->redirect($this->form->settingsUrl($project));
    }

    #[Route('/projects/{project_id}/boards/{id}.{_format}', name: 'board_show', requirements: ['id' => '\d+', '_format' => 'html|atom'], defaults: ['_format' => 'html'], methods: ['GET'])]
    public function show(Request $request, string $project_id, int $id, string $_format = 'html'): Response
    {
        $project = $this->findProject($project_id);
        $board = $this->finder->boardOf($project, $id);
        $this->authorize('view_messages', $project);
        if ('atom' === $_format) {
            return $this->atom->render($request, $project->name.': '.$board->name, $this->topics->feedItems($board), $project);
        }

        return $this->render('boards/show.html.twig', $this->topics->view($board, $request, $this->paginate($request, $this->topics->count($board))) + [
            'main_menu_selected' => 'boards',
            'page_title' => $board->name,
            'body_css' => 'controller-boards action-show',
        ]);
    }

    #[Route('/projects/{project_id}/boards/{id}/edit', name: 'board_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(string $project_id, int $id): Response
    {
        $project = $this->findProject($project_id);
        $board = $this->finder->boardOf($project, $id);
        $this->authorize('manage_boards', $project);

        return $this->render('boards/edit.html.twig', $this->form->view($board) + [
            'main_menu_selected' => 'settings',
            'page_title' => $this->l('label_board'),
            'body_css' => 'controller-boards action-edit',
        ]);
    }

    #[Route('/projects/{project_id}/boards/{id}', name: 'board_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, string $project_id, int $id): Response
    {
        $project = $this->findProject($project_id);
        $board = $this->finder->boardOf($project, $id);
        $this->authorize('manage_boards', $project);
        $errors = $this->form->update($board, $request);
        $xhr = $request->isXmlHttpRequest() || 'js' === $request->getRequestFormat(null) || str_contains((string) $request->headers->get('Accept'), 'javascript');
        if ($xhr) {
            return new Response('', [] === $errors ? 200 : 422);
        }
        if ([] !== $errors) {
            $response = $this->render('boards/edit.html.twig', $this->form->view($board, $errors) + [
                'main_menu_selected' => 'settings',
                'page_title' => $this->l('label_board'),
                'body_css' => 'controller-boards action-edit',
            ], new Response('', 422));
            // revert the invalid in-memory changes
            $this->form->revert($board);

            return $response;
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirect($this->form->settingsUrl($project));
    }

    #[Route('/projects/{project_id}/boards/{id}', name: 'board_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(string $project_id, int $id): Response
    {
        $project = $this->findProject($project_id);
        $board = $this->finder->boardOf($project, $id);
        $this->authorize('manage_boards', $project);
        $this->forums->destroyBoard($board);
        $this->flashNotice('notice_successful_delete');

        return $this->redirect($this->form->settingsUrl($project));
    }
}
