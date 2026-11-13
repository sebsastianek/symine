<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Api\ApiRenderer;
use App\Controller\AbstractController;
use App\Controller\IssueListController;
use App\Entity\AuthSource;
use App\Entity\Group;
use App\Entity\Issue;
use App\Entity\Journal;
use App\Entity\Principal;
use App\Entity\User;
use App\I18n\RailsTimeZones;
use App\Query\QueryColumn;
use App\Query\QueryError;
use App\Query\QueryFactory;
use App\Query\StatementInvalid;
use App\Query\UserQuery;
use App\Service\Admin\PrincipalApi;
use App\Service\Admin\UserManager;
use App\Service\CustomFieldService;
use App\Service\IssueService;
use App\Twig\UserQueryExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's UsersController */
final class UsersController extends AbstractController
{
    public function __construct(
        private readonly UserManager $users,
        private readonly PrincipalApi $api,
        private readonly ApiRenderer $renderer,
        private readonly CustomFieldService $customFields,
        private readonly IssueService $issues,
        private readonly QueryFactory $queries,
        private readonly UserQueryExtension $userQueryHelper,
        private readonly \App\Service\Activity\ActivityFetcher $activityFetcher,
    ) {
    }

    #[Route('/users.{_format}', name: 'users_index', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|csv|json|xml'])]
    public function index(Request $request, string $_format = 'html'): Response
    {
        $this->requireAdmin();
        $q = $request->query;
        // Legacy (Redmine < 6) "status=" meant all users; keep it working as "status=*" (any status)
        if (!$q->has('f') && !$q->has('fields') && $q->has('status') && \is_string($q->get('status')) && '' === trim((string) $q->get('status'))) {
            $q->set('status', '*');
        }
        // Legacy list links (/users?status=3, /users?name=x): build the query from these params instead of the session one
        if (!$q->has('set_filter') && !$q->has('query_id') && !$q->has('f') && ($q->has('status') || $q->has('name'))) {
            $q->set('set_filter', '1');
        }
        $isApi = $this->isApi($request);
        $query = $this->retrieveUserQuery($request, 'csv' !== $_format);

        // API backwards compatibility: handle legacy filter parameters
        if ('html' !== $_format) {
            if (\is_string($name = $q->get('name')) && '' !== trim($name)) {
                $query->addFilter('name', '~', [$name]);
            }
            if (\is_scalar($groupId = $q->get('group_id')) && '' !== trim((string) $groupId)) {
                $query->addFilter('is_member_of_group', '=', [(string) $groupId]);
            }
        }

        if (!$query->isValid()) {
            if ($isApi) {
                return $this->renderer->errors($request, $query->errors);
            }
            if ('csv' === $_format) {
                return new Response('', 422);
            }

            return $this->renderIndex($request, $query, [], null);
        }
        try {
            $count = $query->userCount();
            if ('csv' === $_format) {
                return $this->csv($request, $query, $query->results());
            }
            $pager = $this->paginate($request, $count);
            $users = $query->results($pager['offset'], $pager['limit']);
        } catch (StatementInvalid) {
            return $this->render('common/error.html.twig', ['message' => $this->l('error_query_statement_invalid'), 'status' => 500], new Response('', 500));
        }

        if ($isApi) {
            $includes = $this->includes($request);

            return $this->renderer->render($request, [
                'users' => array_map(fn (User $u) => $this->api->userListItem($u, $includes), $users),
                'total_count' => $count, 'offset' => $pager['offset'], 'limit' => $pager['limit'],
            ]);
        }

        return $this->renderIndex($request, $query, $users, $pager);
    }

    private function retrieveUserQuery(Request $request, bool $useSession): UserQuery
    {
        try {
            $query = $this->queries->retrieve($request, 'UserQuery', null, $useSession);
        } catch (QueryError) {
            $query = $this->queries->create('UserQuery');
        }
        \assert($query instanceof UserQuery);

        return $query;
    }

    /** @param list<User> $users */
    private function renderIndex(Request $request, UserQuery $query, array $users, ?array $pager): Response
    {
        return $this->render('admin/users/index.html.twig', [
            'query' => $query,
            'users' => $users,
            'pager' => $pager,
            'clear_url' => $this->generateUrl('users_index', ['set_filter' => 1, 'sort' => '']),
            'new_query_url' => $this->generateUrl('query_new'),
            'can_save_queries' => $this->auth->allowedTo('save_queries', null, null, true),
            'extra_query_params' => [],
            'can_import' => $this->auth->allowedTo('import_users', null, null, true),
            'admin_menu_selected' => 'users',
            'page_title' => $this->l('label_user_plural'),
            'body_css' => 'controller-users action-index',
        ]);
    }

    /**
     * QueriesHelper#query_to_csv
     *
     * @param list<User> $users
     */
    private function csv(Request $request, UserQuery $query, array $users): Response
    {
        $columns = $query->columns();
        $sep = (string) ($request->query->get('field_separator') ?: $this->l('general_csv_separator'));
        $encoding = (string) ($request->query->get('encoding') ?: $this->l('general_csv_encoding'));
        $rows = [array_map(static fn (QueryColumn $c) => $c->caption, $columns)];
        foreach ($users as $u) {
            $rows[] = array_map(fn (QueryColumn $c) => $this->userQueryHelper->csvValue($query, $c, $u), $columns);
        }
        $csv = IssueListController::csvString($rows, $sep);
        if (0 === strcasecmp($encoding, 'UTF-8')) {
            $csv = "\xEF\xBB\xBF".$csv;
        } else {
            $converted = @mb_convert_encoding($csv, $encoding, 'UTF-8');
            $csv = false === $converted ? $csv : $converted;
        }
        // filename_for_export(@query, 'users')
        $name = (string) ($request->query->get('query_name') ?: $query->name());
        if ('_' === $name || '' === trim($name)) {
            $name = 'users';
        }
        $title = trim((string) preg_replace('/[\s,.\/\?\;\|\:]+/u', '_', $name));
        $title = (string) preg_replace('/[^\p{L}\p{N}_\-]/u', '', $title);
        $filename = mb_strtolower($title).'.csv';

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; header=present',
            'Content-Disposition' => 'attachment; filename="'.addcslashes($filename, '"\\').'"; filename*=UTF-8\'\''.rawurlencode($filename),
        ]);
    }

    #[Route('/users/new', name: 'user_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->requireAdmin();
        $user = $this->users->newUser();
        $attrs = (array) $request->query->all('user');

        return $this->renderForm('admin/users/new.html.twig', $user, [
            'generate_password' => UserManager::bool($attrs['generate_password'] ?? false),
        ], $request);
    }

    #[Route('/users.{_format}', name: 'user_create', methods: ['POST'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'])]
    public function create(Request $request): Response
    {
        $this->requireAdmin();
        $api = $this->isApi($request);
        $payload = $api ? ApiRenderer::payload($request) : $request->request->all();
        $attrs = \is_array($payload['user'] ?? null) ? $payload['user'] : [];
        $pref = \is_array($payload['pref'] ?? null) ? $payload['pref'] : null;
        $user = $this->users->newUser();
        unset($attrs['group_ids']);
        if (!$api && !\array_key_exists('password_confirmation', $attrs)) {
            $attrs['password_confirmation'] = '';
        }
        [$errors, $password] = $this->users->save($user, $attrs, $pref);
        if ([] === $errors) {
            if (!empty($payload['send_information'])) {
                $this->users->deliverAccountInformation($user, $password);
            }
            if ($api) {
                $response = $this->renderer->render($request, ['user' => $this->api->user($user)], 201);
                $response->headers->set('Location', $this->generateUrl('user_show', ['id' => $user->id], 0));

                return $response;
            }
            $this->addFlash('notice', $this->l('notice_user_successful_create', ['id' => $user->login]));
            if ($request->request->has('continue')) {
                return $this->redirectToRoute('user_new', ['user' => ['generate_password' => !empty($attrs['generate_password']) ? '1' : '']]);
            }

            return $this->redirectToRoute('user_edit', ['id' => $user->id]);
        }
        if ($api) {
            return $this->renderer->errors($request, $errors);
        }

        return $this->renderForm('admin/users/new.html.twig', $user, [
            'errors' => $errors,
            'generate_password' => UserManager::bool($attrs['generate_password'] ?? false),
            'send_information' => !empty($payload['send_information']),
            'posted_cf' => $this->users->customFieldParams($attrs),
        ], $request);
    }

    #[Route('/users/current.{_format}', name: 'users_current', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'])]
    public function current(Request $request): Response
    {
        $this->requireLogin();

        return $this->showUser($request, $this->user());
    }

    #[Route('/users/{id}.{_format}', name: 'user_show', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function show(Request $request, int $id): Response
    {
        $user = $this->em->find(Principal::class, $id);
        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }

        return $this->showUser($request, $user);
    }

    private function showUser(Request $request, User $user): Response
    {
        if (!$this->users->isVisible($user)) {
            throw $this->createNotFoundException();
        }
        $memberships = $this->api->visibleMemberships($user);
        if ($this->isApi($request)) {
            return $this->renderer->render($request, ['user' => $this->api->user($user, $this->includes($request))]);
        }
        $viewer = $this->user();
        $cond = $this->issues->visibleCondition($viewer, 'i');
        $assignees = array_merge([$user->id], $user->getGroupIds());
        $count = fn (string $where, array $params) => (int) $this->em->createQuery("SELECT COUNT(i.id) FROM App\\Entity\\Issue i JOIN i.status s WHERE $cond AND $where")->setParameters($params)->getSingleScalarResult();
        $issueCounts = [
            'assigned' => [
                'total' => $count('IDENTITY(i.assignedTo) IN (:ids)', ['ids' => $assignees]),
                'open' => $count('IDENTITY(i.assignedTo) IN (:ids) AND s.isClosed = false', ['ids' => $assignees]),
            ],
            'reported' => [
                'total' => $count('i.author = :u', ['u' => $user->id]),
                'open' => $count('i.author = :u AND s.isClosed = false', ['u' => $user->id]),
            ],
        ];
        $values = $this->customFields->values('Principal', $user->id);
        $cfs = [];
        foreach ($this->users->visibleCustomFields($this->users->userCustomFields(), $viewer) as $f) {
            $v = $values[$f->id] ?? null;
            if (null !== $v && '' !== $v && [] !== $v) {
                $cfs[] = ['field' => $f, 'value' => $this->customFields->formatValue($f, $v)];
            }
        }
        $emails = [];
        if ($user->getMail()) {
            $emails[] = $user->getMail();
        }
        $extra = $user->emailAddresses->toArray();
        usort($extra, static fn ($a, $b) => $a->id <=> $b->id);
        foreach ($extra as $ea) {
            if (!$ea->isDefault) {
                $emails[] = $ea->address;
            }
        }

        return $this->render('admin/users/show.html.twig', [
            'edited_user' => $user,
            'memberships' => $memberships,
            'issue_counts' => $issueCounts,
            'assigned_to_ids' => implode('|', $assignees),
            'custom_values' => $cfs,
            'emails' => $emails,
            'event_days' => $this->events($user, $viewer),
            'atom_key' => $viewer->isLogged() ? ($this->em->getConnection()->fetchOne("SELECT value FROM tokens WHERE user_id = ? AND action = 'feeds'", [$viewer->id]) ?: null) : null,
            'page_title' => $user->getName(),
            'body_css' => 'controller-users action-show',
        ]);
    }

    /**
     * UsersController#show: Redmine::Activity::Fetcher.new(User.current, :author => @user).events(nil, nil, :limit => 10)
     * grouped by day (activities/_activities).
     *
     * @return list<array<string, mixed>>
     */
    private function events(User $user, User $viewer): array
    {
        $events = $this->activityFetcher->events($viewer, $this->activityFetcher->eventTypes($viewer, null), null, false, $user, null, null, 10);

        return \App\Service\Activity\ActivityFetcher::eventDays($events, $this->i18n->userTimezone(), $this->i18n->today(), $this->settings->array('non_working_week_days'));
    }

    #[Route('/users/{id}/edit', name: 'user_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $user = $this->findUser($id);

        return $this->renderEdit($request, $user);
    }

    #[Route('/users/{id}.{_format}', name: 'user_update', methods: ['PUT', 'PATCH'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function update(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $user = $this->findUser($id);
        $api = $this->isApi($request);
        $payload = $api ? ApiRenderer::payload($request) : array_merge($request->query->all(), $request->request->all());
        $attrs = \is_array($payload['user'] ?? null) ? $payload['user'] : [];
        $pref = \is_array($payload['pref'] ?? null) ? $payload['pref'] : null;
        $wasStatus = $user->status;
        if (\array_key_exists('password', $attrs) && '' === (string) $attrs['password']) {
            unset($attrs['password'], $attrs['password_confirmation']);
        }
        [$errors, $password] = $this->users->save($user, $attrs, $pref);
        if ([] === $errors) {
            $wasActivated = Principal::STATUS_REGISTERED === $wasStatus && Principal::STATUS_ACTIVE === $user->status;
            if (!$wasActivated && $user->isActive() && !empty($payload['send_information']) && $user->id !== $this->user()->id) {
                $this->users->deliverAccountInformation($user, $password);
            }
            if ($api) {
                return $this->renderer->noContent();
            }
            $this->flashNotice();
            $referer = (string) $request->headers->get('referer');
            if ('' !== $referer && parse_url($referer, \PHP_URL_HOST) === $request->getHost()) {
                return $this->redirect($referer);
            }

            return $this->redirectToRoute('user_edit', ['id' => $user->id]);
        }
        if ($api) {
            return $this->renderer->errors($request, $errors);
        }

        return $this->renderEdit($request, $user, ['errors' => $errors, 'posted_cf' => $this->users->customFieldParams($attrs)]);
    }

    #[Route('/users/{id}.{_format}', name: 'user_destroy', methods: ['DELETE'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function destroy(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $user = $this->findUser($id);
        $api = $this->isApi($request);
        if ($user->id === $this->user()->id && !$this->ownAccountDeletable($user)) {
            if ($api) {
                return $this->renderer->errors($request, [], 422);
            }

            return new Response($this->renderView('common/error.html.twig', ['status' => 422, 'message' => $this->l('notice_not_authorized')]), 422);
        }
        if ($api || $request->request->has('lock') || (string) $request->request->get('confirm') === $user->login) {
            if ($request->request->has('lock')) {
                $user->status = Principal::STATUS_LOCKED;
                $this->em->flush();
                $this->users->deleteLoginTokens($user);
                $this->flashNotice();
            } else {
                $this->users->destroyUser($user);
                if (!$api) {
                    $this->flashNotice('notice_successful_delete');
                }
            }
            if ($api) {
                return $this->renderer->noContent();
            }

            return $this->redirectBackOr($request, $this->generateUrl('users_index'));
        }

        return $this->render('admin/users/destroy.html.twig', [
            'edited_user' => $user,
            'admin_menu_selected' => 'users',
            'page_title' => $this->l('label_confirmation'),
            'body_css' => 'controller-users action-destroy',
        ]);
    }

    #[Route('/users/bulk_lock', name: 'users_bulk_lock', methods: ['POST'])]
    public function bulkLock(Request $request): Response
    {
        return $this->bulkUpdateStatus($request, Principal::STATUS_LOCKED);
    }

    #[Route('/users/bulk_unlock', name: 'users_bulk_unlock', methods: ['POST'])]
    public function bulkUnlock(Request $request): Response
    {
        return $this->bulkUpdateStatus($request, Principal::STATUS_ACTIVE);
    }

    /** UsersController#bulk_destroy (confirmation page until "Yes" is typed) */
    #[Route('/users/bulk_destroy', name: 'users_bulk_destroy', methods: ['DELETE'])]
    public function bulkDestroy(Request $request): Response
    {
        $this->requireAdmin();
        $params = array_merge($request->query->all(), $request->request->all());
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($params['ids'] ?? [])))));
        $users = $ids ? $this->em->createQuery('SELECT u FROM App\Entity\User u WHERE u.id IN (:ids) AND u.id <> :me AND u.status <> 0 ORDER BY u.id')
            ->setParameters(['ids' => $ids, 'me' => $this->user()->id])->getResult() : [];
        if ([] === $users) {
            throw $this->createNotFoundException();
        }
        if ((string) ($params['confirm'] ?? '') === $this->l('general_text_Yes')) {
            foreach ($users as $u) {
                $this->users->destroyUser($u);
            }
            $this->flashNotice('notice_successful_delete');

            return $this->redirectToRoute('users_index');
        }

        return $this->render('admin/users/bulk_destroy.html.twig', [
            'users' => $users,
            'ids' => array_map(static fn (User $u) => (int) $u->id, $users),
            'admin_menu_selected' => 'users',
            'page_title' => $this->l('label_confirmation'),
            'body_css' => 'controller-users action-bulk_destroy',
        ]);
    }

    private function bulkUpdateStatus(Request $request, int $status): Response
    {
        $this->requireAdmin();
        $ids = array_values(array_filter(array_map('intval', (array) ($request->request->all()['ids'] ?? $request->query->all()['ids'] ?? []))));
        $users = $ids ? $this->em->createQuery('SELECT u FROM App\Entity\User u WHERE u.id IN (:ids) AND u.id <> :me AND u.status <> 0')
            ->setParameters(['ids' => $ids, 'me' => $this->user()->id])->getResult() : [];
        if ([] === $users) {
            throw $this->createNotFoundException();
        }
        foreach ($users as $u) {
            $u->status = $status;
            if (Principal::STATUS_ACTIVE !== $status) {
                $this->users->deleteLoginTokens($u);
            }
        }
        $this->em->flush();
        $this->flashNotice();

        return $this->redirectToRoute('users_index');
    }

    // --- helpers ----------------------------------------------------------------

    private function findUser(int $id): User
    {
        $user = $this->em->find(Principal::class, $id);
        if (!$user instanceof User || !$user->isLogged()) {
            throw $this->createNotFoundException();
        }

        return $user;
    }

    private function ownAccountDeletable(User $user): bool
    {
        if (!$this->settings->bool('unsubscribe')) {
            return false;
        }
        if (!$user->admin) {
            return true;
        }

        return (int) $this->em->createQuery('SELECT COUNT(u.id) FROM App\Entity\User u WHERE u.admin = true AND u.status = 1 AND u.id <> :id')->setParameter('id', $user->id)->getSingleScalarResult() > 0;
    }

    /** @return list<string> */
    private function includes(Request $request): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $request->query->get('include', '')))));
    }

    private function renderEdit(Request $request, User $user, array $extra = []): Response
    {
        $tabs = [['name' => 'general', 'label' => 'label_general', 'template' => 'admin/users/_general.html.twig']];
        $groups = $this->users->givableGroups();
        if ([] !== $groups) {
            $tabs[] = ['name' => 'groups', 'label' => 'label_group_plural', 'template' => 'admin/users/_groups.html.twig'];
        }
        $tabs[] = ['name' => 'memberships', 'label' => 'label_project_plural', 'template' => 'admin/principal_memberships/_index.html.twig'];

        return $this->renderForm('admin/users/edit.html.twig', $user, $extra + [
            'tabs' => $tabs,
            'groups' => $groups,
            'principal' => $user,
            'principal_memberships' => $this->sortedMemberships($user),
        ], $request);
    }

    /** @return list<\App\Entity\Member> */
    private function sortedMemberships(Principal $p): array
    {
        return $this->em->createQuery('SELECT m, pr FROM App\Entity\Member m JOIN m.project pr WHERE m.principal = :p AND pr.status <> 9 ORDER BY pr.lft, pr.name')
            ->setParameter('p', $p->id)->getResult();
    }

    private function renderForm(string $template, User $user, array $extra, Request $request): Response
    {
        $isNew = null === $user->id;
        $projects = [];
        if (!$isNew) {
            foreach ($this->sortedMemberships($user) as $m) {
                $projects[] = $m->project;
            }
        }
        $fields = $this->users->userCustomFields();
        $values = $isNew ? $this->customFields->defaultValues($fields) : $this->customFields->values('Principal', $user->id);
        foreach ($extra['posted_cf'] ?? [] as $k => $v) {
            $values[$k] = $v;
        }
        $highPriority = null;
        $default = $this->em->createQuery('SELECT p FROM App\Entity\IssuePriority p WHERE p.isDefault = true AND p.project IS NULL')->setMaxResults(1)->getOneOrNullResult();
        $all = $this->em->createQuery('SELECT p FROM App\Entity\IssuePriority p WHERE p.project IS NULL ORDER BY p.position')->getResult();
        if (null === $default && [] !== $all) {
            $default = $all[intdiv(\count($all) - 1, 2)];
        }
        if (null !== $default) {
            foreach ($all as $p) {
                if ($p->position > $default->position) {
                    $highPriority = $p;
                    break;
                }
            }
        }
        $issueQueries = $this->em->createQuery("SELECT q FROM App\\Entity\\IssueQuery q WHERE q.project IS NULL ORDER BY q.name")->getResult();
        $projectQueries = $this->em->createQuery('SELECT q FROM App\Entity\ProjectQuery q ORDER BY q.name')->getResult();

        $tzOptions = [];
        $now = new \DateTimeImmutable();
        foreach (RailsTimeZones::MAP as $name => $iana) {
            $offset = (new \DateTimeZone($iana))->getOffset($now);
            $tzOptions[] = ['offset' => $offset, 'value' => $name, 'label' => sprintf('(GMT%s%02d:%02d) %s', $offset < 0 ? '-' : '+', intdiv(abs($offset), 3600), intdiv(abs($offset) % 3600, 60), $name)];
        }
        usort($tzOptions, static fn ($a, $b) => [$a['offset'], $a['value']] <=> [$b['offset'], $b['value']]);

        return $this->render($template, $extra + [
            'edited_user' => $user,
            'pref' => $user->getPref(),
            'auth_sources' => $this->em->getRepository(AuthSource::class)->findAll(),
            'languages' => $this->i18n->languageOptions(),
            'custom_fields' => $fields,
            'custom_values' => $values,
            'cf_options' => array_combine(array_map(static fn ($f) => $f->id, $fields), array_map(fn ($f) => $this->customFields->possibleValuesOptions($f), $fields)) ?: [],
            'mail_notification_options' => $this->users->mailNotificationOptions($user),
            'notified_project_ids' => $isNew ? [] : $this->users->notifiedProjectIds($user),
            'user_projects' => $projects,
            'high_priority' => $highPriority,
            'auto_watch_on_options' => UserManager::AUTO_WATCH_ON_OPTIONS,
            'toolbar_language_options' => $user->getPref()->get('toolbar_language_options') ?: UserManager::DEFAULT_TOOLBAR_LANGUAGE_OPTIONS,
            'time_zones' => $tzOptions,
            'issue_queries' => $issueQueries,
            'project_queries' => $projectQueries,
            'admin_menu_selected' => 'users',
            'page_title' => $isNew ? $this->l('label_user_new') : $user->login,
            'body_css' => 'controller-users action-'.($isNew ? 'new' : 'edit'),
            'errors' => $extra['errors'] ?? [],
            'generate_password' => $extra['generate_password'] ?? false,
            'send_information' => $extra['send_information'] ?? false,
            'is_new' => $isNew,
            'group_ids' => $user->getGroupIds(),
        ]);
    }
}
