<?php

declare(strict_types=1);

namespace App\Service\Tracking\Reports;

use App\Entity\Group;
use App\Entity\GroupBuiltin;
use App\Entity\IssuePriority;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Version;
use App\I18n\Localizer;
use App\Security\Authorization;
use App\Service\ProjectService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The lines of an issue report: the trackers, versions, priorities, categories, members or subprojects of the
 * project the issues are counted for.
 *
 * Report rows are plain arrays ['id' => ?int, 'name' => string, 'project' => ?Project].
 */
final class IssueReportRows
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IssueReportCounts $counts,
        private readonly ProjectService $projects,
        private readonly Authorization $auth,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
    ) {
    }

    /** @return list<array{id: ?int, name: string, project?: Project}> */
    public function rows(Project $project, string $detail, bool $with): array
    {
        $none = '['.$this->i18n->l('label_none').']';
        $ref = static fn (?int $id, string $name, ?Project $p = null) => ['id' => $id, 'name' => $name, 'project' => $p];
        switch ($detail) {
            case 'tracker':
                $ids = $this->counts->trackerIds($project, $with, true);
                $trackers = [] === $ids ? [] : $this->em->getRepository(Tracker::class)->findBy(['id' => $ids], ['position' => 'ASC']);

                return array_map(static fn (Tracker $t) => $ref($t->id, $t->name), $trackers);
            case 'version':
                $versions = $this->projects->sharedVersions($project);
                usort($versions, static function (Version $a, Version $b): int {
                    return [null === $a->effectiveDate ? 1 : 0, $a->effectiveDate?->format('Y-m-d') ?? '', (string) $a->name, $a->id]
                        <=> [null === $b->effectiveDate ? 1 : 0, $b->effectiveDate?->format('Y-m-d') ?? '', (string) $b->name, $b->id];
                });
                $rows = array_map(static fn (Version $v) => $ref($v->id, (string) $v->name), $versions);
                $rows[] = $ref(null, $none);

                return $rows;
            case 'priority':
                $priorities = $this->em->getRepository(IssuePriority::class)->findBy([], ['position' => 'DESC']);

                return array_map(static fn (IssuePriority $p) => $ref($p->id, $p->name), $priorities);
            case 'category':
                $rows = array_map(static fn ($c) => $ref($c->id, $c->name), $project->issueCategories->toArray());
                $rows[] = $ref(null, $none);

                return $rows;
            case 'assigned_to':
                $rows = array_map(static fn (Principal $p) => $ref($p->id, $p->getName()), $this->members($project, $this->settings->bool('issue_group_assignment')));
                $rows[] = $ref(null, $none.' ');

                return $rows;
            case 'author':
                return array_map(static fn (Principal $p) => $ref($p->id, $p->getName()), $this->members($project, false));
            case 'subproject':
                $visible = $this->auth->visibleProjectIds();
                $subs = $this->em->createQuery('SELECT p FROM App\Entity\Project p WHERE p.lft > :l AND p.rgt < :r ORDER BY p.lft')
                    ->setParameters(['l' => $project->lft, 'r' => $project->rgt])->getResult();

                return array_values(array_map(static fn (Project $p) => $ref($p->id, $p->name, $p), array_filter($subs, static fn (Project $p) => \in_array($p->id, $visible, true))));
        }

        return [];
    }

    /** @return list<Principal> Project#users / Project#principals, sorted (users first) */
    private function members(Project $project, bool $withGroups): array
    {
        $list = $this->em->createQuery('SELECT DISTINCT pr FROM App\Entity\Principal pr JOIN pr.memberships m WHERE m.project = :p AND pr.status = 1')
            ->setParameter('p', $project->id)->getResult();
        $list = array_values(array_filter($list, static fn ($p) => !$p instanceof GroupBuiltin && ($p instanceof User || ($withGroups && $p instanceof Group))));
        usort($list, static fn (Principal $a, Principal $b) => [$a instanceof Group ? 1 : 0, $a->firstname ?? '', $a->lastname ?? '', $a->id] <=> [$b instanceof Group ? 1 : 0, $b->firstname ?? '', $b->lastname ?? '', $b->id]);

        return $list;
    }
}
