<?php

declare(strict_types=1);

namespace App\Service\Admin\Users;

use App\Entity\User;
use App\I18n\Localizer;
use App\Query\QueryError;
use App\Query\QueryFactory;
use App\Query\UserQuery;
use App\Security\Authorization;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The list of Administration > Users (users#index): the UserQuery of the request, including the filter
 * parameters of older Redmine versions, and what the page shows.
 */
final class UserList
{
    public function __construct(
        private readonly QueryFactory $queries,
        private readonly Authorization $auth,
        private readonly Localizer $i18n,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** The query asked by the request, for the given representation (html, csv, json, xml) */
    public function query(Request $request, string $format): UserQuery
    {
        $q = $request->query;
        // Legacy (Redmine < 6) "status=" meant all users; keep it working as "status=*" (any status)
        if (!$q->has('f') && !$q->has('fields') && $q->has('status') && \is_string($q->get('status')) && '' === trim((string) $q->get('status'))) {
            $q->set('status', '*');
        }
        // Legacy list links (/users?status=3, /users?name=x): build the query from these params instead of the session one
        if (!$q->has('set_filter') && !$q->has('query_id') && !$q->has('f') && ($q->has('status') || $q->has('name'))) {
            $q->set('set_filter', '1');
        }
        $query = $this->retrieve($request, 'csv' !== $format);

        // API backwards compatibility: handle legacy filter parameters
        if ('html' !== $format) {
            if (\is_string($name = $q->get('name')) && '' !== trim($name)) {
                $query->addFilter('name', '~', [$name]);
            }
            if (\is_scalar($groupId = $q->get('group_id')) && '' !== trim((string) $groupId)) {
                $query->addFilter('is_member_of_group', '=', [(string) $groupId]);
            }
        }

        return $query;
    }

    /**
     * Variables of the list page.
     *
     * @param list<User>                $users
     * @param array<string, mixed>|null $pager
     *
     * @return array<string, mixed>
     */
    public function view(UserQuery $query, array $users, ?array $pager): array
    {
        return [
            'query' => $query,
            'users' => $users,
            'pager' => $pager,
            'clear_url' => $this->urls->generate('users_index', ['set_filter' => 1, 'sort' => '']),
            'new_query_url' => $this->urls->generate('query_new'),
            'can_save_queries' => $this->auth->allowedTo('save_queries', null, null, true),
            'extra_query_params' => [],
            'can_import' => $this->auth->allowedTo('import_users', null, null, true),
            'admin_menu_selected' => 'users',
            'page_title' => $this->i18n->l('label_user_plural'),
            'body_css' => 'controller-users action-index',
        ];
    }

    private function retrieve(Request $request, bool $useSession): UserQuery
    {
        try {
            $query = $this->queries->retrieve($request, 'UserQuery', null, $useSession);
        } catch (QueryError) {
            $query = $this->queries->create('UserQuery');
        }
        \assert($query instanceof UserQuery);

        return $query;
    }
}
