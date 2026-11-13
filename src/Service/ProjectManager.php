<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Board;
use App\Entity\Document;
use App\Entity\Group;
use App\Entity\Issue;
use App\Entity\IssueCategory;
use App\Entity\IssueCustomField;
use App\Entity\Member;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\ProjectCustomField;
use App\Entity\Query;
use App\Entity\Role;
use App\Entity\TimeEntryActivity;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Version;
use App\Entity\Wiki;
use App\Entity\WikiContent;
use App\Entity\WikiPage;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Security\CurrentUser;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Project lifecycle (port of Redmine's Project model: safe_attributes, validations, archive/close,
 * copy, destroy, time entry activities overrides, principals_by_role).
 */
final class ProjectManager
{
    public const array COPY_ITEMS = ['members', 'wiki', 'versions', 'issue_categories', 'issues', 'queries', 'boards', 'documents'];

    /** @var array<int, array<int|string, mixed>> pending custom values by spl_object_id */
    private array $pendingCustomValues = [];
    /** @var array<int, bool> */
    private array $unallowedParent = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly ProjectService $projects,
        private readonly MembershipService $memberships,
        private readonly CustomFieldService $customFields,
        private readonly IssueService $issues,
        private readonly ?\App\Plugin\Hook\Hooks $hooks = null,
    ) {
    }

    /** Project.new with Redmine defaults (public, modules, trackers, sequential identifier) */
    public function newProject(): Project
    {
        $project = new Project();
        $this->projects->applyDefaults($project);
        if ($this->settings->bool('sequential_project_identifiers')) {
            $project->identifier = $this->projects->nextIdentifier();
        }
        $this->pendingCustomValues[spl_object_id($project)] = $this->customFields->defaultValues($this->customFields->fieldsOf(ProjectCustomField::class));

        return $project;
    }

    /** Project.copy_from: unsaved copy of the source attributes */
    public function copyFrom(Project $source): Project
    {
        $p = new Project();
        $p->description = $source->description;
        $p->homepage = $source->homepage;
        $p->isPublic = $source->isPublic;
        $p->inheritMembers = $source->inheritMembers;
        $p->defaultAssignedTo = $source->defaultAssignedTo;
        $p->setEnabledModuleNames($source->getEnabledModuleNames());
        foreach ($source->trackers as $t) {
            $p->trackers->add($t);
        }
        foreach ($source->issueCustomFields as $f) {
            $p->issueCustomFields->add($f);
        }
        $this->pendingCustomValues[spl_object_id($p)] = $this->customFields->values('Project', $source->id);
        if ($this->settings->bool('sequential_project_identifiers')) {
            $p->identifier = $this->projects->nextIdentifier();
        }

        return $p;
    }

    public function defaultMemberRole(): ?Role
    {
        $repo = $this->em->getRepository(Role::class);
        $id = $this->settings->int('new_project_user_role_id');
        if ($id > 0) {
            $role = $repo->find($id);
            if ($role instanceof Role && Role::BUILTIN_NONE === $role->builtin) {
                return $role;
            }
        }

        return $repo->findOneBy(['builtin' => Role::BUILTIN_NONE], ['position' => 'ASC']);
    }

    /** Project#safe_attribute? for the conditional attributes */
    public function isSafeAttribute(Project $project, string $name, ?User $user = null): bool
    {
        $user = $this->auth->user($user);
        $perm = match ($name) {
            'is_public' => 'select_project_publicity',
            'enabled_module_names' => 'select_project_modules',
            'inherit_members' => 'manage_members',
            default => null,
        };
        if (null === $perm) {
            return true;
        }
        if ('inherit_members' === $name && null !== $project->parent && !$this->auth->isProjectVisible($project->parent, $user)) {
            return false;
        }
        if (null === $project->id) {
            if ($user->admin) {
                return true;
            }
            $role = $this->defaultMemberRole();

            return null !== $role && $this->auth->roleAllowedTo($role, $perm);
        }

        return $this->auth->allowedTo($perm, $project, $user);
    }

    /**
     * Project#allowed_parents: projects the project can be moved to; contains null when it can be a root project.
     *
     * @return list<Project|null>
     */
    public function allowedParents(Project $project, ?User $user = null): array
    {
        $user = $this->auth->user($user);
        $cond = $this->auth->allowedToCondition('add_subprojects', 'p.id', $user);
        $list = $this->em->createQuery("SELECT p FROM App\Entity\Project p WHERE $cond ORDER BY p.lft")->getResult();
        if (null !== $project->id) {
            $exclude = array_map(static fn (Project $p) => $p->id, $project->getSelfAndDescendants());
            $list = array_values(array_filter($list, static fn (Project $p) => !\in_array($p->id, $exclude, true)));
        }
        if ($this->auth->allowedToGlobally('add_project', $user) || (null !== $project->id && null === $project->parent)) {
            $list[] = null;
        }
        if (null !== $project->parent && [] !== $list && !\in_array($project->parent, $list, true)) {
            $list[] = $project->parent;
        }

        return $list;
    }

    /** @return list<ProjectCustomField> custom fields visible by the user for the project */
    public function visibleCustomFields(Project $project, ?User $user = null): array
    {
        $user = $this->auth->user($user);
        $roles = null !== $project->id ? $this->auth->rolesForProject($project, $user) : [];

        return array_values(array_filter(
            $this->customFields->fieldsOf(ProjectCustomField::class),
            fn ($f) => $this->customFields->isVisibleForRoles($f, $roles, $user),
        ));
    }

    /** @return array<int, mixed> fieldId => value (pending values first) */
    public function customValues(Project $project): array
    {
        $pending = $this->pendingCustomValues[spl_object_id($project)] ?? null;
        $saved = null !== $project->id ? $this->customFields->values('Project', $project->id) : [];

        return null === $pending ? $saved : $pending + $saved;
    }

    /**
     * Project#safe_attributes= : applies request attributes.
     *
     * @param array<string, mixed> $attrs
     */
    public function setSafeAttributes(Project $project, array $attrs, ?User $user = null): void
    {
        $user = $this->auth->user($user);
        $oid = spl_object_id($project);
        $isNew = null === $project->id;
        unset($this->unallowedParent[$oid]);

        if ($isNew || \array_key_exists('parent_id', $attrs)) {
            $param = (string) (\is_array($attrs['parent_id'] ?? null) ? '' : ($attrs['parent_id'] ?? ''));
            if ($isNew || $param !== (string) ($project->parent?->id ?? '')) {
                $parent = '' !== $param ? $this->em->find(Project::class, (int) $param) : null;
                if (\in_array($parent, $this->allowedParents($project, $user), true)) {
                    $project->parent = $parent;
                } else {
                    $this->unallowedParent[$oid] = true;
                }
            }
        }
        foreach (['name', 'description', 'homepage'] as $f) {
            if (\array_key_exists($f, $attrs) && !\is_array($attrs[$f])) {
                $project->{$f} = null === $attrs[$f] ? null : (string) $attrs[$f];
            }
        }
        if ('' === $project->name) {
            $project->name = '';
        }
        if (\array_key_exists('identifier', $attrs) && !$this->isIdentifierFrozen($project)) {
            $project->identifier = (string) $attrs['identifier'];
        }
        if (\array_key_exists('is_public', $attrs) && $this->isSafeAttribute($project, 'is_public', $user)) {
            $project->isPublic = self::bool($attrs['is_public']);
        }
        if (\array_key_exists('inherit_members', $attrs) && $this->isSafeAttribute($project, 'inherit_members', $user)) {
            $project->inheritMembers = self::bool($attrs['inherit_members']);
        }
        if (\array_key_exists('enabled_module_names', $attrs) && $this->isSafeAttribute($project, 'enabled_module_names', $user)) {
            $names = array_values(array_filter(array_map('strval', (array) $attrs['enabled_module_names']), static fn ($n) => '' !== $n));
            $project->setEnabledModuleNames($names);
        }
        if (\array_key_exists('tracker_ids', $attrs)) {
            $ids = array_map('intval', array_filter((array) $attrs['tracker_ids'], static fn ($v) => '' !== (string) $v));
            $project->trackers->clear();
            foreach ([] === $ids ? [] : $this->em->getRepository(Tracker::class)->findBy(['id' => $ids], ['position' => 'ASC']) as $t) {
                $project->trackers->add($t);
            }
        }
        if (\array_key_exists('issue_custom_field_ids', $attrs)) {
            $ids = array_map('intval', array_filter((array) $attrs['issue_custom_field_ids'], static fn ($v) => '' !== (string) $v));
            $project->issueCustomFields->clear();
            foreach ([] === $ids ? [] : $this->em->getRepository(IssueCustomField::class)->findBy(['id' => $ids]) as $f) {
                $project->issueCustomFields->add($f);
            }
        }
        if (\array_key_exists('default_version_id', $attrs)) {
            $v = '' !== (string) $attrs['default_version_id'] ? $this->em->find(Version::class, (int) $attrs['default_version_id']) : null;
            $project->defaultVersion = $v;
        }
        if (\array_key_exists('default_assigned_to_id', $attrs)) {
            $project->defaultAssignedTo = '' !== (string) $attrs['default_assigned_to_id'] ? $this->em->find(Principal::class, (int) $attrs['default_assigned_to_id']) : null;
        }
        if (\array_key_exists('default_issue_query_id', $attrs)) {
            $project->defaultIssueQueryId = '' !== (string) $attrs['default_issue_query_id'] ? (int) $attrs['default_issue_query_id'] : null;
        }
        $values = [];
        if (isset($attrs['custom_field_values']) && \is_array($attrs['custom_field_values'])) {
            $values = $attrs['custom_field_values'];
        }
        if (isset($attrs['custom_fields']) && \is_array($attrs['custom_fields'])) {
            foreach ($attrs['custom_fields'] as $cf) {
                if (\is_array($cf) && isset($cf['id'])) {
                    $values[(int) $cf['id']] = $cf['value'] ?? null;
                }
            }
        }
        if ([] !== $values) {
            $editable = array_map(static fn ($f) => (string) $f->id, $this->visibleCustomFields($project, $user));
            $values = array_filter($values, static fn ($k) => \in_array((string) $k, $editable, true), \ARRAY_FILTER_USE_KEY);
            $current = $this->pendingCustomValues[$oid] ?? [];
            foreach ($values as $k => $v) {
                $current[(int) $k] = $v;
            }
            $this->pendingCustomValues[$oid] = $current;
        }
    }

    public function isIdentifierFrozen(Project $project): bool
    {
        return null !== $project->id && null !== $project->identifier && '' !== $project->identifier;
    }

    /** @return list<string> full error messages */
    public function validate(Project $project): array
    {
        $errors = [];
        $msg = fn (string $field, string $key, array $p = []) => $this->i18n->l('field_'.$field).' '.$this->i18n->l('activerecord.errors.messages.'.$key, $p);
        if ('' === trim($project->name)) {
            $errors[] = $msg('name', 'blank');
        } elseif (mb_strlen($project->name) > 255) {
            $errors[] = $msg('name', 'too_long', ['count' => 255]);
        }
        $identifier = (string) $project->identifier;
        $identifierChanged = null === $project->id || $identifier !== $this->originalIdentifier($project);
        if ('' === $identifier) {
            $errors[] = $msg('identifier', 'blank');
        } else {
            if (\strlen($identifier) > Project::IDENTIFIER_MAX_LENGTH) {
                $errors[] = $msg('identifier', 'too_long', ['count' => Project::IDENTIFIER_MAX_LENGTH]);
            }
            if ($identifierChanged && !preg_match('/\A(?!\d+$)[a-z0-9\-_]*\z/', $identifier)) {
                $errors[] = $msg('identifier', 'invalid');
            }
            if ($identifierChanged && \in_array($identifier, ['new', 'autocomplete', 'bulk_destroy'], true)) {
                $errors[] = $msg('identifier', 'exclusion');
            }
            if ($identifierChanged) {
                $other = $this->em->getRepository(Project::class)->findOneBy(['identifier' => $identifier]);
                if (null !== $other && $other !== $project) {
                    $errors[] = $msg('identifier', 'taken');
                }
            }
        }
        if (null !== $project->homepage && mb_strlen($project->homepage) > 255) {
            $errors[] = $msg('homepage', 'too_long', ['count' => 255]);
        }
        if ($this->unallowedParent[spl_object_id($project)] ?? false) {
            $errors[] = $msg('parent', 'invalid');
        } elseif (null !== $project->parent && $this->parentChanged($project)) {
            if (!$project->parent->isActive() || (null !== $project->id && ($project->parent === $project || $project->parent->isDescendantOf($project)))) {
                $errors[] = $msg('parent', 'invalid');
            }
        }
        $pending = $this->pendingCustomValues[spl_object_id($project)] ?? null;
        if (null !== $pending) {
            foreach ($this->visibleCustomFields($project) as $field) {
                $value = $pending[$field->id] ?? (null === $project->id ? null : ($this->customFields->values('Project', $project->id)[$field->id] ?? null));
                array_push($errors, ...$this->customFields->validate($field, $value, $project));
            }
        } elseif (null === $project->id) {
            foreach ($this->visibleCustomFields($project) as $field) {
                array_push($errors, ...$this->customFields->validate($field, null, $project));
            }
        }

        return $errors;
    }

    private function originalIdentifier(Project $project): ?string
    {
        $orig = $this->em->getUnitOfWork()->getOriginalEntityData($project);

        return $orig['identifier'] ?? null;
    }

    private function parentChanged(Project $project): bool
    {
        if (null === $project->id) {
            return true;
        }
        $orig = $this->em->getUnitOfWork()->getOriginalEntityData($project);

        return ($orig['parent'] ?? null) !== $project->parent;
    }

    /**
     * Validates and saves the project (callbacks: nested set, inherited members, custom values).
     *
     * @return list<string> errors
     */
    public function save(Project $project, ?User $user = null): array
    {
        $user = $this->auth->user($user);
        $errors = $this->validate($project);
        if ([] !== $errors) {
            return $errors;
        }
        $isNew = null === $project->id;
        $oid = spl_object_id($project);
        $orig = $isNew ? [] : $this->em->getUnitOfWork()->getOriginalEntityData($project);
        $parentChanged = $isNew || ($orig['parent'] ?? null) !== $project->parent;
        $inheritChanged = !$isNew && (bool) ($orig['inheritMembers'] ?? false) !== $project->inheritMembers;
        if (null === $project->homepage) {
            $project->homepage = '';
        }
        $this->em->persist($project);
        $this->em->flush();
        $pending = $this->pendingCustomValues[$oid] ?? null;
        if (null !== $pending) {
            $this->customFields->saveValues('Project', (int) $project->id, $this->visibleCustomFields($project, $user), $pending);
            $this->em->flush();
            unset($this->pendingCustomValues[$oid]);
        }
        if ($parentChanged) {
            $this->projects->rebuildTree();
        }
        if ($parentChanged || $inheritChanged) {
            $this->memberships->updateInheritedMembers($project);
            if (!$isNew && $parentChanged) {
                $this->updateVersionsFromHierarchyChange($project);
            }
        }
        $this->auth->reset();

        return [];
    }

    /** Issue.update_versions_from_hierarchy_change: unassign versions no longer shared */
    private function updateVersionsFromHierarchyChange(Project $project): void
    {
        foreach ($project->getSelfAndDescendants() as $p) {
            $shared = array_map(static fn (Version $v) => $v->id, $this->projects->sharedVersions($p));
            $issues = $this->em->createQuery('SELECT i FROM App\Entity\Issue i WHERE i.project = :p AND i.fixedVersion IS NOT NULL')->setParameter('p', $p)->getResult();
            foreach ($issues as $issue) {
                if (!\in_array($issue->fixedVersion->id, $shared, true)) {
                    $issue->fixedVersion = null;
                    $this->issues->save($issue);
                }
            }
        }
    }

    public function addDefaultMember(Project $project, User $user): void
    {
        $role = $this->defaultMemberRole();
        if (null !== $role) {
            $this->memberships->addMember($project, $user, [$role]);
        }
    }

    // --- status -------------------------------------------------------------------------------

    /** Project#archive (false when versions are used by issues of other projects) */
    public function archive(Project $project): bool
    {
        $ids = array_map(static fn (Project $p) => (int) $p->id, $project->getSelfAndDescendants());
        $conn = $this->em->getConnection();
        $versionIds = $conn->fetchFirstColumn('SELECT id FROM versions WHERE project_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
        if ([] !== $versionIds) {
            $used = $conn->fetchOne(
                'SELECT COUNT(*) FROM issues i JOIN projects p ON p.id = i.project_id WHERE (p.lft < ? OR p.rgt > ?) AND i.fixed_version_id IN (?)',
                [$project->lft, $project->rgt, $versionIds],
                [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER, ArrayParameterType::INTEGER],
            );
            if ((int) $used > 0) {
                return false;
            }
        }
        $this->setStatus($ids, Project::STATUS_ARCHIVED);

        return true;
    }

    /** Project#unarchive: unarchives the project and its archived ancestors */
    public function unarchive(Project $project): void
    {
        $closedAncestor = false;
        foreach ($project->getAncestors() as $a) {
            $closedAncestor = $closedAncestor || $a->isClosed();
        }
        $ids = array_map(static fn (Project $p) => (int) $p->id, [...$project->getAncestors(), $project]);
        $this->setStatus($ids, $closedAncestor ? Project::STATUS_CLOSED : Project::STATUS_ACTIVE, Project::STATUS_ARCHIVED);
    }

    public function close(Project $project): void
    {
        $this->setStatus(array_map(static fn (Project $p) => (int) $p->id, $project->getSelfAndDescendants()), Project::STATUS_CLOSED, Project::STATUS_ACTIVE);
    }

    public function reopen(Project $project): void
    {
        $this->setStatus(array_map(static fn (Project $p) => (int) $p->id, $project->getSelfAndDescendants()), Project::STATUS_ACTIVE, Project::STATUS_CLOSED);
    }

    /** @param list<int> $ids */
    private function setStatus(array $ids, int $status, ?int $onlyFrom = null): void
    {
        $sql = 'UPDATE projects SET status = ?, updated_on = ? WHERE id IN (?)';
        $params = [$status, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $ids];
        $types = [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::STRING, ArrayParameterType::INTEGER];
        if (null !== $onlyFrom) {
            $sql .= ' AND status = ?';
            $params[] = $onlyFrom;
            $types[] = \Doctrine\DBAL\ParameterType::INTEGER;
        }
        $this->em->getConnection()->executeStatement($sql, $params, $types);
        foreach ($this->em->getUnitOfWork()->getIdentityMap()[Project::class] ?? [] as $p) {
            if (\in_array($p->id, $ids, true)) {
                $this->em->refresh($p);
            }
        }
        $this->auth->reset();
    }

    public function isDeletable(Project $project, ?User $user = null): bool
    {
        $user = $this->auth->user($user);
        if ($user->admin) {
            return true;
        }

        return $this->auth->allowedTo('delete_project', $project, $user) && 0 === $project->children->count();
    }

    // --- destroy ------------------------------------------------------------------------------

    /** Deletes the project, its subprojects and all their data (synchronously) */
    public function destroy(Project $project): void
    {
        $user = $this->currentUser->get();
        $all = $project->getSelfAndDescendants();
        $ids = array_map(static fn (Project $p) => (int) $p->id, $all);
        // issues first (journals, relations, attachments...) through the issue service
        $issues = $this->em->createQuery('SELECT i FROM App\Entity\Issue i WHERE IDENTITY(i.project) IN (:ids) AND i.parent IS NULL')->setParameter('ids', $ids)->getResult();
        if ([] !== $issues) {
            $this->issues->destroy($issues, 'destroy', null, $user);
        }
        $remaining = $this->em->createQuery('SELECT i FROM App\Entity\Issue i WHERE IDENTITY(i.project) IN (:ids)')->setParameter('ids', $ids)->getResult();
        if ([] !== $remaining) {
            $this->issues->destroy($remaining, 'destroy', null, $user);
        }
        $this->em->clear();

        $conn = $this->em->getConnection();
        $a = [ArrayParameterType::INTEGER];
        $conn->beginTransaction();
        try {
            $ex = static fn (string $sql) => $conn->executeStatement($sql, [$ids], $a);
            $col = static fn (string $sql) => array_map('intval', $conn->fetchFirstColumn($sql, [$ids], $a));
            $versionIds = $col('SELECT id FROM versions WHERE project_id IN (?)');
            $newsIds = $col('SELECT id FROM news WHERE project_id IN (?)');
            $documentIds = $col('SELECT id FROM documents WHERE project_id IN (?)');
            $boardIds = $col('SELECT id FROM boards WHERE project_id IN (?)');
            $wikiIds = $col('SELECT id FROM wikis WHERE project_id IN (?)');
            $repoIds = $col('SELECT id FROM repositories WHERE project_id IN (?)');
            $queryIds = $col('SELECT id FROM queries WHERE project_id IN (?)');
            $memberIds = $col('SELECT id FROM members WHERE project_id IN (?)');
            $by = static function (string $sql, array $list) use ($conn, $a): array {
                return [] === $list ? [] : array_map('intval', $conn->fetchFirstColumn($sql, [$list], $a));
            };
            $exList = static function (string $sql, array $list) use ($conn, $a): void {
                if ([] !== $list) {
                    $conn->executeStatement($sql, [$list], $a);
                }
            };
            $messageIds = $by('SELECT id FROM messages WHERE board_id IN (?)', $boardIds);
            $pageIds = $by('SELECT id FROM wiki_pages WHERE wiki_id IN (?)', $wikiIds);
            $contentIds = $by('SELECT id FROM wiki_contents WHERE page_id IN (?)', $pageIds);
            $changesetIds = $by('SELECT id FROM changesets WHERE repository_id IN (?)', $repoIds);

            // attachments of all containers
            foreach (['Project' => $ids, 'Version' => $versionIds, 'News' => $newsIds, 'Document' => $documentIds, 'Message' => $messageIds, 'WikiPage' => $pageIds] as $type => $list) {
                $exList("DELETE FROM attachments WHERE container_type = '$type' AND container_id IN (?)", $list);
            }
            // watchers
            foreach (['News' => $newsIds, 'Board' => $boardIds, 'Message' => $messageIds, 'Wiki' => $wikiIds, 'WikiPage' => $pageIds] as $type => $list) {
                $exList("DELETE FROM watchers WHERE watchable_type = '$type' AND watchable_id IN (?)", $list);
            }
            $exList("DELETE FROM reactions WHERE reactable_type = 'News' AND reactable_id IN (?)", $newsIds);
            $exList("DELETE FROM reactions WHERE reactable_type = 'Message' AND reactable_id IN (?)", $messageIds);
            $exList("DELETE FROM comments WHERE commented_type = 'News' AND commented_id IN (?)", $newsIds);
            $exList('DELETE FROM news WHERE id IN (?)', $newsIds);
            $exList("DELETE FROM custom_values WHERE customized_type = 'Document' AND customized_id IN (?)", $documentIds);
            $exList('DELETE FROM documents WHERE id IN (?)', $documentIds);
            $exList('UPDATE messages SET parent_id = NULL, last_reply_id = NULL WHERE id IN (?)', $messageIds);
            $exList('UPDATE boards SET last_message_id = NULL, parent_id = NULL WHERE id IN (?)', $boardIds);
            $exList('DELETE FROM messages WHERE id IN (?)', $messageIds);
            $exList('DELETE FROM boards WHERE id IN (?)', $boardIds);
            $exList('DELETE FROM wiki_content_versions WHERE wiki_content_id IN (?)', $contentIds);
            $exList('DELETE FROM wiki_contents WHERE id IN (?)', $contentIds);
            $exList('UPDATE wiki_pages SET parent_id = NULL WHERE id IN (?)', $pageIds);
            $exList('DELETE FROM wiki_pages WHERE id IN (?)', $pageIds);
            $exList('DELETE FROM wiki_redirects WHERE wiki_id IN (?)', $wikiIds);
            $exList('DELETE FROM wikis WHERE id IN (?)', $wikiIds);
            $exList('DELETE FROM changes WHERE changeset_id IN (?)', $changesetIds);
            $exList('DELETE FROM changesets_issues WHERE changeset_id IN (?)', $changesetIds);
            $exList('DELETE FROM changeset_parents WHERE changeset_id IN (?) OR parent_id IN (?)', $changesetIds);
            $exList('DELETE FROM changesets WHERE id IN (?)', $changesetIds);
            $exList('DELETE FROM repositories WHERE id IN (?)', $repoIds);
            $ex('DELETE FROM time_entries WHERE project_id IN (?)');
            $exList('DELETE FROM queries_roles WHERE query_id IN (?)', $queryIds);
            $exList('DELETE FROM queries WHERE id IN (?)', $queryIds);
            $exList('DELETE FROM member_roles WHERE member_id IN (?)', $memberIds);
            $exList('DELETE FROM members WHERE id IN (?)', $memberIds);
            // versions: issues of other projects using them are unassigned
            $exList('UPDATE issues SET fixed_version_id = NULL WHERE fixed_version_id IN (?)', $versionIds);
            $exList("DELETE FROM custom_values WHERE customized_type = 'Version' AND customized_id IN (?)", $versionIds);
            $exList('UPDATE projects SET default_version_id = NULL WHERE default_version_id IN (?)', $versionIds);
            $exList('DELETE FROM versions WHERE id IN (?)', $versionIds);
            $ex('DELETE FROM issue_categories WHERE project_id IN (?)');
            $ex('DELETE FROM enabled_modules WHERE project_id IN (?)');
            $ex('DELETE FROM projects_trackers WHERE project_id IN (?)');
            $ex('DELETE FROM custom_fields_projects WHERE project_id IN (?)');
            $ex('DELETE FROM projects_webhooks WHERE project_id IN (?)');
            $ex("DELETE FROM custom_values WHERE customized_type = 'Project' AND customized_id IN (?)");
            $activityIds = $col("SELECT id FROM enumerations WHERE type = 'TimeEntryActivity' AND project_id IN (?)");
            $exList("DELETE FROM custom_values WHERE customized_type = 'Enumeration' AND customized_id IN (?)", $activityIds);
            $ex('DELETE FROM enumerations WHERE project_id IN (?)');
            $ex('UPDATE projects SET parent_id = NULL WHERE id IN (?)');
            $ex('DELETE FROM projects WHERE id IN (?)');
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
        $this->projects->rebuildTree();
        $this->auth->reset();
    }

    // --- copy ---------------------------------------------------------------------------------

    /**
     * Project#copy: saves $target then copies the selected items from $source.
     *
     * @param list<string>|null $only
     *
     * @return list<string> errors (validation of the new project)
     */
    public function copy(Project $source, Project $target, ?array $only = null, ?User $user = null): array
    {
        $user = $this->auth->user($user);
        $items = null === $only ? self::COPY_ITEMS : array_values(array_intersect(self::COPY_ITEMS, $only));
        $errors = $this->save($target, $user);
        if ([] !== $errors) {
            return $errors;
        }
        $this->copyAttachments('Project', (int) $source->id, (int) $target->id);
        $versionMap = [];
        $categoryMap = [];
        // Redmine order: members wiki versions issue_categories issues queries boards documents
        foreach ($items as $item) {
            match ($item) {
                'members' => $this->copyMembers($source, $target),
                'wiki' => $this->copyWiki($source, $target),
                'versions' => $versionMap = $this->copyVersions($source, $target),
                'issue_categories' => $categoryMap = $this->copyCategories($source, $target),
                'issues' => $this->copyIssues($source, $target, $versionMap, $categoryMap, $user),
                'queries' => $this->copyQueries($source, $target),
                'boards' => $this->copyBoards($source, $target),
                'documents' => $this->copyDocuments($source, $target),
                default => null,
            };
        }
        $this->hooks?->call('model_project_copy_before_save', ['source_project' => $source, 'destination_project' => $target]);
        $this->em->flush();
        $this->auth->reset();

        return [];
    }

    private function copyAttachments(string $type, int $fromId, int $toId): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO attachments (container_id, container_type, filename, disk_filename, filesize, content_type, digest, downloads, author_id, created_on, description, disk_directory)
             SELECT ?, container_type, filename, disk_filename, filesize, content_type, digest, 0, author_id, ?, description, disk_directory FROM attachments WHERE container_type = ? AND container_id = ?',
            [$toId, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $type, $fromId],
        );
    }

    private function copyMembers(Project $source, Project $target): void
    {
        $members = $this->em->getRepository(Member::class)->findBy(['project' => $source]);
        usort($members, static fn (Member $a, Member $b) => [$a->principal instanceof Group ? 1 : 0, $a->id] <=> [$b->principal instanceof Group ? 1 : 0, $b->id]);
        foreach ($members as $m) {
            $roles = [];
            foreach ($m->memberRoles as $mr) {
                if (null === $mr->inheritedFrom) {
                    $roles[] = $mr->role;
                }
            }
            if ([] === $roles) {
                continue;
            }
            $this->memberships->addMember($target, $m->principal, $roles, $m->mailNotification);
        }
    }

    /** @return array<int, Version> source version id => copy */
    private function copyVersions(Project $source, Project $target): array
    {
        $map = [];
        foreach ($this->em->getRepository(Version::class)->findBy(['project' => $source]) as $v) {
            $c = new Version();
            $c->project = $target;
            foreach (['name', 'description', 'effectiveDate', 'wikiPageTitle', 'status', 'sharing'] as $f) {
                $c->{$f} = $v->{$f};
            }
            $this->em->persist($c);
            $this->em->flush();
            $this->copyAttachments('Version', (int) $v->id, (int) $c->id);
            $map[(int) $v->id] = $c;
            if ($source->defaultVersion?->id === $v->id) {
                $target->defaultVersion = $c;
            }
        }
        $this->em->flush();

        return $map;
    }

    /** @return array<int, IssueCategory> */
    private function copyCategories(Project $source, Project $target): array
    {
        $map = [];
        foreach ($this->em->getRepository(IssueCategory::class)->findBy(['project' => $source]) as $cat) {
            $c = new IssueCategory();
            $c->project = $target;
            $c->name = $cat->name;
            $c->assignedTo = $cat->assignedTo;
            $this->em->persist($c);
            $map[(int) $cat->id] = $c;
        }
        $this->em->flush();

        return $map;
    }

    /**
     * @param array<int, Version>       $versionMap
     * @param array<int, IssueCategory> $categoryMap
     */
    private function copyIssues(Project $source, Project $target, array $versionMap, array $categoryMap, User $user): void
    {
        // reopen locked/closed versions during the copy
        $statuses = [];
        foreach ($versionMap as $v) {
            if (!$v->isOpen()) {
                $statuses[] = [$v, $v->status];
                $v->status = 'open';
            }
        }
        $this->em->flush();
        $issues = $this->em->createQuery('SELECT i FROM App\Entity\Issue i WHERE i.project = :p ORDER BY i.rootId, i.lft')->setParameter('p', $source)->getResult();
        $map = [];
        foreach ($issues as $issue) {
            $copy = $this->issues->copyFrom($issue, $user, ['subtasks' => false, 'link' => false]);
            $copy->project = $target;
            $copy->author = $issue->author;
            $copy->fixedVersion = null;
            if (null !== $issue->fixedVersion) {
                if ($issue->fixedVersion->project->id === $source->id) {
                    $copy->fixedVersion = $versionMap[(int) $issue->fixedVersion->id] ?? null;
                } else {
                    $copy->fixedVersion = $issue->fixedVersion;
                }
            }
            $copy->category = null !== $issue->category ? ($categoryMap[(int) $issue->category->id] ?? null) : null;
            $copy->parent = null !== $issue->parent ? ($map[(int) $issue->parent->id] ?? null) : null;
            $errors = $this->issues->save($copy, $user);
            if ([] === $errors) {
                $map[(int) $issue->id] = $copy;
            }
        }
        foreach ($statuses as [$v, $status]) {
            $v->status = $status;
        }
        $this->em->flush();
        // relations
        $conn = $this->em->getConnection();
        $crossProject = $this->settings->bool('cross_project_issue_relations');
        $sourceIds = array_keys($map);
        if ([] === $sourceIds) {
            return;
        }
        $rels = $conn->fetchAllAssociative('SELECT * FROM issue_relations WHERE issue_from_id IN (?) OR issue_to_id IN (?)', [$sourceIds, $sourceIds], [ArrayParameterType::INTEGER, ArrayParameterType::INTEGER]);
        foreach ($rels as $r) {
            $from = isset($map[(int) $r['issue_from_id']]) ? $map[(int) $r['issue_from_id']]->id : ($crossProject ? (int) $r['issue_from_id'] : null);
            $to = isset($map[(int) $r['issue_to_id']]) ? $map[(int) $r['issue_to_id']]->id : ($crossProject ? (int) $r['issue_to_id'] : null);
            if (null === $from || null === $to) {
                continue;
            }
            $conn->executeStatement('INSERT INTO issue_relations (issue_from_id, issue_to_id, relation_type, delay) VALUES (?, ?, ?, ?)', [$from, $to, $r['relation_type'], $r['delay']]);
        }
    }

    private function copyQueries(Project $source, Project $target): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->em->getRepository(Query::class)->findBy(['project' => $source]) as $q) {
            $c = clone $q;
            $c->id = null;
            $c->project = $target;
            $c->roles = new \Doctrine\Common\Collections\ArrayCollection($q->roles->toArray());
            $this->em->persist($c);
            $this->em->flush();
            if ($source->defaultIssueQueryId === $q->id) {
                $target->defaultIssueQueryId = $c->id;
            }
        }
        unset($conn);
    }

    private function copyBoards(Project $source, Project $target): void
    {
        $map = [];
        $boards = $this->em->getRepository(Board::class)->findBy(['project' => $source], ['position' => 'ASC']);
        foreach ($boards as $b) {
            $c = new Board();
            $c->project = $target;
            $c->name = $b->name;
            $c->description = $b->description;
            $c->position = $b->position;
            $this->em->persist($c);
            $map[(int) $b->id] = $c;
        }
        foreach ($boards as $b) {
            if (null !== $b->parent && isset($map[(int) $b->parent->id])) {
                $map[(int) $b->id]->parent = $map[(int) $b->parent->id];
            }
        }
        $this->em->flush();
    }

    private function copyDocuments(Project $source, Project $target): void
    {
        foreach ($this->em->getRepository(Document::class)->findBy(['project' => $source]) as $d) {
            $c = new Document();
            $c->project = $target;
            $c->category = $d->category;
            $c->title = $d->title;
            $c->description = $d->description;
            $c->createdOn = $d->createdOn;
            $this->em->persist($c);
            $this->em->flush();
            $this->copyAttachments('Document', (int) $d->id, (int) $c->id);
        }
    }

    private function copyWiki(Project $source, Project $target): void
    {
        $wiki = $this->em->getRepository(Wiki::class)->findOneBy(['project' => $source]);
        if (null === $wiki) {
            return;
        }
        $new = $this->em->getRepository(Wiki::class)->findOneBy(['project' => $target]) ?? new Wiki();
        $new->project = $target;
        $new->startPage = $wiki->startPage;
        $new->status = $wiki->status;
        $this->em->persist($new);
        $this->em->flush();
        $map = [];
        $pages = $this->em->getRepository(WikiPage::class)->findBy(['wiki' => $wiki]);
        foreach ($pages as $page) {
            if (null === $page->content) {
                continue;
            }
            $np = new WikiPage();
            $np->wiki = $new;
            $np->title = $page->title;
            $np->protected = $page->protected;
            $this->em->persist($np);
            $nc = new WikiContent();
            $nc->page = $np;
            $nc->author = $page->content->author;
            $nc->text = $page->content->text;
            $nc->comments = $page->content->comments;
            $nc->version = $page->content->version;
            $np->content = $nc;
            $this->em->persist($nc);
            $this->em->flush();
            $this->copyAttachments('WikiPage', (int) $page->id, (int) $np->id);
            $map[(int) $page->id] = $np;
        }
        foreach ($pages as $page) {
            if (null !== $page->parent && isset($map[(int) $page->id], $map[(int) $page->parent->id])) {
                $map[(int) $page->id]->parent = $map[(int) $page->parent->id];
            }
        }
        $this->em->flush();
    }

    /** @return array<string, int> counts of copyable items of the source project (copy form) */
    public function copyCounts(Project $source): array
    {
        $c = $this->em->getConnection();
        $id = (int) $source->id;
        $wikiPages = (int) $c->fetchOne('SELECT COUNT(*) FROM wiki_pages wp JOIN wikis w ON w.id = wp.wiki_id WHERE w.project_id = ?', [$id]);

        return [
            'members' => (int) $c->fetchOne('SELECT COUNT(*) FROM members m JOIN users u ON u.id = m.user_id WHERE m.project_id = ? AND u.status = 1', [$id]),
            'versions' => (int) $c->fetchOne('SELECT COUNT(*) FROM versions WHERE project_id = ?', [$id]),
            'issue_categories' => (int) $c->fetchOne('SELECT COUNT(*) FROM issue_categories WHERE project_id = ?', [$id]),
            'issues' => (int) $c->fetchOne('SELECT COUNT(*) FROM issues WHERE project_id = ?', [$id]),
            'queries' => (int) $c->fetchOne('SELECT COUNT(*) FROM queries WHERE project_id = ?', [$id]),
            'documents' => (int) $c->fetchOne('SELECT COUNT(*) FROM documents WHERE project_id = ?', [$id]),
            'boards' => (int) $c->fetchOne('SELECT COUNT(*) FROM boards WHERE project_id = ?', [$id]),
            'wiki' => $wikiPages,
        ];
    }

    // --- members / overview -------------------------------------------------------------------

    /** @return list<array{role: Role, principals: list<Principal>}> Project#principals_by_role sorted by role */
    public function principalsByRole(Project $project): array
    {
        $members = $this->em->createQuery(
            'SELECT m, mr, r, pr FROM App\Entity\Member m JOIN m.memberRoles mr JOIN mr.role r JOIN m.principal pr
             WHERE m.project = :p AND pr.status = 1 AND pr NOT INSTANCE OF App\Entity\GroupBuiltin'
        )->setParameter('p', $project)->getResult();
        $out = [];
        foreach ($members as $m) {
            foreach ($m->getRoles() as $r) {
                $out[$r->id] ??= ['role' => $r, 'principals' => []];
                $out[$r->id]['principals'][$m->principal->id] = $m->principal;
            }
        }
        usort($out, static fn ($a, $b) => [$a['role']->builtin, $a['role']->position] <=> [$b['role']->builtin, $b['role']->position]);
        foreach ($out as &$row) {
            $list = array_values($row['principals']);
            // Principal#<=> : users before groups, then by name
            usort($list, static fn (Principal $a, Principal $b) => [$a instanceof Group ? 1 : 0, mb_strtolower($a->getName())] <=> [$b instanceof Group ? 1 : 0, mb_strtolower($b->getName())]);
            $row['principals'] = $list;
        }

        return array_values($out);
    }

    // --- time entry activities ----------------------------------------------------------------

    /** @return list<TimeEntryActivity> Project#activities: system activities overridden by project ones */
    public function activities(Project $project, bool $includeInactive = false): array
    {
        $all = $this->em->createQuery(
            'SELECT a FROM App\Entity\TimeEntryActivity a WHERE a.project IS NULL OR a.project = :p ORDER BY a.position ASC'
        )->setParameter('p', $project)->getResult();
        $overridden = [];
        foreach ($all as $a) {
            if (null !== $a->project && null !== $a->parent) {
                $overridden[] = $a->parent->id;
            }
        }
        $list = array_values(array_filter($all, static fn (TimeEntryActivity $a) => !\in_array($a->id, $overridden, true) && ($includeInactive || $a->active)));
        if (!$includeInactive) {
            return $list;
        }

        return $list;
    }

    /** Project#update_or_create_time_entry_activities @param array<int|string, array<string, mixed>> $data */
    public function updateActivities(Project $project, array $data): void
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            foreach ($data as $id => $attrs) {
                if (!\is_array($attrs)) {
                    continue;
                }
                $active = self::bool($attrs['active'] ?? '0');
                $cfValues = \is_array($attrs['custom_field_values'] ?? null) ? $attrs['custom_field_values'] : [];
                if (\array_key_exists('parent_id', $attrs) && '' !== (string) $attrs['parent_id']) {
                    $parent = $this->em->find(TimeEntryActivity::class, (int) $attrs['parent_id']);
                    if (null === $parent || null !== $parent->project) {
                        continue;
                    }
                    $parentValues = $this->customFields->values('Enumeration', $parent->id);
                    $changed = $active !== $parent->active;
                    foreach ($cfValues as $k => $v) {
                        if ((string) ($parentValues[(int) $k] ?? '') !== (string) (\is_array($v) ? implode(',', $v) : $v)) {
                            $changed = true;
                        }
                    }
                    if (!$changed) {
                        continue;
                    }
                    $a = new TimeEntryActivity();
                    $a->name = $parent->name;
                    $a->position = $parent->position;
                    $a->isDefault = false;
                    $a->active = $active;
                    $a->project = $project;
                    $a->parent = $parent;
                    $this->em->persist($a);
                    $this->em->flush();
                    if ([] !== $cfValues) {
                        $this->customFields->saveValues('Enumeration', (int) $a->id, $this->customFields->fieldsOf(\App\Entity\TimeEntryActivityCustomField::class), $cfValues);
                    }
                    $conn->executeStatement('UPDATE time_entries SET activity_id = ? WHERE project_id = ? AND activity_id = ?', [$a->id, $project->id, $parent->id]);
                } else {
                    $a = $this->em->find(TimeEntryActivity::class, (int) $id);
                    if (null === $a || $a->project?->id !== $project->id) {
                        continue;
                    }
                    $a->active = $active;
                    if ([] !== $cfValues) {
                        $this->customFields->saveValues('Enumeration', (int) $a->id, $this->customFields->fieldsOf(\App\Entity\TimeEntryActivityCustomField::class), $cfValues);
                    }
                }
            }
            $this->em->flush();
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /** ProjectEnumerationsController#destroy: removes overrides, reassigning time entries to the system activity */
    public function resetActivities(Project $project): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->em->getRepository(TimeEntryActivity::class)->findBy(['project' => $project]) as $a) {
            if (null !== $a->parent) {
                $conn->executeStatement('UPDATE time_entries SET activity_id = ? WHERE activity_id = ?', [$a->parent->id, $a->id]);
            }
            $this->customFields->deleteValues('Enumeration', (int) $a->id);
            $this->em->remove($a);
        }
        $this->em->flush();
    }

    // --- bookmarks (Redmine::ProjectJumpBox) --------------------------------------------------

    public function findProject(int $id): ?Project
    {
        return $this->em->find(Project::class, $id);
    }

    /** @return list<int> */
    public function bookmarkedProjectIds(User $user): array
    {
        if (!$user->isLogged()) {
            return [];
        }

        return self::idList($user->getPref()->get('bookmarked_project_ids'));
    }

    /** @return list<int> */
    public function recentlyUsedProjectIds(User $user): array
    {
        if (!$user->isLogged()) {
            return [];
        }
        $count = (int) ($user->getPref()->get('recently_used_projects') ?? 3) ?: 3;

        return \array_slice(self::idList($user->getPref()->get('recently_used_project_ids')), 0, $count);
    }

    public function bookmark(User $user, Project $project, bool $add): void
    {
        $ids = $this->bookmarkedProjectIds($user);
        $recent = array_values(array_filter(self::idList($user->getPref()->get('recently_used_project_ids')), static fn ($id) => $id !== $project->id));
        if ($add) {
            if (!\in_array($project->id, $ids, true)) {
                $ids[] = (int) $project->id;
            }
            $this->setPrefIds($user, 'recently_used_project_ids', $recent);
        } else {
            $ids = array_values(array_filter($ids, static fn ($id) => $id !== $project->id));
        }
        $this->setPrefIds($user, 'bookmarked_project_ids', $ids);
    }

    /** ProjectJumpBox#project_used */
    public function projectUsed(User $user, Project $project): void
    {
        if (!$user->isLogged() || null === $project->id) {
            return;
        }
        if (\in_array($project->id, $this->bookmarkedProjectIds($user), true)) {
            return;
        }
        $count = (int) ($user->getPref()->get('recently_used_projects') ?? 3) ?: 3;
        $ids = array_values(array_filter(self::idList($user->getPref()->get('recently_used_project_ids')), static fn ($id) => $id !== $project->id));
        array_unshift($ids, (int) $project->id);
        $this->setPrefIds($user, 'recently_used_project_ids', \array_slice($ids, 0, $count));
    }

    /** @param list<int> $ids */
    private function setPrefIds(User $user, string $key, array $ids): void
    {
        $pref = $user->getPref();
        $value = implode(',', $ids);
        if ((string) $pref->get($key) === $value) {
            return;
        }
        $pref->set($key, $value);
        $this->em->persist($pref);
        $this->em->flush();
    }

    /** @return list<int> */
    private static function idList(mixed $v): array
    {
        if (\is_array($v)) {
            return array_values(array_map('intval', $v));
        }
        $v = trim((string) $v);

        return '' === $v ? [] : array_values(array_map('intval', explode(',', $v)));
    }

    public static function bool(mixed $v): bool
    {
        if (\is_array($v)) {
            $v = end($v);
        }

        return \in_array($v, [true, 1, '1', 'true', 'yes', 'on'], true);
    }
}
