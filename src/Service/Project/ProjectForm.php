<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Api\ApiRenderer;
use App\Entity\Project;
use App\Security\AccessControl;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\ProjectManager;
use App\Service\ProjectService;
use Symfony\Component\HttpFoundation\Request;

/**
 * The project form (projects/_form) of the new, copy and settings pages, and of the REST API: assigns the
 * submitted project[...] attributes the user may set, saves, and gives the form its variables.
 */
final class ProjectForm
{
    public function __construct(
        private readonly ProjectManager $manager,
        private readonly ProjectService $projects,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
    ) {
    }

    /** projects#new: a project with the default values, those of the URL and the parent given by parent_id */
    public function build(Request $request): Project
    {
        $project = $this->manager->newProject();
        $attrs = (array) ($request->query->all()['project'] ?? []);
        if ($request->query->has('parent_id')) {
            $parent = $this->projects->findByIdentifierOrId((string) $request->query->get('parent_id'));
            $attrs['parent_id'] = $parent?->id;
        }
        $this->manager->setSafeAttributes($project, $attrs);

        return $project;
    }

    /**
     * projects#create: the creator becomes a member of the new project, unless it is an administrator.
     *
     * @return array{0: Project, 1: list<string>} the project and what prevented saving it
     */
    public function create(Request $request, bool $api): array
    {
        $project = $this->manager->newProject();
        $this->manager->setSafeAttributes($project, self::attributes($request, $api));
        $errors = $this->manager->save($project);
        if ([] === $errors && !$this->currentUser->get()->admin) {
            $this->manager->addDefaultMember($project, $this->currentUser->get());
        }

        return [$project, $errors];
    }

    /** @return list<string> what prevented saving the project */
    public function update(Project $project, Request $request, bool $api): array
    {
        $this->manager->setSafeAttributes($project, self::attributes($request, $api));

        return $this->manager->save($project);
    }

    /**
     * projects#copy: a new project with the submitted attributes and the selected content (only[]) of the source.
     *
     * @return array{0: Project, 1: list<string>} the copy and what prevented saving it
     */
    public function copy(Project $source, Request $request): array
    {
        $project = $this->manager->newProject();
        $this->manager->setSafeAttributes($project, self::attributes($request, false));
        $only = $request->request->all()['only'] ?? null;
        $only = \is_array($only) ? array_values(array_filter(array_map('strval', $only))) : null;
        // Mailer.with_deliveries(params[:notifications] == '1')
        $errors = $this->manager->copy($source, $project, $only, null, '1' === (string) $request->request->get('notifications'));

        return [$project, $errors];
    }

    /**
     * Variables needed by projects/_form.html.twig
     *
     * @return array<string, mixed>
     */
    public function view(Project $project): array
    {
        $parents = $this->manager->allowedParents($project);
        $user = $this->currentUser->get();

        return [
            'form_project' => $project,
            'allowed_parents' => $parents,
            'allow_root' => \in_array(null, $parents, true),
            'parent_options' => array_values(array_filter($parents)),
            'custom_fields' => $this->manager->visibleCustomFields($project),
            'custom_values' => $this->manager->customValues($project),
            'identifier_frozen' => $this->manager->isIdentifierFrozen($project),
            'safe_is_public' => $this->manager->isSafeAttribute($project, 'is_public'),
            'safe_modules' => $this->manager->isSafeAttribute($project, 'enabled_module_names'),
            'safe_inherit_members' => $this->manager->isSafeAttribute($project, 'inherit_members'),
            'available_modules' => AccessControl::modules(),
            'inherit_confirm' => !$user->admin && $project->inheritMembers && null !== $project->parent && $this->auth->isMemberOf($user, $project->parent),
        ];
    }

    /**
     * What the copy page shows besides the form: the source and how much of each kind of content it has.
     *
     * @return array<string, mixed>
     */
    public function copyView(Project $source): array
    {
        return [
            'source_project' => $source,
            'copy_counts' => $this->manager->copyCounts($source),
        ];
    }

    /** @return array<array-key, mixed> params[:project], from the form or from the API document */
    private static function attributes(Request $request, bool $api): array
    {
        return $api ? (array) (ApiRenderer::payload($request)['project'] ?? []) : (array) ($request->request->all()['project'] ?? []);
    }
}
