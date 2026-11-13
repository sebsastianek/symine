<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\DocumentCategory;
use App\Entity\Enumeration;
use App\Entity\IssuePriority;
use App\Entity\IssueQuery;
use App\Entity\IssueStatus;
use App\Entity\ProjectQuery;
use App\Entity\Query;
use App\Entity\Role;
use App\Entity\TimeEntryActivity;
use App\Entity\TimeEntryQuery;
use App\Entity\Tracker;
use App\Entity\WorkflowTransition;
use App\I18n\Localizer;
use App\Security\AccessControl;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Port of Redmine::DefaultData::Loader (lib/redmine/default_data/loader.rb):
 * default roles, trackers, issue statuses, workflows, enumerations and queries.
 */
final class DefaultDataLoader
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Localizer $i18n,
        private readonly Settings $settings,
    ) {
    }

    /** Returns true if no configuration data is loaded yet (Loader.no_data?) */
    public function noData(): bool
    {
        $count = fn (string $dql) => (int) $this->em->createQuery($dql)->getSingleScalarResult();

        return 0 === $count('SELECT COUNT(r.id) FROM App\Entity\Role r WHERE r.builtin = 0')
            && 0 === $count('SELECT COUNT(t.id) FROM App\Entity\Tracker t')
            && 0 === $count('SELECT COUNT(s.id) FROM App\Entity\IssueStatus s')
            && 0 === $count('SELECT COUNT(e.id) FROM App\Entity\Enumeration e')
            && 0 === $count('SELECT COUNT(q.id) FROM App\Entity\Query q');
    }

    /**
     * Loads the default data.
     *
     * @throws \RuntimeException when some configuration data is already loaded
     */
    public function load(?string $lang = null, bool $workflow = true): void
    {
        if (!$this->noData()) {
            throw new \RuntimeException('Some configuration data is already loaded.');
        }
        $locale = null !== $lang && \in_array($lang, $this->i18n->availableLocales(), true) ? $lang : 'en';
        $l = fn (string $key) => $this->i18n->l($key, [], $locale);

        $this->em->wrapInTransaction(function () use ($l, $workflow): void {
            // Roles
            $manager = $this->role($l('default_role_manager'), 1, AccessControl::setablePermissions(Role::BUILTIN_NONE));
            $manager->issuesVisibility = 'all';
            $manager->usersVisibility = 'all';
            $developer = $this->role($l('default_role_developer'), 2, [
                'manage_versions', 'manage_categories', 'view_issues', 'export_issues', 'add_issues', 'edit_issues',
                'view_private_notes', 'set_notes_private', 'manage_issue_relations', 'manage_subtasks', 'add_issue_notes',
                'save_queries', 'view_gantt', 'view_calendar', 'log_time', 'view_time_entries', 'export_time_entries',
                'view_news', 'comment_news', 'view_documents', 'view_wiki_pages', 'view_wiki_edits', 'edit_wiki_pages',
                'delete_wiki_pages', 'view_messages', 'add_messages', 'edit_own_messages', 'view_files', 'manage_files',
                'browse_repository', 'view_changesets', 'commit_access', 'manage_related_issues',
            ]);
            $reporter = $this->role($l('default_role_reporter'), 3, [
                'view_issues', 'export_issues', 'add_issues', 'add_issue_notes', 'save_queries', 'view_gantt',
                'view_calendar', 'log_time', 'view_time_entries', 'export_time_entries', 'view_news', 'comment_news',
                'view_documents', 'view_wiki_pages', 'view_wiki_edits', 'view_messages', 'add_messages',
                'edit_own_messages', 'view_files', 'browse_repository', 'view_changesets',
            ]);
            $this->builtinRole(Role::BUILTIN_NON_MEMBER)->setPermissions([
                'view_issues', 'export_issues', 'add_issues', 'add_issue_notes', 'save_queries', 'view_gantt',
                'view_calendar', 'view_time_entries', 'export_time_entries', 'view_news', 'comment_news',
                'view_documents', 'view_wiki_pages', 'view_wiki_edits', 'view_messages', 'add_messages', 'view_files',
                'browse_repository', 'view_changesets',
            ]);
            $this->builtinRole(Role::BUILTIN_ANONYMOUS)->setPermissions([
                'view_issues', 'view_gantt', 'view_calendar', 'view_time_entries', 'view_news', 'view_documents',
                'view_wiki_pages', 'view_wiki_edits', 'view_messages', 'view_files', 'browse_repository',
                'view_changesets',
            ]);

            // Issue statuses
            $new = $this->status($l('default_issue_status_new'), false, 1);
            $inProgress = $this->status($l('default_issue_status_in_progress'), false, 2);
            $resolved = $this->status($l('default_issue_status_resolved'), false, 3);
            $feedback = $this->status($l('default_issue_status_feedback'), false, 4);
            $closed = $this->status($l('default_issue_status_closed'), true, 5);
            $rejected = $this->status($l('default_issue_status_rejected'), true, 6);
            $statuses = [$new, $inProgress, $resolved, $feedback, $closed, $rejected];

            // Trackers
            $trackers = [
                $this->tracker($l('default_tracker_bug'), $new, false, 1),
                $this->tracker($l('default_tracker_feature'), $new, true, 2),
                $this->tracker($l('default_tracker_support'), $new, false, 3),
            ];
            $this->em->flush();

            $this->settings->set('default_projects_tracker_ids', array_map(static fn (Tracker $t) => (string) $t->id, $trackers));

            if ($workflow) {
                foreach ($trackers as $t) {
                    foreach ($statuses as $os) {
                        foreach ($statuses as $ns) {
                            if ($os !== $ns) {
                                $this->transition($t, $manager, $os, $ns);
                            }
                        }
                    }
                }
                foreach ($trackers as $t) {
                    foreach ([$new, $inProgress, $resolved, $feedback] as $os) {
                        foreach ([$inProgress, $resolved, $feedback, $closed] as $ns) {
                            if ($os !== $ns) {
                                $this->transition($t, $developer, $os, $ns);
                            }
                        }
                    }
                }
                foreach ($trackers as $t) {
                    foreach ([$new, $inProgress, $resolved, $feedback] as $os) {
                        $this->transition($t, $reporter, $os, $closed);
                    }
                    $this->transition($t, $reporter, $resolved, $feedback);
                }
            }

            // Enumerations
            $this->enumeration(new IssuePriority(), $l('default_priority_low'), 1);
            $this->enumeration(new IssuePriority(), $l('default_priority_normal'), 2, true);
            $this->enumeration(new IssuePriority(), $l('default_priority_high'), 3);
            $this->enumeration(new IssuePriority(), $l('default_priority_urgent'), 4);
            $this->enumeration(new IssuePriority(), $l('default_priority_immediate'), 5);
            $this->computePriorityPositionNames();

            $this->enumeration(new DocumentCategory(), $l('default_doc_category_user'), 1);
            $this->enumeration(new DocumentCategory(), $l('default_doc_category_tech'), 2);

            $this->enumeration(new TimeEntryActivity(), $l('default_activity_design'), 1);
            $this->enumeration(new TimeEntryActivity(), $l('default_activity_development'), 2);

            // Issue queries
            $open = ['status_id' => ['operator' => 'o', 'values' => ['']]];
            $active = ['project.status' => ['operator' => '=', 'values' => ['1']]];
            $this->query(new IssueQuery(), $l('label_assigned_to_me_issues'), $open + ['assigned_to_id' => ['operator' => '=', 'values' => ['me']]] + $active, [['priority', 'desc'], ['updated_on', 'desc']]);
            $this->query(new IssueQuery(), $l('label_reported_issues'), $open + ['author_id' => ['operator' => '=', 'values' => ['me']]] + $active, [['updated_on', 'desc']]);
            $this->query(new IssueQuery(), $l('label_updated_issues'), $open + ['updated_by' => ['operator' => '=', 'values' => ['me']]] + $active, [['updated_on', 'desc']]);
            $this->query(new IssueQuery(), $l('label_watched_issues'), $open + ['watcher_id' => ['operator' => '=', 'values' => ['me']]] + $active, [['updated_on', 'desc']]);

            // Project queries
            $this->query(new ProjectQuery(), $l('label_my_projects'), ['status' => ['operator' => '=', 'values' => ['1']], 'id' => ['operator' => '=', 'values' => ['mine']]], null);
            $this->query(new ProjectQuery(), $l('label_my_bookmarks'), ['status' => ['operator' => '=', 'values' => ['1']], 'id' => ['operator' => '=', 'values' => ['bookmarks']]], null);

            // Time entry queries
            $q = $this->query(new TimeEntryQuery(), $l('label_spent_time'), ['spent_on' => ['operator' => '*', 'values' => ['']], 'user_id' => ['operator' => '=', 'values' => ['me']]], [['spent_on', 'desc']]);
            $q->options = ['totalable_names' => ['hours']];

            $this->em->flush();
        });
        $this->settings->clearCache();
    }

    /** IssuePriority.compute_position_names: lowest / low2.. / default / high2.. / highest */
    public function computePriorityPositionNames(): void
    {
        $this->em->flush();
        /** @var list<IssuePriority> $priorities */
        $priorities = $this->em->createQuery('SELECT p FROM App\Entity\IssuePriority p WHERE p.active = true ORDER BY p.position ASC')->getResult();
        if ([] === $priorities) {
            return;
        }
        $default = $this->em->createQuery('SELECT p FROM App\Entity\IssuePriority p WHERE p.isDefault = true ORDER BY p.id ASC')->setMaxResults(1)->getOneOrNullResult()
            ?? $priorities[intdiv(\count($priorities) - 1, 2)];
        $defaultPosition = $default->position;
        $count = \count($priorities);
        foreach ($priorities as $i => $p) {
            $p->positionName = match (true) {
                $p->position === $defaultPosition => 'default',
                $p->position < $defaultPosition => 0 === $i ? 'lowest' : 'low'.($i + 1),
                default => $i === $count - 1 ? 'highest' : 'high'.($count - $i),
            };
        }
        $this->em->flush();
    }

    private function role(string $name, int $position, array $permissions): Role
    {
        $role = new Role();
        $role->name = $name;
        $role->position = $position;
        $role->setPermissions($permissions);
        $this->em->persist($role);

        return $role;
    }

    private function builtinRole(int $builtin): Role
    {
        $role = $this->em->getRepository(Role::class)->findOneBy(['builtin' => $builtin]);
        if (null === $role) {
            $role = new Role();
            $role->builtin = $builtin;
            $role->name = Role::BUILTIN_NON_MEMBER === $builtin ? 'Non member' : 'Anonymous';
            $role->position = Role::BUILTIN_NON_MEMBER === $builtin ? 1 : 2;
            $this->em->persist($role);
        }

        return $role;
    }

    private function status(string $name, bool $closed, int $position): IssueStatus
    {
        $s = new IssueStatus();
        $s->name = $name;
        $s->isClosed = $closed;
        $s->position = $position;
        $this->em->persist($s);

        return $s;
    }

    private function tracker(string $name, IssueStatus $default, bool $roadmap, int $position): Tracker
    {
        $t = new Tracker();
        $t->name = $name;
        $t->defaultStatus = $default;
        $t->isInRoadmap = $roadmap;
        $t->position = $position;
        $t->fieldsBits = 0;
        $this->em->persist($t);

        return $t;
    }

    private function transition(Tracker $t, Role $r, IssueStatus $os, IssueStatus $ns): void
    {
        $w = new WorkflowTransition();
        $w->trackerId = (int) $t->id;
        $w->roleId = (int) $r->id;
        $w->oldStatusId = (int) $os->id;
        $w->newStatusId = (int) $ns->id;
        $this->em->persist($w);
    }

    private function enumeration(Enumeration $e, string $name, int $position, bool $default = false): void
    {
        $e->name = $name;
        $e->position = $position;
        $e->isDefault = $default;
        $e->active = true;
        $this->em->persist($e);
    }

    private function query(Query $q, string $name, array $filters, ?array $sort): Query
    {
        $q->name = $name;
        $q->filters = $filters;
        $q->sortCriteria = $sort;
        $q->visibility = Query::VISIBILITY_PUBLIC;
        $q->userId = 0;
        $this->em->persist($q);

        return $q;
    }
}
