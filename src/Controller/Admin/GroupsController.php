<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Api\ApiRenderer;
use App\Controller\AbstractController;
use App\Entity\Group;
use App\Entity\Principal;
use App\Entity\User;
use App\Service\Admin\PrincipalApi;
use App\Service\Admin\UserManager;
use App\Service\CustomFieldService;
use App\Service\MembershipService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's GroupsController */
final class GroupsController extends AbstractController
{
    public function __construct(
        private readonly UserManager $users,
        private readonly PrincipalApi $api,
        private readonly ApiRenderer $renderer,
        private readonly CustomFieldService $customFields,
        private readonly MembershipService $memberships,
    ) {
    }

    #[Route('/groups.{_format}', name: 'groups_index', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'])]
    public function index(Request $request): Response
    {
        $this->requireAdmin();
        $groups = $this->users->allGroups();
        if ($this->isApi($request)) {
            if ('1' !== $request->query->get('builtin')) {
                $groups = array_values(array_filter($groups, static fn (Group $g) => !$g->isBuiltin()));
            }

            return $this->renderer->render($request, ['groups' => array_map(fn (Group $g) => $this->api->groupListItem($g), $groups)]);
        }
        $name = trim((string) $request->query->get('name', ''));
        if ('' !== $name) {
            $n = mb_strtolower($name);
            $groups = array_values(array_filter($groups, static fn (Group $g) => str_contains(mb_strtolower($g->getName()), $n)));
        }
        $pager = $this->paginate($request, \count($groups));
        $counts = [];
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT group_id, COUNT(*) AS c FROM groups_users GROUP BY group_id') as $r) {
            $counts[(int) $r['group_id']] = (int) $r['c'];
        }

        return $this->render('admin/groups/index.html.twig', [
            'groups' => \array_slice($groups, $pager['offset'], $pager['limit']),
            'pager' => $pager,
            'name' => $name,
            'user_counts' => $counts,
            'admin_menu_selected' => 'groups',
            'page_title' => $this->l('label_group_plural'),
            'body_css' => 'controller-groups action-index',
        ]);
    }

    #[Route('/groups/new', name: 'group_new', methods: ['GET'])]
    public function new(): Response
    {
        $this->requireAdmin();

        return $this->renderForm('admin/groups/new.html.twig', new Group());
    }

    #[Route('/groups.{_format}', name: 'group_create', methods: ['POST'], defaults: ['_format' => 'html'], requirements: ['_format' => 'html|json|xml'])]
    public function create(Request $request): Response
    {
        $this->requireAdmin();
        $api = $this->isApi($request);
        $payload = $api ? ApiRenderer::payload($request) : $request->request->all();
        $attrs = \is_array($payload['group'] ?? null) ? $payload['group'] : [];
        $group = new Group();
        $errors = $this->users->saveGroup($group, $attrs);
        if ([] === $errors) {
            if ($api) {
                $response = $this->renderer->render($request, ['group' => $this->api->group($group)], 201);
                $response->headers->set('Location', $this->generateUrl('group_show', ['id' => $group->id], 0));

                return $response;
            }
            $this->flashNotice('notice_successful_create');

            return $this->redirectToRoute($request->request->has('continue') ? 'group_new' : 'groups_index');
        }
        if ($api) {
            return $this->renderer->errors($request, $errors);
        }

        return $this->renderForm('admin/groups/new.html.twig', $group, ['errors' => $errors, 'posted_cf' => $this->users->customFieldParams($attrs)]);
    }

    #[Route('/groups/{id}.{_format}', name: 'group_show', methods: ['GET'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function show(Request $request, int $id): Response
    {
        $group = $this->findGroup($id);
        if ($this->isApi($request)) {
            $includes = array_values(array_filter(array_map('trim', explode(',', (string) $request->query->get('include', '')))));

            return $this->renderer->render($request, ['group' => $this->api->group($group, $includes)]);
        }
        $values = $this->customFields->values('Principal', $group->id);
        $cfs = [];
        foreach ($this->users->visibleCustomFields($this->users->groupCustomFields()) as $f) {
            $cfs[] = ['field' => $f, 'value' => $this->customFields->formatValue($f, $values[$f->id] ?? null)];
        }
        $users = array_values(array_filter($group->users->toArray(), fn (User $u) => $this->users->isVisible($u)));

        return $this->render('admin/groups/show.html.twig', [
            'group' => $group,
            'custom_values' => $cfs,
            'users' => $users,
            'page_title' => $group->getName(),
            'body_css' => 'controller-groups action-show',
        ]);
    }

    #[Route('/groups/{id}/edit', name: 'group_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        $this->requireAdmin();

        return $this->renderEdit($request, $this->findGroup($id));
    }

    #[Route('/groups/{id}.{_format}', name: 'group_update', methods: ['PUT', 'PATCH'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function update(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $group = $this->findGroup($id);
        $api = $this->isApi($request);
        $payload = $api ? ApiRenderer::payload($request) : $request->request->all();
        $attrs = \is_array($payload['group'] ?? null) ? $payload['group'] : [];
        $errors = $this->users->saveGroup($group, $attrs);
        if ([] === $errors) {
            if ($api) {
                return $this->renderer->noContent();
            }
            $this->flashNotice();
            $referer = (string) $request->headers->get('referer');
            if ('' !== $referer && parse_url($referer, \PHP_URL_HOST) === $request->getHost()) {
                return $this->redirect($referer);
            }

            return $this->redirectToRoute('groups_index');
        }
        if ($api) {
            return $this->renderer->errors($request, $errors);
        }

        return $this->renderEdit($request, $group, ['errors' => $errors, 'posted_cf' => $this->users->customFieldParams($attrs)]);
    }

    #[Route('/groups/{id}.{_format}', name: 'group_destroy', methods: ['DELETE'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function destroy(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $group = $this->findGroup($id);
        if ($group->isBuiltin()) {
            // builtin groups cannot be deleted
            if ($this->isApi($request)) {
                return $this->renderer->errors($request, [$this->l('notice_not_authorized')], 422);
            }
            throw $this->createAccessDeniedException();
        }
        $this->users->destroyGroup($group);
        if ($this->isApi($request)) {
            return $this->renderer->noContent();
        }
        $referer = (string) $request->headers->get('referer');
        if ('' !== $referer && parse_url($referer, \PHP_URL_HOST) === $request->getHost() && !str_contains($referer, '/groups/'.$id)) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('groups_index');
    }

    #[Route('/groups/{id}/users/new', name: 'group_users_new', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function newUsers(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $group = $this->findGroup($id);

        return $this->render('admin/groups/new_users.html.twig', [
            'group' => $group,
            'principals' => $this->usersNotInGroup($group, (string) $request->query->get('q', '')),
            'admin_menu_selected' => 'groups',
            'page_title' => $this->l('label_user_new'),
            'body_css' => 'controller-groups action-new_users',
        ]);
    }

    #[Route('/groups/{id}/autocomplete_for_user', name: 'group_autocomplete_for_user', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function autocompleteForUser(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $group = $this->findGroup($id);

        return $this->render('admin/groups/_principals.html.twig', [
            'principals' => $this->usersNotInGroup($group, (string) $request->query->get('q', '')),
        ]);
    }

    #[Route('/groups/{id}/users.{_format}', name: 'group_users_add', methods: ['POST'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function addUsers(Request $request, int $id): Response
    {
        $this->requireAdmin();
        $group = $this->findGroup($id);
        $api = $this->isApi($request);
        $payload = $api ? ApiRenderer::payload($request) : array_merge($request->query->all(), $request->request->all());
        $ids = array_values(array_filter(array_map('intval', (array) ($payload['user_id'] ?? $payload['user_ids'] ?? []))));
        $users = $ids ? $this->em->getRepository(User::class)->findBy(['id' => $ids]) : [];
        $users = array_values(array_filter($users, static fn (User $u) => $u->isLogged() && !$group->users->contains($u)));
        if (!$group->isBuiltin()) {
            foreach ($users as $u) {
                $this->memberships->addUserToGroup($group, $u);
            }
        }
        if ($api) {
            if ([] !== $users) {
                return $this->renderer->noContent();
            }

            return $this->renderer->errors($request, [$this->l('label_user').' '.$this->l('activerecord.errors.messages.invalid')]);
        }
        $this->flashNotice();

        return $this->redirectBackOr($request, $this->generateUrl('group_edit', $this->usersTabParams($request, $group)));
    }

    #[Route('/groups/{id}/users.{_format}', name: 'group_users_remove', methods: ['DELETE'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', '_format' => 'html|json|xml'])]
    public function removeUsers(Request $request, int $id): Response
    {
        return $this->doRemoveUsers($request, $this->findGroupAsAdmin($id), $this->idsFrom($request));
    }

    #[Route('/groups/{id}/users/{user_id}.{_format}', name: 'group_user_remove', methods: ['DELETE'], defaults: ['_format' => 'html'], requirements: ['id' => '\d+', 'user_id' => '\d+', '_format' => 'html|json|xml'])]
    public function removeUser(Request $request, int $id, int $user_id): Response
    {
        return $this->doRemoveUsers($request, $this->findGroupAsAdmin($id), [$user_id]);
    }

    private function doRemoveUsers(Request $request, Group $group, array $ids): Response
    {
        $users = array_values(array_filter($group->users->toArray(), static fn (User $u) => \in_array($u->id, $ids, true)));
        if ([] === $users) {
            throw $this->createNotFoundException();
        }
        $api = $this->isApi($request);
        if ($api || (string) $request->request->get('confirm') === $this->l('general_text_Yes')) {
            foreach ($users as $u) {
                $this->memberships->removeUserFromGroup($group, $u);
            }
            if ($api) {
                return $this->renderer->noContent();
            }
            $this->flashNotice('notice_successful_delete');

            return $this->redirectBackOr($request, $this->generateUrl('group_edit', $this->usersTabParams($request, $group)));
        }

        return $this->render('admin/groups/remove_users.html.twig', [
            'group' => $group,
            'users' => $users,
            'back_url' => $request->request->get('back_url') ?? $request->query->get('back_url') ?? $this->generateUrl('group_edit', $this->usersTabParams($request, $group)),
            'admin_menu_selected' => 'groups',
            'page_title' => $this->l('label_confirmation'),
            'body_css' => 'controller-groups action-remove_users',
        ]);
    }

    /** @return list<int> */
    private function idsFrom(Request $request): array
    {
        $all = array_merge($request->query->all(), $this->isApi($request) ? ApiRenderer::payload($request) : $request->request->all());

        return array_values(array_filter(array_map('intval', (array) ($all['user_id'] ?? $all['user_ids'] ?? []))));
    }

    private function usersTabParams(Request $request, Group $group): array
    {
        $p = ['id' => $group->id, 'tab' => 'users'];
        foreach (['users_page', 'per_page'] as $k) {
            $v = $request->query->get($k) ?? $request->request->get($k);
            if (null !== $v && '' !== (string) $v) {
                $p[$k] = $v;
            }
        }

        return $p;
    }

    /** @return list<User> User.active.sorted.not_in_group(group).like(q) limited to 100 */
    private function usersNotInGroup(Group $group, string $q): array
    {
        $qb = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')
            ->where('u.status = 1')->andWhere('u NOT INSTANCE OF App\Entity\AnonymousUser')
            ->andWhere(':g NOT MEMBER OF u.groups')->setParameter('g', $group->id)
            ->orderBy('u.firstname')->addOrderBy('u.lastname')->addOrderBy('u.id')->setMaxResults(100);
        if ('' !== trim($q)) {
            $qb->andWhere('LOWER(u.login) LIKE :q OR LOWER(u.firstname) LIKE :q OR LOWER(u.lastname) LIKE :q OR LOWER(CONCAT(u.firstname, \' \', u.lastname)) LIKE :q OR u.id IN (SELECT IDENTITY(e.user) FROM App\Entity\EmailAddress e WHERE LOWER(e.address) LIKE :q)')
                ->setParameter('q', '%'.mb_strtolower(trim($q)).'%');
        }

        return $qb->getQuery()->getResult();
    }

    private function findGroup(int $id): Group
    {
        $g = $this->em->find(Principal::class, $id);
        if (!$g instanceof Group) {
            throw $this->createNotFoundException();
        }

        return $g;
    }

    private function findGroupAsAdmin(int $id): Group
    {
        $this->requireAdmin();

        return $this->findGroup($id);
    }

    private function renderEdit(Request $request, Group $group, array $extra = []): Response
    {
        $tabs = [['name' => 'general', 'label' => 'label_general', 'template' => 'admin/groups/_general.html.twig']];
        if (!$group->isBuiltin()) {
            $tabs[] = ['name' => 'users', 'label' => 'label_user_plural', 'template' => 'admin/groups/_users.html.twig'];
        }
        $tabs[] = ['name' => 'memberships', 'label' => 'label_project_plural', 'template' => 'admin/principal_memberships/_index.html.twig'];

        $users = $group->users->toArray();
        usort($users, static fn (User $a, User $b) => [$a->firstname, $a->lastname, $a->id] <=> [$b->firstname, $b->lastname, $b->id]);
        $perPage = $this->settings->perPageOptions();
        $limit = \in_array((int) $request->query->get('per_page'), $perPage, true) ? (int) $request->query->get('per_page') : $perPage[0];
        $pages = max(1, (int) ceil(\count($users) / $limit));
        $page = max(1, min($pages, (int) $request->query->get('users_page', 1)));
        $usersPager = ['offset' => ($page - 1) * $limit, 'limit' => $limit, 'page' => $page, 'count' => \count($users), 'pages' => $pages, 'per_page_options' => $perPage];

        $memberships = $this->em->createQuery('SELECT m, pr FROM App\Entity\Member m JOIN m.project pr WHERE m.principal = :p AND pr.status <> 9 ORDER BY pr.lft, pr.name')
            ->setParameter('p', $group->id)->getResult();

        return $this->renderForm('admin/groups/edit.html.twig', $group, $extra + [
            'tabs' => $tabs,
            'group_users' => \array_slice($users, $usersPager['offset'], $limit),
            'users_pager' => $usersPager,
            'principal' => $group,
            'principal_memberships' => $memberships,
        ]);
    }

    private function renderForm(string $template, Group $group, array $extra = []): Response
    {
        $fields = $group->isBuiltin() ? [] : $this->users->groupCustomFields();
        $values = null === $group->id ? $this->customFields->defaultValues($fields) : $this->customFields->values('Principal', $group->id);
        foreach ($extra['posted_cf'] ?? [] as $k => $v) {
            $values[$k] = $v;
        }

        return $this->render($template, $extra + [
            'group' => $group,
            'custom_fields' => $fields,
            'custom_values' => $values,
            'cf_options' => array_combine(array_map(static fn ($f) => $f->id, $fields), array_map(fn ($f) => $this->customFields->possibleValuesOptions($f), $fields)) ?: [],
            'errors' => $extra['errors'] ?? [],
            'admin_menu_selected' => 'groups',
            'page_title' => null === $group->id ? $this->l('label_group_new') : $group->getName(),
            'body_css' => 'controller-groups action-'.(null === $group->id ? 'new' : 'edit'),
        ]);
    }
}
