<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Board;
use App\Entity\IssueCustomField;
use App\Entity\Project;
use App\Entity\Repository;
use App\Entity\TimeEntryActivityCustomField;
use App\Entity\Tracker;
use App\I18n\Localizer;
use App\Security\AccessGuard;
use App\Security\Authorization;
use App\Service\CustomFieldService;
use App\Service\MembersManager;
use App\Service\ProjectManager;
use App\Service\ProjectService;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The settings page of a project (projects#settings): the tabs the user may open (ProjectsHelper
 * #project_settings_tabs) and what each of them lists.
 */
final class ProjectSettingsView
{
    /** name, permission, module that must be enabled, label */
    private const array TABS = [
        ['info', 'edit_project', null, 'label_project'],
        ['members', 'manage_members', null, 'label_member_plural'],
        ['issues', 'edit_project', 'issue_tracking', 'label_issue_tracking'],
        ['versions', 'manage_versions', null, 'label_version_plural'],
        ['categories', 'manage_categories', null, 'label_issue_category_plural'],
        ['repositories', 'manage_repository', null, 'label_repository_plural'],
        ['boards', 'manage_boards', null, 'label_board_plural'],
        ['activities', 'manage_project_activities', null, 'label_time_tracking'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly AccessGuard $guard,
        private readonly Localizer $i18n,
        private readonly Settings $settings,
        private readonly ProjectService $projects,
        private readonly ProjectManager $manager,
        private readonly ProjectForm $form,
        private readonly MembersManager $members,
        private readonly CustomFieldService $customFields,
    ) {
    }

    /** @return list<array{name: string, label: string}> */
    public function tabs(Project $project): array
    {
        $tabs = [];
        foreach (self::TABS as [$name, $perm, $module, $label]) {
            if ($this->auth->allowedTo($perm, $project) && (null === $module || $project->isModuleEnabled($module))) {
                $tabs[] = ['name' => $name, 'label' => $this->i18n->l($label)];
            }
        }

        return $tabs;
    }

    /**
     * Variables of projects/settings.html.twig; access is denied to a user who has none of the tabs.
     *
     * @param ?string      $tab    the tab asked for (the first one when the user does not have it)
     * @param list<string> $errors what prevented saving the project form
     *
     * @return array<string, mixed>
     */
    public function view(Project $project, Request $request, ?string $tab, array $errors = []): array
    {
        $tabs = $this->tabs($project);
        if ([] === $tabs) {
            $this->guard->deny();
        }
        $names = array_column($tabs, 'name');
        $selected = \in_array($tab, $names, true) ? $tab : $names[0];

        $versionStatus = $request->query->has('version_status') ? (string) $request->query->get('version_status') : 'open';
        $versionName = trim((string) $request->query->get('version_name', ''));
        $versions = array_values(array_filter($this->projects->sharedVersions($project), static function ($v) use ($versionStatus, $versionName) {
            if ('' !== $versionStatus && $v->status !== $versionStatus) {
                return false;
            }

            return '' === $versionName || str_contains(mb_strtolower((string) $v->name), mb_strtolower($versionName));
        }));
        $issueQueries = $this->em->createQuery('SELECT q FROM App\Entity\IssueQuery q WHERE q.visibility = 2 AND (q.project IS NULL OR q.project = :p) ORDER BY q.name')->setParameter('p', $project)->getResult();
        $openShared = array_values(array_filter($this->projects->sharedVersions($project), static fn ($v) => $v->isOpen()));
        if (null !== $project->defaultVersion && !\in_array($project->defaultVersion, $openShared, true)) {
            $openShared[] = $project->defaultVersion;
        }
        $assignable = $this->projects->assignableUsers($project);
        if (null !== $project->defaultAssignedTo && !\in_array($project->defaultAssignedTo, $assignable, true)) {
            $assignable[] = $project->defaultAssignedTo;
        }
        $allIssueFieldIds = array_map(static fn ($f) => $f->id, $project->issueCustomFields->toArray());

        // members tab: MembersHelper#paginate_members(members_scope(@project))
        $membersList = \in_array('members', $names, true) ? $this->members->listView($project, $request->query->all(), $this->settings->perPageOptions()) : [];

        return $this->form->view($project) + $membersList + [
            'project' => $project,
            'errors' => $errors,
            'tabs' => $tabs,
            'selected_tab' => $selected,
            // members
            'members_manager' => $this->members,
            // issues tab
            'trackers' => $this->em->getRepository(Tracker::class)->findBy([], ['position' => 'ASC']),
            'issue_custom_fields' => $this->em->getRepository(IssueCustomField::class)->findBy([], ['position' => 'ASC']),
            'all_issue_custom_field_ids' => $allIssueFieldIds,
            'default_version_options' => $openShared,
            'default_assignee_options' => $assignable,
            'issue_queries_global' => array_values(array_filter($issueQueries, static fn ($q) => null === $q->project)),
            'issue_queries_project' => array_values(array_filter($issueQueries, static fn ($q) => null !== $q->project)),
            // versions / categories (partials provided by the versions module)
            'versions' => $versions,
            'version_status' => $versionStatus,
            'version_name' => $versionName,
            'categories' => $project->issueCategories->toArray(),
            // repositories / boards / activities
            'repositories' => $this->em->getRepository(Repository::class)->findBy(['project' => $project], ['isDefault' => 'DESC', 'identifier' => 'ASC']),
            'boards' => $this->boardsTree($project),
            'activities' => $this->manager->activities($project, true),
            'activity_custom_fields' => $this->customFields->fieldsOf(TimeEntryActivityCustomField::class),
        ];
    }

    /** @return list<array{0: Board, 1: int}> the boards of the project with their depth, children after their parent */
    private function boardsTree(Project $project): array
    {
        $boards = $this->em->getRepository(Board::class)->findBy(['project' => $project], ['position' => 'ASC']);
        $out = [];
        $walk = function (?Board $parent, int $level) use (&$walk, &$out, $boards): void {
            foreach ($boards as $b) {
                if ($b->parent?->id === $parent?->id) {
                    $out[] = [$b, $level];
                    $walk($b, $level + 1);
                }
            }
        };
        $walk(null, 0);

        return $out;
    }
}
