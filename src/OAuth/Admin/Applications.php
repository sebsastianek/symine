<?php

declare(strict_types=1);

namespace App\OAuth\Admin;

use App\Entity\OauthApplication;
use App\Security\AccessGuard;
use App\Security\CurrentUser;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The OAuth2 client applications as Administration > Applications manages them (Redmine's
 * Oauth2ApplicationsController): who may manage them, which exist, and the secret of a new application,
 * which is shown once.
 */
final class Applications
{
    private const string FLASH_SECRET = 'oauth_application_secret';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessGuard $guard,
        private readonly CurrentUser $currentUser,
        private readonly Settings $settings,
    ) {
    }

    /** Redmine's admin_authenticator: REST API enabled and administrator */
    public function authenticateAdmin(): void
    {
        $this->guard->requireLogin();
        if (!$this->settings->restApiEnabled() || !$this->currentUser->get()->admin) {
            $this->guard->deny();
        }
    }

    /** @return list<OauthApplication> oldest first */
    public function all(): array
    {
        return $this->em->getRepository(OauthApplication::class)->findBy([], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    public function find(int $id): OauthApplication
    {
        $app = $this->em->find(OauthApplication::class, $id);
        if (null === $app) {
            throw new NotFoundHttpException();
        }

        return $app;
    }

    /** Keeps the secret of the application just created for the next page, the only one that shows it */
    public function rememberSecret(Request $request, string $secret): void
    {
        $request->getSession()->set(self::FLASH_SECRET, $secret);
    }

    /** The secret kept for this page, which is then forgotten */
    public function takeSecret(Request $request): ?string
    {
        $secret = $request->getSession()->get(self::FLASH_SECRET);
        $request->getSession()->remove(self::FLASH_SECRET);

        return \is_string($secret) && '' !== $secret ? $secret : null;
    }
}
