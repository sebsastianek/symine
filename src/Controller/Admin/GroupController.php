<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Group;
use App\Entity\GroupsUser;
use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/groups')]
#[IsGranted('ROLE_ADMIN')]
class GroupController extends AbstractController
{
    public function __construct(
        private GroupRepository $groupRepository,
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('', name: 'admin_group_index', methods: ['GET'])]
    public function index(): Response
    {
        $groups = $this->groupRepository->findBy([], ['lastname' => 'ASC']);

        return $this->render('admin/group/index.html.twig', [
            'groups' => $groups,
        ]);
    }

    #[Route('/new', name: 'admin_group_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $group = new Group();

        if ($request->isMethod('POST')) {
            // Groups use lastname field for the group name
            $group->setLastname($request->request->get('name'));
            $group->setLogin($request->request->get('name')); // Also set login for consistency
            $group->setStatus(Group::STATUS_ACTIVE);
            $group->setAdmin(false); // Groups cannot be admin

            $this->entityManager->persist($group);
            $this->entityManager->flush();

            $this->addFlash('success', 'Group created successfully.');

            return $this->redirectToRoute('admin_group_edit', ['id' => $group->getId()]);
        }

        return $this->render('admin/group/new.html.twig', [
            'group' => $group,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_group_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Group $group): Response
    {
        // Check if it's a built-in group
        if ($group->isBuiltin()) {
            $this->addFlash('error', 'Built-in groups cannot be edited.');
            return $this->redirectToRoute('admin_group_index');
        }

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');

            if ($action === 'update_group') {
                // Update group name
                $group->setLastname($request->request->get('name'));
                $group->setLogin($request->request->get('name'));

                $this->entityManager->flush();

                $this->addFlash('success', 'Group updated successfully.');

                return $this->redirectToRoute('admin_group_edit', ['id' => $group->getId()]);
            } elseif ($action === 'add_users') {
                // Add users to group
                $userIds = $request->request->all('user_ids');

                foreach ($userIds as $userId) {
                    $user = $this->userRepository->find($userId);

                    if ($user && !$group->hasUser($user)) {
                        $groupsUser = new GroupsUser();
                        $groupsUser->setGroup($group);
                        $groupsUser->setUser($user);

                        $this->entityManager->persist($groupsUser);
                    }
                }

                $this->entityManager->flush();

                $this->addFlash('success', 'Users added to group successfully.');

                return $this->redirectToRoute('admin_group_edit', ['id' => $group->getId()]);
            }
        }

        // Get all active users that are not in the group
        $allUsers = $this->userRepository->findBy(['status' => Group::STATUS_ACTIVE], ['lastname' => 'ASC', 'firstname' => 'ASC']);
        $availableUsers = array_filter($allUsers, fn($user) => !$group->hasUser($user));

        return $this->render('admin/group/edit.html.twig', [
            'group' => $group,
            'available_users' => $availableUsers,
        ]);
    }

    #[Route('/{id}/remove-user/{userId}', name: 'admin_group_remove_user', requirements: ['id' => '\d+', 'userId' => '\d+'], methods: ['POST'])]
    public function removeUser(Request $request, Group $group, int $userId): Response
    {
        // Verify CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('remove_user_' . $group->getId() . '_' . $userId, $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_group_edit', ['id' => $group->getId()]);
        }

        // Check if it's a built-in group
        if ($group->isBuiltin()) {
            $this->addFlash('error', 'Cannot modify built-in groups.');
            return $this->redirectToRoute('admin_group_index');
        }

        $user = $this->userRepository->find($userId);

        if (!$user) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('admin_group_edit', ['id' => $group->getId()]);
        }

        // Find and remove the GroupsUser entity
        foreach ($group->getGroupsUsers() as $groupsUser) {
            if ($groupsUser->getUser()->getId() === $userId) {
                $this->entityManager->remove($groupsUser);
                break;
            }
        }

        $this->entityManager->flush();

        $this->addFlash('success', sprintf('User "%s" removed from group.', $user->getLogin()));

        return $this->redirectToRoute('admin_group_edit', ['id' => $group->getId()]);
    }

    #[Route('/{id}/delete', name: 'admin_group_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Group $group): Response
    {
        // Verify CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete_group_' . $group->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_group_index');
        }

        // Check if it's a built-in group
        if ($group->isBuiltin()) {
            $this->addFlash('error', 'Built-in groups cannot be deleted.');
            return $this->redirectToRoute('admin_group_index');
        }

        $groupName = $group->getName();

        // Remove all GroupsUser relationships
        foreach ($group->getGroupsUsers() as $groupsUser) {
            $this->entityManager->remove($groupsUser);
        }

        $this->entityManager->remove($group);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Group "%s" deleted successfully.', $groupName));

        return $this->redirectToRoute('admin_group_index');
    }
}
