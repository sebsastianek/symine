<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AbstractController;
use App\Entity\Group;
use App\Entity\User;
use App\Service\Admin\UserManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Port of Redmine's ContextMenus::UsersController (context menu of the admin user list). */
final class UsersContextMenuController extends AbstractController
{
    public function __construct(private readonly UserManager $users)
    {
    }

    #[Route('/users/context_menu', name: 'users_context_menu', methods: ['GET', 'POST'], priority: 10)]
    public function index(Request $request): Response
    {
        $this->requireAdmin();
        $params = array_merge($request->query->all(), $request->request->all());
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($params['id'] ?? $params['ids'] ?? [])))));
        $users = [] !== $ids ? $this->em->createQuery('SELECT u FROM App\Entity\User u WHERE u.id IN (:ids) ORDER BY u.id')->setParameter('ids', $ids)->getResult() : [];
        if ([] === $users) {
            throw $this->createNotFoundException();
        }
        /** @var list<Group> $groups */
        $groups = $this->users->givableGroups();
        $userIds = array_map(static fn (User $u) => (int) $u->id, $users);
        $commonGroupIds = [];
        if ([] !== $groups) {
            $commonGroupIds = array_map('intval', $this->em->getConnection()->fetchFirstColumn(
                "SELECT DISTINCT g.id FROM users g INNER JOIN groups_users gu ON gu.group_id = g.id WHERE g.type = 'Group' AND gu.user_id IN (".implode(',', $userIds).')'
            ));
        }

        return $this->render('context_menus/users.html.twig', [
            'users' => $users,
            'edited_user' => 1 === \count($users) ? $users[0] : null,
            'ids' => $userIds,
            'groups' => $groups,
            'common_group_ids' => $commonGroupIds,
            'back' => $this->backUrl($request),
        ]);
    }

    /** ApplicationController#back_url: params[:back_url] or the referer */
    private function backUrl(Request $request): ?string
    {
        $back = $request->request->get('back_url') ?? $request->query->get('back_url');
        if (null === $back) {
            $ref = $request->headers->get('referer');
            $back = null !== $ref ? rawurldecode($ref) : null;
        }

        return null === $back || '' === (string) $back ? null : (string) $back;
    }
}
