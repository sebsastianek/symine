<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Entity\Repository;
use App\Security\CurrentUser;
use App\Service\Scm\RepositoryService;
use App\Service\Scm\ScmConfiguration;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly RepositoryService $repos,
        private readonly CurrentUser $currentUser,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    #[Route('/sys/projects.{_format}', name: 'sys_projects', requirements: ['_format' => 'json|xml'], defaults: ['_format' => 'json'], methods: ['GET'])]
    #[Route('/sys/projects', name: 'sys_projects_html', methods: ['GET'])]
    public function projects(Request $request): Response
    {
        if (null !== ($denied = $this->checkEnabled($request))) {
            return $denied;
        }
        // extra_info attribute from repository breaks activeresource client
        $out = [];
        foreach ($this->activeProjectsWithRepositoryModule('p.identifier ASC') as $p) {
            $repository = $this->repos->defaultRepository($p);
            $out[] = [
                'id' => $p->id, 'name' => $p->name, 'is_public' => $p->isPublic, 'identifier' => $p->identifier, 'status' => $p->status,
                'repository' => null === $repository ? null : ['id' => $repository->id, 'url' => $repository->url],
            ];
        }

        return new JsonResponse($out);
    }

    #[Route('/sys/projects/{id}/repository.{_format}', name: 'sys_create_project_repository', requirements: ['_format' => 'json|xml'], defaults: ['_format' => 'json'], methods: ['POST'])]
    #[Route('/sys/projects/{id}/repository', name: 'sys_create_project_repository_html', methods: ['POST'])]
    public function createProjectRepository(Request $request, string $id): Response
    {
        if (null !== ($denied = $this->checkEnabled($request))) {
            return $denied;
        }
        $project = ctype_digit($id) ? $this->em->find(Project::class, (int) $id) : $this->em->getRepository(Project::class)->findOneBy(['identifier' => $id]);
        if (null === $project) {
            return new Response('', 404);
        }
        if (null !== $this->repos->defaultRepository($project)) {
            return new Response('', 409);
        }
        $this->logger?->info(\sprintf('Repository for %s was reported to be created by %s.', $project->name, $request->getClientIp()));
        $scm = ScmConfiguration::normalizeName((string) $this->param($request, 'vendor'));
        if (null === $scm) {
            return new Response('', 422);
        }
        $repository = $this->repos->build($scm);
        $repository->project = $project;
        if (!$this->repos->adapterEnabled($repository)) {
            return new Response('', 422);
        }
        $attrs = (array) ($this->param($request, 'repository') ?? []);
        foreach (['identifier', 'login', 'password', 'path_encoding', 'log_encoding', 'is_default', 'url', 'root_url'] as $attr) {
            if (!\array_key_exists($attr, $attrs)) {
                continue;
            }
            $v = null === $attrs[$attr] ? null : (string) $attrs[$attr];
            match ($attr) {
                'identifier' => $repository->identifier = trim((string) $v),
                'login' => $repository->login = $v,
                'password' => $repository->password = $v,
                'path_encoding' => $repository->pathEncoding = '' === (string) $v ? null : $v,
                'log_encoding' => $repository->logEncoding = '' === (string) $v ? null : $v,
                'is_default' => $repository->isDefault = \in_array($v, ['1', 'true'], true),
                'url' => $repository->url = trim((string) $v),
                'root_url' => 'Cvs' === $scm ? $repository->rootUrl = trim((string) $v) : null,
            };
        }
        if ([] !== $this->repos->validate($repository, true)) {
            return new Response('', 422);
        }
        $this->repos->save($repository);
        $key = 'repository-'.strtolower($scm);

        return new JsonResponse([$key => ['id' => $repository->id, 'url' => $repository->url]], 201);
    }

    #[Route('/sys/fetch_changesets.{_format}', name: 'sys_fetch_changesets', requirements: ['_format' => 'json|xml'], defaults: ['_format' => 'html'], methods: ['GET', 'POST'])]
    #[Route('/sys/fetch_changesets', name: 'sys_fetch_changesets_html', methods: ['GET', 'POST'])]
    public function fetchChangesets(Request $request): Response
    {
        if (null !== ($denied = $this->checkEnabled($request))) {
            return $denied;
        }
        $id = $this->param($request, 'id');
        if (null !== $id && '' !== (string) $id) {
            $id = (string) $id;
            $projects = array_values(array_filter(
                $this->activeProjectsWithRepositoryModule('p.id ASC'),
                static fn (Project $p) => preg_match('/^\d*$/', $id) ? $p->id === (int) $id : $p->identifier === $id,
            ));
            if ([] === $projects) {
                return new Response('', 404);
            }
        } else {
            $projects = $this->activeProjectsWithRepositoryModule('p.id ASC');
        }
        foreach ($projects as $project) {
            foreach ($this->em->getRepository(Repository::class)->findBy(['project' => $project], ['id' => 'ASC']) as $repository) {
                if ($this->repos->adapterEnabled($repository)) {
                    $this->repos->fetchChangesets($repository);
                }
            }
        }

        return new Response('', 200);
    }

    /** before_action :check_enabled */
    private function checkEnabled(Request $request): ?Response
    {
        $this->currentUser->set(null);
        if (!$this->settings->bool('sys_api_enabled') || !hash_equals($this->settings->string('sys_api_key'), (string) $this->param($request, 'key'))) {
            return new Response('Access denied. Repository management WS is disabled or key is invalid.', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return null;
    }

    private function param(Request $request, string $name): mixed
    {
        if ($request->query->has($name)) {
            return \is_array($request->query->all()[$name] ?? null) ? $request->query->all($name) : $request->query->get($name);
        }
        if ($request->request->has($name)) {
            return \is_array($request->request->all()[$name] ?? null) ? $request->request->all($name) : $request->request->get($name);
        }
        $ct = (string) $request->headers->get('Content-Type');
        if (str_contains($ct, 'json')) {
            $data = json_decode((string) $request->getContent(), true);

            return \is_array($data) ? ($data[$name] ?? null) : null;
        }

        return null;
    }

    /** @return list<Project> Project.active.has_module(:repository) */
    private function activeProjectsWithRepositoryModule(string $order): array
    {
        return $this->em->createQuery("SELECT p FROM App\Entity\Project p WHERE p.status = 1 AND EXISTS (SELECT em.id FROM App\Entity\EnabledModule em WHERE em.project = p AND em.name = 'repository') ORDER BY ".$order)->getResult();
    }
}
