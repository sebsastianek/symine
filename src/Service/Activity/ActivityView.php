<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\Project;
use App\Entity\User;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\AccountService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** What the activity page shows: the events of the period day by day, the filter of the sidebar and the links to the other periods. */
final class ActivityView
{
    public function __construct(
        private readonly ActivityFetcher $fetcher,
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly AccountService $accounts,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Variables of activities/index.html.twig.
     *
     * @return array<string, mixed>
     */
    public function view(ActivityCriteria $criteria, Request $request, ?Project $project): array
    {
        $user = $this->currentUser->get();
        $days = $criteria->days;
        $dateTo = $criteria->dateTo;
        $author = $criteria->author;
        $events = $this->fetcher->events($user, $criteria->scope, $project, $criteria->withSubprojects, $author, $criteria->dateFrom, $dateTo);

        $query = $request->query->all();
        $prevQuery = array_merge($query, ['from' => $dateTo->modify('-'.($days + 1).' days')->format('Y-m-d')]);
        $nextQuery = array_merge($query, ['from' => $dateTo->modify('+'.($days - 1).' days')->format('Y-m-d')]);
        $atomQuery = $query;
        unset($atomQuery['from']);
        // User#atom_key (creates the feeds token when missing)
        if ($user->isLogged() && null !== $user->id) {
            $atomQuery['key'] = $this->accounts->atomKey($user);
        }
        $route = null === $project ? 'activity' : 'project_activity';
        $routeParams = null === $project ? [] : ['id' => $project->identifier];

        return [
            'project' => $project,
            'author' => $author,
            'days' => $days,
            'date_to' => $dateTo,
            'date_from' => $criteria->dateFrom,
            'today' => $criteria->today,
            'event_days' => ActivityFetcher::eventDays($events, $this->i18n->userTimezone(), $criteria->today, $this->settings->array('non_working_week_days')),
            'event_types' => $criteria->eventTypes,
            'singular_types' => array_combine($criteria->eventTypes, array_map(self::singularize(...), $criteria->eventTypes)),
            'scope' => $criteria->scope,
            'with_subprojects' => $criteria->withSubprojects,
            'show_subprojects_option' => null !== $project && $this->hasVisibleDescendants($project),
            'authors' => $this->authorOptions($project),
            'user_id' => (string) $request->query->get('user_id', ''),
            'from_param' => $request->query->get('from'),
            'route' => $route,
            'route_params' => $routeParams,
            'prev_url' => $this->urls->generate($route, $routeParams + $prevQuery),
            'next_url' => $dateTo > $criteria->today ? null : $this->urls->generate($route, $routeParams + $nextQuery),
            'atom_url' => $this->urls->generate($route, $routeParams + ['_format' => 'atom'] + $atomQuery),
            'atom_absolute_url' => $this->urls->generate($route, $routeParams + ['_format' => 'atom'] + $atomQuery, UrlGeneratorInterface::ABSOLUTE_URL),
            'page_title' => $this->i18n->l('label_activity').(null !== $author ? ' - '.$author->getName() : ''),
        ];
    }

    /** The singular of a kind of events, as in the label_<singular>_plural and show_<type> names of Redmine */
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
        $user = $this->currentUser->get();
        $visible = $this->auth->visibleProjectIds();
        if (null !== $project) {
            $ids = array_values(array_filter(array_map(static fn (Project $p) => (int) $p->id, $project->getSelfAndDescendants()), static fn (int $id) => $id === $project->id || \in_array($id, $visible, true)));
        } else {
            $ids = $visible;
        }
        $options = [];
        if ($user->isLogged()) {
            $options[] = ['id' => (int) $user->id, 'name' => '<< '.$this->i18n->l('label_me').' >>'];
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
