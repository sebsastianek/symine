<?php

declare(strict_types=1);

namespace App\Service\Tracking\Categories;

use App\Api\ApiRenderer;
use App\Entity\IssueCategory;
use App\Entity\Project;
use App\Service\ProjectService;
use App\Service\VersionService;
use Symfony\Component\HttpFoundation\Request;

/**
 * The form of an issue category. Fields: issue_category[name], issue_category[assigned_to_id]; the REST API
 * sends the same attributes in its issue_category element.
 */
final class IssueCategoryForm
{
    public function __construct(
        private readonly VersionService $service,
        private readonly ProjectService $projects,
    ) {
    }

    public function blank(Project $project): IssueCategory
    {
        $category = new IssueCategory();
        $category->project = $project;

        return $category;
    }

    /** Gives a new category the attributes of the link that opened the form */
    public function prefill(IssueCategory $category, Request $request): void
    {
        $this->service->assignCategory($category, $request->query->all('issue_category'));
    }

    /** @return list<string> the validation errors, [] when the category was saved */
    public function submit(IssueCategory $category, Request $request, bool $api): array
    {
        $attrs = $api ? (array) (ApiRenderer::payload($request)['issue_category'] ?? []) : $request->request->all('issue_category');
        $this->service->assignCategory($category, $attrs);

        return $this->service->saveCategory($category);
    }

    /**
     * @param list<string> $errors
     *
     * @return array{project: Project, category: IssueCategory, errors: list<string>, assignable_users: array} what the form shows
     */
    public function view(IssueCategory $category, array $errors = []): array
    {
        return [
            'project' => $category->project,
            'category' => $category,
            'errors' => $errors,
            'assignable_users' => $this->projects->assignableUsers($category->project),
        ];
    }
}
