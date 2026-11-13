<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiRenderer;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\Version;
use App\Service\CustomFieldService;
use App\Service\IssueService;
use App\Service\VersionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Port of Redmine's VersionsController (roadmap, version pages and REST API). */
final class VersionsController extends AbstractController
{
    public function __construct(
        private readonly VersionService $versions,
        private readonly IssueService $issues,
        private readonly CustomFieldService $customFields,
        private readonly ApiRenderer $api,
    ) {
    }

    // ------------------------------------------------------------------------------------------
    // index / roadmap
    // ------------------------------------------------------------------------------------------

    #[Route('/projects/{project_id}/roadmap', name: 'project_roadmap', methods: ['GET'])]
    #[Route('/projects/{project_id}/versions', name: 'project_versions', methods: ['GET'])]
    public function index(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('view_issues', $project);

        $trackers = $project->trackers->toArray();
        usort($trackers, static fn (Tracker $a, Tracker $b) => $a->position <=> $b->position);
        $selectedTrackerIds = $this->selectedTrackerIds($request, $trackers);
        $withSubprojects = $request->query->has('with_subprojects')
            ? '1' === $request->query->get('with_subprojects')
            : $this->settings->displaySubprojectsIssues();
        $projectIds = $withSubprojects ? array_map(static fn (Project $p) => $p->id, $project->getSelfAndDescendants()) : [$project->id];

        $versions = $this->projects->sharedVersions($project);
        if ($withSubprojects) {
            foreach ($this->projects->rolledUpVersions($project) as $v) {
                if ($this->auth->allowedTo('view_issues', $v->project)) {
                    $versions[] = $v;
                }
            }
        }
        $versions = $this->sortVersions($this->uniqueVersions($versions));
        $completedVersions = [];
        if (!$request->query->has('completed')) {
            $completedVersions = array_reverse(array_values(array_filter($versions, $this->versions->isCompleted(...))));
            $versions = array_values(array_filter($versions, fn (Version $v) => !$this->versions->isCompleted($v)));
        }

        $issuesByVersion = [];
        if ([] !== $selectedTrackerIds && [] !== $versions) {
            $cond = $this->issues->visibleCondition($this->user(), 'i');
            $list = $this->em->createQuery(
                "SELECT i, p, t FROM App\Entity\Issue i JOIN i.project p JOIN i.tracker t
                 WHERE $cond AND t.id IN (:trackers) AND p.id IN (:projects) AND IDENTITY(i.fixedVersion) IN (:versions)
                 ORDER BY p.lft ASC, t.position ASC, i.id ASC"
            )->setParameters([
                'trackers' => array_map('intval', $selectedTrackerIds),
                'projects' => $projectIds,
                'versions' => array_map(static fn (Version $v) => $v->id, $versions),
            ])->getResult();
            foreach ($list as $issue) {
                $issuesByVersion[$issue->fixedVersion->id][] = $issue;
            }
        }
        $versions = array_values(array_filter($versions, static fn (Version $v) => \in_array($v->project->id, $projectIds, true) || !empty($issuesByVersion[$v->id])));

        $hasSubprojectsWithIssues = false;
        foreach ($project->getSelfAndDescendants() as $d) {
            if ($d !== $project && $this->auth->allowedTo('view_issues', $d)) {
                $hasSubprojectsWithIssues = true;
                break;
            }
        }

        return $this->render('versions/index.html.twig', [
            'project' => $project,
            'versions' => $versions,
            'completed_versions' => $completedVersions,
            'issues_by_version' => $issuesByVersion,
            'trackers' => $trackers,
            'selected_tracker_ids' => $selectedTrackerIds,
            'with_subprojects' => $withSubprojects,
            'show_completed' => $request->query->has('completed'),
            'show_subprojects_option' => $hasSubprojectsWithIssues,
            'main_menu_selected' => 'roadmap',
            'page_title' => $this->l('label_roadmap'),
            'body_css' => 'controller-versions action-index',
        ]);
    }

    #[Route('/projects/{project_id}/versions.{_format}', name: 'project_versions_api', requirements: ['_format' => 'json|xml'], methods: ['GET'])]
    public function indexApi(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('view_issues', $project);
        $versions = $this->projects->sharedVersions($project);
        usort($versions, static fn (Version $a, Version $b) => $a->id <=> $b->id);

        return $this->api->render($request, [
            'versions' => array_map(fn (Version $v) => $this->apiVersion($v, false), $versions),
            'total_count' => \count($versions),
        ]);
    }

    // ------------------------------------------------------------------------------------------
    // show
    // ------------------------------------------------------------------------------------------

    #[Route('/versions/{id}', name: 'version_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[Route('/versions/{id}.{_format}', name: 'version_show_format', requirements: ['id' => '\d+', '_format' => 'json|xml|txt'], methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        [$version, $project] = $this->findVersion($id);
        $this->authorize('view_issues', $project);
        $format = $request->attributes->get('_format');
        if ('txt' === $format) {
            return $this->textExport($version);
        }
        if (\in_array($format, ['json', 'xml'], true)) {
            return $this->api->render($request, ['version' => $this->apiVersion($version, true)]);
        }

        return $this->renderShow($request, $version, $project);
    }

    #[Route('/versions/{id}/status_by', name: 'version_status_by', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function statusBy(Request $request, int $id): Response
    {
        [$version, $project] = $this->findVersion($id);
        $this->authorize('view_issues', $project);
        $criteria = (string) ($request->request->get('status_by') ?? $request->query->get('status_by', ''));
        if ($this->wantsJs($request)) {
            $html = $this->renderView('versions/_issue_counts.html.twig', ['version' => $version, 'criteria' => $criteria]);

            return new Response("$('#status_by').html(".json_encode($html, \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP).');', 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }

        return $this->renderShow($request, $version, $project, $criteria);
    }

    private function renderShow(Request $request, Version $version, Project $project, ?string $criteria = null): Response
    {
        $cond = $this->issues->visibleCondition($this->user(), 'i');
        $issues = $this->em->createQuery(
            "SELECT i, t, s FROM App\Entity\Issue i JOIN i.tracker t JOIN i.status s WHERE i.fixedVersion = :v AND $cond ORDER BY t.position ASC, i.id ASC"
        )->setParameter('v', $version->id)->getResult();
        $hasFixedIssues = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM issues WHERE fixed_version_id = ?', [$version->id]) > 0;

        return $this->render('versions/show.html.twig', [
            'project' => $project,
            'version' => $version,
            'issues' => $issues,
            'has_fixed_issues' => $hasFixedIssues,
            'criteria' => $criteria ?? (string) $request->query->get('status_by', ''),
            'can_view_time_entries' => $this->auth->allowedTo('view_time_entries', $project),
            'can_view_all_time_entries' => $this->canViewAllTimeEntries($project),
            'main_menu_selected' => 'roadmap',
            'page_title' => $version->name,
            'body_css' => 'controller-versions action-show',
        ]);
    }

    /** User#allowed_to_view_all_time_entries? */
    private function canViewAllTimeEntries(Project $project): bool
    {
        return $this->auth->allowedTo('view_time_entries', $project, null, false, static fn ($role) => 'all' === $role->timeEntriesVisibility);
    }

    // ------------------------------------------------------------------------------------------
    // new / create
    // ------------------------------------------------------------------------------------------

    #[Route('/projects/{project_id}/versions/new', name: 'version_new', methods: ['GET'])]
    public function new(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_versions', $project);
        $version = $this->newVersion($project);
        $extra = $this->versions->assign($version, $request->query->all('version'));

        if ($this->wantsJs($request)) {
            $html = $this->renderView('versions/_new_modal.html.twig', ['project' => $project, 'version' => $version, 'errors' => [], 'extra' => $extra, 'cf_values' => []]);

            return new Response("$('#ajax-modal').html(".$this->jsString($html).");\nshowModal('ajax-modal', '600px');", 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }

        return $this->renderForm('versions/new.html.twig', $project, $version, [], $extra);
    }

    #[Route('/projects/{project_id}/versions', name: 'version_create', methods: ['POST'])]
    #[Route('/projects/{project_id}/versions.{_format}', name: 'version_create_api', requirements: ['_format' => 'json|xml'], methods: ['POST'])]
    public function create(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_versions', $project);
        $isApi = $this->isApi($request);
        $version = $this->newVersion($project);
        $attrs = $isApi ? (array) (ApiRenderer::payload($request)['version'] ?? []) : $request->request->all('version');
        $extra = $this->versions->assign($version, $attrs);
        $errors = $this->versions->save($version, $extra);

        if ($isApi) {
            if ([] !== $errors) {
                return $this->api->errors($request, $errors);
            }
            $response = $this->api->render($request, ['version' => $this->apiVersion($version, true)], 201);
            $response->headers->set('Location', $this->generateUrl('version_show', ['id' => $version->id], UrlGeneratorInterface::ABSOLUTE_URL));

            return $response;
        }
        $js = $this->wantsJs($request);
        if ([] !== $errors) {
            if ($js) {
                $html = $this->renderView('versions/_new_modal.html.twig', ['project' => $project, 'version' => $version, 'errors' => $errors, 'extra' => $extra, 'cf_values' => $extra['custom_field_values']]);

                return new Response("$('#ajax-modal').html(".$this->jsString($html).");\nshowModal('ajax-modal', '600px');", 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
            }

            return $this->renderForm('versions/new.html.twig', $project, $version, $errors, $extra, 422);
        }
        if ($js) {
            $options = '<option></option>';
            foreach ($this->projects->sharedVersions($project) as $v) {
                if ($v->isOpen()) {
                    $options .= '<option value="'.$v->id.'"'.($v->id === $version->id ? ' selected="selected"' : '').'>'.htmlspecialchars($v->displayName($project)).'</option>';
                }
            }
            $select = '<select id="issue_fixed_version_id" name="issue[fixed_version_id]">'.$options.'</select>';

            return new Response("hideModal();\n$('#issue_fixed_version_id').replaceWith(".$this->jsString($select).');', 200, ['Content-Type' => 'text/javascript; charset=utf-8']);
        }
        $this->flashNotice('notice_successful_create');

        return $this->redirectBackOr($request, $this->settingsUrl($project));
    }

    // ------------------------------------------------------------------------------------------
    // edit / update
    // ------------------------------------------------------------------------------------------

    #[Route('/versions/{id}/edit', name: 'version_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        [$version, $project] = $this->findVersion($id);
        $this->authorize('manage_versions', $project);

        return $this->renderForm('versions/edit.html.twig', $project, $version, [], ['default_project_version' => false, 'custom_field_values' => []]);
    }

    #[Route('/versions/{id}', name: 'version_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    #[Route('/versions/{id}.{_format}', name: 'version_update_api', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id): Response
    {
        [$version, $project] = $this->findVersion($id);
        $this->authorize('manage_versions', $project);
        $isApi = $this->isApi($request);
        $attrs = $isApi ? ApiRenderer::payload($request)['version'] ?? null : ($request->request->has('version') ? $request->request->all('version') : null);
        if (!\is_array($attrs)) {
            return $isApi ? $this->api->noContent() : $this->redirect($this->settingsUrl($project));
        }
        $extra = $this->versions->assign($version, $attrs);
        $errors = $this->versions->save($version, $extra);
        if ($isApi) {
            return [] === $errors ? $this->api->noContent() : $this->api->errors($request, $errors);
        }
        if ([] !== $errors) {
            $this->em->refresh($version);
            $this->versions->assign($version, $attrs);

            return $this->renderForm('versions/edit.html.twig', $project, $version, $errors, $extra, 422);
        }
        $this->flashNotice('notice_successful_update');

        return $this->redirectBackOr($request, $this->settingsUrl($project));
    }

    #[Route('/projects/{project_id}/versions/close_completed', name: 'versions_close_completed', methods: ['PUT', 'POST'])]
    public function closeCompleted(string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_versions', $project);
        $this->versions->closeCompleted($project);

        return $this->redirect($this->settingsUrl($project));
    }

    #[Route('/versions/{id}', name: 'version_destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[Route('/versions/{id}.{_format}', name: 'version_destroy_api', requirements: ['id' => '\d+', '_format' => 'json|xml'], methods: ['DELETE'])]
    public function destroy(Request $request, int $id): Response
    {
        [$version, $project] = $this->findVersion($id);
        $this->authorize('manage_versions', $project);
        $isApi = $this->isApi($request);
        if ($this->versions->isDeletable($version)) {
            $this->versions->destroy($version);

            return $isApi ? $this->api->noContent() : $this->redirectBackOr($request, $this->settingsUrl($project));
        }
        if ($isApi) {
            return new Response('', 422);
        }
        $this->flashError($this->l('notice_unable_delete_version'));

        return $this->redirect($this->settingsUrl($project));
    }

    // ------------------------------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------------------------------

    /** @return array{0: Version, 1: Project} */
    private function findVersion(int $id): array
    {
        $version = $this->em->find(Version::class, $id);
        if (null === $version) {
            throw $this->createNotFoundException();
        }
        $project = $this->findProject((int) $version->project->id);

        return [$version, $project];
    }

    private function newVersion(Project $project): Version
    {
        $version = new Version();
        $version->project = $project;
        $version->status = 'open';
        $version->sharing = 'none';
        $version->description = '';

        return $version;
    }

    /** @param list<string> $errors */
    private function renderForm(string $template, Project $project, Version $version, array $errors, array $extra, int $status = 200): Response
    {
        $cfValues = null !== $version->id ? $this->customFields->values('Version', $version->id) : $this->customFields->defaultValues($this->versions->visibleCustomFields($version));
        foreach ($extra['custom_field_values'] ?? [] as $fid => $v) {
            $cfValues[$fid] = $v;
        }

        return $this->render($template, [
            'project' => $project,
            'version' => $version,
            'errors' => $errors,
            'extra' => $extra,
            'cf_values' => $cfValues,
            'back_url' => (string) ($this->requestStack->getCurrentRequest()?->query->get('back_url') ?? $this->requestStack->getCurrentRequest()?->request->get('back_url') ?? ''),
            'main_menu_selected' => 'roadmap',
            'page_title' => $this->l(null === $version->id ? 'label_version_new' : 'label_version'),
            'body_css' => 'controller-versions action-'.(null === $version->id ? 'new' : 'edit'),
        ], new Response(null, $status));
    }

    private function settingsUrl(Project $project): string
    {
        if (null !== $this->container->get('router')->getRouteCollection()->get('project_settings')) {
            return $this->generateUrl('project_settings', ['id' => $project->identifier, 'tab' => 'versions']);
        }

        return '/projects/'.$project->identifier.'/settings/versions';
    }

    /** @param list<Tracker> $trackers @return list<string> */
    private function selectedTrackerIds(Request $request, array $trackers): array
    {
        $all = $request->query->all();
        if (\array_key_exists('tracker_ids', $all)) {
            $ids = $all['tracker_ids'];
            $ids = \is_array($ids) ? $ids : explode('/', (string) $ids);

            return array_values(array_map(static fn ($id) => (string) (int) $id, $ids));
        }

        return array_values(array_map(static fn (Tracker $t) => (string) $t->id, array_filter($trackers, static fn (Tracker $t) => $t->isInRoadmap)));
    }

    /** @param list<Version> $versions @return list<Version> */
    private function uniqueVersions(array $versions): array
    {
        $out = [];
        foreach ($versions as $v) {
            $out[$v->id] = $v;
        }

        return array_values($out);
    }

    /** Version#<=> @param list<Version> $versions @return list<Version> */
    private function sortVersions(array $versions): array
    {
        usort($versions, static function (Version $a, Version $b): int {
            if ($a->effectiveDate && $b->effectiveDate) {
                if ($a->effectiveDate == $b->effectiveDate) {
                    return [$a->name, $a->id] <=> [$b->name, $b->id];
                }

                return $a->effectiveDate <=> $b->effectiveDate;
            }
            if ($a->effectiveDate) {
                return -1;
            }
            if ($b->effectiveDate) {
                return 1;
            }

            return [$a->name, $a->id] <=> [$b->name, $b->id];
        });

        return $versions;
    }

    private function wantsJs(Request $request): bool
    {
        return str_contains((string) $request->headers->get('Accept'), 'javascript') || 'js' === $request->query->get('format');
    }

    private function jsString(string $s): string
    {
        return (string) json_encode($s, \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP | \JSON_UNESCAPED_UNICODE);
    }

    private function textExport(Version $version): Response
    {
        $cond = $this->issues->visibleCondition($this->user(), 'i');
        $issues = $this->em->createQuery("SELECT i FROM App\Entity\Issue i WHERE i.fixedVersion = :v AND $cond ORDER BY i.id")
            ->setParameter('v', $version->id)->getResult();
        $lines = '';
        foreach ($issues as $issue) {
            /* @var Issue $issue */
            $lines .= '* '.$issue->tracker->name.' #'.$issue->id.': '.$issue->subject."\n";
        }
        $parts = array_filter(['# '.$version->name, $this->i18n->formatDate($version->effectiveDate), $version->description, $lines], static fn ($p) => null !== $p);
        $response = new Response(implode("\n\n", $parts), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        $response->headers->set('Content-Disposition', 'attachment; filename="'.addcslashes((string) $version->name, '"\\').'.txt"');

        return $response;
    }

    /** versions/show.api.rsb & index.api.rsb */
    private function apiVersion(Version $version, bool $full): array
    {
        $data = [
            'id' => $version->id,
            'project' => ['id' => $version->project->id, 'name' => $version->project->name],
            'name' => $version->name,
            'description' => $version->description,
            'status' => $version->status,
            'due_date' => ApiRenderer::date($version->effectiveDate),
            'sharing' => $version->sharing,
            'wiki_page_title' => $version->wikiPageTitle,
        ];
        if ($full && $this->auth->allowedTo('view_time_entries', $version->project)) {
            $data['estimated_hours'] = $this->versions->stats($version, true)['estimated_hours'];
            $data['spent_hours'] = $this->versions->spentHours($version);
        }
        $cfs = [];
        foreach ($this->versions->customValues($version) as $cv) {
            $f = $cv['field'];
            $item = ['id' => $f->id, 'name' => $f->name];
            if ($f->isMultiple()) {
                $item['multiple'] = true;
                $item['value'] = array_values(array_filter((array) $cv['value'], static fn ($v) => '' !== $v && null !== $v));
            } else {
                $item['value'] = $cv['value'];
            }
            $cfs[] = $item;
        }
        if ([] !== $cfs) {
            $data['custom_fields'] = $cfs;
        }
        $data['created_on'] = ApiRenderer::time($version->createdOn);
        $data['updated_on'] = ApiRenderer::time($version->updatedOn);

        return $data;
    }
}
