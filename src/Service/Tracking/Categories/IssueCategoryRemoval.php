<?php

declare(strict_types=1);

namespace App\Service\Tracking\Categories;

use App\Entity\IssueCategory;
use App\Entity\Project;
use App\Service\VersionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Deleting an issue category (IssueCategoriesController#destroy): a category that has issues is deleted only
 * once the user has said what becomes of them (todo = nullify or reassign, reassign_to_id).
 */
final class IssueCategoryRemoval
{
    public function __construct(
        private readonly VersionService $service,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Deletes the category when it has no issue, when the choice was made, or for the REST API.
     *
     * @return array{project: Project, category: IssueCategory, issue_count: int, categories: list<IssueCategory>}|null null when
     *         the category was deleted, otherwise what the page asking for the choice shows
     */
    public function attempt(IssueCategory $category, Request $request, bool $api): ?array
    {
        $project = $category->project;
        $issueCount = $this->service->categoryIssuesCount($category);
        $todo = (string) ($request->request->get('todo') ?? $request->query->get('todo', ''));
        if (0 === $issueCount || '' !== $todo || $api) {
            $reassignTo = null;
            $reassignId = $request->request->get('reassign_to_id') ?? $request->query->get('reassign_to_id');
            if (null !== $reassignId && ('reassign' === $todo || '' === $todo)) {
                $reassignTo = $this->em->getRepository(IssueCategory::class)->findOneBy(['id' => (int) $reassignId, 'project' => $project]);
            }
            $this->service->destroyCategory($category, $reassignTo);

            return null;
        }

        return [
            'project' => $project,
            'category' => $category,
            'issue_count' => $issueCount,
            'categories' => array_values(array_filter($project->issueCategories->toArray(), static fn (IssueCategory $c) => $c->id !== $category->id)),
        ];
    }
}
