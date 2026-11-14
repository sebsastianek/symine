<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Project;
use App\Menu\MenuBuilder;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/** Where the project actions send the user next: the "jump" of the project box and the page shown after an action. */
final class ProjectNavigation
{
    /** Application menu items the projects list can jump to (params[:jump] without a project) */
    private const array MENU_ITEM_ROUTES = ['issues' => 'issues_index', 'activity' => 'activity', 'time_entries' => 'time_entries_index', 'gantt' => 'gantt', 'calendar' => 'calendar', 'news' => 'news_index', 'projects' => null];

    public function __construct(
        private readonly RouterInterface $router,
        private readonly MenuBuilder $menus,
        private readonly CurrentUser $currentUser,
    ) {
    }

    /** redirect_to_menu_item on the projects list: the URL of the application menu item, null to stay on the list */
    public function menuItemUrl(string $jump): ?string
    {
        $route = self::MENU_ITEM_ROUTES[$jump] ?? null;
        if (null === $route || !$this->hasRoute($route)) {
            return null;
        }

        return $this->router->generate($route);
    }

    /** redirect_to_project_menu_item: the URL of the item of the project menu, null when the user does not have it */
    public function projectMenuItemUrl(Project $project, string $jump): ?string
    {
        if ('' === $jump) {
            return null;
        }
        foreach ($this->menus->projectMenu($project) as $item) {
            if ($item['name'] === $jump) {
                return $item['url'];
            }
        }

        return null;
    }

    /** redirect_back_or_default(admin_projects_path(status: params[:status])) after archiving or unarchiving */
    public function backToAdminUrl(Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');
        if ('' !== $referer && str_starts_with($referer, $request->getSchemeAndHttpHost())) {
            return $referer;
        }
        if ($this->hasRoute('admin_projects')) {
            return $this->router->generate('admin_projects', array_filter(['status' => $request->query->get('status')]));
        }

        return $this->router->generate('projects_index');
    }

    /** The list a deletion comes from and returns to: the administration list for administrators, the projects list otherwise */
    public function projectsListUrl(): string
    {
        $admin = $this->currentUser->get()->admin && $this->hasRoute('admin_projects');

        return $this->router->generate($admin ? 'admin_projects' : 'projects_index');
    }

    private function hasRoute(string $name): bool
    {
        return null !== $this->router->getRouteCollection()->get($name);
    }
}
