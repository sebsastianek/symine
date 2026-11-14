<?php

declare(strict_types=1);

namespace App\Service\Admin\Users;

use App\Entity\Group;
use App\Entity\User;
use App\Service\Admin\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/** What the context menu of the administration users list offers for the selected users (ContextMenus::UsersController). */
final class UsersContextMenu
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserFinder $finder,
        private readonly UserManager $users,
    ) {
    }

    /** @return array<string, mixed> */
    public function view(Request $request): array
    {
        $users = $this->finder->forContextMenu($request);
        /** @var list<Group> $groups */
        $groups = $this->users->givableGroups();
        $userIds = array_map(static fn (User $u) => (int) $u->id, $users);
        $commonGroupIds = [];
        if ([] !== $groups) {
            $commonGroupIds = array_map('intval', $this->em->getConnection()->fetchFirstColumn(
                "SELECT DISTINCT g.id FROM users g INNER JOIN groups_users gu ON gu.group_id = g.id WHERE g.type = 'Group' AND gu.user_id IN (".implode(',', $userIds).')'
            ));
        }

        return [
            'users' => $users,
            'edited_user' => 1 === \count($users) ? $users[0] : null,
            'ids' => $userIds,
            'groups' => $groups,
            'common_group_ids' => $commonGroupIds,
            'back' => $this->backUrl($request),
        ];
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
