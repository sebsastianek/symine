<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\CustomField;
use App\Entity\Import;
use App\Entity\ImportItem;
use App\Entity\Issue;
use App\Entity\IssueCategory;
use App\Entity\IssueCustomField;
use App\Entity\IssuePriority;
use App\Entity\IssueRelation;
use App\Entity\IssueStatus;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Version;
use App\Service\IssueService;
use App\Service\ProjectService;

/**
 * Port of app/models/issue_import.rb. Issues are created through IssueService (safe attributes,
 * workflow, validation, journals, events) exactly like the web form.
 */
final class IssueImporter extends AbstractImporter
{
    public const array AUTO_MAPPABLE_FIELDS = [
        'tracker' => 'field_tracker',
        'subject' => 'field_subject',
        'description' => 'field_description',
        'status' => 'field_status',
        'priority' => 'field_priority',
        'category' => 'field_category',
        'assigned_to' => 'field_assigned_to',
        'fixed_version' => 'field_fixed_version',
        'is_private' => 'field_is_private',
        'parent_issue_id' => 'field_parent_issue',
        'start_date' => 'field_start_date',
        'due_date' => 'field_due_date',
        'estimated_hours' => 'field_estimated_hours',
        'done_ratio' => 'field_done_ratio',
        'unique_id' => 'field_unique_id',
        'relation_duplicates' => 'label_duplicates',
        'relation_duplicated' => 'label_duplicated_by',
        'relation_blocks' => 'label_blocks',
        'relation_blocked' => 'label_blocked_by',
        'relation_relates' => 'label_relates_to',
        'relation_precedes' => 'label_precedes',
        'relation_follows' => 'label_follows',
        'relation_copied_to' => 'label_copied_to',
        'relation_copied_from' => 'label_copied_from',
    ];

    public function __construct(
        private readonly IssueService $issues,
        private readonly ProjectService $projects,
    ) {
    }

    public static function type(): string
    {
        return 'IssueImport';
    }

    public static function partialPrefix(): string
    {
        return 'issues';
    }

    public function autoMappableFields(): array
    {
        return self::AUTO_MAPPABLE_FIELDS;
    }

    public function menuItem(): ?string
    {
        return 'issues';
    }

    public function isAuthorized(User $user): bool
    {
        return $this->auth->allowedToGlobally('import_issues', $user) && $this->auth->allowedToGlobally('add_issues', $user);
    }

    /** @return list<Issue> */
    public function savedObjects(Import $import): array
    {
        $ids = $this->savedObjectIds($import);

        return [] === $ids ? [] : $this->em->getRepository(Issue::class)->findBy(['id' => $ids], ['id' => 'ASC']);
    }

    /** @return list<Project> Project.allowed_to(user, :import_issues) */
    public function allowedTargetProjects(Import $import): array
    {
        $cond = $this->auth->allowedToCondition('import_issues', 'p.id', $import->user);

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

    /** @return list<Tracker> */
    public function allowedTargetTrackers(Import $import): array
    {
        $project = $this->project($import);

        return null === $project ? [] : $this->projects->allowedTargetTrackers($project, $import->user);
    }

    public function tracker(Import $import): ?Tracker
    {
        if (preg_match('/\Avalue:(\d+)\z/', (string) ($this->mapping($import)['tracker'] ?? ''), $m)) {
            foreach ($this->allowedTargetTrackers($import) as $t) {
                if ($t->id === (int) $m[1]) {
                    return $t;
                }
            }
        }

        return null;
    }

    public function canManageCategories(Import $import): bool
    {
        return $this->auth->allowedTo('manage_categories', $this->project($import), $import->user);
    }

    public function canManageVersions(Import $import): bool
    {
        return $this->auth->allowedTo('manage_versions', $this->project($import), $import->user);
    }

    public function createCategories(Import $import): bool
    {
        return $this->canManageCategories($import) && '1' === (string) ($this->mapping($import)['create_categories'] ?? '');
    }

    public function createVersions(Import $import): bool
    {
        return $this->canManageVersions($import) && '1' === (string) ($this->mapping($import)['create_versions'] ?? '');
    }

    /** @return list<CustomField> */
    public function mappableCustomFields(Import $import): array
    {
        $project = $this->project($import);
        $tracker = $this->tracker($import);
        if (null !== $project && null !== $tracker) {
            $issue = new Issue();
            $issue->project = $project;
            $issue->tracker = $tracker;
            $issue->author = $import->user;

            return $this->issues->editableCustomFields($issue, $import->user);
        }
        if (null !== $project) {
            $projectFieldIds = array_map(static fn ($f) => $f->id, $project->issueCustomFields->toArray());

            return array_values(array_filter(
                $this->em->getRepository(IssueCustomField::class)->findBy([], ['position' => 'ASC']),
                static fn (IssueCustomField $f) => $f->isForAll || \in_array($f->id, $projectFieldIds, true)
            ));
        }

        return [];
    }

    private function notificationsEnabled(Import $import): bool
    {
        return \in_array($this->settingsOf($import)['notifications'] ?? '0', ['1', 1, true, 'true'], true);
    }

    protected function beforeRun(Import $import): void
    {
        if (!$this->notificationsEnabled($import)) {
            ImportNotificationSuppressor::begin();
        }
    }

    protected function afterRun(Import $import): void
    {
        if (!$this->notificationsEnabled($import)) {
            ImportNotificationSuppressor::end();
        }
    }

    protected function importRow(Import $import, array $row, ImportItem $item): array
    {
        $user = $import->user;
        $mapping = $this->mapping($import);
        $errors = [];

        $issue = new Issue();
        $issue->author = $user;
        if ($p = $this->issues->defaultPriority()) {
            $issue->priority = $p;
        }

        // project: the mapped project, if the user can add issues to it
        $projectId = (int) ($mapping['project_id'] ?? 0);
        $project = null;
        foreach ($this->projects->allowedTargetProjects($user) as $p) {
            if ($p->id === $projectId) {
                $project = $p;
            }
        }
        if (null === $project) {
            return [null, [$this->error('field_project', 'blank')]];
        }
        $issue->project = $project;

        $trackerId = null;
        if ($t = $this->tracker($import)) {
            $trackerId = $t->id;
        } elseif (null !== ($trackerName = $this->rowValue($import, $row, 'tracker'))) {
            foreach ($this->projects->allowedTargetTrackers($project, $user) as $t) {
                if (0 === strcasecmp($t->name, trim($trackerName))) {
                    $trackerId = $t->id;
                    break;
                }
            }
        }

        $attributes = [
            'tracker_id' => $trackerId,
            'subject' => $this->rowValue($import, $row, 'subject') ?? '',
            'description' => $this->rowValue($import, $row, 'description'),
        ];
        if (null !== ($statusName = $this->rowValue($import, $row, 'status'))) {
            if ($status = $this->findNamed(IssueStatus::class, $statusName)) {
                $attributes['status_id'] = $status->id;
            }
        }
        if (null === $trackerId) {
            // Redmine resets tracker_id to nil when it differs from the requested one
            return [null, [$this->error('field_tracker', 'blank')]];
        }
        $this->issues->setSafeAttributes($issue, $attributes, $user);
        if (!isset($issue->tracker) || $issue->tracker->id !== $trackerId) {
            return [null, [$this->error('field_tracker', 'blank')]];
        }

        $attributes = [];
        if (null !== ($priorityName = $this->rowValue($import, $row, 'priority'))) {
            $priority = $this->findNamed(IssuePriority::class, $priorityName, ['active' => true]);
            if ($priority) {
                $attributes['priority_id'] = $priority->id;
            }
        }
        if (null !== ($categoryName = $this->rowValue($import, $row, 'category'))) {
            $category = null;
            foreach ($issue->project->issueCategories as $c) {
                if (0 === strcasecmp($c->name, trim($categoryName))) {
                    $category = $c;
                    break;
                }
            }
            if (null === $category && $this->createCategories($import) && mb_strlen(trim($categoryName)) <= 60) {
                $category = new IssueCategory();
                $category->project = $issue->project;
                $category->name = trim($categoryName);
                $this->em->persist($category);
                $this->em->flush();
                $issue->project->issueCategories->add($category);
            }
            if ($category) {
                $attributes['category_id'] = $category->id;
            }
        }
        if (null !== ($assigneeName = $this->rowValue($import, $row, 'assigned_to'))) {
            if ($assignee = self::detectByKeyword($this->issues->assignableUsers($issue), $assigneeName)) {
                $attributes['assigned_to_id'] = $assignee->id;
            }
        }
        if (null !== ($versionName = $this->rowValue($import, $row, 'fixed_version'))) {
            $version = null;
            foreach ($issue->project->versions as $v) {
                if (0 === strcasecmp((string) $v->name, trim($versionName))) {
                    $version = $v;
                    break;
                }
            }
            if (null === $version) {
                foreach ($this->projects->sharedVersions($issue->project) as $v) {
                    if (0 === strcasecmp((string) $v->name, trim($versionName))) {
                        $version = $v;
                        break;
                    }
                }
            }
            if (null === $version && $this->createVersions($import) && mb_strlen(trim($versionName)) <= 60) {
                $version = new Version();
                $version->project = $issue->project;
                $version->name = trim($versionName);
                $this->em->persist($version);
                $this->em->flush();
                $issue->project->versions->add($version);
            }
            if ($version) {
                $attributes['fixed_version_id'] = $version->id;
            }
        }
        if (null !== ($isPrivate = $this->rowValue($import, $row, 'is_private')) && $this->yes($import, $isPrivate)) {
            $attributes['is_private'] = '1';
        }
        if (null !== ($parent = $this->rowValue($import, $row, 'parent_issue_id'))) {
            $parent = trim($parent);
            if (str_starts_with($parent, '#')) {
                $attributes['parent_issue_id'] = substr($parent, 1);
            } elseif ($this->useUniqueId($import)) {
                if (null !== ($id = $this->itemObjId($import, 'unique_id', $parent))) {
                    $attributes['parent_issue_id'] = (string) $id;
                } else {
                    $this->addCallback($import, $parent, 'set_as_parent', [$item->position]);
                }
            } elseif (preg_match('/\A\d+\z/', $parent)) {
                $pos = (int) $parent;
                if ($pos > $item->position) {
                    $this->addCallback($import, $pos, 'set_as_parent', [$item->position]);
                } elseif (null !== ($id = $this->itemObjId($import, 'position', $pos))) {
                    $attributes['parent_issue_id'] = (string) $id;
                }
            } else {
                $attributes['parent_issue_id'] = $parent;
            }
        }
        foreach (['start_date', 'due_date'] as $f) {
            if (null !== ($d = $this->rowDate($import, $row, $f))) {
                if (self::isIsoDate($d)) {
                    $attributes[$f] = $d;
                } else {
                    $errors[] = $this->error('field_'.$f, 'not_a_date');
                }
            }
        }
        if (null !== ($h = $this->rowValue($import, $row, 'estimated_hours'))) {
            if (null === IssueService::parseHours($h)) {
                $errors[] = $this->error('field_estimated_hours', 'invalid');
            } else {
                $attributes['estimated_hours'] = $h;
            }
        }
        if (null !== ($ratio = $this->rowValue($import, $row, 'done_ratio'))) {
            if (!preg_match('/^\s*\d+\s*$/', $ratio) || (int) $ratio > 100) {
                $errors[] = $this->error('field_done_ratio', 'inclusion');
            } else {
                $attributes['done_ratio'] = trim($ratio);
            }
        }
        $attributes['custom_field_values'] = $this->customFieldValues($import, $row, $this->customFields->availableIssueFields($issue), $issue->project);
        $this->issues->setSafeAttributes($issue, $attributes, $user);

        if ([] !== $errors) {
            $errors = [...$errors, ...$this->issues->validate($issue, $user)];

            return [null, array_values(array_unique($errors))];
        }
        $errors = $this->issues->save($issue, $user);
        if ([] !== $errors) {
            return [null, $errors];
        }
        if (!$this->notificationsEnabled($import)) {
            ImportNotificationSuppressor::suppress((int) $issue->id);
        }

        return [$issue, []];
    }

    /** @template T of object @param class-string<T> $class @return T|null */
    private function findNamed(string $class, string $name, array $criteria = []): ?object
    {
        foreach ($this->em->getRepository($class)->findBy($criteria, ['position' => 'ASC']) as $o) {
            if (0 === strcasecmp($o->name, trim($name))) {
                return $o;
            }
        }

        return null;
    }

    protected function extendObject(Import $import, array $row, ImportItem $item, object $object): void
    {
        \assert($object instanceof Issue);
        foreach (array_keys(IssueRelation::TYPES) as $type) {
            $hasDelay = \in_array($type, [IssueRelation::TYPE_PRECEDES, IssueRelation::TYPE_FOLLOWS], true);
            foreach ($this->relationValues($import, $row, 'relation_'.$type) as $decl) {
                if (!$decl['matches'] || (null !== $decl['delay'] && !$hasDelay)) {
                    continue;
                }
                $toId = null;
                if (null !== $decl['other_id']) {
                    $toId = (int) $decl['other_id'];
                } elseif (null !== $decl['other_pos']) {
                    if ($this->useUniqueId($import)) {
                        $toId = $this->itemObjId($import, 'unique_id', (string) $decl['other_pos']);
                        if (null === $toId) {
                            $this->addCallback($import, (string) $decl['other_pos'], 'set_relation', [$item->position, $type, $decl['delay']]);
                            continue;
                        }
                    } elseif ((int) $decl['other_pos'] > $item->position) {
                        $this->addCallback($import, (int) $decl['other_pos'], 'set_relation', [$item->position, $type, $decl['delay']]);
                        continue;
                    } else {
                        $toId = $this->itemObjId($import, 'position', (int) $decl['other_pos']);
                    }
                }
                if (null !== $toId) {
                    $this->issues->createRelation($object, $toId, $type, null !== $decl['delay'] ? (int) $decl['delay'] : null, $import->user);
                }
            }
        }
    }

    /**
     * IssueImport#relation_values: "123" (row), "#123" (issue id), "123 7d" (with delay), or unique ids.
     *
     * @param list<string|null> $row
     *
     * @return list<array{matches: bool, declaration: string, delay: string|null, other_id: string|null, other_pos: int|string|null}>
     */
    private function relationValues(Import $import, array $row, string $name): array
    {
        $content = $this->rowValue($import, $row, $name);
        if (null === $content) {
            return [];
        }
        $out = [];
        foreach (explode(',', $content) as $declaration) {
            $declaration = trim($declaration);
            $result = ['matches' => false, 'declaration' => $declaration, 'delay' => null, 'other_id' => null, 'other_pos' => null];
            if (preg_match('/\A(?<unique_id>(?<is_id>#)?(?<id>\d+)|.+?)(?:\s+(?<delay>-?\d+)d)?\z/', $declaration, $m)) {
                $result['matches'] = true;
                $result['delay'] = isset($m['delay']) && '' !== $m['delay'] ? $m['delay'] : null;
                if ('' !== ($m['is_id'] ?? '') && '' !== ($m['id'] ?? '')) {
                    $result['other_id'] = $m['id'];
                } elseif ($this->useUniqueId($import) && '' !== ($m['unique_id'] ?? '')) {
                    $result['other_pos'] = $m['unique_id'];
                } elseif ('' !== ($m['id'] ?? '')) {
                    $result['other_pos'] = (int) $m['id'];
                } else {
                    $result['matches'] = false;
                }
            }
            $out[] = $result;
        }

        return $out;
    }

    protected function callback(Import $import, string $name, ?object $object, array $args): void
    {
        if (!$object instanceof Issue || null === $object->id) {
            return;
        }
        if ('set_as_parent' === $name) {
            $childId = $this->itemObjId($import, 'position', (int) $args[0]);
            $child = null !== $childId ? $this->em->find(Issue::class, $childId) : null;
            if (null === $child) {
                return;
            }
            $child->parent = $object;
            $this->issues->save($child, $import->user);
            $this->em->refresh($object);
        } elseif ('set_relation' === $name) {
            $fromId = $this->itemObjId($import, 'position', (int) $args[0]);
            $from = null !== $fromId ? $this->em->find(Issue::class, $fromId) : null;
            if (null === $from) {
                return;
            }
            $delay = $args[2] ?? null;
            $this->issues->createRelation($from, $object, (string) $args[1], null !== $delay && '' !== $delay ? (int) $delay : null, $import->user);
        }
    }
}
