<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\IssueCategory;
use App\Entity\Project;

/** The issue categories of the REST API (issue_categories/index.api.rsb and show.api.rsb). */
final class IssueCategoryApiPresenter
{
    /** @return array{issue_categories: list<array<string, mixed>>, total_count: int} the categories of the project, by name */
    public function index(Project $project): array
    {
        $categories = $project->issueCategories->toArray();
        usort($categories, static fn (IssueCategory $a, IssueCategory $b) => strcmp($a->name, $b->name));

        return [
            'issue_categories' => array_map($this->category(...), $categories),
            'total_count' => \count($categories),
        ];
    }

    /** issue_categories/show.api.rsb */
    public function category(IssueCategory $category): array
    {
        $data = [
            'id' => $category->id,
            'project' => ['id' => $category->project->id, 'name' => $category->project->name],
            'name' => $category->name,
        ];
        if (null !== $category->assignedTo) {
            $data['assigned_to'] = ['id' => $category->assignedTo->id, 'name' => $category->assignedTo->getName()];
        }

        return $data;
    }
}
