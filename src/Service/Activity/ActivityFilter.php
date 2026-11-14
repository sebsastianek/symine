<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\Project;
use App\Entity\User;
use App\Entity\UserPreference;
use App\I18n\Localizer;
use App\Security\CurrentUser;
use App\Service\Admin\UserManager;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The filter of the activity page (activities#index): the period ending at "from", the author, the subprojects
 * and the kinds of events ticked, which a logged in user saves as a preference by applying the form.
 *
 * Fields: from, user_id, with_subprojects, show_<type> for each kind of event, submit.
 */
final class ActivityFilter
{
    public function __construct(
        private readonly ActivityFetcher $fetcher,
        private readonly UserManager $users,
        private readonly CurrentUser $currentUser,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @throws NotFoundHttpException when the author asked for is not an active user the current user may see */
    public function submit(Request $request, ?Project $project): ActivityCriteria
    {
        $user = $this->currentUser->get();
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
            // User.visible.active.find(params[:user_id]): 404 for users the current user is not allowed to see
            if (!$author instanceof User || !$author->isActive() || !$author->isLogged() || !$this->users->isVisible($author, $user)) {
                throw new NotFoundHttpException('Not Found');
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

        return new ActivityCriteria($days, $today, $dateFrom, $dateTo, $withSubprojects, $author, $eventTypes, $scope);
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
}
