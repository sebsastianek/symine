<?php

declare(strict_types=1);

namespace App\Service\Scm\Sys;

use App\Entity\Project;
use App\Entity\Repository;
use App\Service\Scm\RepositoryService;
use App\Service\Scm\ScmConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The operations of the repository management web service used by reposman.rb / Redmine.pm (SysController):
 * listing the projects that have the repository module, registering the repository created for a project and
 * fetching the changesets.
 */
final class SysApi
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RepositoryService $repos,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The active projects with the repository module and their main repository.
     *
     * @return list<array<string, mixed>>
     */
    public function projects(): array
    {
        // extra_info attribute from repository breaks activeresource client
        $out = [];
        foreach ($this->activeProjectsWithRepositoryModule('p.identifier ASC') as $p) {
            $repository = $this->repos->defaultRepository($p);
            $out[] = [
                'id' => $p->id, 'name' => $p->name, 'is_public' => $p->isPublic, 'identifier' => $p->identifier, 'status' => $p->status,
                'repository' => null === $repository ? null : ['id' => $repository->id, 'url' => $repository->url],
            ];
        }

        return $out;
    }

    /** The project with this id or identifier, whatever its status */
    public function findProject(string $id): ?Project
    {
        return ctype_digit($id) ? $this->em->find(Project::class, (int) $id) : $this->em->getRepository(Project::class)->findOneBy(['identifier' => $id]);
    }

    public function hasRepository(Project $project): bool
    {
        return null !== $this->repos->defaultRepository($project);
    }

    /**
     * Registers the repository reported to be created for the project: params[:vendor] is its SCM,
     * params[:repository] its attributes.
     *
     * @return array<string, array{id: ?int, url: string}>|null the repository as answered to the client
     *                                                         ("repository-<scm>"); null when it cannot be accepted
     */
    public function createRepository(Project $project, Request $request): ?array
    {
        $this->logger?->info(\sprintf('Repository for %s was reported to be created by %s.', $project->name, $request->getClientIp()));
        $scm = ScmConfiguration::normalizeName((string) SysApiRequest::param($request, 'vendor'));
        if (null === $scm) {
            return null;
        }
        $repository = $this->repos->build($scm);
        $repository->project = $project;
        if (!$this->repos->adapterEnabled($repository)) {
            return null;
        }
        $attrs = (array) (SysApiRequest::param($request, 'repository') ?? []);
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
        // Repository::Git: safe_attributes 'report_last_commit' (report_last_commit= stores the value in extra_info,
        // anything but "0" enables it)
        if (\array_key_exists('report_last_commit', $attrs) && $this->repos->isGit($repository)) {
            $this->repos->mergeExtraInfo($repository, ['extra_report_last_commit' => '0' === (string) $attrs['report_last_commit'] ? '0' : '1']);
        }
        if ([] !== $this->repos->validate($repository, true)) {
            return null;
        }
        $this->repos->save($repository);

        return ['repository-'.strtolower($scm) => ['id' => $repository->id, 'url' => $repository->url]];
    }

    /**
     * Fetches the changesets of the repositories of the project of params[:id] (id or identifier), or of every
     * active project with the repository module when the parameter is not given.
     *
     * @return bool false when there is no such project
     */
    public function fetchChangesets(Request $request): bool
    {
        $id = SysApiRequest::param($request, 'id');
        // "if params[:id]": an empty id is a (not found) id, only a missing parameter means all projects
        if (null !== $id) {
            $id = (string) $id;
            $projects = array_values(array_filter(
                $this->activeProjectsWithRepositoryModule('p.id ASC'),
                static fn (Project $p) => preg_match('/^\d*$/', $id) ? $p->id === (int) $id : $p->identifier === $id,
            ));
            if ([] === $projects) {
                return false;
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

        return true;
    }

    /** @return list<Project> Project.active.has_module(:repository) */
    private function activeProjectsWithRepositoryModule(string $order): array
    {
        return $this->em->createQuery("SELECT p FROM App\Entity\Project p WHERE p.status = 1 AND EXISTS (SELECT em.id FROM App\Entity\EnabledModule em WHERE em.project = p AND em.name = 'repository') ORDER BY ".$order)->getResult();
    }
}
