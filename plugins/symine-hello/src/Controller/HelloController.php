<?php

declare(strict_types=1);

namespace Symine\Hello\Controller;

use App\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symine\Hello\Greeting\GreetingBoard;

/**
 * The "Greetings" project tab (project_menu item "hello", permissions of the "hello" module).
 * The two routes share one action: it checks access, hands the request to GreetingBoard and chooses the response.
 */
final class HelloController extends AbstractController
{
    public function __construct(private readonly GreetingBoard $board)
    {
    }

    #[Route('/projects/{project_id}/hello', name: 'hello_index', methods: ['GET'])]
    #[Route('/projects/{project_id}/hello', name: 'hello_create', methods: ['POST'])]
    public function index(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize($request->isMethod('POST') ? 'add_hello_greetings' : 'view_hello_greetings', $project);

        $errors = [];
        if ($request->isMethod('POST')) {
            $errors = $this->board->post($project, $this->user(), $request);
            if ([] === $errors) {
                $this->flashNotice('notice_successful_create');

                return $this->redirectToRoute('hello_index', ['project_id' => $project->identifier]);
            }
        }

        return $this->render('@HelloPlugin/hello/index.html.twig', $this->board->view($project, $errors) + [
            'main_menu_selected' => 'hello',
            'page_title' => $this->l('label_hello_greeting_plural'),
            'body_css' => 'controller-hello action-index',
        ], new Response('', [] === $errors ? 200 : 422));
    }
}
