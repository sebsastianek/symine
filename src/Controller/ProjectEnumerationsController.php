<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ProjectManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's ProjectEnumerationsController (project time entry activities overrides). */
final class ProjectEnumerationsController extends AbstractController
{
    public function __construct(private readonly ProjectManager $manager)
    {
    }

    #[Route('/projects/{project_id}/enumerations', name: 'project_enumerations', methods: ['PUT', 'PATCH'])]
    public function update(Request $request, string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_project_activities', $project);
        $data = $request->request->all()['enumerations'] ?? [];
        if (\is_array($data) && [] !== $data) {
            $this->manager->updateActivities($project, $data);
            $this->flashNotice();
        }

        return $this->redirectToRoute('project_settings', ['id' => $project->identifier, 'tab' => 'activities']);
    }

    #[Route('/projects/{project_id}/enumerations', name: 'project_enumerations_destroy', methods: ['DELETE'])]
    public function destroy(string $project_id): Response
    {
        $project = $this->findProject($project_id);
        $this->authorize('manage_project_activities', $project);
        $this->manager->resetActivities($project);
        $this->flashNotice();

        return $this->redirectToRoute('project_settings', ['id' => $project->identifier, 'tab' => 'activities']);
    }
}
