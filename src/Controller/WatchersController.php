<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Entity\Group;
use App\Entity\Issue;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\User;
use App\Service\IssueService;
use App\Service\WatcherService;
use App\Twig\IssuesExtension;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's WatchersController: watch/unwatch any watchable, manage watchers of an object. */
final class WatchersController extends AbstractController
{
    private const int USERS_PER_PAGE = 100;

    /** @var list<object> */
    private array $watchables = [];
    private ?Project $project = null;
    /** @var list<Project> */
    private array $watchableProjects = [];

    public function __construct(
        private readonly WatcherService $watchers,
        private readonly IssueService $issues,
        private readonly IssuesExtension $helpers,
        private readonly ApiRenderer $api,
    ) {
    }

    #[Route('/watchers/watch', name: 'watch', methods: ['POST'])]
    public function watch(Request $request): Response
    {
        return $this->setWatcher($request, true);
    }

    #[Route('/watchers/watch', name: 'unwatch', methods: ['DELETE'])]
    public function unwatch(Request $request): Response
    {
        return $this->setWatcher($request, false);
    }

    private function setWatcher(Request $request, bool $watching): Response
    {
        $this->requireLogin();
        $this->findWatchables($request);
        if ([] === $this->watchables) {
            throw $this->createNotFoundException();
        }
        $user = $this->user();
        foreach ($this->watchables as $w) {
            $type = IssuesExtension::watchableClassName($w);
            $watching ? $this->watchers->add($type, (int) $w->id, $user) : $this->watchers->remove($type, (int) $w->id, $user);
        }
        $this->em->flush();
        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'javascript')) {
            return $this->js($this->setWatcherJs($user));
        }

        return $this->redirectToReferer($request, $watching ? 'Watcher added.' : 'Watcher removed.');
    }

    private function setWatcherJs(User $user): string
    {
        $selector = '.'.$this->helpers->watcherCss($this->watchables);
        $js = '$('.json_encode($selector).').each(function(){$(this).replaceWith('.self::enc($this->helpers->watcherLink($this->watchables, $user)).')});';
        $first = $this->watchables[0];
        $partial = self::enc($this->renderView('watchers/_watchers.html.twig', ['watched' => $first]));
        if ($first instanceof Issue) {
            $js .= '$(\'#watchers[data-watchable-id="'.$first->id.'"]\').html('.$partial.');';
        } else {
            $js .= "$('#watchers').html(".$partial.');';
        }

        return $js;
    }

    #[Route('/watchers/new', name: 'watchers_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->findProject2($request);
        $this->authorizeWatchers('add');
        if (!$request->isXmlHttpRequest() && !str_contains((string) $request->headers->get('Accept'), 'javascript')) {
            throw $this->createNotFoundException();
        }
        $html = $this->renderView('watchers/_new.html.twig', $this->newVars($request));

        return $this->js('$(\'#ajax-modal\').html('.self::enc($html).');showModal(\'ajax-modal\', \'400px\');$(\'#ajax-modal\').addClass(\'new-watcher\');');
    }

    #[Route('/watchers.{_format}', name: 'watchers_create', requirements: ['_format' => 'json|xml|js|html'], defaults: ['_format' => 'html'], methods: ['POST'])]
    #[Route('/issues/{object_id}/watchers.{_format}', name: 'issue_watchers_create', requirements: ['object_id' => '\d+', '_format' => 'json|xml|html'], defaults: ['_format' => 'html', 'object_type' => 'issue'], methods: ['POST'])]
    public function create(Request $request): Response
    {
        $isApi = $this->isApi($request);
        $this->findProject2($request);
        $this->authorizeWatchers('add');
        if ([] === $this->watchables) {
            throw $this->createNotFoundException();
        }
        foreach ($this->watchables as $w) {
            if (!$this->auth->allowedTo('add_'.$this->helpers->watchableType($w).'_watchers', $this->helpers->watchableProject($w))) {
                $this->deny();
            }
        }
        $userIds = [];
        if ($isApi) {
            $payload = ApiRenderer::payload($request);
            $userIds[] = $payload['user_id'] ?? null;
        } else {
            $w = $request->request->all('watcher');
            if ([] !== $w) {
                $userIds = (array) ($w['user_ids'] ?? $w['user_id'] ?? []);
            } else {
                $userIds[] = $request->request->get('user_id');
            }
        }
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        foreach ($this->assignableWatchers($userIds) as $u) {
            foreach ($this->watchables as $w) {
                if ($this->validWatcher($w, $u)) {
                    $this->watchers->add(IssuesExtension::watchableClassName($w), (int) $w->id, $u);
                }
            }
        }
        $this->em->flush();
        if ($isApi) {
            return $this->api->noContent();
        }
        if ($request->isXmlHttpRequest() || 'js' === $request->attributes->get('_format')) {
            $html = $this->renderView('watchers/_new.html.twig', $this->newVars($request));
            $js = '$(\'#ajax-modal\').html('.self::enc($html).');';
            if (1 === \count($this->watchables)) {
                $js .= $this->setWatcherJs($this->user());
            }

            return $this->js($js);
        }

        return $this->redirectToReferer($request, 'Watcher added.');
    }

    /** Adds watchers checkboxes to the new issue form */
    #[Route('/watchers/append', name: 'watchers_append', methods: ['POST'])]
    public function append(Request $request): Response
    {
        $this->findProject2($request);
        $this->authorizeWatchers('add');
        $w = $request->request->all('watcher');
        $ids = array_map('intval', (array) ($w['user_ids'] ?? [$w['user_id'] ?? null]));
        $users = $this->assignableWatchers(array_values(array_filter($ids)));
        if ([] === $users) {
            return new Response('');
        }
        $js = '';
        $boxes = '';
        foreach ($users as $u) {
            $js .= '$("#issue_watcher_user_ids_'.$u->id.'").remove();';
            $boxes .= '<label id="issue_watcher_user_ids_'.$u->id.'" class="floating"><input type="checkbox" name="issue[watcher_user_ids][]" value="'.$u->id.'" checked="checked"> '.htmlspecialchars($u->getName()).'</label>';
        }
        $js .= '$(\'#watchers_inputs\').append('.self::enc($boxes).');';

        return $this->js($js);
    }

    #[Route('/watchers.{_format}', name: 'watchers_destroy', requirements: ['_format' => 'json|xml|js|html'], defaults: ['_format' => 'html'], methods: ['DELETE'])]
    #[Route('/issues/{object_id}/watchers/{user_id}.{_format}', name: 'issue_watchers_destroy', requirements: ['object_id' => '\d+', 'user_id' => '\d+', '_format' => 'json|xml|html'], defaults: ['_format' => 'html', 'object_type' => 'issue'], methods: ['DELETE'])]
    public function destroy(Request $request): Response
    {
        $this->findProject2($request);
        $this->authorizeWatchers('delete');
        if ([] === $this->watchables) {
            throw $this->createNotFoundException();
        }
        foreach ($this->watchables as $w) {
            if (!$this->auth->allowedTo('delete_'.$this->helpers->watchableType($w).'_watchers', $this->helpers->watchableProject($w))) {
                $this->deny();
            }
        }
        $userId = $request->attributes->get('user_id') ?? $request->query->get('user_id') ?? $request->request->get('user_id');
        $user = $this->em->find(Principal::class, (int) $userId) ?? throw $this->createNotFoundException();
        foreach ($this->watchables as $w) {
            $this->watchers->remove(IssuesExtension::watchableClassName($w), (int) $w->id, $user);
        }
        $this->em->flush();
        if ($this->isApi($request)) {
            return $this->api->noContent();
        }
        if ($request->isXmlHttpRequest()) {
            return $this->js(1 === \count($this->watchables) ? $this->setWatcherJs($this->user()) : '');
        }

        return $this->redirectToReferer($request, 'Watcher removed.');
    }

    #[Route('/watchers/autocomplete_for_user', name: 'watchers_autocomplete_for_user', methods: ['GET'])]
    public function autocompleteForUser(Request $request): Response
    {
        $this->findProject2($request);
        $this->authorizeWatchers('add');
        $vars = $this->newVars($request);

        return $this->render('watchers/_users_for_watcher.html.twig', $vars);
    }

    #[Route('/watchers/autocomplete_for_mention', name: 'watchers_autocomplete_for_mention', methods: ['GET'])]
    public function autocompleteForMention(Request $request): Response
    {
        $this->findProject2($request);
        $this->authorizeWatchers('add');
        $q = trim((string) $request->query->get('q', ''));
        if ('' === $q && $this->project) {
            $users = array_slice($this->projectWatcherCandidates($this->project), 0, 100);
        } else {
            $users = array_slice($this->allWatcherCandidates($q), 0, 10);
        }
        $users = array_values(array_filter($users, static fn ($u) => $u instanceof User));
        $users = array_values(array_filter($users, fn (User $u) => '' === $q || $this->matches($u, $q)));
        if (1 === \count($this->watchables) && $this->watchables[0] instanceof Issue) {
            $users = array_values(array_filter($users, fn (User $u) => $this->issues->isVisible($this->watchables[0], $u)));
        }

        return new JsonResponse(array_map(static fn (User $u) => ['firstname' => $u->firstname, 'lastname' => $u->lastname, 'name' => $u->getName(), 'login' => $u->login], $users));
    }

    // ------------------------------------------------------------------------------------------

    private function newVars(Request $request): array
    {
        $offset = max(0, (int) $request->query->get('offset', 0));
        $q = trim((string) ($request->query->get('q') ?? $request->query->get('user_search') ?? ''));
        if ('' === $q) {
            if ($this->project) {
                $candidates = $this->projectWatcherCandidates($this->project);
            } elseif (\count($this->watchableProjects) > 1) {
                $candidates = [];
                foreach ($this->watchableProjects as $p) {
                    foreach ($this->projectWatcherCandidates($p) as $u) {
                        $candidates[$u->id] = $u;
                    }
                }
                $candidates = array_values($candidates);
            } else {
                $candidates = [];
            }
        } else {
            $candidates = $this->allWatcherCandidates($q);
        }
        if (1 === \count($this->watchables)) {
            $w = $this->watchables[0];
            $existing = array_map(static fn ($p) => $p->id, $this->watchers->watchers(IssuesExtension::watchableClassName($w), (int) $w->id));
            $candidates = array_values(array_filter($candidates, static fn ($u) => !\in_array($u->id, $existing, true)));
        }
        if ('' !== $q) {
            $candidates = array_values(array_filter($candidates, fn ($u) => $this->matches($u, $q)));
        }
        $candidates = array_values(array_filter($candidates, function ($u) {
            foreach ($this->watchables as $w) {
                if (!$this->validWatcher($w, $u)) {
                    return false;
                }
            }

            return true;
        }));
        $page = \array_slice($candidates, $offset, self::USERS_PER_PAGE);
        $more = \count($candidates) > $offset + self::USERS_PER_PAGE;

        return [
            'watchables' => $this->watchables,
            'users' => $page,
            'project' => $this->project,
            'more_users' => $more,
            'next_offset' => $offset + \count($page),
            'object_type' => [] !== $this->watchables ? $this->helpers->watchableType($this->watchables[0]) : null,
            'object_ids' => array_map(static fn ($w) => $w->id, $this->watchables),
            'q' => $q,
        ];
    }

    private function matches(Principal $u, string $q): bool
    {
        $q = mb_strtolower($q);
        foreach ([$u->login, $u->firstname, $u->lastname, $u->getName(), $u instanceof User ? (string) $u->getMail() : ''] as $s) {
            if (str_contains(mb_strtolower((string) $s), $q)) {
                return true;
            }
        }

        return false;
    }

    /** Principal.assignable_watchers: active users and groups (not builtin) @return list<Principal> */
    private function assignableWatchers(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->em->createQuery('SELECT p FROM App\Entity\Principal p WHERE p.id IN (:ids) AND p.status = 1 AND (p INSTANCE OF App\Entity\User OR p INSTANCE OF App\Entity\Group) AND p NOT INSTANCE OF App\Entity\AnonymousUser AND p NOT INSTANCE OF App\Entity\GroupBuiltin')
            ->setParameter('ids', $ids)->getResult();
    }

    /** @return list<Principal> */
    private function projectWatcherCandidates(Project $project): array
    {
        $members = array_values(array_filter($this->projects->members($project), static fn ($p) => ($p instanceof User && !$p instanceof \App\Entity\AnonymousUser) || ($p instanceof Group && !$p instanceof \App\Entity\GroupBuiltin)));

        return $this->sortPrincipals($members);
    }

    /** @return list<Principal> */
    private function allWatcherCandidates(string $q): array
    {
        $like = '%'.mb_strtolower($q).'%';
        $rows = $this->em->createQuery('SELECT p FROM App\Entity\Principal p WHERE p.status = 1 AND (p INSTANCE OF App\Entity\User OR p INSTANCE OF App\Entity\Group) AND p NOT INSTANCE OF App\Entity\AnonymousUser AND p NOT INSTANCE OF App\Entity\GroupBuiltin
            AND (LOWER(p.login) LIKE :q OR LOWER(p.firstname) LIKE :q OR LOWER(p.lastname) LIKE :q OR LOWER(CONCAT(p.firstname, CONCAT(\' \', p.lastname))) LIKE :q)')
            ->setParameter('q', $like)->getResult();

        return $this->sortPrincipals($rows);
    }

    /** @param list<Principal> $list @return list<Principal> */
    private function sortPrincipals(array $list): array
    {
        usort($list, static fn (Principal $a, Principal $b) => [$a instanceof Group ? 1 : 0, mb_strtolower($a->getName())] <=> [$b instanceof Group ? 1 : 0, mb_strtolower($b->getName())]);

        return $list;
    }

    /** Watchable#valid_watcher? (issues: the user must see the issue; groups always valid) */
    private function validWatcher(object $watchable, Principal $p): bool
    {
        if ($watchable instanceof Issue && $p instanceof User) {
            return $this->issues->isVisible($watchable, $p);
        }
        $project = $this->helpers->watchableProject($watchable);
        if ($p instanceof User && null !== $project) {
            return $this->auth->isProjectVisible($project, $p);
        }

        return true;
    }

    private function findProject2(Request $request): void
    {
        $type = $request->attributes->get('object_type') ?? $request->query->get('object_type') ?? $request->request->get('object_type');
        $id = $request->attributes->get('object_id') ?? ($request->query->has('object_id') ? $request->query->all()['object_id'] : null) ?? ($request->request->has('object_id') ? $request->request->all()['object_id'] : null);
        if (null !== $type && '' !== $type && null !== $id && '' !== $id) {
            $this->findWatchables($request);
            $projects = [];
            foreach ($this->watchables as $w) {
                $p = $this->helpers->watchableProject($w);
                if ($p) {
                    $projects[$p->id] = $p;
                }
            }
            $this->watchableProjects = array_values($projects);
            if (1 === \count($projects)) {
                $this->project = array_values($projects)[0];
            }
        } elseif ($pid = ($request->query->get('project_id') ?? $request->request->get('project_id'))) {
            $this->project = $this->findProject((string) $pid);
        }
    }

    private function authorizeWatchers(string $action): void
    {
        $perm = $action.'_'.([] !== $this->watchables ? $this->helpers->watchableType($this->watchables[0]) : 'issue').'_watchers';
        if ($this->project) {
            $this->authorize($perm, $this->project);
        } elseif ([] === $this->watchables) {
            $this->authorize($perm, null, true);
        }
    }

    private function findWatchables(Request $request): void
    {
        $type = (string) ($request->attributes->get('object_type') ?? $request->query->get('object_type') ?? $request->request->get('object_type'));
        $class = IssuesExtension::WATCHABLE_TYPES[$type] ?? null;
        if (null === $class) {
            $this->watchables = [];

            return;
        }
        $raw = $request->attributes->get('object_id') ?? ($request->query->has('object_id') ? $request->query->all()['object_id'] : ($request->request->has('object_id') ? $request->request->all()['object_id'] : null));
        $ids = array_values(array_filter(array_map('intval', (array) $raw)));
        if ([] === $ids) {
            $this->watchables = [];

            return;
        }
        $objects = $this->em->getRepository($class)->findBy(['id' => $ids], ['id' => 'ASC']);
        foreach ($objects as $o) {
            if (!$this->isWatchableVisible($o)) {
                $this->deny();
            }
        }
        $this->watchables = $objects;
    }

    private function isWatchableVisible(object $o): bool
    {
        if ($o instanceof Issue) {
            return $this->issues->isVisible($o);
        }
        $project = $this->helpers->watchableProject($o);
        if (null === $project) {
            return true;
        }
        $perm = match (true) {
            $o instanceof \App\Entity\WikiPage, $o instanceof \App\Entity\Wiki => 'view_wiki_pages',
            $o instanceof \App\Entity\Board, $o instanceof \App\Entity\Message => 'view_messages',
            $o instanceof \App\Entity\News => 'view_news',
            default => null,
        };

        return null === $perm ? $this->auth->isProjectVisible($project) : $this->auth->allowedTo($perm, $project);
    }

    private function redirectToReferer(Request $request, string $text): Response
    {
        $ref = (string) $request->headers->get('referer');
        if ('' !== $ref) {
            return $this->redirect($ref);
        }

        return new Response($text);
    }

    private static function enc(string $s): string
    {
        return (string) json_encode($s, \JSON_HEX_TAG | \JSON_UNESCAPED_UNICODE);
    }

    private function js(string $js): Response
    {
        return new Response($js, 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
    }
}
