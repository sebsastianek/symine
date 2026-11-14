<?php

declare(strict_types=1);

namespace App\Menu;

use App\Entity\Project;
use App\I18n\Localizer;
use App\Security\AccessControl;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\ProjectService;
use App\Service\Settings;
use Symfony\Component\Routing\RouterInterface;

/**
 * Port of Redmine's MenuManager menus (top_menu, account_menu, application_menu, project_menu, admin_menu).
 * Items whose route does not exist are skipped, so modules can be added independently.
 *
 * @phpstan-type MenuItem array{name: string, url: string, caption: string, selected?: bool, class?: string, icon?: string, html?: array<string, string>, children?: list<array>}
 */
final class MenuBuilder
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly CurrentUser $currentUser,
        private readonly Authorization $auth,
        private readonly Settings $settings,
        private readonly Localizer $i18n,
        private readonly ProjectService $projects,
        private readonly ?MenuRegistry $registry = null,
        private readonly ?\App\Theme\ThemeContext $themes = null,
    ) {
    }

    /** Items that stay at the end of their menu (Redmine :last => true) */
    private const array LAST_ITEMS = [
        'top_menu' => ['administration', 'help'],
        'project_menu' => ['settings'],
        'admin_menu' => ['plugins', 'info'],
    ];

    private function url(string $route, array $params = []): ?string
    {
        if (null === $this->router->getRouteCollection()->get($route)) {
            return null;
        }

        return $this->router->generate($route, $params);
    }

    /** @return list<array> */
    public function topMenu(): array
    {
        $user = $this->currentUser->get();
        $items = [];
        $this->push($items, 'home', 'home', [], 'label_home');
        if ($user->isLogged()) {
            $this->push($items, 'my_page', 'my_page', [], 'label_my_page');
        }
        $this->push($items, 'projects', 'projects_index', [], 'label_project_plural');
        if ($user->admin) {
            $this->push($items, 'administration', 'admin_index', [], 'label_administration');
        }
        $items[] = ['name' => 'help', 'url' => 'https://www.redmine.org/guide', 'caption' => $this->i18n->l('label_help'), 'html' => ['target' => '_blank', 'rel' => 'noopener']];

        return $this->applyRegistry('top_menu', $items, null);
    }

    /** @return list<array> */
    public function accountMenu(): array
    {
        $user = $this->currentUser->get();
        $items = [];
        if (!$user->isLogged()) {
            $this->push($items, 'login', 'signin', [], 'label_login');
            if ('0' !== $this->settings->selfRegistration() && '' !== $this->settings->selfRegistration()) {
                $this->push($items, 'register', 'register', [], 'label_register');
            }
        } else {
            $this->push($items, 'my_profile', 'user_show', ['id' => $user->id], 'label_profile');
            $this->push($items, 'my_account', 'my_account', [], 'label_my_account');
            // Symine: personal appearance, when the theme in use or the administrators leave something to choose
            if ($this->themes?->customisable()) {
                $this->push($items, 'my_appearance', 'my_appearance', [], 'label_appearance');
            }
            $this->push($items, 'logout', 'signout', [], 'label_logout', ['data-method' => 'post']);
        }

        return $this->applyRegistry('account_menu', $items, null);
    }

    /** Global (no project) main menu @return list<array> */
    public function applicationMenu(?string $selected = null): array
    {
        $items = [];
        $this->push($items, 'projects', 'projects_index', [], 'label_project_plural');
        $this->push($items, 'activity', 'activity', [], 'label_activity');
        $modulePerm = [
            ['issues', 'issues_index', 'label_issue_plural', 'view_issues', 'issue_tracking'],
            ['time_entries', 'time_entries_index', 'label_spent_time', 'view_time_entries', 'time_tracking'],
            ['gantt', 'gantt', 'label_gantt', 'view_gantt', 'gantt'],
            ['calendar', 'calendar', 'label_calendar', 'view_calendar', 'calendar'],
            ['news', 'news_index', 'label_news_plural', 'view_news', 'news'],
        ];
        foreach ($modulePerm as [$name, $route, $caption, $perm, $module]) {
            if ($this->auth->allowedToGlobally($perm) && $this->projects->anyVisibleWithModule($module)) {
                $this->push($items, $name, $route, [], $caption);
            }
        }

        return $this->markSelected($this->applyRegistry('application_menu', $items, null), $selected);
    }

    /** @return list<array> */
    public function projectMenu(Project $project, ?string $selected = null): array
    {
        $items = [];
        $p = ['project_id' => $project->identifier];
        $allowed = fn (string $perm) => $this->auth->allowedTo($perm, $project);
        $newItems = [];
        if ($allowed('add_issues') && $this->projects->allowedTargetTrackers($project) !== []) {
            $this->push($newItems, 'new_issue_sub', 'issue_new', $p, 'label_issue_new');
        }
        if ($allowed('manage_categories')) {
            $this->push($newItems, 'new_issue_category', 'issue_category_new', $p, 'label_issue_category_new');
        }
        if ($allowed('manage_versions')) {
            $this->push($newItems, 'new_version', 'version_new', $p, 'label_version_new');
        }
        if ($allowed('log_time')) {
            $this->push($newItems, 'new_timelog', 'time_entry_new', $p, 'button_log_time');
        }
        if ($allowed('manage_news')) {
            $this->push($newItems, 'new_news', 'news_new', $p, 'label_news_new');
        }
        if ($allowed('add_documents')) {
            $this->push($newItems, 'new_document', 'document_new', $p, 'label_document_new');
        }
        if ($allowed('edit_wiki_pages')) {
            $this->push($newItems, 'new_wiki_page', 'wiki_new', $p, 'label_wiki_page_new');
        }
        if ($allowed('manage_files')) {
            $this->push($newItems, 'new_file', 'file_new', $p, 'label_attachment_new');
        }
        if ('2' === $this->settings->string('new_item_menu_tab') && [] !== $newItems) {
            $items[] = ['name' => 'new_object', 'url' => '#', 'caption' => ' + ', 'html' => ['id' => 'new-object', 'onclick' => 'toggleNewObjectDropdown(); return false;'], 'children' => $newItems, 'class' => 'new-object'];
        }
        $this->push($items, 'overview', 'project_show', ['id' => $project->identifier], 'label_overview');
        if ($allowed('view_project')) {
            $this->push($items, 'activity', 'project_activity', ['id' => $project->identifier], 'label_activity');
        }
        if ($allowed('view_issues') && $this->projects->hasRoadmap($project)) {
            $this->push($items, 'roadmap', 'project_roadmap', $p, 'label_roadmap');
        }
        if ($allowed('view_issues')) {
            $this->push($items, 'issues', 'project_issues', $p, 'label_issue_plural');
        }
        if ('1' === $this->settings->string('new_item_menu_tab') && $allowed('add_issues') && $this->projects->allowedTargetTrackers($project) !== []) {
            $this->push($items, 'new_issue', 'issue_new', $p, 'label_issue_new');
        }
        $checks = [
            ['time_entries', 'project_time_entries', 'label_spent_time', 'view_time_entries'],
            ['gantt', 'project_gantt', 'label_gantt', 'view_gantt'],
            ['calendar', 'project_calendar', 'label_calendar', 'view_calendar'],
            ['news', 'project_news', 'label_news_plural', 'view_news'],
            ['documents', 'project_documents', 'label_document_plural', 'view_documents'],
        ];
        foreach ($checks as [$name, $route, $caption, $perm]) {
            if ($allowed($perm)) {
                $this->push($items, $name, $route, $p, $caption);
            }
        }
        if ($allowed('view_wiki_pages') && $this->projects->hasWiki($project)) {
            $this->push($items, 'wiki', 'wiki_index', $p, 'label_wiki');
        }
        if ($allowed('view_messages') && $this->projects->hasBoards($project)) {
            $this->push($items, 'boards', 'boards_index', $p, 'label_board_plural');
        }
        if ($allowed('view_files')) {
            $this->push($items, 'files', 'project_files', $p, 'label_file_plural');
        }
        // repositories#show is allowed by browse_repository or view_changesets
        if (($allowed('browse_repository') || $allowed('view_changesets')) && $this->projects->hasRepository($project)) {
            $this->push($items, 'repository', 'repository_show', ['id' => $project->identifier], 'label_repository');
        }
        if ($this->projects->canSeeSettings($project)) {
            $this->push($items, 'settings', 'project_settings', ['id' => $project->identifier], 'label_settings');
        }

        return $this->markSelected($this->applyRegistry('project_menu', $items, $project), $selected);
    }

    /** @return list<array> */
    public function adminMenu(?string $selected = null): array
    {
        $items = [];
        $defs = [
            ['projects', 'admin_projects', 'label_project_plural', 'projects'],
            ['users', 'users_index', 'label_user_plural', 'user'],
            ['groups', 'groups_index', 'label_group_plural', 'group'],
            ['roles', 'roles_index', 'label_role_and_permissions', 'roles'],
            ['trackers', 'trackers_index', 'label_tracker_plural', 'issue'],
            ['issue_statuses', 'issue_statuses_index', 'label_issue_status_plural', 'issue-edit'],
            ['workflows', 'workflows_edit', 'label_workflow', 'workflows'],
            ['custom_fields', 'custom_fields_index', 'label_custom_field_plural', 'custom-fields'],
            ['enumerations', 'enumerations_index', 'label_enumerations', 'list'],
            ['settings', 'settings_index', 'label_settings', 'settings'],
            ['themes', 'admin_themes', 'label_theme_plural', 'photo'],
            ['ldap_authentication', 'auth_sources_index', 'label_ldap_authentication', 'server-authentication'],
            ['applications', 'oauth_applications', 'doorkeeper.layouts.admin.nav.applications', 'apps'],
            ['webhooks', 'admin_webhooks', 'label_webhook_plural', 'webhook'],
            ['plugins', 'admin_plugins', 'label_plugins', 'plugins'],
            ['info', 'admin_info', 'label_information_plural', 'help'],
        ];
        foreach ($defs as [$name, $route, $caption, $icon]) {
            if ('applications' === $name && !$this->settings->restApiEnabled()) {
                continue;
            }
            $this->push($items, $name, $route, [], $caption);
            if ($items && end($items)['name'] === $name) {
                $items[array_key_last($items)]['icon'] = $icon;
                $items[array_key_last($items)]['class'] = 'icon icon-'.('applications' === $name ? 'applications' : $icon);
            }
        }

        return $this->markSelected($this->applyRegistry('admin_menu', $items, null), $selected);
    }

    private function push(array &$items, string $name, string $route, array $params, string $caption, array $html = []): void
    {
        $url = $this->url($route, $params);
        if (null === $url) {
            return;
        }
        $items[] = ['name' => $name, 'url' => $url, 'caption' => $this->i18n->l($caption), 'html' => $html];
    }

    /**
     * Applies the items pushed/deleted/replaced by plugins (MenuRegistry) to the core items of a menu,
     * with Redmine's positioning rules (first, before, after, last; other items go before the "last" ones).
     *
     * @param list<array> $items
     *
     * @return list<array>
     */
    private function applyRegistry(string $menu, array $items, ?Project $project): array
    {
        $ops = $this->registry?->operations($menu) ?? [];
        if ([] === $ops) {
            return $items;
        }
        foreach ($items as &$item) {
            $item['last'] = \in_array($item['name'], self::LAST_ITEMS[$menu] ?? [], true);
        }
        unset($item);
        foreach ($ops as $op) {
            $name = $op['name'];
            if ('delete' === $op['op']) {
                $items = self::removeItem($items, $name);
                continue;
            }
            $options = $op['options'];
            $item = $this->buildItem($menu, $name, $op['url'], $options, $project);
            $position = null;
            if ('replace' === $op['op'] && !isset($options['first']) && !isset($options['before']) && !isset($options['after']) && !isset($options['last'])) {
                $position = self::positionOf($items, $name);
            }
            $items = self::removeItem($items, $name);
            if (null === $item) {
                continue;
            }
            if (isset($options['parent']) && null !== ($p = self::positionOf($items, (string) $options['parent']))) {
                $items[$p]['children'] = [...($items[$p]['children'] ?? []), $item];
                continue;
            }
            $lastCount = \count(array_filter($items, static fn (array $i) => $i['last'] ?? false));
            if (null === $position) {
                if (!empty($options['first'])) {
                    $position = 0;
                } elseif (isset($options['before']) && null !== ($p = self::positionOf($items, (string) $options['before']))) {
                    $position = $p;
                } elseif (isset($options['after']) && null !== ($p = self::positionOf($items, (string) $options['after']))) {
                    $position = $p + 1;
                } elseif (!empty($options['last'])) {
                    $position = \count($items);
                } else {
                    $position = \count($items) - $lastCount;
                }
            }
            array_splice($items, max(0, min($position, \count($items))), 0, [$item]);
        }

        return array_values(array_map(static function (array $i): array {
            unset($i['last']);

            return $i;
        }, $items));
    }

    /**
     * Builds a menu item from registry options, or null when it is not allowed / its route does not exist
     * (MenuItem#allowed?, caption, url).
     */
    private function buildItem(string $menu, string $name, mixed $url, array $options, ?Project $project): ?array
    {
        $user = $this->currentUser->get();
        if (isset($options['if']) && !($options['if'])($project, $user)) {
            return null;
        }
        $route = \is_string($url) && null !== $this->router->getRouteCollection()->get($url) ? $url : (\is_array($url) ? (string) ($url['route'] ?? '') : null);
        if (null !== $project) {
            $permission = \array_key_exists('permission', $options) ? $options['permission'] : (null !== $route ? AccessControl::permissionForRoute($route) : null);
            if (\is_string($permission) && '' !== $permission && !$this->auth->allowedTo($permission, $project)) {
                return null;
            }
        }
        if ($url instanceof \Closure) {
            $href = (string) $url($project);
        } elseif (null !== $route && '' !== $route) {
            $params = \is_array($url) ? (array) ($url['params'] ?? []) : [];
            if (null !== $project && 'project_menu' === $menu) {
                $params = [(string) ($options['param'] ?? 'id') => $project->identifier, ...$params];
            }
            $href = $this->url($route, $params);
            if (null === $href) {
                return null;
            }
        } elseif (\is_string($url) && (str_starts_with($url, '/') || str_starts_with($url, '#') || str_contains($url, '://'))) {
            $href = $url;
        } else {
            return null; // unknown route
        }
        $caption = $options['caption'] ?? null;
        if ($caption instanceof \Closure) {
            $caption = (string) $caption($project);
            if ('' === $caption) {
                $caption = self::humanize($name);
            }
        } elseif (null === $caption) {
            $caption = $this->i18n->has('label_'.$name) ? $this->i18n->l('label_'.$name) : self::humanize($name);
        } else {
            $caption = $this->i18n->has((string) $caption) ? $this->i18n->l((string) $caption) : (string) $caption;
        }
        $item = ['name' => $name, 'url' => $href, 'caption' => $caption, 'html' => array_map('strval', (array) ($options['html'] ?? [])), 'last' => !empty($options['last'])];
        if (isset($options['icon'])) {
            $item['icon'] = (string) $options['icon'];
            $item['class'] = trim(($options['class'] ?? '').' icon icon-'.$options['icon']);
        } elseif (isset($options['class'])) {
            $item['class'] = (string) $options['class'];
        }
        if (isset($options['children'])) {
            $children = [];
            foreach (($options['children'])($project) as $child) {
                [$cName, $cUrl, $cOptions] = array_pad(array_values((array) $child), 3, []);
                if (null !== ($c = $this->buildItem($menu, (string) $cName, $cUrl, (array) $cOptions, $project))) {
                    $children[] = $c;
                }
            }
            $item['children'] = $children;
        }
        if (isset($options['plugin'])) {
            $item['plugin'] = (string) $options['plugin'];
        }

        return $item;
    }

    private static function humanize(string $name): string
    {
        return ucfirst(str_replace('_', ' ', preg_replace('/_id$/', '', $name) ?? $name));
    }

    private static function positionOf(array $items, string $name): ?int
    {
        foreach ($items as $i => $item) {
            if ($item['name'] === $name) {
                return $i;
            }
        }

        return null;
    }

    /** Removes an item (and the children with that name) */
    private static function removeItem(array $items, string $name): array
    {
        $out = [];
        foreach ($items as $item) {
            if ($item['name'] === $name) {
                continue;
            }
            if (!empty($item['children'])) {
                $item['children'] = self::removeItem($item['children'], $name);
            }
            $out[] = $item;
        }

        return $out;
    }

    private function markSelected(array $items, ?string $selected): array
    {
        foreach ($items as &$item) {
            $item['selected'] = $item['name'] === $selected;
        }

        return $items;
    }
}
