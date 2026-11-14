<?php

declare(strict_types=1);

namespace App\Service\Scm\Http;

use App\Entity\Repository;
use App\I18n\Localizer;
use App\Security\AccessGuard;
use App\Security\Authorization;
use App\Service\Scm\RepositoryService;
use App\Service\Scm\ScmCommandFailed;
use App\Service\Scm\ScmNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Finds the repository an action works on and checks the user may do so (RepositoriesController#find_repository,
 * #find_project_repository and their authorize).
 */
final class RepositoryFinder
{
    private const string REV_PARAM_RE = '/\A[a-f0-9]*\z/i';

    public function __construct(
        private readonly RepositoryService $repos,
        private readonly EntityManagerInterface $em,
        private readonly AccessGuard $guard,
        private readonly Authorization $auth,
        private readonly Localizer $i18n,
    ) {
    }

    /** The repository with this id, which the user must be allowed to manage */
    public function manageable(int $id): Repository
    {
        $repository = $this->em->find(Repository::class, $id) ?? throw new NotFoundHttpException();
        $this->guard->findProject((string) $repository->project->id);
        $this->guard->authorize('manage_repository', $repository->project);

        return $repository;
    }

    /**
     * find_project_repository + authorize + rev/rev_to validation.
     *
     * @param ?string             $repositoryId the identifier (or id) of the repository; the main one when blank
     * @param string|list<string> $permission   one of them is enough
     * @param ?string             $rev          the revision of the route, else params[:rev], [:branch] or [:tag]
     *
     * @throws ScmNotFound when a revision is not a valid one
     */
    public function locate(string $projectId, ?string $repositoryId, string|array $permission, Request $request, ?string $rev = null, string $path = ''): RepositoryLocation
    {
        $project = $this->guard->findProject($projectId);
        if (null !== $repositoryId && '' !== $repositoryId) {
            $repository = $this->repos->findByIdentifierParam($project, $repositoryId);
        } else {
            $repository = $this->repos->defaultRepository($project) ?? ($this->repos->projectRepositories($project)[0] ?? null);
        }
        if (null === $repository || !$this->repos->adapterEnabled($repository)) {
            throw new NotFoundHttpException();
        }
        $allowed = false;
        foreach ((array) $permission as $perm) {
            if ($this->auth->allowedTo($perm, $project)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            $this->guard->deny();
        }
        $path = implode('/', array_filter(preg_split('#[/\\\\]#', $path) ?: [], static fn ($p) => '' !== trim($p)));
        $rev = trim((string) ($rev ?? $request->query->get('rev') ?? ''));
        // branch / tag selectors of the navigation form
        if ('' === $rev) {
            $rev = trim((string) ($request->query->get('branch') ?: $request->query->get('tag') ?: ''));
        }
        try {
            if ('' === $rev) {
                $rev = $this->repos->defaultBranch($repository);
            }
            $revTo = trim((string) ($request->query->get('rev_to') ?? ''));
            $revTo = '' === $revTo ? null : $revTo;
            $adapter = $this->repos->adapter($repository);
            foreach ([$rev, $revTo] as $r) {
                if (null !== $r && !preg_match(self::REV_PARAM_RE, $r) && !$adapter->isValidName($r)) {
                    throw new \InvalidArgumentException();
                }
            }
        } catch (\InvalidArgumentException) {
            // InvalidRevisionParam -> show_error_not_found
            throw new ScmNotFound();
        } catch (ScmCommandFailed $e) {
            throw new HttpException(500, $this->i18n->l('error_scm_command_failed', ['value' => $e->getMessage()]));
        }

        return new RepositoryLocation($project, $repository, $path, $rev, $revTo);
    }
}
