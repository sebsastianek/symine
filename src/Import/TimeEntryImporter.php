<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Import;
use App\Entity\ImportItem;
use App\Entity\Issue;
use App\Entity\Member;
use App\Entity\Project;
use App\Entity\TimeEntry;
use App\Entity\TimeEntryActivity;
use App\Entity\TimeEntryCustomField;
use App\Entity\User;
use App\Event\ContentEvent;
use App\Service\IssueService;
use App\Service\TimeEntryService;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Port of app/models/time_entry_import.rb, with TimeEntry's safe attributes and validations
 * (time_entry.rb validate_time_entry, timelog settings, custom fields).
 */
final class TimeEntryImporter extends AbstractImporter
{
    public const array AUTO_MAPPABLE_FIELDS = [
        'activity' => 'field_activity',
        'user' => 'field_user',
        'issue_id' => 'field_issue',
        'spent_on' => 'field_spent_on',
        'hours' => 'field_hours',
        'comments' => 'field_comments',
    ];

    /** @var array<int, Project|null> */
    private array $projectsCache = [];

    public function __construct(
        private readonly IssueService $issues,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    public static function type(): string
    {
        return 'TimeEntryImport';
    }

    public static function partialPrefix(): string
    {
        return 'time_entries';
    }

    public function autoMappableFields(): array
    {
        return self::AUTO_MAPPABLE_FIELDS;
    }

    public function menuItem(): ?string
    {
        return 'time_entries';
    }

    public function isAuthorized(User $user): bool
    {
        return $this->auth->allowedToGlobally('import_time_entries', $user) && $this->auth->allowedToGlobally('log_time', $user);
    }

    /** @return list<TimeEntry> */
    public function savedObjects(Import $import): array
    {
        $ids = $this->savedObjectIds($import);

        return [] === $ids ? [] : $this->em->getRepository(TimeEntry::class)->findBy(['id' => $ids], ['id' => 'ASC']);
    }

    public function mappableCustomFields(Import $import): array
    {
        return $this->em->getRepository(TimeEntryCustomField::class)->findBy([], ['position' => 'ASC']);
    }

    /** @return list<Project> */
    public function allowedTargetProjects(Import $import): array
    {
        $cond = $this->auth->allowedToCondition('log_time', 'p.id', $import->user);

        return $this->em->createQuery("SELECT p FROM App\\Entity\\Project p WHERE $cond ORDER BY p.lft ASC")->getResult();
    }

    public function project(Import $import): ?Project
    {
        $projects = $this->allowedTargetProjects($import);
        $id = (int) ($this->mapping($import)['project_id'] ?? 0);
        foreach ($projects as $p) {
            if ($p->id === $id) {
                return $p;
            }
        }

        return $projects[0] ?? null;
    }

    /** Project#activities: system activities not overridden + project activities, active only @return list<TimeEntryActivity> */
    public function projectActivities(?Project $project): array
    {
        if (null === $project) {
            return [];
        }
        $all = $this->em->createQuery('SELECT a FROM App\Entity\TimeEntryActivity a WHERE (a.project IS NULL OR a.project = :p) AND a.active = true ORDER BY a.position ASC')
            ->setParameter('p', $project)->getResult();
        $overridden = [];
        foreach ($this->em->getRepository(TimeEntryActivity::class)->findBy(['project' => $project]) as $a) {
            if (null !== $a->parent) {
                $overridden[] = $a->parent->id;
            }
        }

        return array_values(array_filter($all, static fn (TimeEntryActivity $a) => !\in_array($a->id, $overridden, true)));
    }

    /** @return list<TimeEntryActivity> */
    public function allowedTargetActivities(Import $import): array
    {
        return $this->projectActivities($this->project($import));
    }

    /** Active members allowed to log time on the project (TimeEntry#assignable_users) @return list<User> */
    public function assignableUsers(?Project $project): array
    {
        if (null === $project) {
            return [];
        }
        $users = [];
        foreach ($this->em->getRepository(Member::class)->findBy(['project' => $project]) as $m) {
            $u = $m->principal;
            if ($u instanceof User && $u->isLogged() && $u->isActive() && $this->auth->allowedTo('log_time', $project, $u)) {
                $users[$u->id] = $u;
            }
        }
        usort($users, static fn (User $a, User $b) => strcasecmp($a->getName(), $b->getName()));

        return array_values($users);
    }

    /** @return list<User> */
    public function allowedTargetUsers(Import $import): array
    {
        $users = $this->assignableUsers($this->project($import));
        if (!\in_array($import->user, $users, true)) {
            $users[] = $import->user;
        }

        return $users;
    }

    public function activity(Import $import): ?TimeEntryActivity
    {
        if (preg_match('/\Avalue:(\d+)\z/', (string) ($this->mapping($import)['activity'] ?? ''), $m)) {
            foreach ($this->allowedTargetActivities($import) as $a) {
                if ($a->id === (int) $m[1]) {
                    return $a;
                }
            }
        }

        return null;
    }

    public function userValue(Import $import): ?int
    {
        return preg_match('/\Avalue:(\d+)\z/', (string) ($this->mapping($import)['user'] ?? ''), $m) ? (int) $m[1] : null;
    }

    public function canLogTimeForOthers(Import $import): bool
    {
        return $this->auth->allowedTo('log_time_for_other_users', $this->project($import), $import->user);
    }

    protected function importRow(Import $import, array $row, ImportItem $item): array
    {
        $author = $import->user;
        $project = $this->project($import);
        $errors = [];

        $activityId = null;
        if ($a = $this->activity($import)) {
            $activityId = $a->id;
        } elseif (null !== ($name = $this->rowValue($import, $row, 'activity'))) {
            foreach ($this->allowedTargetActivities($import) as $a) {
                if (0 === strcasecmp($a->name, trim($name))) {
                    $activityId = $a->id;
                    break;
                }
            }
        }

        $userId = null;
        if ($this->canLogTimeForOthers($import)) {
            if (null !== ($uv = $this->userValue($import))) {
                $userId = $uv;
            } elseif (null !== ($name = $this->rowValue($import, $row, 'user'))) {
                $userId = self::detectByKeyword($this->allowedTargetUsers($import), $name)?->id;
            }
        } else {
            $userId = $author->id;
        }

        $entry = new TimeEntry();
        $entry->author = $author;
        $issueRaw = $this->rowValue($import, $row, 'issue_id');
        $issue = null;
        $invalidIssue = false;
        if (null !== $issueRaw) {
            $issueId = (int) ltrim(trim($issueRaw), '#');
            $issueProjectId = $this->em->getConnection()->fetchOne('SELECT project_id FROM issues WHERE id = ?', [$issueId]);
            $targetProject = null;
            if (false !== $issueProjectId && null !== $issueProjectId) {
                $pid = (int) $issueProjectId;
                if (!\array_key_exists($pid, $this->projectsCache)) {
                    $this->projectsCache[$pid] = null;
                    foreach ($this->allowedTargetProjects($import) as $p) {
                        if ($p->id === $pid) {
                            $this->projectsCache[$pid] = $p;
                        }
                    }
                }
                $targetProject = $this->projectsCache[$pid];
            }
            $project = $targetProject;
            $issue = $this->em->find(Issue::class, $issueId);
            // TimeEntry#safe_attributes=: the issue must be visible
            if (null === $issue || !$this->issues->isVisible($issue, $author)) {
                $issue = null;
                $invalidIssue = true;
            }
        }

        // presence & validity (TimeEntry validations)
        if (null === $userId) {
            $errors[] = $this->error('field_user', 'blank');
        }
        $user = null !== $userId ? $this->em->find(User::class, $userId) : null;
        if (null !== $userId && null === $user) {
            $errors[] = $this->error('field_user', 'invalid');
        }
        $activity = null !== $activityId ? $this->em->find(TimeEntryActivity::class, $activityId) : null;
        if (null === $activity) {
            $errors[] = $this->error('field_activity', 'blank');
        }
        if (null === $project) {
            $errors[] = $this->error('field_project', 'blank');
        }
        $hoursRaw = $this->rowValue($import, $row, 'hours');
        $hours = null;
        if (null === $hoursRaw) {
            $errors[] = $this->error('field_hours', 'blank');
        } else {
            // TimeEntry#hours=: String#to_hours ("2h30", "1:30", "90m", "1,5"...)
            $hours = TimeEntryService::toHours($hoursRaw);
            if (null === $hours) {
                $errors[] = $this->error('field_hours', 'invalid');
            }
        }
        $spentOn = $this->rowDate($import, $row, 'spent_on');
        if (null === $spentOn) {
            $errors[] = $this->error('field_spent_on', 'blank');
        } elseif (!self::isIsoDate($spentOn)) {
            $errors[] = $this->error('field_spent_on', 'not_a_date');
            $spentOn = null;
        }
        $comments = $this->rowValue($import, $row, 'comments');
        $required = $this->settings->array('timelog_required_fields');
        if (\in_array('issue_id', $required, true) && null === $issue && !$invalidIssue) {
            $errors[] = $this->error('field_issue', 'blank');
        }
        if (\in_array('comments', $required, true) && null === $comments) {
            $errors[] = $this->error('field_comments', 'blank');
        }
        if (null !== $comments && mb_strlen($comments) > 1024) {
            $errors[] = $this->error('field_comments', 'too_long', ['count' => 1024]);
        }
        if (null !== $hours) {
            if ($hours < 0 || (0.0 === $hours && !$this->settings->bool('timelog_accept_0_hours'))) {
                $errors[] = $this->error('field_hours', 'invalid');
            }
            $max = (float) $this->settings->string('timelog_max_hours_per_day');
            if ($max > 0 && null !== $user && null !== $spentOn) {
                $logged = (float) $this->em->createQuery('SELECT COALESCE(SUM(t.hours), 0) FROM App\Entity\TimeEntry t WHERE t.user = :u AND t.spentOn = :d')
                    ->setParameter('u', $user)->setParameter('d', new \DateTimeImmutable($spentOn), 'date_immutable')->getSingleScalarResult();
                if ($logged + $hours > $max) {
                    $errors[] = $this->i18n->l('error_exceeds_maximum_hours_per_day', ['logged_hours' => $this->i18n->formatHours($logged), 'max_hours' => $this->i18n->formatHours($max)]);
                }
            }
        }
        if (null !== $user && null !== $project && $user->id !== $author->id && !\in_array($user, $this->assignableUsers($project), true)) {
            $errors[] = $this->error('field_user', 'invalid');
        }
        if ($invalidIssue || (null !== $issue && null !== $project && $issue->project->id !== $project->id)) {
            $errors[] = $this->error('field_issue', 'invalid');
        }
        if (null !== $activity && null !== $project && !\in_array($activity, $this->projectActivities($project), true)) {
            $errors[] = $this->error('field_activity', 'inclusion');
        }
        if (null !== $spentOn && null !== $user && !$this->settings->bool('timelog_accept_future_dates')
            && $spentOn > $this->i18n->today($user)->format('Y-m-d')) {
            $errors[] = $this->i18n->l('error_spent_on_future_date');
        }
        if (null !== $issue && $issue->isClosed() && !$this->settings->bool('timelog_accept_closed_issues')) {
            $errors[] = $this->i18n->l('error_spent_on_closed_issue');
        }

        $fields = $this->mappableCustomFields($import);
        $cfValues = $this->customFieldValues($import, $row, $fields, $project);
        foreach ($fields as $cf) {
            $v = $cfValues[(string) $cf->id] ?? $this->customFields->defaultValue($cf);
            array_push($errors, ...$this->customFields->validate($cf, $this->customFields->normalize($cf, $v), $project));
        }

        if ([] !== $errors) {
            return [null, array_values(array_unique($errors))];
        }

        \assert(null !== $project && null !== $user && null !== $activity && null !== $spentOn && null !== $hours);
        $entry->project = $project;
        $entry->user = $user;
        $entry->issue = $issue;
        $entry->activity = $activity;
        $entry->hours = $hours;
        $entry->comments = $comments;
        $entry->setSpentOn(new \DateTimeImmutable($spentOn));
        $this->em->persist($entry);
        $this->em->flush();
        $this->customFields->saveValues('TimeEntry', (int) $entry->id, $fields, $cfValues + $this->customFields->defaultValues($fields));
        $this->em->flush();
        $this->events->dispatch(new ContentEvent('time_entry', 'created', $entry, $author));

        return [$entry, []];
    }
}
