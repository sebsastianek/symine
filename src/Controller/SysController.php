<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Scm\Sys\SysApi;
use App\Service\Scm\Sys\SysApiAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController as BaseController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Port of SysController: repository management web service used by reposman.rb / Redmine.pm,
 * enabled by Setting sys_api_enabled and authenticated by the "key" parameter (Setting sys_api_key).
 */
final class SysController extends BaseController
{
    public function __construct(
        private readonly SysApiAccess $access,
        private readonly SysApi $sys,
    ) {
    }

    #[Route('/sys/projects.{_format}', name: 'sys_projects', requirements: ['_format' => 'json|xml'], defaults: ['_format' => 'json'], methods: ['GET'])]
    #[Route('/sys/projects', name: 'sys_projects_html', methods: ['GET'])]
    public function projects(Request $request): Response
    {
        if (!$this->access->granted($request)) {
            return new Response(SysApiAccess::DENIED, 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return new JsonResponse($this->sys->projects());
    }

    #[Route('/sys/projects/{id}/repository.{_format}', name: 'sys_create_project_repository', requirements: ['_format' => 'json|xml'], defaults: ['_format' => 'json'], methods: ['POST'])]
    #[Route('/sys/projects/{id}/repository', name: 'sys_create_project_repository_html', methods: ['POST'])]
    public function createProjectRepository(Request $request, string $id): Response
    {
        if (!$this->access->granted($request)) {
            return new Response(SysApiAccess::DENIED, 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $project = $this->sys->findProject($id);
        if (null === $project) {
            return new Response('', 404);
        }
        if ($this->sys->hasRepository($project)) {
            return new Response('', 409);
        }
        $created = $this->sys->createRepository($project, $request);

        return null === $created ? new Response('', 422) : new JsonResponse($created, 201);
    }

    #[Route('/sys/fetch_changesets.{_format}', name: 'sys_fetch_changesets', requirements: ['_format' => 'json|xml'], defaults: ['_format' => 'html'], methods: ['GET', 'POST'])]
    #[Route('/sys/fetch_changesets', name: 'sys_fetch_changesets_html', methods: ['GET', 'POST'])]
    public function fetchChangesets(Request $request): Response
    {
        if (!$this->access->granted($request)) {
            return new Response(SysApiAccess::DENIED, 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return new Response('', $this->sys->fetchChangesets($request) ? 200 : 404);
    }
}
