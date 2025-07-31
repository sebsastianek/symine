<?php

declare(strict_types=1);

namespace App\Security\Authenticator;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\PasswordHasher\RedmineLegacyPasswordHasher;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Custom authenticator that handles legacy Redmine passwords
 * and migrates them to modern hashing on successful login
 */
class LegacyPasswordAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'login';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UserRepository $userRepository,
        private RedmineLegacyPasswordHasher $legacyPasswordHasher,
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $login = $request->getPayload()->getString('login');
        $password = $request->getPayload()->getString('password');

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $login);

        return new Passport(
            new UserBadge($login, [$this, 'loadUser']),
            new CustomCredentials([$this, 'checkCredentials'], $password),
            [
                new RememberMeBadge(),
            ]
        );
    }

    public function loadUser(string $login): User
    {
        // Try to find user by login (case-insensitive)
        $user = $this->userRepository->findOneByLogin($login);

        if (!$user) {
            $this->logger->info('User not found by login', ['login' => $login]);
            throw new UserNotFoundException('User not found');
        }

        // Check if user is active
        if ($user->getStatus() !== User::STATUS_ACTIVE) {
            $this->logger->info('User account is not active', [
                'login' => $login,
                'status' => $user->getStatus()
            ]);
            throw new UserNotFoundException('User account is not active');
        }

        return $user;
    }

    public function checkCredentials($password, User $user): bool
    {
        if (empty($password)) {
            return false;
        }

        // First try modern password verification
        if ($this->passwordHasher->isPasswordValid($user, $password)) {
            $this->logger->info('User authenticated with modern password', ['user_id' => $user->getId()]);
            return true;
        }

        // Try legacy Redmine password verification
        if ($this->verifyLegacyPassword($user, $password)) {
            $this->logger->info('User authenticated with legacy password', ['user_id' => $user->getId()]);

            // Schedule password migration
            $this->scheduleLegacyPasswordMigration($user, $password);

            return true;
        }

        $this->logger->info('Password verification failed', ['user_id' => $user->getId()]);
        return false;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $targetPath = $this->getTargetPath($request->getSession(), $firewallName);

        if ($targetPath) {
            return new RedirectResponse($targetPath);
        }

        // Default redirect after successful login
        return new RedirectResponse($this->urlGenerator->generate('app_dashboard'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $this->logger->warning('Authentication failed', [
            'exception' => $exception->getMessage(),
            'login' => $request->getSession()->get(SecurityRequestAttributes::LAST_USERNAME)
        ]);

        return parent::onAuthenticationFailure($request, $exception);
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }

    /**
     * Verify password using legacy Redmine algorithm
     */
    private function verifyLegacyPassword(User $user, string $password): bool
    {
        try {
            return $this->legacyPasswordHasher->verifyLegacyUser($user, $password);
        } catch (\Exception $e) {
            $this->logger->error('Legacy password verification error', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Schedule legacy password migration to modern hashing
     */
    private function scheduleLegacyPasswordMigration(User $user, string $password): void
    {
        try {
            // Hash password using modern algorithm
            $newHashedPassword = $this->passwordHasher->hashPassword($user, $password);

            // Update user with new password hash and clear salt
            $user->setHashedPassword($newHashedPassword);
            $user->setSalt(null);
            $user->setPasswdChangedOn(new \DateTime());

            $this->entityManager->flush();

            $this->logger->info('Legacy password migrated successfully', [
                'user_id' => $user->getId()
            ]);

        } catch (\Exception $e) {
            // Don't fail if migration fails
            $this->logger->error('Failed to migrate legacy password', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check if user has legacy password format
     */
    private function hasLegacyPassword(User $user): bool
    {
        // Legacy passwords have salt and use SHA1 format
        return !empty($user->getSalt()) && !empty($user->getHashedPassword());
    }

    /**
     * Get authentication statistics
     */
    public function getAuthenticationStats(): array
    {
        $stats = [
            'total_users' => $this->userRepository->count([]),
            'legacy_password_users' => 0,
            'modern_password_users' => 0,
        ];

        // Count users with legacy vs modern passwords
        $users = $this->userRepository->findAll();
        foreach ($users as $user) {
            if ($this->hasLegacyPassword($user)) {
                $stats['legacy_password_users']++;
            } else {
                $stats['modern_password_users']++;
            }
        }

        return $stats;
    }
}
