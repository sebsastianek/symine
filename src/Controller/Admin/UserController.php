<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users')]
#[IsGranted('ROLE_ADMIN')]
class UserController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
    }

    #[Route('', name: 'admin_user_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = $request->query->get('status', 'active');

        $queryBuilder = $this->userRepository->createQueryBuilder('u')
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC');

        if ($status === 'active') {
            $queryBuilder->where('u.status = :status')
                ->setParameter('status', User::STATUS_ACTIVE);
        } elseif ($status === 'locked') {
            $queryBuilder->where('u.status = :status')
                ->setParameter('status', User::STATUS_LOCKED);
        } elseif ($status === 'registered') {
            $queryBuilder->where('u.status = :status')
                ->setParameter('status', User::STATUS_REGISTERED);
        }
        // 'all' shows all users without filter

        $users = $queryBuilder->getQuery()->getResult();

        return $this->render('admin/user/index.html.twig', [
            'users' => $users,
            'current_status' => $status,
        ]);
    }

    #[Route('/new', name: 'admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = new User();

        if ($request->isMethod('POST')) {
            $user->setLogin($request->request->get('login'));
            $user->setFirstname($request->request->get('firstname', ''));
            $user->setLastname($request->request->get('lastname', ''));
            $user->setAdmin($request->request->has('admin'));
            $user->setStatus((int) $request->request->get('status', User::STATUS_ACTIVE));
            $user->setLanguage($request->request->get('language', 'en'));

            // Hash the password if provided
            $password = $request->request->get('password');
            if (!empty($password)) {
                $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
                // Set the hashed password directly on the entity
                $reflection = new \ReflectionClass($user);
                $property = $reflection->getProperty('hashedPassword');
                $property->setAccessible(true);
                $property->setValue($user, $hashedPassword);
            }

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $this->addFlash('success', 'User created successfully.');

            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/new.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_user_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user): Response
    {
        if ($request->isMethod('POST')) {
            $user->setLogin($request->request->get('login'));
            $user->setFirstname($request->request->get('firstname', ''));
            $user->setLastname($request->request->get('lastname', ''));
            $user->setAdmin($request->request->has('admin'));
            $user->setStatus((int) $request->request->get('status', User::STATUS_ACTIVE));
            $user->setLanguage($request->request->get('language', 'en'));

            // Update password if provided
            $password = $request->request->get('password');
            if (!empty($password)) {
                $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
                // Set the hashed password directly on the entity
                $reflection = new \ReflectionClass($user);
                $property = $reflection->getProperty('hashedPassword');
                $property->setAccessible(true);
                $property->setValue($user, $hashedPassword);
            }

            $this->entityManager->flush();

            $this->addFlash('success', 'User updated successfully.');

            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/edit.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/{id}/lock', name: 'admin_user_lock', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function lock(Request $request, User $user): Response
    {
        // Verify CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('lock_user_' . $user->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_user_index');
        }

        // Prevent locking yourself
        if ($user->getId() === $this->getUser()->getId()) {
            $this->addFlash('error', 'You cannot lock your own account.');
            return $this->redirectToRoute('admin_user_index');
        }

        $user->setStatus(User::STATUS_LOCKED);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('User "%s" has been locked.', $user->getLogin()));

        return $this->redirectToRoute('admin_user_index');
    }

    #[Route('/{id}/unlock', name: 'admin_user_unlock', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unlock(Request $request, User $user): Response
    {
        // Verify CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('unlock_user_' . $user->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_user_index');
        }

        $user->setStatus(User::STATUS_ACTIVE);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('User "%s" has been unlocked.', $user->getLogin()));

        return $this->redirectToRoute('admin_user_index');
    }

    #[Route('/{id}/delete', name: 'admin_user_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, User $user): Response
    {
        // Verify CSRF token
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete_user_' . $user->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_user_index');
        }

        // Prevent deleting yourself
        if ($user->getId() === $this->getUser()->getId()) {
            $this->addFlash('error', 'You cannot delete your own account.');
            return $this->redirectToRoute('admin_user_index');
        }

        $userName = $user->getLogin();

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('User "%s" deleted successfully.', $userName));

        return $this->redirectToRoute('admin_user_index');
    }
}
