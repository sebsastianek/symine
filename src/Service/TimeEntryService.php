<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\TimeEntry;
use App\Entity\TimeEntryActivity;
use App\Entity\TimeEntryCustomField;
use App\Entity\User;
use App\Event\ContentEvent;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Port of Redmine's TimeEntry and TimeEntryActivity models: visibility, editability, activities
 * (with project overrides), safe attributes, validations and persistence.
 */
final class TimeEntryService
{
    /** @var \WeakMap<TimeEntry, array<string, mixed>> per entry state: original values, invalid ids, custom values */
    private \WeakMap $state;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly IssueService $issues,
        private readonly CustomFieldService $customFields,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
        $this->state = new \WeakMap();
    }

    /** String#to_hours: "1.5", "1,5", "1:30", "1h30", "1h", "30m" */
    public static function toHours(?string $s): ?float
    {
        $s = trim((string) $s);
        if ('' === $s) {
            return null;
        }
        if (preg_match('/^(\d+([.,]\d+)?)h?$/', $s, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }
        if (preg_match('/^(\d+):(\d+)$/', $s, $m)) {
            return (int) $m[1] + (int) $m[2] / 60;
        }
        if (preg_match('/^((\d+)\s*(h|hours?))?\s*((\d+)\s*(m|min)?)?$/i', $s, $m) && ('' !== ($m[1] ?? '') || '' !== ($m[4] ?? ''))) {
            return (int) ($m[2] ?? 0) + (int) ($m[5] ?? 0) / 60;
        }
        $s = str_replace(',', '.', $s);

        return is_numeric($s) ? (float) $s : null;
    }

    // --- Visibility -----------------------------------------------------------

    /** Role condition used with allowedToCondition (TimeEntry.visible_condition); $userExpr e.g. "IDENTITY(te.user)" or "time_entries.user_id" */
    public function visibilityRoleCondition(string $userExpr): callable
    {
        return static function (Role $role, User $user) use ($userExpr): ?string {
            if ('all' === $role->timeEntriesVisibility) {
                return null;
            }
            if ('own' === $role->timeEntriesVisibility && $user->id && $user->isLogged()) {
                return "$userExpr = ".(int) $user->id;
            }

            return '1=0';
        };
    }

    /** DQL condition for visible time entries (alias of TimeEntry) */
    public function visibleCondition(?User $user = null, string $alias = 'te'): string
    {
        return $this->auth->allowedToCondition('view_time_entries', "IDENTITY($alias.project)", $user, $this->visibilityRoleCondition("IDENTITY($alias.user)"));
    }

    /** SQL condition on the time_entries table */
    public function visibleSqlCondition(?User $user = null): string
    {
        return $this->auth->allowedToCondition('view_time_entries', 'time_entries.project_id', $user, $this->visibilityRoleCondition('time_entries.user_id'));
    }

    public function isVisible(TimeEntry $entry, ?User $user = null): bool
    {
        $user = $this->auth->user($user);

        return $this->auth->allowedTo('view_time_entries', $entry->project, $user, false, static function (Role $role, User $u) use ($entry) {
            return match ($role->timeEntriesVisibility) {
                'all' => true,
                'own' => isset($entry->user) && $entry->user->id === $u->id,
                default => false,
            };
        });
    }

    public function isEditable(TimeEntry $entry, ?User $user = null): bool
    {
        $user = $this->auth->user($user);

        return $this->isVisible($entry, $user) && (
            ((isset($entry->user) && $entry->user->id === $user->id) && $this->auth->allowedTo('edit_own_time_entries', $entry->project, $user))
            || $this->auth->allowedTo('edit_time_entries', $entry->project, $user)
        );
    }

    public function canViewAll(?Project $project, ?User $user = null): bool
    {
        return $this->auth->allowedTo('view_time_entries', $project, $user, null === $project, static fn (Role $r) => 'all' === $r->timeEntriesVisibility);
    }

    // --- Activities -------------------------------------------------------------

    /**
     * Project#activities / TimeEntryActivity.available_activities
     *
     * @return list<TimeEntryActivity>
     */
    public function activities(?Project $project, bool $includeInactive = false): array
    {
        $qb = $this->em->createQueryBuilder()->select('a')->from(TimeEntryActivity::class, 'a')->orderBy('a.position', 'ASC')->addOrderBy('a.id', 'ASC');
        if (null === $project) {
            $qb->where('a.project IS NULL');
        } else {
            $qb->where('a.project IS NULL OR a.project = :p')->setParameter('p', $project->id);
            $overridden = array_map('intval', $this->em->getConnection()->fetchFirstColumn(
                "SELECT parent_id FROM enumerations WHERE type = 'TimeEntryActivity' AND project_id = ? AND parent_id IS NOT NULL", [$project->id]
            ));
            if ([] !== $overridden) {
                $qb->andWhere('a.id NOT IN (:ov)')->setParameter('ov', $overridden);
            }
        }
        if (!$includeInactive) {
            $qb->andWhere('a.active = true');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<TimeEntryActivity> TimeEntryActivity.shared (all, project_id NULL) */
    public function sharedActivities(): array
    {
        return $this->em->getRepository(TimeEntryActivity::class)->findBy(['project' => null], ['position' => 'ASC']);
    }

    /** TimeEntryActivity.default(project) */
    public function defaultActivity(?Project $project = null): ?TimeEntryActivity
    {
        $default = $this->em->getRepository(TimeEntryActivity::class)->findOneBy(['project' => null, 'isDefault' => true]);
        if (null === $default || null === $project) {
            return $default;
        }
        $acts = $this->activities($project);
        if ([] === $acts) {
            return $default;
        }
        foreach ($acts as $a) {
            if ($a->id === $default->id) {
                return $default;
            }
        }
        foreach ($acts as $a) {
            if ($a->parent?->id === $default->id) {
                return $a;
            }
        }

        return null;
    }

    /** TimeEntryActivity.default_activity_id */
    public function defaultActivityId(?User $user, ?Project $project): ?int
    {
        $available = $this->activities($project);
        if ([] === $available) {
            return null;
        }
        if (1 === \count($available)) {
            return $available[0]->id;
        }
        $find = static function (array $ids) use ($available): ?int {
            foreach ($ids as $id) {
                foreach ($available as $a) {
                    if ($a->id === $id || $a->parent?->id === $id) {
                        return $a->id;
                    }
                }
            }

            return null;
        };
        if (null !== $project && null !== $user && $user->isLogged()) {
            $m = $this->auth->membership($user, $project);
            if (null !== $m) {
                $ids = [];
                foreach ($m->getRoles() as $r) {
                    if (null !== $r->defaultTimeEntryActivityId) {
                        $ids[] = $r->defaultTimeEntryActivityId;
                    }
                }
                if (null !== ($aid = $find($ids))) {
                    return $aid;
                }
            }
            $pd = $this->defaultActivity($project);
            if (null !== $pd && null !== ($aid = $find([$pd->id]))) {
                return $aid;
            }
        }
        $global = $this->defaultActivity();
        if (null !== $global && null !== ($aid = $find([$global->id]))) {
            return $aid;
        }

        return null;
    }

    /** TimeEntryActivity.activity_id_for_project */
    public function activityIdForProject(?int $activityId, ?Project $project): ?int
    {
        if (null === $activityId) {
            return null;
        }
        $a = $this->em->find(TimeEntryActivity::class, $activityId);
        if (null === $a) {
            return $activityId;
        }
        $shared = $a->parent?->id ?? $a->id;
        if (null !== $project) {
            $override = $this->em->getConnection()->fetchOne("SELECT id FROM enumerations WHERE type = 'TimeEntryActivity' AND project_id = ? AND parent_id = ?", [$project->id, $shared]);
            if (false !== $override) {
                return (int) $override;
            }
        }

        return $shared;
    }

    // --- Users ------------------------------------------------------------------

    /**
     * TimeEntry#assignable_users: active members whose roles allow log_time, plus the current user.
     *
     * @return list<User>
     */
    public function assignableUsers(?Project $project): array
    {
        $users = [];
        if (null !== $project) {
            $members = $this->em->createQuery(
                'SELECT m, mr, r, u FROM App\Entity\Member m JOIN m.memberRoles mr JOIN mr.role r JOIN m.principal u WHERE m.project = :p AND u INSTANCE OF App\Entity\User AND u.status = 1'
            )->setParameter('p', $project->id)->getResult();
            foreach ($members as $m) {
                foreach ($m->getRoles() as $role) {
                    if ($role->hasPermission('log_time')) {
                        $users[$m->principal->id] = $m->principal;
                        break;
                    }
                }
            }
        }
        $users = array_values(array_filter($users, static fn ($u) => $u instanceof User));
        usort($users, static fn (User $a, User $b) => [mb_strtolower($a->firstname), mb_strtolower($a->lastname), $a->id] <=> [mb_strtolower($b->firstname), mb_strtolower($b->lastname), $b->id]);
        $current = $this->currentUser->get();
        if ($current->isLogged()) {
            $found = false;
            foreach ($users as $u) {
                if ($u->id === $current->id) {
                    $found = true;
                }
            }
            if (!$found) {
                $users[] = $current;
            }
        }

        return $users;
    }

    // --- Custom fields ------------------------------------------------------------

    /** @return list<TimeEntryCustomField> custom fields visible/editable by the user for the entry's project */
    public function editableCustomFields(TimeEntry $entry, ?User $user = null): array
    {
        $user = $this->auth->user($user);
        $project = $entry->project ?? null;
        $out = [];
        foreach ($this->customFields->fieldsOf(TimeEntryCustomField::class) as $f) {
            if ($f->isVisibleBy($project, $user, fn (?Project $p) => $this->auth->rolesForProject($p, $user))) {
                $out[] = $f;
            }
        }

        return $out;
    }

    /** @return array<int, mixed> */
    public function customValues(TimeEntry $entry): array
    {
        $s = $this->state[$entry] ?? null;
        if (null !== $s && \array_key_exists('cf', $s)) {
            return $s['cf'];
        }
        if (null === $entry->id) {
            return $this->customFields->defaultValues($this->customFields->fieldsOf(TimeEntryCustomField::class));
        }

        return $this->customFields->values('TimeEntry', $entry->id);
    }

    // --- Building / attributes ------------------------------------------------------

    public function newEntry(?Project $project, ?Issue $issue, ?User $author = null): TimeEntry
    {
        $author ??= $this->currentUser->get();
        $entry = new TimeEntry();
        if (null !== $project) {
            $entry->project = $project;
        }
        $entry->issue = $issue;
        $entry->author = $author;
        $entry->user = $author;
        $entry->setSpentOn($this->i18n->today($author));
        $aid = $this->defaultActivityId($author, $project);
        if (null !== $aid) {
            $entry->activity = $this->em->getReference(TimeEntryActivity::class, $aid);
        }
        $this->snapshot($entry);
        $this->state[$entry]['new'] = true;
        $this->state[$entry]['hours_blank'] = true;

        return $entry;
    }

    /** Remembers the original values used to detect changes (dirty tracking) */
    public function snapshot(TimeEntry $entry): void
    {
        $this->state[$entry] = [
            'orig' => $this->values($entry),
            'new' => null === $entry->id,
        ];
    }

    /** @return array<string, mixed> */
    private function values(TimeEntry $e): array
    {
        return [
            'project_id' => isset($e->project) ? $e->project->id : null,
            'issue_id' => $e->issue?->id,
            'user_id' => isset($e->user) ? $e->user->id : null,
            'activity_id' => isset($e->activity) ? $e->activity->id : null,
            'hours' => $e->hours,
            'spent_on' => $e->spentOn->format('Y-m-d'),
            'comments' => $e->comments,
        ];
    }

    private function changed(TimeEntry $e, string $attr): bool
    {
        $s = $this->state[$e] ?? null;
        if (null === $s) {
            return false;
        }
        if ('hours' === $attr && ($s['hours_set'] ?? false)) {
            return ($s['new'] ?? false) || $this->values($e)['hours'] !== $s['orig']['hours'];
        }
        if ($s['new'] ?? false) {
            return null !== $this->values($e)[$attr];
        }

        return $this->values($e)[$attr] !== $s['orig'][$attr];
    }

    /**
     * TimeEntry#safe_attributes= : user_id, hours, comments, project_id, issue_id, activity_id, spent_on, custom_field_values.
     *
     * @param array<string, mixed> $attrs
     */
    public function setAttributes(TimeEntry $entry, array $attrs, ?User $user = null): void
    {
        $user = $this->auth->user($user);
        if (!isset($this->state[$entry])) {
            $this->snapshot($entry);
        }
        $st = $this->state[$entry];
        $st['errors'] ??= [];

        if (\array_key_exists('project_id', $attrs)) {
            $pid = $attrs['project_id'];
            if (null === $pid || '' === $pid) {
                $st['project_blank'] = true;
            } else {
                $p = $this->em->find(Project::class, (int) $pid);
                if (null !== $p) {
                    $entry->project = $p;
                    $st['project_blank'] = false;
                } else {
                    $st['project_invalid'] = true;
                }
            }
        }
        if (\array_key_exists('issue_id', $attrs)) {
            $iid = $attrs['issue_id'];
            $st['invalid_issue_id'] = null;
            if (null === $iid || '' === $iid) {
                $entry->issue = null;
            } else {
                $issue = ctype_digit((string) $iid) ? $this->em->find(Issue::class, (int) $iid) : null;
                if (null === $issue) {
                    $entry->issue = null;
                    $st['invalid_issue_id'] = (string) $iid;
                } else {
                    $entry->issue = $issue;
                }
            }
        }
        if (\array_key_exists('user_id', $attrs) && null !== $attrs['user_id'] && '' !== $attrs['user_id']) {
            $u = $this->em->find(User::class, (int) $attrs['user_id']);
            if ($u instanceof User && $u->isLogged()) {
                $entry->user = $u;
            } else {
                $st['user_not_found'] = true;
            }
        }
        if (\array_key_exists('hours', $attrs)) {
            $raw = $attrs['hours'];
            $h = \is_int($raw) || \is_float($raw) ? (float) $raw : self::toHours((string) $raw);
            $st['hours_set'] = true;
            $st['hours_blank'] = null === $raw || '' === trim((string) $raw);
            $st['hours_invalid'] = !$st['hours_blank'] && null === $h;
            $entry->hours = $h ?? 0.0;
        }
        if (\array_key_exists('comments', $attrs)) {
            $entry->comments = null === $attrs['comments'] ? null : (string) $attrs['comments'];
        }
        if (\array_key_exists('activity_id', $attrs)) {
            $aid = $attrs['activity_id'];
            if (null === $aid || '' === $aid) {
                $st['activity_blank'] = true;
            } else {
                $a = $this->em->find(TimeEntryActivity::class, (int) $aid);
                if (null !== $a) {
                    $entry->activity = $a;
                    $st['activity_blank'] = false;
                    $st['activity_unknown'] = false;
                } else {
                    // activity_id present but unknown: "is not included in the list" (validate_time_entry)
                    $st['activity_blank'] = false;
                    $st['activity_unknown'] = true;
                }
            }
        }
        if (\array_key_exists('spent_on', $attrs)) {
            $raw = trim((string) $attrs['spent_on']);
            $st['spent_on_blank'] = '' === $raw;
            $st['spent_on_invalid'] = false;
            if ('' !== $raw) {
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
                if (false === $d || $d->format('Y-m-d') !== $raw) {
                    $st['spent_on_invalid'] = true;
                } else {
                    $entry->setSpentOn($d);
                }
            }
        }
        $this->state[$entry] = $st;

        // issue checks (visible & log_time on the issue's project)
        if ($this->changed($entry, 'issue_id') && null !== $entry->issue) {
            $issue = $entry->issue;
            if ($this->issues->isVisible($issue, $user) && $this->auth->allowedTo('log_time', $issue->project, $user)) {
                if ((!\array_key_exists('project_id', $attrs) || '' === (string) $attrs['project_id']) && (!isset($entry->project) || $issue->project->id !== $entry->project->id)) {
                    $entry->project = $issue->project;
                }
                $st['invalid_issue_id'] = null;
            } else {
                $st['invalid_issue_id'] = (string) $issue->id;
            }
        }
        // set_project_if_nil
        if (!isset($entry->project) && null !== $entry->issue) {
            $entry->project = $entry->issue->project;
        }
        if ($this->changed($entry, 'user_id') && isset($entry->user, $entry->author) && $entry->user->id !== $entry->author->id
            && !(isset($entry->project) && $this->auth->allowedTo('log_time_for_other_users', $entry->project, $user))) {
            $st['invalid_user_id'] = $entry->user->id;
        } else {
            $st['invalid_user_id'] = null;
        }
        // custom field values (only editable fields)
        $cf = $this->customValues($entry);
        $submitted = [];
        foreach ((array) ($attrs['custom_field_values'] ?? []) as $id => $v) {
            $submitted[(int) $id] = $v;
        }
        foreach ((array) ($attrs['custom_fields'] ?? []) as $c) {
            if (\is_array($c) && isset($c['id'])) {
                $submitted[(int) $c['id']] = $c['value'] ?? null;
            }
        }
        if ([] !== $submitted && isset($entry->project)) {
            $editable = array_map(static fn ($f) => $f->id, $this->editableCustomFields($entry, $user));
            foreach ($submitted as $id => $v) {
                if (\in_array($id, $editable, true)) {
                    $cf[$id] = $v;
                    $st['cf_changed'] = true;
                }
            }
        }
        $st['cf'] = $cf;
        $this->state[$entry] = $st;
        // set_activity_for_project when the project changed but not the activity
        if (null !== $entry->id && $this->changed($entry, 'project_id') && !$this->changed($entry, 'activity_id') && isset($entry->activity)) {
            $aid = $this->activityIdForProject($entry->activity->id, $entry->project);
            if (null !== $aid && $aid !== $entry->activity->id) {
                $entry->activity = $this->em->getReference(TimeEntryActivity::class, $aid);
                $this->state[$entry]['orig']['activity_id'] = $aid;
            }
        }
    }

    /**
     * Validations of the TimeEntry model.
     *
     * @return list<string> full error messages
     */
    public function validate(TimeEntry $entry, ?User $user = null): array
    {
        $user = $this->auth->user($user);
        $st = $this->state[$entry] ?? ['orig' => $this->values($entry), 'new' => null === $entry->id];
        $blank = $this->i18n->l('activerecord.errors.messages.blank');
        $invalid = $this->i18n->l('activerecord.errors.messages.invalid');
        $f = fn (string $k) => $this->i18n->l($k);
        $errors = [];
        $required = $this->settings->array('timelog_required_fields');

        if ($st['activity_unknown'] ?? false) {
            $errors[] = $f('field_activity').' '.$this->i18n->l('activerecord.errors.messages.inclusion');
        } elseif (!isset($entry->activity) || ($st['activity_blank'] ?? false)) {
            $errors[] = $f('field_activity').' '.$blank;
        }
        if (!isset($entry->project)) {
            $errors[] = $f('field_project').' '.$blank;
        }
        if ($st['hours_blank'] ?? false) {
            $errors[] = $f('field_hours').' '.$blank;
        }
        if ($st['spent_on_blank'] ?? false) {
            $errors[] = $f('field_spent_on').' '.$blank;
        }
        if (\in_array('issue_id', $required, true) && null === $entry->issue && null === ($st['invalid_issue_id'] ?? null)) {
            $errors[] = $f('field_issue').' '.$blank;
        }
        if (\in_array('comments', $required, true) && '' === trim((string) $entry->comments)) {
            $errors[] = $f('field_comments').' '.$blank;
        }
        if ($st['hours_invalid'] ?? false) {
            $errors[] = $f('field_hours').' '.$invalid;
        }
        if (null !== $entry->comments && mb_strlen($entry->comments) > 1024) {
            $errors[] = $f('field_comments').' '.$this->i18n->l('activerecord.errors.messages.too_long', ['count' => 1024]);
        }
        if ($st['spent_on_invalid'] ?? false) {
            $errors[] = $f('field_spent_on').' '.$this->i18n->l('activerecord.errors.messages.not_a_date');
        }
        $hoursPresent = !($st['hours_blank'] ?? false) && !($st['hours_invalid'] ?? false);
        if ($hoursPresent) {
            if ($entry->hours < 0) {
                $errors[] = $f('field_hours').' '.$invalid;
            }
            if (0.0 === $entry->hours && $this->changed($entry, 'hours') && !$this->settings->bool('timelog_accept_0_hours')) {
                $errors[] = $f('field_hours').' '.$invalid;
            }
            $max = (float) $this->settings->string('timelog_max_hours_per_day');
            if ($this->changed($entry, 'hours') && $max > 0.0) {
                $logged = $this->otherHoursWithSameUserAndDay($entry);
                if ($logged + $entry->hours > $max) {
                    $errors[] = $this->i18n->l('error_exceeds_maximum_hours_per_day', ['logged_hours' => $this->i18n->formatHours($logged), 'max_hours' => $this->i18n->formatHours($max)]);
                }
            }
        }
        if (($st['project_invalid'] ?? false)) {
            $errors[] = $f('field_project').' '.$invalid;
        }
        if (($st['user_not_found'] ?? false) || null !== ($st['invalid_user_id'] ?? null)
            || ($this->changed($entry, 'user_id') && isset($entry->user, $entry->author) && $entry->user->id !== $entry->author->id
                && !\in_array($entry->user->id, array_map(static fn ($u) => $u->id, $this->assignableUsers($entry->project ?? null)), true))) {
            $errors[] = $f('field_user').' '.$invalid;
        }
        if (null !== ($st['invalid_issue_id'] ?? null) || (null !== $entry->issue && isset($entry->project) && $entry->project->id !== $entry->issue->project->id)) {
            $errors[] = $f('field_issue').' '.$invalid;
        }
        if (isset($entry->activity, $entry->project) && $this->changed($entry, 'activity_id')) {
            $ids = array_map(static fn ($a) => $a->id, $this->activities($entry->project));
            if (!\in_array($entry->activity->id, $ids, true)) {
                $errors[] = $f('field_activity').' '.$this->i18n->l('activerecord.errors.messages.inclusion');
            }
        }
        if (isset($entry->user) && $this->changed($entry, 'spent_on') && !$this->settings->bool('timelog_accept_future_dates')
            && $entry->spentOn > $this->i18n->today($entry->user)) {
            $errors[] = $this->i18n->l('error_spent_on_future_date');
        }
        if (!$this->settings->bool('timelog_accept_closed_issues') && null !== $entry->issue && $entry->issue->isClosed()) {
            $errors[] = $this->i18n->l('error_spent_on_closed_issue');
        }
        // custom fields (only editable ones, on creation or when changed)
        if (isset($entry->project) && (($st['new'] ?? false) || ($st['cf_changed'] ?? false))) {
            $values = $st['cf'] ?? $this->customValues($entry);
            foreach ($this->editableCustomFields($entry, $user) as $field) {
                $errors = array_merge($errors, $this->customFields->validate($field, $values[$field->id] ?? null, $entry->project));
            }
        }

        return array_values(array_unique($errors));
    }

    private function otherHoursWithSameUserAndDay(TimeEntry $entry): float
    {
        if (!isset($entry->user)) {
            return 0.0;
        }

        return (float) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(hours), 0) FROM time_entries WHERE user_id = ? AND spent_on = ? AND id <> ?',
            [$entry->user->id, $entry->spentOn->format('Y-m-d'), (int) $entry->id]
        );
    }

    /**
     * Validates and saves; returns errors (empty on success). Dispatches ContentEvent('time_entry', created|updated).
     *
     * @return list<string>
     */
    public function save(TimeEntry $entry, ?User $user = null): array
    {
        $user = $this->auth->user($user);
        $entry->author ??= $user;
        $errors = $this->validate($entry, $user);
        if ([] !== $errors) {
            return $errors;
        }
        $isNew = null === $entry->id;
        // round to the minute like TimeEntry#hours (rational with denominator 60)
        $entry->hours = round($entry->hours * 60) / 60;
        $entry->setSpentOn($entry->spentOn);
        if ($isNew) {
            $entry->createdOn = new \DateTimeImmutable();
            $this->em->persist($entry);
        }
        $entry->updatedOn = new \DateTimeImmutable();
        $this->em->flush();
        $st = $this->state[$entry] ?? [];
        if (\array_key_exists('cf', $st) && ($isNew || ($st['cf_changed'] ?? false))) {
            $fields = $this->editableCustomFields($entry, $user);
            if ($isNew) {
                // all fields get a value on creation (defaults for non editable ones)
                $fields = $this->customFields->fieldsOf(TimeEntryCustomField::class);
            }
            $this->customFields->saveValues('TimeEntry', (int) $entry->id, $fields, $st['cf']);
            $this->em->flush();
        }
        $this->snapshot($entry);
        $this->dispatcher->dispatch(new ContentEvent('time_entry', $isNew ? 'created' : 'updated', $entry, $user));

        return [];
    }

    public function destroy(TimeEntry $entry, ?User $user = null): void
    {
        $user = $this->auth->user($user);
        $this->dispatcher->dispatch(new ContentEvent('time_entry', 'deleted', $entry, $user));
        $this->customFields->deleteValues('TimeEntry', (int) $entry->id);
        $this->em->remove($entry);
        $this->em->flush();
    }

    /**
     * Visible entries by ids (find_with_preloads)
     *
     * @param list<int|string> $ids
     *
     * @return list<TimeEntry>
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ([] === $ids) {
            return [];
        }

        return $this->em->createQuery('SELECT te, p, u, a FROM App\Entity\TimeEntry te JOIN te.project p JOIN te.user u JOIN te.activity a WHERE te.id IN (:ids) ORDER BY te.id')
            ->setParameter('ids', $ids)->getResult();
    }

    /** API representation (timelog/show.api.rsb) */
    public function toApi(TimeEntry $e, ?User $user = null): array
    {
        $user = $this->auth->user($user);
        $data = ['id' => $e->id];
        $data['project'] = ['id' => $e->project->id, 'name' => $e->project->name];
        if (null !== $e->issue) {
            $data['issue'] = ['id' => $e->issue->id];
        }
        $data['user'] = ['id' => $e->user->id, 'name' => $e->user->getName()];
        $data['activity'] = ['id' => $e->activity->id, 'name' => $e->activity->name];
        $data['hours'] = round($e->hours, 2);
        $data['comments'] = $e->comments;
        $data['spent_on'] = $e->spentOn->format('Y-m-d');
        $data['created_on'] = \App\Api\ApiRenderer::time($e->createdOn);
        $data['updated_on'] = \App\Api\ApiRenderer::time($e->updatedOn);
        $fields = $this->editableCustomFields($e, $user);
        if ([] !== $fields) {
            $values = $this->customValues($e);
            $data['custom_fields'] = array_map(static fn ($f) => ['id' => $f->id, 'name' => $f->name] + ($f->isMultiple() ? ['multiple' => true] : []) + ['value' => $values[$f->id] ?? ($f->isMultiple() ? [] : null)], $fields);
        }

        return $data;
    }
}
