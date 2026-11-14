<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\I18n\Localizer;
use App\Query\ProjectAdminQuery;
use App\Query\QueryError;
use App\Query\QueryFactory;
use App\Query\StatementInvalid;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\Activity\ProjectLastActivity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** The list of Administration > Projects (admin#projects): the ProjectAdminQuery of the request and one page of its projects. */
final class ProjectAdminList
{
    public function __construct(
        private readonly QueryFactory $queries,
        private readonly ProjectLastActivity $lastActivity,
        private readonly Authorization $auth,
        private readonly CurrentUser $currentUser,
        private readonly Localizer $i18n,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Variables of the list page.
     *
     * @param callable(Request, int): array<string, mixed> $paginate the pager for a number of results
     *
     * @return array<string, mixed>
     */
    public function view(Request $request, callable $paginate): array
    {
        // retrieve_query(ProjectAdminQuery, false)
        try {
            $query = $this->queries->retrieve($request, 'ProjectAdminQuery', null, false);
        } catch (QueryError) {
            $query = $this->queries->create('ProjectAdminQuery');
        }
        \assert($query instanceof ProjectAdminQuery);
        $vars = [
            'query' => $query,
            'admin_menu_selected' => 'projects',
            'page_title' => $this->i18n->l('label_project_plural'),
            'body_css' => 'controller-admin action-projects',
            'clear_url' => $this->urls->generate('admin_projects', ['set_filter' => 1, 'sort' => '']),
            'new_query_url' => $this->urls->generate('query_new'),
            'can_save_queries' => $this->auth->allowedTo('save_queries', null, null, true),
            'extra_query_params' => [],
            'entries' => [],
        ];
        if ($query->isValid()) {
            try {
                $count = $query->resultCount();
                $vars['pager'] = $paginate($request, $count);
                $vars['entries'] = $query->results($vars['pager']['offset'], $vars['pager']['limit']);
            } catch (StatementInvalid) {
                $query->errors[] = $this->i18n->l('error_query_statement_invalid');
                $vars['entries'] = [];
            }
            if ($query->hasColumn('last_activity_date') && [] !== $vars['entries']) {
                $query->setLastActivityDates($this->lastActivity->lastActivityDates($this->currentUser->get(), array_map(static fn ($p) => (int) $p->id, $vars['entries'])));
            }
        }

        return $vars;
    }
}
