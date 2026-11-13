<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Api\ApiRenderer;
use App\Controller\AbstractController;
use App\Entity\IssueCustomField;
use App\Entity\IssueStatus;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Service\Admin\ActsAsPositioned;
use App\Service\Admin\WorkflowService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's TrackersController. */
final class TrackersController extends AbstractController
{
    public function __construct(
        private readonly ApiRenderer $api,
        private readonly WorkflowService $workflows,
        private readonly ActsAsPositioned $positioned,
    ) {
    }

    #[Route('/trackers.{_format}', name: 'trackers_index', requirements: ['_format' => 'html|json|xml'], defaults: ['_format' => 'html'], methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($this->isApi($request)) {
            if ($this->settings->loginRequired() && !$this->user()->isLogged()) {
                $this->deny();
            }

            return $this->api->render($request, ['trackers' => array_map(static fn (Tracker $t) => [
                'id' => $t->id,
                'name' => $t->name,
                ...(null !== $t->defaultStatus ? ['default_status' => ['id' => $t->defaultStatus->id, 'name' => $t->defaultStatus->name]] : []),
                'description' => $t->description,
                'enabled_standard_fields' => $t->getCoreFields(),
            ], $this->workflows->sortedTrackers())]);
        }
        $this->requireAdmin();

        return $this->renderIndex();
    }

    private function renderIndex(?string $errorHtml = null): Response
    {
        $trackers = $this->workflows->sortedTrackers();
        $withRules = [];
        foreach ($trackers as $t) {
            $withRules[$t->id] = $this->workflows->trackerHasRules($t);
        }

        return $this->render('admin/trackers/index.html.twig', [
            'trackers' => $trackers,
            'with_rules' => $withRules,
            'error_html' => $errorHtml,
            'admin_menu_selected' => 'trackers',
            'page_title' => $this->l('label_tracker_plural'),
            'body_css' => 'controller-trackers action-index',
        ]);
    }

    #[Route('/trackers/new', name: 'tracker_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->requireAdmin();
        $tracker = new Tracker();
        $tracker->defaultStatus = $this->workflows->sortedStatuses()[0] ?? null;
        $state = ['custom_field_ids' => [], 'project_ids' => []];
        $this->assign($tracker, $request->query->all('tracker'), $state);
        $copyFrom = null;
        if ($request->query->get('copy') && ($copyFrom = $this->em->find(Tracker::class, (int) $request->query->get('copy')))) {
            $tracker->isInRoadmap = $copyFrom->isInRoadmap;
            $tracker->fieldsBits = $copyFrom->fieldsBits;
            $tracker->defaultStatus = $copyFrom->defaultStatus;
            $tracker->description = $copyFrom->description;
            $tracker->privateByDefault = $copyFrom->privateByDefault;
            $state['custom_field_ids'] = $this->customFieldIds($copyFrom);
            $state['project_ids'] = $this->projectIds($copyFrom);
        }

        return $this->renderForm($tracker, 'new', $state, [], $copyFrom ? (string) $copyFrom->id : (string) $request->query->get('copy_workflow_from', ''));
    }

    #[Route('/trackers', name: 'tracker_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->requireAdmin();
        $tracker = new Tracker();
        $state = ['custom_field_ids' => [], 'project_ids' => []];
        $this->assign($tracker, $request->request->all('tracker'), $state);
        $errors = $this->validate($tracker);
        if ([] !== $errors) {
            return $this->renderForm($tracker, 'new', $state, $errors, (string) $request->request->get('copy_workflow_from', ''), 422);
        }
        $tracker->position ??= $this->positioned->nextPosition('trackers');
        $this->em->persist($tracker);
        $this->em->flush();
        $this->saveAssociations($tracker, $state);
        $copyId = (int) $request->request->get('copy_workflow_from', 0);
        if ($copyId && ($source = $this->em->find(Tracker::class, $copyId))) {
            $this->workflows->copy($source, null, [$tracker], []);
        }
        $this->flashNotice('notice_successful_create');

        return $this->redirectToRoute('trackers_index');
    }

    #[Route('/trackers/{id}/edit', name: 'tracker_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->requireAdmin();
        $tracker = $this->findTracker($id);

        return $this->renderForm($tracker, 'edit', ['custom_field_ids' => $this->customFieldIds($tracker), 'project_ids' => $this->projectIds($tracker)]);
    }

    #[Route('/trackers/{id}', name: 'tracker_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $tracker = $this->findTracker($id);
        $oldPosition = $tracker->position;
        $state = ['custom_field_ids' => null, 'project_ids' => null];
        $this->assign($tracker, $request->request->all('tracker'), $state);
        $errors = $this->validate($tracker);
        $xhr = $request->isXmlHttpRequest();
        if ([] !== $errors) {
            if ($xhr) {
                return new Response('', 422);
            }
            $state['custom_field_ids'] ??= $this->customFieldIds($tracker);
            $state['project_ids'] ??= $this->projectIds($tracker);

            return $this->renderForm($tracker, 'edit', $state, $errors, null, 422);
        }
        $this->em->flush();
        $this->saveAssociations($tracker, $state);
        if (null !== $tracker->position && $oldPosition !== $tracker->position) {
            $this->positioned->positionChanged('trackers', (int) $tracker->id, $oldPosition, $tracker->position);
        }
        if ($xhr) {
            return new Response('', 200);
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectToRoute('trackers_index', array_filter(['page' => $request->query->get('page')]));
    }

    #[Route('/trackers/{id}', name: 'tracker_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $this->requireAdmin();
        $tracker = $this->findTracker($id);
        $conn = $this->em->getConnection();
        if (false !== $conn->fetchOne('SELECT 1 FROM issues WHERE tracker_id = ? LIMIT 1', [$tracker->id])) {
            $projects = $this->em->createQuery('SELECT DISTINCT p FROM App\Entity\Project p WHERE p.id IN (SELECT IDENTITY(i.project) FROM App\Entity\Issue i WHERE i.tracker = :t) ORDER BY p.lft')
                ->setParameter('t', $tracker)->getResult();
            $links = implode(', ', array_map(fn (Project $p) => $this->projectIssuesLink($p, $tracker), $projects));

            return $this->renderIndex($this->l('error_can_not_delete_tracker_html', ['projects' => $links]));
        }
        $tid = (int) $tracker->id;
        $position = $tracker->position;
        $this->workflows->deleteForTracker($tid);
        $conn->executeStatement('DELETE FROM projects_trackers WHERE tracker_id = ?', [$tid]);
        $conn->executeStatement('DELETE FROM custom_fields_trackers WHERE tracker_id = ?', [$tid]);
        $this->em->remove($tracker);
        $this->em->flush();
        $this->positioned->removePosition('trackers', $tid, $position);

        return $this->redirectToRoute('trackers_index');
    }

    #[Route('/trackers/fields', name: 'trackers_fields', methods: ['GET', 'POST'])]
    public function fields(Request $request): Response
    {
        $this->requireAdmin();
        if ($request->isMethod('POST') && $request->request->has('trackers')) {
            foreach ($request->request->all('trackers') as $trackerId => $params) {
                $tracker = $this->em->find(Tracker::class, (int) $trackerId);
                if (null === $tracker || !\is_array($params)) {
                    continue;
                }
                $tracker->setCoreFields(array_values(array_filter((array) ($params['core_fields'] ?? []), static fn ($v) => '' !== (string) $v)));
                $this->em->flush();
                $this->saveAssociations($tracker, ['custom_field_ids' => self::idList($params['custom_field_ids'] ?? []), 'project_ids' => null]);
            }
            $this->flashNotice('notice_successful_update');

            return $this->redirectToRoute('trackers_fields');
        }
        $trackers = $this->workflows->sortedTrackers();
        $cfIds = [];
        foreach ($trackers as $t) {
            $cfIds[$t->id] = $this->customFieldIds($t);
        }

        return $this->render('admin/trackers/fields.html.twig', [
            'trackers' => $trackers,
            'custom_fields' => $this->em->getRepository(IssueCustomField::class)->findBy([], ['position' => 'ASC']),
            'cf_ids' => $cfIds,
            'core_fields' => $this->coreFieldLabels(),
            'admin_menu_selected' => 'trackers',
            'page_title' => $this->l('field_summary'),
            'body_css' => 'controller-trackers action-fields',
        ]);
    }

    // ------------------------------------------------------------------

    private function findTracker(int $id): Tracker
    {
        return $this->em->find(Tracker::class, $id) ?? throw $this->createNotFoundException();
    }

    /** @return array<string, string> core field => label */
    private function coreFieldLabels(): array
    {
        $out = [];
        foreach (Tracker::CORE_FIELDS as $f) {
            $out[$f] = $this->l('field_'.(str_ends_with($f, '_id') ? substr($f, 0, -3) : $f));
        }

        return $out;
    }

    /** @return list<int> */
    private static function idList(mixed $v): array
    {
        return array_values(array_unique(array_map('intval', array_filter((array) $v, static fn ($x) => '' !== (string) $x))));
    }

    /** Tracker#safe_attributes= ; associations are kept in $state until saved */
    private function assign(Tracker $tracker, array $p, array &$state): void
    {
        if (\array_key_exists('name', $p)) {
            $tracker->name = trim((string) $p['name']);
        }
        if (\array_key_exists('default_status_id', $p)) {
            $tracker->defaultStatus = '' === (string) $p['default_status_id'] ? null : $this->em->find(IssueStatus::class, (int) $p['default_status_id']);
        }
        if (\array_key_exists('is_in_roadmap', $p)) {
            $tracker->isInRoadmap = \in_array((string) $p['is_in_roadmap'], ['1', 'true'], true);
        }
        if (\array_key_exists('private_by_default', $p)) {
            $tracker->privateByDefault = \in_array((string) $p['private_by_default'], ['1', 'true'], true);
        }
        if (\array_key_exists('core_fields', $p)) {
            $tracker->setCoreFields(array_values(array_filter((array) $p['core_fields'], static fn ($v) => '' !== (string) $v)));
        }
        if (\array_key_exists('position', $p) && '' !== (string) $p['position']) {
            $tracker->position = (int) $p['position'];
        }
        if (\array_key_exists('description', $p)) {
            $tracker->description = (string) $p['description'];
        }
        if (\array_key_exists('custom_field_ids', $p)) {
            $state['custom_field_ids'] = self::idList($p['custom_field_ids']);
        }
        if (\array_key_exists('project_ids', $p)) {
            $state['project_ids'] = self::idList($p['project_ids']);
        }
    }

    private function saveAssociations(Tracker $tracker, array $state): void
    {
        $conn = $this->em->getConnection();
        if (null !== ($state['custom_field_ids'] ?? null)) {
            $conn->executeStatement('DELETE FROM custom_fields_trackers WHERE tracker_id = ?', [$tracker->id]);
            $valid = $conn->fetchFirstColumn("SELECT id FROM custom_fields WHERE type = 'IssueCustomField'");
            foreach ($state['custom_field_ids'] as $cf) {
                if (\in_array($cf, array_map('intval', $valid), true)) {
                    $conn->insert('custom_fields_trackers', ['custom_field_id' => $cf, 'tracker_id' => $tracker->id]);
                }
            }
        }
        if (null !== ($state['project_ids'] ?? null)) {
            $conn->executeStatement('DELETE FROM projects_trackers WHERE tracker_id = ?', [$tracker->id]);
            $valid = array_map('intval', $conn->fetchFirstColumn('SELECT id FROM projects'));
            foreach ($state['project_ids'] as $pid) {
                if (\in_array($pid, $valid, true)) {
                    $conn->insert('projects_trackers', ['project_id' => $pid, 'tracker_id' => $tracker->id]);
                }
            }
        }
        $this->em->refresh($tracker);
    }

    /** @return list<int> */
    private function customFieldIds(Tracker $tracker): array
    {
        return null === $tracker->id ? [] : array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT custom_field_id FROM custom_fields_trackers WHERE tracker_id = ?', [$tracker->id]));
    }

    /** @return list<int> */
    private function projectIds(Tracker $tracker): array
    {
        return null === $tracker->id ? [] : array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT project_id FROM projects_trackers WHERE tracker_id = ?', [$tracker->id]));
    }

    /** @return list<string> */
    private function validate(Tracker $tracker): array
    {
        $errors = [];
        $label = $this->l('field_name');
        if (null === $tracker->defaultStatus) {
            $errors[] = $this->l('field_default_status').' '.$this->l('activerecord.errors.messages.blank');
        }
        if ('' === $tracker->name) {
            $errors[] = $label.' '.$this->l('activerecord.errors.messages.blank');
        } else {
            $other = $this->em->getRepository(Tracker::class)->findOneBy(['name' => $tracker->name]);
            if (null !== $other && $other->id !== $tracker->id) {
                $errors[] = $label.' '.$this->l('activerecord.errors.messages.taken');
            }
            if (mb_strlen($tracker->name) > 30) {
                $errors[] = $label.' '.$this->l('activerecord.errors.messages.too_long', ['count' => 30]);
            }
        }
        if (mb_strlen((string) $tracker->description) > 255) {
            $errors[] = $this->l('field_description').' '.$this->l('activerecord.errors.messages.too_long', ['count' => 255]);
        }

        return $errors;
    }

    private function renderForm(Tracker $tracker, string $action, array $state, array $errors = [], ?string $copyWorkflowFrom = null, int $status = 200): Response
    {
        $projects = $this->em->createQuery('SELECT p FROM App\Entity\Project p ORDER BY p.lft')->getResult();

        return $this->render('admin/trackers/'.$action.'.html.twig', [
            'tracker' => $tracker,
            'errors' => $errors,
            'statuses' => $this->workflows->sortedStatuses(),
            'core_fields' => $this->coreFieldLabels(),
            'enabled_core_fields' => $tracker->getCoreFields(),
            'issue_custom_fields' => $this->em->getRepository(IssueCustomField::class)->findBy([], ['position' => 'ASC']),
            'custom_field_ids' => $state['custom_field_ids'] ?? [],
            'projects_html' => $this->projectNestedCheckboxes($projects, $state['project_ids'] ?? []),
            'has_projects' => [] !== $projects,
            'trackers' => $this->workflows->sortedTrackers(),
            'copy_workflow_from' => $copyWorkflowFrom,
            'admin_menu_selected' => 'trackers',
            'page_title' => 'new' === $action ? $this->l('label_tracker_new') : $tracker->name,
            'body_css' => 'controller-trackers action-'.$action,
        ], new Response('', $status));
    }

    /**
     * render_project_nested_lists with a tracker[project_ids][] checkbox per project.
     *
     * @param list<Project> $projects sorted by lft
     * @param list<int>     $selected
     */
    private function projectNestedCheckboxes(array $projects, array $selected): string
    {
        $s = '';
        $ancestors = [];
        foreach ($projects as $project) {
            if ([] === $ancestors || self::isDescendant($project, end($ancestors))) {
                $s .= "<ul class='projects ".([] === $ancestors ? 'root' : '')."'>\n";
            } else {
                array_pop($ancestors);
                $s .= '</li>';
                while ([] !== $ancestors && !self::isDescendant($project, end($ancestors))) {
                    array_pop($ancestors);
                    $s .= "</ul></li>\n";
                }
            }
            $classes = [] === $ancestors ? 'root' : 'child';
            if ($project->isArchived()) {
                $classes .= ' archived';
            }
            $s .= "<li class='$classes'><div class='$classes'>";
            $s .= '<label><input type="checkbox" name="tracker[project_ids][]" value="'.$project->id.'"'.(\in_array($project->id, $selected, true) ? ' checked="checked"' : '').' /> '.htmlspecialchars($project->name).'</label>';
            $s .= "</div>\n";
            $ancestors[] = $project;
        }

        return $s.str_repeat("</li></ul>\n", \count($ancestors));
    }

    private static function isDescendant(Project $p, Project $ancestor): bool
    {
        return null !== $p->lft && null !== $ancestor->lft && $p->lft > $ancestor->lft && $p->rgt < $ancestor->rgt;
    }

    private function projectIssuesLink(Project $p, Tracker $t): string
    {
        try {
            $url = $this->generateUrl('project_issues', ['id' => $p->identifier, 'set_filter' => 1, 'tracker_id' => $t->id, 'status_id' => '*']);
        } catch (\Throwable) {
            return htmlspecialchars($p->name);
        }

        return '<a href="'.htmlspecialchars($url).'">'.htmlspecialchars($p->name).'</a>';
    }
}
