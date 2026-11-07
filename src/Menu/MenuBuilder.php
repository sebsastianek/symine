<?php

declare(strict_types=1);

namespace App\Menu;

use App\Entity\Project;
use App\I18n\Localizer;
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
    ) {
    }

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

        return $items;
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
            $this->push($items, 'logout', 'signout', [], 'label_logout', ['data-method' => 'post']);
        }

        return $items;
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

        return $this->markSelected($items, $selected);
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
        if ($allowed('browse_repository') && $this->projects->hasRepository($project)) {
            $this->push($items, 'repository', 'repository_show', ['id' => $project->identifier], 'label_repository');
        }
        if ($this->projects->canSeeSettings($project)) {
            $this->push($items, 'settings', 'project_settings', ['id' => $project->identifier], 'label_settings');
        }

        return $this->markSelected($items, $selected);
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
            ['ldap_authentication', 'auth_sources_index', 'label_ldap_authentication', 'server-authentication'],
            ['webhooks', 'admin_webhooks', 'label_webhook_plural', 'webhook'],
            ['plugins', 'admin_plugins', 'label_plugins', 'plugins'],
            ['info', 'admin_info', 'label_information_plural', 'help'],
        ];
        foreach ($defs as [$name, $route, $caption, $icon]) {
            $this->push($items, $name, $route, [], $caption);
            if ($items && end($items)['name'] === $name) {
                $items[array_key_last($items)]['icon'] = $icon;
                $items[array_key_last($items)]['class'] = 'icon icon-'.$icon;
            }
        }

        return $this->markSelected($items, $selected);
    }

    private function push(array &$items, string $name, string $route, array $params, string $caption, array $html = []): void
    {
        $url = $this->url($route, $params);
        if (null === $url) {
            return;
        }
        $items[] = ['name' => $name, 'url' => $url, 'caption' => $this->i18n->l($caption), 'html' => $html];
    }

    private function markSelected(array $items, ?string $selected): array
    {
        foreach ($items as &$item) {
            $item['selected'] = $item['name'] === $selected;
        }

        return $items;
    }
}
