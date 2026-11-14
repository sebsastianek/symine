<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Which actions accept key based (stateless) authentication: the equivalent of Redmine's
 * accept_atom_auth / accept_api_auth declarations (ApplicationController#find_current_user).
 *
 * - The Atom feeds key (params[:key]) is accepted only for GET requests answered as Atom by an
 *   action listed in ATOM_ACTIONS.
 * - API credentials (key parameter, X-Redmine-API-Key header, HTTP Basic, OAuth2 Bearer) are accepted
 *   only by the actions listed in API_ACTIONS.
 *
 * Actions are identified by the "_controller" of the matched route. Plugins declare their own actions
 * with the route defaults "_accept_atom_auth" / "_accept_api_auth" (true).
 */
final class KeyAuthScope
{
    private const string NS = 'App\\Controller\\';

    /** accept_atom_auth: Redmine controller => Symine controller methods */
    private const array ATOM_ACTIONS = [
        'ActivitiesController' => ['index', 'project'],                 // activities#index
        'BoardsController' => ['index', 'show'],                        // boards#index, #show
        'IssueListController' => ['index', 'projectIndex'],             // issues#index
        'IssuesController' => ['show'],                                 // issues#show
        'JournalsController' => ['index'],                              // journals#index
        'NewsController' => ['index', 'projectIndex'],                  // news#index
        'ProjectsController' => ['index'],                              // projects#index
        'RepositoriesController' => ['revisions'],                      // repositories#revisions
        'TimelogController' => ['index'],                               // timelog#index
    ];

    /** accept_api_auth: Redmine controller => Symine controller methods */
    private const array API_ACTIONS = [
        'AttachmentsController' => ['show', 'apiShow', 'download', 'thumbnail', 'upload', 'update', 'destroy'],
        'DocumentsController' => ['index', 'show', 'create', 'update', 'destroy'],
        'FilesController' => ['index', 'create'],
        'IssueCategoriesController' => ['index', 'show', 'create', 'update', 'destroy'],
        'IssueListController' => ['index', 'projectIndex'],             // issues#index
        'IssueRelationsController' => ['index', 'show', 'create', 'destroy'],
        'IssuesController' => ['show', 'create', 'createGlobal', 'update', 'destroy'],
        'JournalsController' => ['update'],
        'MembersController' => ['index', 'members', 'exportCsv', 'show', 'create', 'update', 'destroy'],   // members#index: html, csv, api
        'MyController' => ['account'],
        'NewsController' => ['index', 'projectIndex', 'show', 'create', 'createGlobal', 'update', 'destroy'],
        'ProjectsController' => ['index', 'show', 'create', 'update', 'destroy', 'archive', 'unarchive', 'close', 'reopen'],
        'QueriesController' => ['index'],
        'RepositoriesController' => ['addRelatedIssue', 'removeRelatedIssue'],
        'SearchController' => ['index', 'project'],
        'TimelogController' => ['index', 'show', 'create', 'update', 'destroy'],
        'VersionsController' => ['index', 'indexApi', 'show', 'create', 'update', 'destroy'],
        'WatchersController' => ['create', 'destroy'],
        'WikiController' => ['index', 'show', 'update', 'destroy'],
        'Admin\\CustomFieldsController' => ['index'],
        'Admin\\EnumerationsController' => ['index', 'apiIndex'],
        'Admin\\GroupsController' => ['index', 'show', 'create', 'update', 'destroy', 'addUsers', 'removeUsers', 'removeUser'],
        'Admin\\IssueStatusesController' => ['index'],
        'Admin\\RolesController' => ['index', 'show'],
        'Admin\\TrackersController' => ['index'],
        'Admin\\UsersController' => ['index', 'show', 'current', 'create', 'update', 'destroy'],   // users#show for /users/current
    ];

    /** Format of the request as far as authentication is concerned: json, xml, atom or null */
    public static function format(Request $request): ?string
    {
        if (preg_match('/\.(json|xml|atom)$/', $request->getPathInfo(), $m)) {
            return $m[1];
        }
        // "?format=" is turned into the path suffix by FormatParameterSubscriber when the action has that
        // representation. What is left is still an API request for Redmine (api_request?): no session, no CSRF.
        $f = $request->query->get('format');

        return \in_array($f, ['json', 'xml'], true) ? $f : null;
    }

    /** ApplicationController#api_request? */
    public static function isApiRequest(Request $request): bool
    {
        return \in_array(self::format($request), ['json', 'xml'], true);
    }

    /** accept_atom_auth?: a GET request, answered as Atom, of an action that accepts the feeds key */
    public static function acceptsAtomAuth(Request $request): bool
    {
        return $request->isMethod('GET')
            && 'atom' === self::format($request)
            && self::listed($request, self::ATOM_ACTIONS, '_accept_atom_auth');
    }

    /** True when acceptsAtomAuth() and the request carries a feeds key (params[:key]) */
    public static function hasFeedsKey(Request $request): bool
    {
        $key = $request->query->get('key');

        return \is_string($key) && '' !== $key && self::acceptsAtomAuth($request);
    }

    /** accept_api_auth? */
    public static function acceptsApiAuth(Request $request): bool
    {
        return self::listed($request, self::API_ACTIONS, '_accept_api_auth');
    }

    /** API key given as parameter or header (ApplicationController#api_key_from_request) */
    public static function apiKey(Request $request): ?string
    {
        $key = $request->query->get('key') ?? $request->headers->get('X-Redmine-API-Key');

        return !\is_string($key) || '' === $key ? null : $key;
    }

    public static function hasBasicCredentials(Request $request): bool
    {
        return 0 === stripos((string) $request->headers->get('Authorization'), 'Basic ');
    }

    /**
     * @param array<string, list<string>> $actions
     */
    private static function listed(Request $request, array $actions, string $routeDefault): bool
    {
        if (true === $request->attributes->get($routeDefault)) {
            return true;
        }
        $controller = $request->attributes->get('_controller');
        if (!\is_string($controller) || !str_starts_with($controller, self::NS) || !str_contains($controller, '::')) {
            return false;
        }
        [$class, $method] = explode('::', substr($controller, \strlen(self::NS)), 2);

        return \in_array($method, $actions[$class] ?? [], true);
    }

    /**
     * @return array{atom: array<string, list<string>>, api: array<string, list<string>>} the declared actions (for tests)
     */
    public static function actions(): array
    {
        return ['atom' => self::ATOM_ACTIONS, 'api' => self::API_ACTIONS];
    }
}
