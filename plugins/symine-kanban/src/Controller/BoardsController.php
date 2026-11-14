<?php

declare(strict_types=1);

namespace Symine\Kanban\Controller;

use App\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symine\Kanban\Board\BoardAccess;
use Symine\Kanban\Board\BoardFactory;
use Symine\Kanban\Board\BoardFinder;
use Symine\Kanban\Board\BoardForm;
use Symine\Kanban\Board\BoardView;
use Symine\Kanban\Card\CardFilter;
use Symine\Kanban\Card\CardPositions;
use Symine\Kanban\Entity\Board;
use Symine\Kanban\Http\BoardValueResolver;
use Symine\Kanban\KanbanSchema;

/**
 * Kanban boards: the lists of boards, a board, and its settings. The Board of an action comes from the {id} of
 * its route (Symine\Kanban\Http\BoardValueResolver), which also checks that the user may see it.
 */
final class BoardsController extends AbstractController
{
    public function __construct(
        private readonly BoardFinder $finder,
        private readonly BoardAccess $access,
        private readonly BoardFactory $factory,
        private readonly BoardForm $form,
        private readonly BoardView $view,
        private readonly CardPositions $positions,
        private readonly KanbanSchema $schema,
    ) {
    }

    /** Every board the user may see, above the projects */
    #[Route('/kanban', name: 'kanban_index', methods: ['GET'])]
    public function index(): Response
    {
        if (!$this->access->mayViewAnyBoard()) {
            $this->deny();
        }

        return $this->render('@KanbanPlugin/boards/index.html.twig', [
            'project' => null,
            'boards' => $this->finder->all(),
            'can_create' => $this->access->canCreate(null),
            'main_menu_selected' => 'kanban',
            'page_title' => $this->l('label_kanban'),
            'body_css' => 'controller-kanban action-index',
        ]);
    }

    /** The boards of a project: straight to the first one when there is one */
    #[Route('/projects/{project_id}/kanban', name: 'project_kanban', methods: ['GET'])]
    public function project(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('view_kanban_boards', $project);
        $boards = $this->schema->isInstalled() ? $this->finder->forProject($project) : [];
        if ([] !== $boards && !$request->query->has('all')) {
            return $this->redirectToRoute('kanban_board_show', ['id' => $boards[0]->id]);
        }

        return $this->render('@KanbanPlugin/boards/index.html.twig', [
            'project' => $project,
            'boards' => $boards,
            'can_create' => $this->schema->isInstalled() && $this->access->canCreate($project),
            'installed' => $this->schema->isInstalled(),
            'main_menu_selected' => 'kanban',
            'page_title' => $this->l('label_kanban'),
            'body_css' => 'controller-kanban action-index',
        ]);
    }

    #[Route('/kanban/boards/new', name: 'kanban_board_new', methods: ['GET'], priority: 1)]
    #[Route('/kanban/boards', name: 'kanban_board_create', methods: ['POST'])]
    public function new(Request $request): Response
    {
        $projectId = $request->get('project_id');
        $project = null === $projectId || '' === $projectId ? null : $this->findProject((string) $projectId);
        if (!$this->schema->isInstalled()) {
            throw new NotFoundHttpException();
        }
        if (!$this->access->canCreate($project)) {
            $this->deny();
        }

        $board = $this->factory->blank($project, $this->user());
        $errors = [];
        if ($request->isMethod('POST')) {
            $errors = $this->form->create($board, $request, $this->user());
            if ([] === $errors) {
                $this->flashNotice('notice_successful_create');

                return $this->redirectToRoute('kanban_board_edit', ['id' => $board->id]);
            }
        }

        return $this->render('@KanbanPlugin/boards/new.html.twig', $this->form->view($board, $this->user(), $errors, $request) + [
            'main_menu_selected' => 'kanban',
            'page_title' => $this->l('label_kanban_board_new'),
            'body_css' => 'controller-kanban action-new',
        ], new Response('', [] === $errors ? 200 : 422));
    }

    #[Route('/kanban/boards/{id}', name: 'kanban_board_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Request $request, #[ValueResolver(BoardValueResolver::class)] Board $board): Response
    {
        return $this->render('@KanbanPlugin/boards/show.html.twig', $this->view->page($board, CardFilter::fromRequest($request), $this->user()) + [
            'main_menu_selected' => 'kanban',
            'page_title' => $board->name,
            'body_css' => 'controller-kanban action-show',
        ]);
    }

    /** The board without the page around it: what the page redraws when cards changed */
    #[Route('/kanban/boards/{id}/cards', name: 'kanban_board_cards', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function cards(Request $request, #[ValueResolver(BoardValueResolver::class)] Board $board): Response
    {
        $filter = CardFilter::fromRequest($request);
        $columnId = $request->query->get('column');
        if (null !== $columnId) {
            return $this->render('@KanbanPlugin/boards/_cards.html.twig', [
                'board' => $board,
                'cards' => $this->view->moreCards($board, (string) $columnId, $request->query->getInt('offset'), $filter, $this->user()),
            ] + $this->view->body($board, $filter, $this->user()));
        }

        return $this->render('@KanbanPlugin/boards/_board.html.twig', $this->view->body($board, $filter, $this->user()));
    }

    /** Whether the cards changed since the page was drawn: the page asks from time to time */
    #[Route('/kanban/boards/{id}/version', name: 'kanban_board_version', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function version(Request $request, #[ValueResolver(BoardValueResolver::class)] Board $board): Response
    {
        return new JsonResponse(['version' => $this->view->body($board, CardFilter::fromRequest($request), $this->user())['version']]);
    }

    #[Route('/kanban/boards/{id}/edit', name: 'kanban_board_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[Route('/kanban/boards/{id}', name: 'kanban_board_update', requirements: ['id' => '\d+'], methods: ['POST', 'PUT', 'PATCH'])]
    public function edit(Request $request, #[ValueResolver(BoardValueResolver::class)] Board $board): Response
    {
        if (!$this->access->canEdit($board)) {
            $this->deny();
        }
        $errors = [];
        if (!$request->isMethod('GET')) {
            $errors = $this->form->update($board, $request, $this->user());
            if ([] === $errors) {
                $this->flashNotice('notice_successful_update');

                return $request->request->has('continue')
                    ? $this->redirectToRoute('kanban_board_edit', ['id' => $board->id])
                    : $this->redirectToRoute('kanban_board_show', ['id' => $board->id]);
            }
        }

        return $this->render('@KanbanPlugin/boards/edit.html.twig', $this->form->view($board, $this->user(), $errors) + [
            'main_menu_selected' => 'kanban',
            'page_title' => $board->name,
            'body_css' => 'controller-kanban action-edit',
        ], new Response('', [] === $errors ? 200 : 422));
    }

    #[Route('/kanban/boards/{id}/copy', name: 'kanban_board_copy', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function copy(#[ValueResolver(BoardValueResolver::class)] Board $board): Response
    {
        if (!$this->access->canCreate($this->access->project($board))) {
            $this->deny();
        }
        $copy = $this->factory->copy($board, $this->user());
        $this->em->persist($copy);
        $this->em->flush();
        $this->flashNotice('notice_successful_create');

        return $this->redirectToRoute('kanban_board_edit', ['id' => $copy->id]);
    }

    #[Route('/kanban/boards/{id}', name: 'kanban_board_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(#[ValueResolver(BoardValueResolver::class)] Board $board): Response
    {
        if (!$this->access->canEdit($board)) {
            $this->deny();
        }
        $project = $this->access->project($board);
        $this->positions->clear($board);
        $this->em->remove($board);
        $this->em->flush();
        $this->flashNotice('notice_successful_delete');

        return null === $project
            ? $this->redirectToRoute('kanban_index')
            : $this->redirectToRoute('project_kanban', ['project_id' => $project->identifier, 'all' => 1]);
    }
}
