<?php

declare(strict_types=1);

namespace App\Service\Tracking\ContextMenu;

use App\Entity\CustomField;
use App\Entity\Issue;
use App\Entity\IssuePriority;
use App\Entity\IssueStatus;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\Version;
use App\Security\Authorization;
use App\Service\CustomFieldService;
use App\Service\IssueService;
use App\Service\ProjectService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What can be done to all the selected issues at once (ContextMenusController#issues): the actions the user is
 * allowed, and the values every issue accepts for each attribute.
 */
final class IssueBulkChoices
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly IssueService $issues,
        private readonly ProjectService $projects,
        private readonly CustomFieldService $customFields,
    ) {
    }

    /** @return array{edit: bool, log_time: bool, copy: bool, add_watchers: bool, delete: bool, add_subtask: bool} */
    public function can(IssueSelection $selection): array
    {
        $allProjects = fn (string $perm) => array_reduce($selection->projects, fn (bool $c, Project $p) => $c && $this->auth->allowedTo($perm, $p), true);

        return [
            'edit' => array_reduce($selection->issues, fn (bool $c, Issue $i) => $c && $this->issues->isAttributesEditable($i), true),
            'log_time' => null !== $selection->issue && $this->issues->isTimeLoggable($selection->issue),
            'copy' => $allProjects('copy_issues') && [] !== $this->projects->allowedTargetProjects(),
            'add_watchers' => $allProjects('add_issue_watchers'),
            'delete' => array_reduce($selection->issues, fn (bool $c, Issue $i) => $c && $this->issues->isDeletable($i), true),
            'add_subtask' => null !== $selection->issue && !$selection->issue->isClosed() && $this->auth->allowedTo('manage_subtasks', $selection->project),
        ];
    }

    /** @return list<IssueStatus> */
    public function statuses(IssueSelection $selection): array
    {
        return self::intersect(array_map(fn (Issue $i) => $this->issues->newStatusesAllowedTo($i), $selection->issues));
    }

    /** @return list<Principal> */
    public function assignables(IssueSelection $selection): array
    {
        return self::intersect(array_map(fn (Issue $i) => $this->issues->assignableUsers($i), $selection->issues));
    }

    /** @return list<Tracker> */
    public function trackers(IssueSelection $selection): array
    {
        return self::intersect(array_map(fn (Project $p) => $this->projects->allowedTargetTrackers($p), $selection->projects));
    }

    /** @return list<Version> the open versions shared with every project */
    public function versions(IssueSelection $selection): array
    {
        $versions = self::intersect(array_map(fn (Project $p) => array_values(array_filter($this->projects->sharedVersions($p), static fn (Version $v) => $v->isOpen())), $selection->projects));

        return $this->projects->sortVersions($versions);
    }

    /** @return list<IssuePriority> */
    public function priorities(): array
    {
        return $this->em->getRepository(IssuePriority::class)->findBy(['active' => true], ['position' => 'DESC']);
    }

    /** @return list<string> the attributes the user may set on every issue */
    public function safeAttributes(IssueSelection $selection): array
    {
        $safe = null;
        foreach ($selection->issues as $i) {
            $names = $this->issues->safeAttributeNames($i);
            $safe = null === $safe ? $names : array_values(array_intersect($safe, $names));
        }

        return $safe ?? [];
    }

    public function isPriorityDerived(IssueSelection $selection): bool
    {
        return array_reduce($selection->issues, fn (bool $c, Issue $i) => $c || $this->issues->isPriorityDerived($i), false);
    }

    public function isDoneRatioDerived(IssueSelection $selection): bool
    {
        return array_reduce($selection->issues, fn (bool $c, Issue $i) => $c || $this->issues->isDoneRatioDerived($i), false);
    }

    /**
     * Custom fields editable on all the issues, with their possible values ([value, text]).
     *
     * @return list<array{0: CustomField, 1: list<array{0: string, 1: string}>}>
     */
    public function customFieldOptions(IssueSelection $selection): array
    {
        $fields = self::intersect(array_map(fn (Issue $i) => $this->issues->editableCustomFields($i), $selection->issues));
        $out = [];
        foreach ($fields as $field) {
            if ($field->isMultiple() || !\in_array($field->fieldFormat, ['list', 'bool', 'enumeration', 'user', 'version'], true)) {
                continue;
            }
            $values = null;
            foreach ($selection->projects as $p) {
                $opts = $this->customFields->possibleValuesOptions($field, $p);
                if (null === $values) {
                    $values = $opts;
                } else {
                    $keys = array_map(static fn ($o) => (string) $o[0], $opts);
                    $values = array_values(array_filter($values, static fn ($o) => \in_array((string) $o[0], $keys, true)));
                }
            }
            if ([] !== ($values ?? [])) {
                $out[] = [$field, array_map(static fn ($o) => [(string) $o[0], (string) $o[1]], $values)];
            }
        }

        return $out;
    }

    /** The value of a custom field of an issue, as it is stored */
    public function customFieldValue(Issue $issue, CustomField $field): mixed
    {
        return $this->customFields->values('Issue', (int) $issue->id)[$field->id] ?? null;
    }

    /**
     * Intersection of lists of entities (by id), keeping the order of the first list.
     *
     * @param list<list<object>> $lists
     */
    private static function intersect(array $lists): array
    {
        if ([] === $lists) {
            return [];
        }
        $result = array_shift($lists);
        foreach ($lists as $list) {
            $ids = array_map(static fn ($o) => $o->id, $list);
            $result = array_values(array_filter($result, static fn ($o) => \in_array($o->id, $ids, true)));
        }

        return $result;
    }
}
