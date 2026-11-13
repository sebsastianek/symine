<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Entity\Token;
use App\Entity\User;
use App\Entity\UserPreference;
use App\Service\Activity\ActivityEvent;
use App\Service\Activity\ActivityFetcher;
use App\Service\AtomFeed;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's ActivitiesController. */
final class ActivitiesController extends AbstractController
{
    public function __construct(
        private readonly ActivityFetcher $fetcher,
        private readonly AtomFeed $atom,
    ) {
    }

    #[Route('/activity.{_format}', name: 'activity', defaults: ['_format' => 'html'], requirements: ['_format' => 'html|atom'], methods: ['GET'])]
    public function index(Request $request): Response
    {
        // /search?id=... style optional project parameter (find_optional_project_by_id)
        $id = $request->query->get('id');

        return $this->doIndex($request, null !== $id && '' !== $id ? $this->findProject((string) $id) : null);
    }

    #[Route('/projects/{id}/activity.{_format}', name: 'project_activity', defaults: ['_format' => 'html'], requirements: ['_format' => 'html|atom'], methods: ['GET'])]
    public function project(Request $request, string $id): Response
    {
        return $this->doIndex($request, $this->findProject($id));
    }

    private function doIndex(Request $request, ?Project $project): Response
    {
        $this->authorize('view_project', $project, null === $project);
        $user = $this->user();
        $days = max(1, $this->settings->int('activity_days_default') ?: 30);
        $today = $this->i18n->today();
        $dateTo = null;
        if ($from = $request->query->get('from')) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $from);
            if (false !== $d) {
                $dateTo = $d->modify('+1 day');
            }
        }
        $dateTo ??= $today->modify('+1 day');
        $dateFrom = $dateTo->modify("-$days days");
        $withSubprojects = $request->query->has('with_subprojects')
            ? '1' === $request->query->get('with_subprojects')
            : $this->settings->displaySubprojectsIssues();
        $author = null;
        if ('' !== (string) $request->query->get('user_id', '')) {
            $author = $this->em->getRepository(User::class)->find((int) $request->query->get('user_id'));
            if (!$author instanceof User || !$author->isActive() || !$author->isLogged()) {
                throw $this->createNotFoundException();
            }
        }

        $eventTypes = $this->fetcher->eventTypes($user, $project);
        $scope = array_values(array_filter($eventTypes, static fn (string $t) => $request->query->has('show_'.$t)));
        if ([] !== $scope) {
            if ('' !== (string) $request->query->get('submit', '') && $user->isLogged()) {
                $this->savePrefScope($user, $scope);
            }
        } elseif (null === $author) {
            $pref = $user->isLogged() ? (array) ($user->getPref()->get('activity_scope') ?? []) : [];
            $scope = array_values(array_intersect(array_map('strval', $pref), $eventTypes));
            if ([] === $scope) {
                $scope = $this->fetcher->defaultTypes();
            }
        } else {
            $scope = $eventTypes;
        }

        if ('atom' === $request->getRequestFormat()) {
            $events = $this->fetcher->events($user, $scope, $project, $withSubprojects, $author, null, null, max(1, $this->settings->int('feeds_limit') ?: 15));
            $title = $this->l('label_activity');
            if (null !== $author) {
                $title = $author->getName();
            } elseif (1 === \count($scope)) {
                $title = $this->l('label_'.self::singularize($scope[0]).'_plural');
            }
            $items = array_map(static fn (ActivityEvent $e) => [
                'title' => $e->title, 'url' => $e->url, 'updated' => $e->datetime, 'author' => $e->author instanceof User ? $e->author : null,
                'content' => $e->description, 'project' => $e->project,
            ], $events);

            return $this->atom->render($request, ($project?->name ?? $this->settings->appTitle()).': '.$title, $items, $project);
        }

        $events = $this->fetcher->events($user, $scope, $project, $withSubprojects, $author, $dateFrom, $dateTo);
        $tz = $this->i18n->userTimezone();
        $byDay = [];
        foreach ($events as $e) {
            $byDay[$e->datetime->setTimezone($tz)->format('Y-m-d')][] = $e;
        }
        krsort($byDay);
        $days_ = [];
        $nonWorking = array_map('intval', $this->settings->array('non_working_week_days'));
        foreach ($byDay as $day => $dayEvents) {
            $date = new \DateTimeImmutable($day);
            $days_[] = [
                'date' => $date,
                'today' => $date->format('Y-m-d') === $today->format('Y-m-d'),
                'non_working' => \in_array((int) $date->format('N'), $nonWorking, true),
                'events' => self::sortEvents($dayEvents),
            ];
        }

        $query = $request->query->all();
        $prevQuery = array_merge($query, ['from' => $dateTo->modify('-'.($days + 1).' days')->format('Y-m-d')]);
        $nextQuery = array_merge($query, ['from' => $dateTo->modify('+'.($days - 1).' days')->format('Y-m-d')]);
        $atomQuery = $query;
        unset($atomQuery['from']);
        $atomKey = $this->atomKey($user);
        if (null !== $atomKey) {
            $atomQuery['key'] = $atomKey;
        }
        $route = null === $project ? 'activity' : 'project_activity';
        $routeParams = null === $project ? [] : ['id' => $project->identifier];

        return $this->render('activities/index.html.twig', [
            'project' => $project,
            'author' => $author,
            'days' => $days,
            'date_to' => $dateTo,
            'date_from' => $dateFrom,
            'today' => $today,
            'event_days' => $days_,
            'event_types' => $eventTypes,
            'singular_types' => array_combine($eventTypes, array_map(self::singularize(...), $eventTypes)),
            'scope' => $scope,
            'with_subprojects' => $withSubprojects,
            'show_subprojects_option' => null !== $project && $this->hasVisibleDescendants($project),
            'authors' => $this->authorOptions($project),
            'user_id' => (string) $request->query->get('user_id', ''),
            'from_param' => $request->query->get('from'),
            'route' => $route,
            'route_params' => $routeParams,
            'prev_url' => $this->generateUrl($route, $routeParams + $prevQuery),
            'next_url' => $dateTo > $today ? null : $this->generateUrl($route, $routeParams + $nextQuery),
            'atom_url' => $this->generateUrl($route, $routeParams + ['_format' => 'atom'] + $atomQuery),
            'atom_absolute_url' => $this->generateUrl($route, $routeParams + ['_format' => 'atom'] + $atomQuery, \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
            'page_title' => $this->l('label_activity').(null !== $author ? ' - '.$author->getName() : ''),
            'main_menu_selected' => 'activity',
            'body_css' => 'controller-activities action-index',
        ]);
    }

    public static function singularize(string $type): string
    {
        return match ($type) {
            'issues' => 'issue',
            'news' => 'news',
            'time_entries' => 'time_entry',
            'wiki_edits' => 'wiki_edit',
            default => rtrim($type, 's'),
        };
    }

    /**
     * ActivitiesHelper#sort_activity_events: events grouped by event_group, most recent first.
     *
     * @param list<ActivityEvent> $events
     *
     * @return list<array{event: ActivityEvent, grouped: bool}>
     */
    public static function sortEvents(array $events): array
    {
        $byGroup = [];
        foreach ($events as $e) {
            $byGroup[$e->group][] = $e;
        }
        $sorted = $events;
        usort($sorted, static fn (ActivityEvent $a, ActivityEvent $b) => $b->datetime <=> $a->datetime);
        $out = [];
        foreach ($sorted as $e) {
            if (!isset($byGroup[$e->group])) {
                continue;
            }
            $group = $byGroup[$e->group];
            unset($byGroup[$e->group]);
            usort($group, static fn (ActivityEvent $a, ActivityEvent $b) => $b->datetime <=> $a->datetime);
            foreach ($group as $i => $g) {
                $out[] = ['event' => $g, 'grouped' => $i > 0];
            }
        }

        return $out;
    }

    /** @param list<string> $scope */
    private function savePrefScope(User $user, array $scope): void
    {
        $pref = $user->getPref();
        $pref->set('activity_scope', $scope);
        if ($pref instanceof UserPreference && null === $pref->id) {
            $this->em->persist($pref);
        }
        $this->em->flush();
    }

    /** User#atom_key (creates the feeds token when missing) */
    private function atomKey(User $user): ?string
    {
        if (!$user->isLogged() || null === $user->id) {
            return null;
        }
        $token = $this->em->getRepository(Token::class)->findOneBy(['user' => $user, 'action' => 'feeds']);
        if (null === $token) {
            $token = new Token($user, 'feeds');
            $this->em->persist($token);
            $this->em->flush();
        }

        return $token->value;
    }

    private function hasVisibleDescendants(Project $project): bool
    {
        $visible = $this->auth->visibleProjectIds();
        foreach ($project->getSelfAndDescendants() as $p) {
            if ($p !== $project && \in_array($p->id, $visible, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ActivitiesHelper#activity_authors_options_for_select: active users members of the project
     * (and its visible subprojects) or of any visible project.
     *
     * @return list<array{id: int, name: string}>
     */
    private function authorOptions(?Project $project): array
    {
        $visible = $this->auth->visibleProjectIds();
        if (null !== $project) {
            $ids = array_values(array_filter(array_map(static fn (Project $p) => (int) $p->id, $project->getSelfAndDescendants()), static fn (int $id) => $id === $project->id || \in_array($id, $visible, true)));
        } else {
            $ids = $visible;
        }
        $options = [];
        if ($this->user()->isLogged()) {
            $options[] = ['id' => (int) $this->user()->id, 'name' => '<< '.$this->l('label_me').' >>'];
        }
        if ([] === $ids) {
            return $options;
        }
        $users = $this->em->createQuery('SELECT DISTINCT u FROM App\Entity\User u JOIN u.memberships m WHERE IDENTITY(m.project) IN (:ids) AND u.status = 1')
            ->setParameter('ids', $ids)->getResult();
        $users = array_values(array_filter($users, static fn (User $u) => $u->isLogged()));
        usort($users, static fn (User $a, User $b) => strcasecmp($a->getName(), $b->getName()));
        foreach ($users as $u) {
            $options[] = ['id' => (int) $u->id, 'name' => $u->getName()];
        }

        return $options;
    }
}
