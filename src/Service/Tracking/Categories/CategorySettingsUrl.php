<?php

declare(strict_types=1);

namespace App\Service\Tracking\Categories;

use App\Entity\Project;
use Symfony\Component\Routing\RouterInterface;

/**
 * The "Issue categories" tab of the project settings: where the categories are listed and managed.
 */
final class CategorySettingsUrl
{
    public function __construct(private readonly RouterInterface $router)
    {
    }

    public function of(Project $project): string
    {
        if (null !== $this->router->getRouteCollection()->get('project_settings')) {
            return $this->router->generate('project_settings', ['id' => $project->identifier, 'tab' => 'categories']);
        }

        return '/projects/'.$project->identifier.'/settings/categories';
    }
}
