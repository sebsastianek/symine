<?php

declare(strict_types=1);

namespace App\Service\Admin\Users;

use App\Entity\User;
use App\I18n\Localizer;
use App\Security\CurrentUser;
use App\Service\Activity\ActivityFetcher;
use App\Service\Admin\PrincipalApi;
use App\Service\Admin\UserManager;
use App\Service\CustomFieldService;
use App\Service\IssueService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the profile page of a user shows to the current user (users#show): projects, issue counts, custom
 * fields, e-mail addresses and latest activity.
 */
final class UserProfile
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurrentUser $currentUser,
        private readonly UserManager $users,
        private readonly PrincipalApi $api,
        private readonly CustomFieldService $customFields,
        private readonly IssueService $issues,
        private readonly ActivityFetcher $activityFetcher,
        private readonly Localizer $i18n,
        private readonly Settings $settings,
    ) {
    }

    /** @return array<string, mixed> */
    public function view(User $user): array
    {
        $memberships = $this->api->visibleMemberships($user);
        $viewer = $this->currentUser->get();
        $cond = $this->issues->visibleCondition($viewer, 'i');
        $assignees = array_merge([$user->id], $user->getGroupIds());
        $count = fn (string $where, array $params) => (int) $this->em->createQuery("SELECT COUNT(i.id) FROM App\\Entity\\Issue i JOIN i.status s WHERE $cond AND $where")->setParameters($params)->getSingleScalarResult();
        $issueCounts = [
            'assigned' => [
                'total' => $count('IDENTITY(i.assignedTo) IN (:ids)', ['ids' => $assignees]),
                'open' => $count('IDENTITY(i.assignedTo) IN (:ids) AND s.isClosed = false', ['ids' => $assignees]),
            ],
            'reported' => [
                'total' => $count('i.author = :u', ['u' => $user->id]),
                'open' => $count('i.author = :u AND s.isClosed = false', ['u' => $user->id]),
            ],
        ];
        $values = $this->customFields->values('Principal', $user->id);
        $cfs = [];
        foreach ($this->users->visibleCustomFields($this->users->userCustomFields(), $viewer) as $f) {
            $v = $values[$f->id] ?? null;
            if (null !== $v && '' !== $v && [] !== $v) {
                $cfs[] = ['field' => $f, 'value' => $this->customFields->formatValue($f, $v), 'raw' => $v];
            }
        }
        $emails = [];
        if ($user->getMail()) {
            $emails[] = $user->getMail();
        }
        $extra = $user->emailAddresses->toArray();
        usort($extra, static fn ($a, $b) => $a->id <=> $b->id);
        foreach ($extra as $ea) {
            if (!$ea->isDefault) {
                $emails[] = $ea->address;
            }
        }

        return [
            'edited_user' => $user,
            'memberships' => $memberships,
            'issue_counts' => $issueCounts,
            'assigned_to_ids' => implode('|', $assignees),
            'custom_values' => $cfs,
            'emails' => $emails,
            'event_days' => $this->events($user, $viewer),
            'atom_key' => $viewer->isLogged() ? ($this->em->getConnection()->fetchOne("SELECT value FROM tokens WHERE user_id = ? AND action = 'feeds'", [$viewer->id]) ?: null) : null,
            'page_title' => $user->getName(),
            'body_css' => 'controller-users action-show',
        ];
    }

    /**
     * UsersController#show: Redmine::Activity::Fetcher.new(User.current, :author => @user).events(nil, nil, :limit => 10)
     * grouped by day (activities/_activities).
     *
     * @return list<array<string, mixed>>
     */
    private function events(User $user, User $viewer): array
    {
        $events = $this->activityFetcher->events($viewer, $this->activityFetcher->eventTypes($viewer, null), null, false, $user, null, null, 10);

        return ActivityFetcher::eventDays($events, $this->i18n->userTimezone(), $this->i18n->today(), $this->settings->array('non_working_week_days'));
    }
}
