<?php

declare(strict_types=1);

namespace App\Service\Admin\Users;

use App\Entity\Principal;
use App\Entity\User;
use App\Security\CurrentUser;
use App\Service\Admin\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads the user accounts the users administration works on (find_user and the bulk selections of
 * Redmine's UsersController): 404 when there is nothing to work on.
 */
final class UserFinder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CurrentUser $currentUser,
        private readonly UserManager $users,
    ) {
    }

    /** A user account that can be administered: not a group, not the anonymous user */
    public function find(int $id): User
    {
        $user = $this->em->find(Principal::class, $id);
        if (!$user instanceof User || !$user->isLogged()) {
            throw new NotFoundHttpException();
        }

        return $user;
    }

    /** The user of a profile page (users#show): a user the current user may see */
    public function findVisible(int $id): User
    {
        $user = $this->em->find(Principal::class, $id);
        if (!$user instanceof User) {
            throw new NotFoundHttpException();
        }

        return $this->visible($user);
    }

    /** The given user when the current user may see its profile */
    public function visible(User $user): User
    {
        if (!$this->users->isVisible($user)) {
            throw new NotFoundHttpException();
        }

        return $user;
    }

    /**
     * The accounts of a bulk lock / unlock (ids[]): never the current user, never the anonymous user.
     *
     * @return list<User>
     */
    public function forBulkStatus(Request $request): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($request->request->all()['ids'] ?? $request->query->all()['ids'] ?? []))));
        $users = $ids ? $this->em->createQuery('SELECT u FROM App\Entity\User u WHERE u.id IN (:ids) AND u.id <> :me AND u.status <> 0')
            ->setParameters(['ids' => $ids, 'me' => $this->currentUser->get()->id])->getResult() : [];
        if ([] === $users) {
            throw new NotFoundHttpException();
        }

        return $users;
    }

    /**
     * The accounts of a bulk deletion (ids[]): never the current user, never the anonymous user.
     *
     * @return list<User>
     */
    public function forBulkDestroy(Request $request): array
    {
        $params = array_merge($request->query->all(), $request->request->all());
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($params['ids'] ?? [])))));
        $users = $ids ? $this->em->createQuery('SELECT u FROM App\Entity\User u WHERE u.id IN (:ids) AND u.id <> :me AND u.status <> 0 ORDER BY u.id')
            ->setParameters(['ids' => $ids, 'me' => $this->currentUser->get()->id])->getResult() : [];
        if ([] === $users) {
            throw new NotFoundHttpException();
        }

        return $users;
    }

    /**
     * The users selected in the list when its context menu is opened (id or ids[]).
     *
     * @return list<User>
     */
    public function forContextMenu(Request $request): array
    {
        $params = array_merge($request->query->all(), $request->request->all());
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($params['id'] ?? $params['ids'] ?? [])))));
        $users = [] !== $ids ? $this->em->createQuery('SELECT u FROM App\Entity\User u WHERE u.id IN (:ids) ORDER BY u.id')->setParameter('ids', $ids)->getResult() : [];
        if ([] === $users) {
            throw new NotFoundHttpException();
        }

        return $users;
    }
}
