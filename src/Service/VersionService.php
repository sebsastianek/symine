<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Issue;
use App\Entity\IssueCategory;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Version;
use App\Entity\VersionCustomField;
use App\Entity\Wiki;
use App\Entity\WikiPage;
use App\I18n\Localizer;
use App\Security\Authorization;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Port of Redmine's Version model logic (incl. FixedIssuesExtension) and IssueCategory model logic.
 */
final class VersionService
{
    public const array STATUS_BY_CRITERIAS = ['tracker', 'status', 'priority', 'author', 'assigned_to', 'category'];

    /** @var array<string, array<string, mixed>> */
    private array $statsCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly ProjectService $projects,
        private readonly IssueService $issues,
        private readonly CustomFieldService $customFields,
        private readonly Localizer $i18n,
        private readonly ?\Symfony\Contracts\EventDispatcher\EventDispatcherInterface $dispatcher = null,
    ) {
    }

    public function reset(): void
    {
        $this->statsCache = [];
    }

    // ------------------------------------------------------------------------------------------
    // Fixed issues statistics (FixedIssuesExtension)
    // ------------------------------------------------------------------------------------------

    /**
     * @return array{count: int, open: int, closed: int, estimated_hours: float, estimated_remaining_hours: float,
     *               completed_percent: float, closed_percent: float}
     */
    public function stats(Version $version, bool $visible = false, ?User $user = null): array
    {
        $user = $this->auth->user($user);
        $key = $version->id.'|'.($visible ? 'v'.$user->id : 'all');
        if (isset($this->statsCache[$key])) {
            return $this->statsCache[$key];
        }
        $dql = 'SELECT i, s FROM App\Entity\Issue i JOIN i.status s WHERE i.fixedVersion = :v';
        if ($visible) {
            $dql .= ' AND '.$this->issues->visibleCondition($user, 'i');
        }
        /** @var list<Issue> $list */
        $list = $this->em->createQuery($dql)->setParameter('v', $version->id)->getResult();
        $open = 0;
        $closed = 0;
        $est = 0.0;
        $remaining = 0.0;
        foreach ($list as $i) {
            $i->status->isClosed ? ++$closed : ++$open;
            $est += (float) ($i->estimatedHours ?? 0);
            $remaining += (float) ($i->estimatedHours ?? 0) * (100 - $i->doneRatio) / 100;
        }
        $count = $open + $closed;
        $completed = 0.0;
        $closedPct = 0.0;
        if ($count > 0) {
            if (0 === $open) {
                $completed = $closedPct = 100.0;
            } else {
                $totals = [];
                foreach ($list as $i) {
                    $totals[$i->id] = $this->issues->totalEstimatedHours($i);
                }
                $withEst = array_filter($totals, static fn (float $h) => $h > 0.0);
                $average = [] !== $withEst ? array_sum($withEst) / \count($withEst) : 1.0;
                $progress = function (bool $isOpen) use ($list, $totals, $average, $count): float {
                    $done = 0.0;
                    foreach ($list as $i) {
                        if ($i->status->isClosed === $isOpen) {
                            continue;
                        }
                        $e = $totals[$i->id];
                        $e = $e > 0.0 ? $e : $average;
                        $done += $e * ($isOpen ? $i->doneRatio : 100);
                    }

                    return $done / ($average * $count);
                };
                $closedPct = $progress(false);
                $completed = $closedPct + $progress(true);
            }
        }

        return $this->statsCache[$key] = [
            'count' => $count, 'open' => $open, 'closed' => $closed,
            'estimated_hours' => $est, 'estimated_remaining_hours' => $remaining,
            'completed_percent' => $completed, 'closed_percent' => $closedPct,
        ];
    }

    public function spentHours(Version $version): float
    {
        return (float) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(t.hours), 0) FROM time_entries t JOIN issues i ON i.id = t.issue_id WHERE i.fixed_version_id = ?',
            [$version->id]
        );
    }

    public function startDate(Version $version): ?\DateTimeImmutable
    {
        $d = $this->em->getConnection()->fetchOne('SELECT MIN(start_date) FROM issues WHERE fixed_version_id = ?', [$version->id]);

        return $d ? new \DateTimeImmutable(substr((string) $d, 0, 10)) : null;
    }

    public function isCompleted(Version $version): bool
    {
        if ($version->isClosed()) {
            return true;
        }

        return null !== $version->effectiveDate
            && $version->effectiveDate < $this->i18n->today()
            && 0 === $this->stats($version)['open'];
    }

    public function isOverdue(Version $version): bool
    {
        return null !== $version->effectiveDate && $version->effectiveDate < $this->i18n->today() && $this->stats($version)['open'] > 0;
    }

    public function isBehindSchedule(Version $version): bool
    {
        $start = $this->startDate($version);
        $pct = $this->stats($version)['completed_percent'];
        if (null === $version->effectiveDate || null === $start || $pct >= 100.0) {
            return false;
        }
        $days = (int) $start->diff($version->effectiveDate)->format('%r%a') + 1;
        $done = $start->modify('+'.(int) floor($days * $pct / 100).' days');

        return $done <= $this->i18n->today();
    }

    public function cssClasses(Version $version): string
    {
        return ($this->isCompleted($version) ? 'version-completed' : 'version-incompleted').' version-'.$version->status;
    }

    /** @return list<string> Version#allowed_sharings */
    public function allowedSharings(Version $version, ?User $user = null): array
    {
        $user = $this->auth->user($user);
        $out = [];
        foreach (Version::SHARINGS as $s) {
            $ok = match (true) {
                $version->sharing === $s => true,
                'system' === $s => $user->admin,
                \in_array($s, ['hierarchy', 'tree'], true) => !isset($version->project) || $this->auth->allowedTo('manage_versions', $version->project->getRoot(), $user),
                default => true,
            };
            if ($ok) {
                $out[] = $s;
            }
        }

        return $out;
    }

    public function isDeletable(Version $version): bool
    {
        $conn = $this->em->getConnection();
        if ((int) $conn->fetchOne('SELECT COUNT(*) FROM issues WHERE fixed_version_id = ?', [$version->id]) > 0) {
            return false;
        }
        if ((int) $conn->fetchOne("SELECT COUNT(*) FROM custom_values cv JOIN custom_fields cf ON cf.id = cv.custom_field_id WHERE cf.field_format = 'version' AND cv.value = ?", [(string) $version->id]) > 0) {
            return false;
        }

        return 0 === (int) $conn->fetchOne("SELECT COUNT(*) FROM attachments WHERE container_type = 'Version' AND container_id = ?", [$version->id]);
    }

    public function wiki(Project $project): ?Wiki
    {
        return $this->em->getRepository(Wiki::class)->findOneBy(['project' => $project]);
    }

    public function wikiPage(Version $version): ?WikiPage
    {
        if (null === $version->wikiPageTitle || '' === trim($version->wikiPageTitle)) {
            return null;
        }
        $wiki = $this->wiki($version->project);
        if (null === $wiki) {
            return null;
        }
        $title = Wiki::titleize($version->wikiPageTitle);
        foreach ($this->em->getRepository(WikiPage::class)->findBy(['wiki' => $wiki]) as $page) {
            if (0 === strcasecmp($page->title, $title)) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Issue counts of the visible fixed issues grouped by a criteria (VersionsHelper#render_issue_status_by).
     *
     * @return list<array{group: object|null, label: string, id: int|null, total: int, open: int, closed: int}>
     */
    public function statusBy(Version $version, string $criteria, ?User $user = null): array
    {
        if (!\in_array($criteria, self::STATUS_BY_CRITERIAS, true)) {
            $criteria = 'tracker';
        }
        $prop = match ($criteria) {
            'assigned_to' => 'assignedTo',
            default => $criteria,
        };
        $cond = $this->issues->visibleCondition($this->auth->user($user), 'i');
        $issues = $this->em->createQuery("SELECT i, s FROM App\Entity\Issue i JOIN i.status s WHERE i.fixedVersion = :v AND $cond")
            ->setParameter('v', $version->id)->getResult();
        $groups = [];
        foreach ($issues as $i) {
            $g = $i->{$prop};
            $k = null === $g ? '' : (string) $g->id;
            $groups[$k] ??= ['group' => $g, 'total' => 0, 'open' => 0];
            ++$groups[$k]['total'];
            if (!$i->status->isClosed) {
                ++$groups[$k]['open'];
            }
        }
        $sortKey = static function ($g): array {
            if (null === $g) {
                return [1, 0, ''];
            }
            if (property_exists($g, 'position') && null !== $g->position && !$g instanceof IssueCategory) {
                return [0, $g->position, ''];
            }
            if ($g instanceof Principal) {
                return [0, 0, mb_strtolower($g->getName())];
            }

            return [0, 0, mb_strtolower((string) $g)];
        };
        $list = array_values($groups);
        usort($list, static fn ($a, $b) => $sortKey($a['group']) <=> $sortKey($b['group']));

        return array_map(static fn ($c) => [
            'group' => $c['group'],
            'label' => null === $c['group'] ? '' : ($c['group'] instanceof Principal ? $c['group']->getName() : (string) $c['group']),
            'id' => $c['group']?->id,
            'total' => $c['total'], 'open' => $c['open'], 'closed' => $c['total'] - $c['open'],
        ], $list);
    }

    // ------------------------------------------------------------------------------------------
    // Custom fields
    // ------------------------------------------------------------------------------------------

    /** @return list<VersionCustomField> custom fields visible by the user for the version's project */
    public function visibleCustomFields(Version $version, ?User $user = null): array
    {
        $user = $this->auth->user($user);
        $roles = isset($version->project) ? $this->auth->rolesForProject($version->project, $user) : [];

        return array_values(array_filter(
            $this->customFields->fieldsOf(VersionCustomField::class),
            fn ($f) => $this->customFields->isVisibleForRoles($f, $roles, $user)
        ));
    }

    /** @return list<array{field: VersionCustomField, value: mixed}> */
    public function customValues(Version $version, ?User $user = null): array
    {
        $values = $this->customFields->values('Version', $version->id);
        $out = [];
        foreach ($this->visibleCustomFields($version, $user) as $f) {
            $out[] = ['field' => $f, 'value' => $values[$f->id] ?? ($f->isMultiple() ? [] : null)];
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------
    // Attributes, validation, persistence
    // ------------------------------------------------------------------------------------------

    /**
     * Version#safe_attributes= (sharing is ignored when not allowed, like the controller does).
     *
     * @param array<string, mixed> $attrs
     *
     * @return array{default_project_version: bool, custom_field_values: array<int|string, mixed>, invalid_date: bool}
     */
    public function assign(Version $version, array $attrs, ?User $user = null): array
    {
        $extra = ['default_project_version' => false, 'custom_field_values' => [], 'invalid_date' => false];
        if (\array_key_exists('sharing', $attrs) && !\in_array((string) $attrs['sharing'], $this->allowedSharings($version, $user), true)) {
            unset($attrs['sharing']);
        }
        foreach (['name', 'description', 'wiki_page_title', 'status', 'sharing'] as $k) {
            if (\array_key_exists($k, $attrs)) {
                $v = null === $attrs[$k] ? null : trim((string) $attrs[$k]);
                match ($k) {
                    'name' => $version->name = $v,
                    'description' => $version->description = $v ?? '',
                    'wiki_page_title' => $version->wikiPageTitle = ('' === $v ? null : $v),
                    'status' => $version->status = $v,
                    'sharing' => $version->sharing = (string) $v,
                };
            }
        }
        $dateKey = \array_key_exists('effective_date', $attrs) ? 'effective_date' : (\array_key_exists('due_date', $attrs) ? 'due_date' : null);
        if (null !== $dateKey) {
            $d = trim((string) $attrs[$dateKey]);
            if ('' === $d) {
                $version->effectiveDate = null;
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && false !== ($dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $d)) && $dt->format('Y-m-d') === $d) {
                $version->effectiveDate = $dt;
            } else {
                $extra['invalid_date'] = true;
            }
        }
        if (\array_key_exists('default_project_version', $attrs)) {
            $extra['default_project_version'] = \in_array($attrs['default_project_version'], ['1', 1, true, 'true'], true);
        }
        $editable = array_map(static fn ($f) => $f->id, $this->visibleCustomFields($version, $user));
        foreach ((array) ($attrs['custom_field_values'] ?? []) as $id => $v) {
            if (\in_array((int) $id, $editable, true)) {
                $extra['custom_field_values'][(int) $id] = $v;
            }
        }
        foreach ((array) ($attrs['custom_fields'] ?? []) as $cf) {
            if (\is_array($cf) && isset($cf['id']) && \in_array((int) $cf['id'], $editable, true)) {
                $extra['custom_field_values'][(int) $cf['id']] = $cf['value'] ?? null;
            }
        }

        return $extra;
    }

    /** @return list<string> */
    public function validate(Version $version, bool $invalidDate = false, array $customValues = []): array
    {
        $errors = [];
        $field = fn (string $f) => $this->i18n->l('field_'.$f);
        $msg = fn (string $k, array $p = []) => $this->i18n->l('activerecord.errors.messages.'.$k, $p);
        $name = (string) $version->name;
        if ('' === trim($name)) {
            $errors[] = $field('name').' '.$msg('blank');
        } else {
            if (mb_strlen($name) > 60) {
                $errors[] = $field('name').' '.$msg('too_long', ['count' => 60]);
            }
            $dup = $this->em->getConnection()->fetchOne(
                'SELECT id FROM versions WHERE project_id = ? AND name = ?'.(null !== $version->id ? ' AND id <> '.(int) $version->id : ''),
                [$version->project->id, $name]
            );
            if (false !== $dup && null !== $dup) {
                $errors[] = $field('name').' '.$msg('taken');
            }
        }
        if (mb_strlen((string) $version->description) > 255) {
            $errors[] = $field('description').' '.$msg('too_long', ['count' => 255]);
        }
        if (mb_strlen((string) $version->wikiPageTitle) > 255) {
            $errors[] = $this->i18n->l('label_wiki_page').' '.$msg('too_long', ['count' => 255]);
        }
        if ($invalidDate) {
            $errors[] = $field('effective_date').' '.$msg('not_a_date');
        }
        if (!\in_array($version->status, Version::STATUSES, true)) {
            $errors[] = $field('status').' '.$msg('inclusion');
        }
        if (!\in_array($version->sharing, Version::SHARINGS, true)) {
            $errors[] = $field('sharing').' '.$msg('inclusion');
        }
        $fields = $this->visibleCustomFields($version);
        $current = null !== $version->id ? $this->customFields->values('Version', $version->id) : [];
        foreach ($fields as $f) {
            $v = \array_key_exists($f->id, $customValues) ? $customValues[$f->id] : ($current[$f->id] ?? (null === $version->id ? $f->defaultValue : null));
            foreach ($this->customFields->validate($f, $v, $version->project) as $e) {
                $errors[] = $e;
            }
        }

        return $errors;
    }

    /**
     * Validates and saves the version (handles default project version, custom values and
     * Version#update_issues_from_sharing_change).
     *
     * @param array{default_project_version?: bool, custom_field_values?: array, invalid_date?: bool} $extra
     *
     * @return list<string> errors
     */
    public function save(Version $version, array $extra = []): array
    {
        $errors = $this->validate($version, $extra['invalid_date'] ?? false, $extra['custom_field_values'] ?? []);
        if ([] !== $errors) {
            return $errors;
        }
        $isNew = null === $version->id;
        $oldSharing = $isNew ? null : $this->em->getConnection()->fetchOne('SELECT sharing FROM versions WHERE id = ?', [$version->id]);
        $this->em->persist($version);
        $this->em->flush();
        $fields = $this->visibleCustomFields($version);
        $values = $extra['custom_field_values'] ?? [];
        if ($isNew) {
            $values += $this->customFields->defaultValues($fields);
        }
        if ([] !== $values) {
            $this->customFields->saveValues('Version', (int) $version->id, $fields, $values);
            $this->em->flush();
        }
        if ($extra['default_project_version'] ?? false) {
            $this->em->getConnection()->executeStatement('UPDATE projects SET default_version_id = ? WHERE id = ?', [$version->id, $version->project->id]);
            $version->project->defaultVersion = $version;
        }
        if (\is_string($oldSharing) && $oldSharing !== $version->sharing) {
            $old = array_search($oldSharing, Version::SHARINGS, true);
            $new = array_search($version->sharing, Version::SHARINGS, true);
            if (false === $old || false === $new || $old > $new) {
                $this->updateIssuesFromSharingChange($version);
            }
        }
        $this->reset();
        // acts_as_webhookable
        $this->dispatcher?->dispatch(new \App\Event\ContentEvent('version', $isNew ? 'created' : 'updated', $version, $this->auth->user(null)));

        return [];
    }

    /** Issue.update_versions_from_sharing_change: unassigns issues whose project does not share the version anymore */
    public function updateIssuesFromSharingChange(Version $version): void
    {
        $list = $this->em->createQuery(
            "SELECT i FROM App\Entity\Issue i JOIN i.fixedVersion v WHERE v.id = :v AND IDENTITY(i.project) <> IDENTITY(v.project) AND v.sharing <> 'system'"
        )->setParameter('v', $version->id)->getResult();
        $shared = [];
        foreach ($list as $issue) {
            $pid = $issue->project->id;
            $shared[$pid] ??= array_map(static fn (Version $v) => $v->id, $this->projects->sharedVersions($issue->project));
            if (!\in_array($version->id, $shared[$pid], true)) {
                $issue->fixedVersion = null;
                if ([] !== $this->issues->save($issue)) {
                    // fallback without journal when the issue is invalid (Redmine would skip it)
                    $this->em->getConnection()->executeStatement('UPDATE issues SET fixed_version_id = NULL WHERE id = ?', [$issue->id]);
                }
            }
        }
    }

    /** Project#close_completed_versions */
    public function closeCompleted(Project $project): void
    {
        foreach ($project->versions as $version) {
            if (!$version->isClosed() && $this->isCompleted($version)) {
                $version->status = 'closed';
            }
        }
        $this->em->flush();
        $this->reset();
    }

    public function destroy(Version $version): void
    {
        // acts_as_webhookable (the payload is built before the deletion)
        $this->dispatcher?->dispatch(new \App\Event\ContentEvent('version', 'deleted', $version, $this->auth->user(null)));
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE projects SET default_version_id = NULL WHERE default_version_id = ?', [$version->id]);
        $conn->executeStatement('UPDATE issues SET fixed_version_id = NULL WHERE fixed_version_id = ?', [$version->id]);
        $this->customFields->deleteValues('Version', (int) $version->id);
        if ($version->project->defaultVersion?->id === $version->id) {
            $version->project->defaultVersion = null;
        }
        $version->project->versions->removeElement($version);
        $this->em->remove($version);
        $this->em->flush();
        $this->reset();
    }

    // ------------------------------------------------------------------------------------------
    // Issue categories
    // ------------------------------------------------------------------------------------------

    /**
     * IssueCategory#safe_attributes= ('name', 'assigned_to_id').
     *
     * @param array<string, mixed> $attrs
     */
    public function assignCategory(IssueCategory $category, array $attrs): void
    {
        if (\array_key_exists('name', $attrs)) {
            $category->name = trim((string) $attrs['name']);
        }
        if (\array_key_exists('assigned_to_id', $attrs)) {
            $id = (int) $attrs['assigned_to_id'];
            $category->assignedTo = $id > 0 ? $this->em->find(Principal::class, $id) : null;
        }
    }

    /** @return list<string> */
    public function validateCategory(IssueCategory $category): array
    {
        $errors = [];
        $msg = fn (string $k, array $p = []) => $this->i18n->l('activerecord.errors.messages.'.$k, $p);
        $label = $this->i18n->l('field_name');
        if ('' === $category->name) {
            $errors[] = $label.' '.$msg('blank');
        } else {
            if (mb_strlen($category->name) > 60) {
                $errors[] = $label.' '.$msg('too_long', ['count' => 60]);
            }
            $dup = $this->em->getConnection()->fetchOne(
                'SELECT id FROM issue_categories WHERE project_id = ? AND name = ?'.(null !== $category->id ? ' AND id <> '.(int) $category->id : ''),
                [$category->project->id, $category->name]
            );
            if (false !== $dup && null !== $dup) {
                $errors[] = $label.' '.$msg('taken');
            }
        }

        return $errors;
    }

    /** @return list<string> errors */
    public function saveCategory(IssueCategory $category): array
    {
        $errors = $this->validateCategory($category);
        if ([] !== $errors) {
            return $errors;
        }
        if (null === $category->id) {
            $category->project->issueCategories->add($category);
        }
        $this->em->persist($category);
        $this->em->flush();

        return [];
    }

    public function categoryIssuesCount(IssueCategory $category): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM issues WHERE category_id = ?', [$category->id]);
    }

    /** IssueCategory#destroy(reassign_to) */
    public function destroyCategory(IssueCategory $category, ?IssueCategory $reassignTo = null): void
    {
        $conn = $this->em->getConnection();
        if (null !== $reassignTo && $reassignTo->project->id === $category->project->id && $reassignTo->id !== $category->id) {
            $conn->executeStatement('UPDATE issues SET category_id = ? WHERE category_id = ?', [$reassignTo->id, $category->id]);
        } else {
            $conn->executeStatement('UPDATE issues SET category_id = NULL WHERE category_id = ?', [$category->id]);
        }
        $category->project->issueCategories->removeElement($category);
        $this->em->remove($category);
        $this->em->flush();
    }
}
