<?php

declare(strict_types=1);

namespace App\Service\Admin\Groups;

use App\Api\ApiRenderer;
use App\Entity\Group;
use App\Entity\User;
use App\I18n\Localizer;
use App\Security\ApiRequestMatcher;
use App\Service\MembershipService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The users of a group, as managed from its "Users" tab and the groups API (groups#new_users, #add_users,
 * #remove_users, #autocomplete_for_user): who can be added, adding, and removing after confirmation.
 */
final class GroupUsers
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MembershipService $memberships,
        private readonly Localizer $i18n,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** @return list<User> User.active.sorted.not_in_group(group).like(q) limited to 100 */
    public function candidates(Group $group, Request $request): array
    {
        $q = (string) $request->query->get('q', '');
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

    /**
     * Adds the users named by user_id / user_ids[] to the group.
     *
     * @return list<string> error messages: empty unless none of the given users can be added
     */
    public function add(Group $group, Request $request): array
    {
        $payload = ApiRequestMatcher::isApiRequest($request) ? ApiRenderer::payload($request) : array_merge($request->query->all(), $request->request->all());
        $ids = array_values(array_filter(array_map('intval', (array) ($payload['user_id'] ?? $payload['user_ids'] ?? []))));
        $users = $ids ? $this->em->getRepository(User::class)->findBy(['id' => $ids]) : [];
        $users = array_values(array_filter($users, static fn (User $u) => $u->isLogged() && !$group->users->contains($u)));
        if (!$group->isBuiltin()) {
            foreach ($users as $u) {
                $this->memberships->addUserToGroup($group, $u);
            }
        }

        return [] !== $users ? [] : [$this->i18n->l('label_user').' '.$this->i18n->l('activerecord.errors.messages.invalid')];
    }

    /**
     * The users of the group named by user_id / user_ids[] of the request: 404 when there is none.
     *
     * @return list<User>
     */
    public function requested(Group $group, Request $request): array
    {
        $all = array_merge($request->query->all(), ApiRequestMatcher::isApiRequest($request) ? ApiRenderer::payload($request) : $request->request->all());

        return $this->among($group, array_values(array_filter(array_map('intval', (array) ($all['user_id'] ?? $all['user_ids'] ?? [])))));
    }

    /**
     * The users of the group with these ids: 404 when there is none.
     *
     * @param list<int> $ids
     *
     * @return list<User>
     */
    public function among(Group $group, array $ids): array
    {
        $users = array_values(array_filter($group->users->toArray(), static fn (User $u) => \in_array($u->id, $ids, true)));
        if ([] === $users) {
            throw new NotFoundHttpException();
        }

        return $users;
    }

    /**
     * Removes the users from the group once "Yes" was typed on the confirmation page (the API needs no
     * confirmation); false when it was not.
     *
     * @param list<User> $users
     */
    public function remove(Group $group, array $users, Request $request): bool
    {
        if (!ApiRequestMatcher::isApiRequest($request) && (string) $request->request->get('confirm') !== $this->i18n->l('general_text_Yes')) {
            return false;
        }
        foreach ($users as $u) {
            $this->memberships->removeUserFromGroup($group, $u);
        }

        return true;
    }

    /**
     * Variables of the page asking to confirm the removal of users.
     *
     * @param list<User> $users
     *
     * @return array<string, mixed>
     */
    public function removalConfirmation(Request $request, Group $group, array $users): array
    {
        return [
            'group' => $group,
            'users' => $users,
            'back_url' => $request->request->get('back_url') ?? $request->query->get('back_url') ?? $this->tabUrl($request, $group),
            'admin_menu_selected' => 'groups',
            'page_title' => $this->i18n->l('label_confirmation'),
            'body_css' => 'controller-groups action-remove_users',
        ];
    }

    /** The "Users" tab of the group, on the page of the list the request came from (users_page, per_page) */
    public function tabUrl(Request $request, Group $group): string
    {
        $p = ['id' => $group->id, 'tab' => 'users'];
        foreach (['users_page', 'per_page'] as $k) {
            $v = $request->query->get($k) ?? $request->request->get($k);
            if (null !== $v && '' !== (string) $v) {
                $p[$k] = $v;
            }
        }

        return $this->urls->generate('group_edit', $p);
    }
}
