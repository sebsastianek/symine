<?php

declare(strict_types=1);

namespace Symine\Hello\Controller;

use App\Controller\AbstractController;
use App\Entity\Project;
use App\Plugin\Hook\Hooks;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symine\Hello\Entity\Greeting;
use Symine\Hello\HelloPlugin;

/** The "Greetings" project tab (project_menu item "hello", permissions of the "hello" module) */
final class HelloController extends AbstractController
{
    #[Route('/projects/{project_id}/hello', name: 'hello_index', methods: ['GET'])]
    public function index(string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('view_hello_greetings', $project);

        return $this->renderIndex($project);
    }

    #[Route('/projects/{project_id}/hello', name: 'hello_create', methods: ['POST'])]
    public function create(Request $request, string $project_id, Hooks $hooks): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('add_hello_greetings', $project);
        $greeting = new Greeting();
        $greeting->project = $project;
        $greeting->author = $this->user();
        $greeting->message = mb_substr(trim((string) ($request->request->all('greeting')['message'] ?? '')), 0, 255);
        if ('' === $greeting->message) {
            return $this->renderIndex($project, [$this->l('field_hello_message').' '.$this->l('activerecord.errors.messages.blank')], 422);
        }
        // plugins can define their own hooks too
        $hooks->call('controller_hello_create_before_save', ['greeting' => $greeting, 'project' => $project]);
        $this->em->persist($greeting);
        $this->em->flush();
        $this->flashNotice('notice_successful_create');

        return $this->redirectToRoute('hello_index', ['project_id' => $project->identifier]);
    }

    /** @param list<string> $errors */
    private function renderIndex(Project $project, array $errors = [], int $status = 200): Response
    {
        $greetings = $this->em->createQuery('SELECT g, a FROM Symine\Hello\Entity\Greeting g JOIN g.author a WHERE g.project = :p ORDER BY g.createdOn DESC, g.id DESC')
            ->setParameter('p', $project)->getResult();
        $settings = $this->settings->hash('plugin_'.HelloPlugin::ID);

        return $this->render('@HelloPlugin/hello/index.html.twig', [
            'project' => $project,
            'greetings' => $greetings,
            'errors' => $errors,
            'greeting_word' => (string) ($settings['greeting'] ?? 'Hello'),
            'main_menu_selected' => 'hello',
            'page_title' => $this->l('label_hello_greeting_plural'),
            'body_css' => 'controller-hello action-index',
        ], new Response('', $status));
    }
}
