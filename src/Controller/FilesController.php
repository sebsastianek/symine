<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Api\FileApiPresenter;
use App\Service\Project\FileForm;
use App\Service\Project\ProjectFilesView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of FilesController (files_controller.rb): files attached to the project or its versions. */
final class FilesController extends AbstractController
{
    public function __construct(
        private readonly ApiRenderer $api,
        private readonly FileApiPresenter $presenter,
        private readonly ProjectFilesView $files,
        private readonly FileForm $form,
    ) {
    }

    #[Route('/projects/{project_id}/files.{_format}', name: 'project_files', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'])]
    public function index(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('view_files', $project);

        if ($this->isApi($request)) {
            $containers = $this->files->containers($project, (string) $request->query->get('sort', ''));

            return $this->api->render($request, ['files' => $this->presenter->files($containers, $request->getSchemeAndHttpHost().$request->getBaseUrl())]);
        }

        return $this->render('files/index.html.twig', $this->files->view($project, $request) + [
            'main_menu_selected' => 'files', 'page_title' => $this->l('label_attachment_plural'),
            'body_css' => 'controller-files action-index',
        ]);
    }

    #[Route('/projects/{project_id}/files/new', name: 'file_new', methods: ['GET'])]
    public function new(string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_files', $project);

        return $this->render('files/new.html.twig', $this->form->view($project) + [
            'main_menu_selected' => 'files', 'page_title' => $this->l('label_attachment_new'),
            'body_css' => 'controller-files action-new',
        ]);
    }

    #[Route('/projects/{project_id}/files.{_format}', name: 'file_create', methods: ['POST'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'])]
    public function create(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_files', $project);
        $isApi = $this->isApi($request);
        [$added, $errors] = $this->form->submit($project, $request);
        if ([] !== $added) {
            if ($isApi) {
                return $this->api->noContent();
            }
            if ([] !== $errors) {
                $this->addFlash('warning', $this->l('warning_attachments_not_saved', ['count' => \count($errors)]));
            }
            $this->addFlash('notice', $this->l('label_file_added'));

            return $this->redirectToRoute('project_files', ['project_id' => $project->identifier]);
        }
        if ($isApi) {
            return new Response('', 400);
        }
        $this->addFlash('error', $this->l('label_attachment').' '.$this->l('activerecord.errors.messages.invalid'));

        return $this->render('files/new.html.twig', $this->form->view($project) + [
            'main_menu_selected' => 'files', 'page_title' => $this->l('label_attachment_new'),
            'body_css' => 'controller-files action-new',
        ], new Response(null, 422));
    }
}
