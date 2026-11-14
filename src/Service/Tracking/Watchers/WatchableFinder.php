<?php

declare(strict_types=1);

namespace App\Service\Tracking\Watchers;

use App\Entity\Board;
use App\Entity\Issue;
use App\Entity\Message;
use App\Entity\News;
use App\Entity\Wiki;
use App\Entity\WikiPage;
use App\Security\AccessGuard;
use App\Security\Authorization;
use App\Service\IssueService;
use App\Twig\IssuesExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads what a watchers request names with object_type / object_id (WatchersController#find_watchables and
 * #find_project): the objects must be visible to the user.
 */
final class WatchableFinder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessGuard $guard,
        private readonly Authorization $auth,
        private readonly IssueService $issues,
        private readonly IssuesExtension $helpers,
    ) {
    }

    /**
     * The watched objects of a watch / unwatch request: 404 when there is none, 403 when one is not visible.
     *
     * @return non-empty-list<object>
     */
    public function find(Request $request): array
    {
        $watchables = $this->watchables($request);
        if ([] === $watchables) {
            throw new NotFoundHttpException();
        }

        return $watchables;
    }

    /** The objects and the project of a request that manages watchers (403 when one is not visible) */
    public function context(Request $request): WatcherContext
    {
        $type = $request->attributes->get('object_type') ?? $request->query->get('object_type') ?? $request->request->get('object_type');
        $id = $request->attributes->get('object_id') ?? ($request->query->has('object_id') ? $request->query->all()['object_id'] : null) ?? ($request->request->has('object_id') ? $request->request->all()['object_id'] : null);
        if (null !== $type && '' !== $type && null !== $id && '' !== $id) {
            $watchables = $this->watchables($request);
            $projects = [];
            foreach ($watchables as $w) {
                $p = $this->helpers->watchableProject($w);
                if ($p) {
                    $projects[$p->id] = $p;
                }
            }
            $projects = array_values($projects);

            return new WatcherContext($watchables, 1 === \count($projects) ? $projects[0] : null, $projects);
        }
        if ($pid = ($request->query->get('project_id') ?? $request->request->get('project_id'))) {
            return new WatcherContext([], $this->guard->findProject((string) $pid));
        }

        return new WatcherContext();
    }

    /** @return list<object> */
    private function watchables(Request $request): array
    {
        $type = (string) ($request->attributes->get('object_type') ?? $request->query->get('object_type') ?? $request->request->get('object_type'));
        $class = IssuesExtension::WATCHABLE_TYPES[$type] ?? null;
        if (null === $class) {
            return [];
        }
        $raw = $request->attributes->get('object_id') ?? ($request->query->has('object_id') ? $request->query->all()['object_id'] : ($request->request->has('object_id') ? $request->request->all()['object_id'] : null));
        $ids = array_values(array_filter(array_map('intval', (array) $raw)));
        if ([] === $ids) {
            return [];
        }
        $objects = $this->em->getRepository($class)->findBy(['id' => $ids], ['id' => 'ASC']);
        foreach ($objects as $o) {
            if (!$this->isVisible($o)) {
                $this->guard->deny();
            }
        }

        return $objects;
    }

    private function isVisible(object $o): bool
    {
        if ($o instanceof Issue) {
            return $this->issues->isVisible($o);
        }
        $project = $this->helpers->watchableProject($o);
        if (null === $project) {
            return true;
        }
        $perm = match (true) {
            $o instanceof WikiPage, $o instanceof Wiki => 'view_wiki_pages',
            $o instanceof Board, $o instanceof Message => 'view_messages',
            $o instanceof News => 'view_news',
            default => null,
        };

        return null === $perm ? $this->auth->isProjectVisible($project) : $this->auth->allowedTo($perm, $project);
    }
}
