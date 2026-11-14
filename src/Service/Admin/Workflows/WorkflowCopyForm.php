<?php

declare(strict_types=1);

namespace App\Service\Admin\Workflows;

use App\Entity\Role;
use App\Entity\Tracker;
use App\I18n\Localizer;
use App\Service\Admin\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The copy form of Administration > Workflow (workflows/copy): copies the workflow of a source tracker
 * and/or role to target trackers and roles.
 */
final class WorkflowCopyForm
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WorkflowService $workflows,
        private readonly Localizer $i18n,
    ) {
    }

    /**
     * What the form shows: the sources and targets named by the parameters (find_sources_and_targets).
     *
     * @param array<string, mixed> $p source_tracker_id, source_role_id ("any": same as the target),
     *                                target_tracker_ids[], target_role_ids[]
     *
     * @return array<string, mixed>
     */
    public function view(array $p, ?string $error = null): array
    {
        $st = (string) ($p['source_tracker_id'] ?? '');
        $sr = (string) ($p['source_role_id'] ?? '');
        $tt = array_filter((array) ($p['target_tracker_ids'] ?? []), static fn ($x) => '' !== (string) $x);
        $tr = array_filter((array) ($p['target_role_ids'] ?? []), static fn ($x) => '' !== (string) $x);

        return [
            'roles' => $this->workflows->workflowRoles(),
            'trackers' => $this->workflows->sortedTrackers(),
            'source_tracker' => '' === $st || 'any' === $st ? null : $this->em->find(Tracker::class, (int) $st),
            'source_role' => '' === $sr || 'any' === $sr ? null : $this->em->find(Role::class, (int) $sr),
            'target_trackers' => [] === $tt ? null : $this->em->getRepository(Tracker::class)->findBy(['id' => array_map('intval', $tt)]),
            'target_roles' => [] === $tr ? null : $this->em->getRepository(Role::class)->findBy(['id' => array_map('intval', $tr)]),
            'error' => $error,
        ];
    }

    /**
     * Copies the workflow when a source and targets are given.
     *
     * @param array<string, mixed> $p the submitted form
     *
     * @return array<string, mixed> the variables of the form; its "error" is null when the workflow was copied
     */
    public function submit(array $p): array
    {
        $v = $this->view($p);
        if ('' === (string) ($p['source_tracker_id'] ?? '') || '' === (string) ($p['source_role_id'] ?? '') || (null === $v['source_tracker'] && null === $v['source_role'])) {
            return ['error' => $this->i18n->l('error_workflow_copy_source')] + $v;
        }
        if ([] === ($v['target_trackers'] ?? []) || [] === ($v['target_roles'] ?? [])) {
            return ['error' => $this->i18n->l('error_workflow_copy_target')] + $v;
        }
        $this->workflows->copy($v['source_tracker'], $v['source_role'], $v['target_trackers'], $v['target_roles']);

        return $v;
    }
}
