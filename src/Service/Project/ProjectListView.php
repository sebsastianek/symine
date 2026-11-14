<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Project;
use App\I18n\Localizer;
use App\Security\CurrentUser;
use App\Service\AccountService;
use App\Service\Activity\ProjectLastActivity;
use App\Service\CustomFieldService;
use App\Service\ProjectQueryService;
use App\Service\Settings;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What the projects list shows for a projects query (the $state of ProjectQueryService): the page with its
 * filters, board or table, and the "latest projects" feed.
 */
final class ProjectListView
{
    public function __construct(
        private readonly ProjectQueryService $queries,
        private readonly CustomFieldService $customFields,
        private readonly ProjectLastActivity $lastActivity,
        private readonly Localizer $i18n,
        private readonly Settings $settings,
        private readonly CurrentUser $currentUser,
        private readonly AccountService $accounts,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Number of projects of the list when it is shown in pages: the board shows them all, and an invalid query
     * shows none (null for both).
     *
     * @param array<string, mixed> $state
     */
    public function pagedCount(array $state): ?int
    {
        return $state['valid'] && 'board' !== $state['display_type'] ? $this->queries->count($state) : null;
    }

    /**
     * Variables of projects/index.html.twig.
     *
     * @param array<string, mixed>      $state
     * @param array<string, mixed>|null $pager the page of the list, for the count of pagedCount()
     *
     * @return array<string, mixed>
     */
    public function view(array $state, ?array $pager): array
    {
        $user = $this->currentUser->get();
        $vars = [
            'query' => $state,
            'available_filters' => $this->queries->availableFilters(),
            'available_columns' => $this->queries->availableColumns(),
            'groupable_columns' => $this->queries->groupableColumns(),
            'sidebar_queries' => $this->queries->visibleQueries(),
            'operators_by_type' => ProjectQueryService::OPERATORS_BY_TYPE,
            'operator_labels' => $this->queries->operatorLabels(),
            'atom_key' => $user->isLogged() ? $this->accounts->atomKey($user) : null,
        ];
        if (!$state['valid']) {
            return $vars;
        }
        $vars['csv_hidden_fields'] = $this->csvHiddenFields($state);
        if (null === $pager) {
            $vars['entries'] = $this->queries->results($state);

            return $vars;
        }
        $vars['pager'] = $pager;
        $vars['entries'] = $this->queries->results($state, $pager['offset'], $pager['limit']);
        $vars['custom_values'] = $this->customFields->valuesFor('Project', array_map(static fn (Project $p) => (int) $p->id, $vars['entries']));
        $vars['custom_fields'] = $this->queries->visibleCustomFields();
        if (\in_array('last_activity_date', $state['columns'], true) && [] !== $vars['entries']) {
            $tz = $this->i18n->userTimezone();
            $vars['last_activity_dates'] = array_map(static fn (\DateTimeImmutable $t) => ['time' => $t, 'date' => $t->setTimezone($tz)->format('Y-m-d')], $this->lastActivity->lastActivityDates($user, array_map(static fn (Project $p) => (int) $p->id, $vars['entries'])));
        }

        return $vars;
    }

    public function feedTitle(): string
    {
        return $this->settings->appTitle().': '.$this->i18n->l('label_project_latest');
    }

    /**
     * format.atom of ProjectsController#index: the latest projects of the query.
     *
     * @param array<string, mixed> $state
     *
     * @return list<array<string, mixed>> items of App\Service\AtomFeed
     */
    public function feedItems(array $state): array
    {
        $projects = $this->queries->results($state, 0, max(1, $this->settings->int('feeds_limit') ?: 15), 'p.createdOn DESC');

        return array_map(fn (Project $p) => [
            'title' => $p->name,
            'url' => $this->urls->generate('project_show', ['id' => $p->identifier]),
            'updated' => $p->createdOn ?? new \DateTimeImmutable(),
            'author' => null,
            'content' => $p->description,
            'project' => null,
            'object' => $p,
        ], $projects);
    }

    /**
     * query_as_hidden_field_tags(@query) for the CSV export form of the projects list
     *
     * @param array<string, mixed> $state
     */
    private function csvHiddenFields(array $state): string
    {
        $h = static fn (string $name, string $value): string => '<input type="hidden" name="'.htmlspecialchars($name).'" value="'.htmlspecialchars($value).'" autocomplete="off" />';
        $tags = $h('set_filter', '1');
        if ([] === $state['filters']) {
            $tags .= $h('f[]', '');
        }
        foreach ($state['filters'] as $field => $f) {
            $tags .= $h('f[]', (string) $field).$h('op['.$field.']', (string) $f['operator']);
            foreach ($f['values'] as $v) {
                $tags .= $h('v['.$field.'][]', (string) $v);
            }
        }
        foreach ($state['columns'] as $c) {
            $tags .= $h('c[]', (string) $c);
        }
        if ($state['group_by']) {
            $tags .= $h('group_by', (string) $state['group_by']);
        }
        if ([] !== $state['sort']) {
            $tags .= $h('sort', implode(',', array_map(static fn ($s) => $s[0].('desc' === $s[1] ? ':desc' : ''), $state['sort'])));
        }

        return $tags;
    }
}
