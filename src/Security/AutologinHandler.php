<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Token;
use App\Entity\User;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\RememberMe\AbstractRememberMeHandler;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;

/**
 * Redmine's autologin ("Stay logged in"): AccountController#set_autologin_cookie,
 * ApplicationController#try_to_autologin and #logout_user.
 *
 * - the cookie is only issued when the "autologin" setting is enabled; it refers to a token stored in the
 *   tokens table (action "autologin", at most 10 per user);
 * - the token is accepted while the setting is enabled, for the configured number of days
 *   (Token.find_active_user('autologin', key, Setting.autologin.to_i)) and only for active users;
 * - logging out deletes the token (User#delete_autologin_token) and the cookie.
 */
final class AutologinHandler extends AbstractRememberMeHandler
{
    public const string COOKIE_NAME = 'autologin';
    private const int MAX_TOKENS = 10;

    public function __construct(
        private readonly UserProvider $users,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($this->users, $requestStack, [
            'name' => self::COOKIE_NAME,
            'path' => '/',
            'secure' => null, // request.ssl?
            'httponly' => true,
            'samesite' => 'lax',
        ], $logger);
    }

    public function createRememberMeCookie(UserInterface $user): void
    {
        $days = $this->settings->int('autologin');
        if ($days <= 0 || !$user instanceof User || null === $user->id) {
            return;
        }
        // Token#delete_previous_tokens: keep the most recently used ones
        $conn = $this->em->getConnection();
        $ids = $conn->fetchFirstColumn("SELECT id FROM tokens WHERE user_id = ? AND action = 'autologin' ORDER BY updated_on DESC, id DESC", [$user->id]);
        $ids = \array_slice($ids, self::MAX_TOKENS - 1);
        if ([] !== $ids) {
            $conn->executeStatement('DELETE FROM tokens WHERE id IN ('.implode(',', array_map('intval', $ids)).')');
        }
        $token = new Token($user, 'autologin');
        $this->em->persist($token);
        $this->em->flush();

        // cookies[autologin_cookie_name] = {expires: 1.year.from_now}; the validity is enforced on the token
        $this->createCookie(new RememberMeDetails($user->getUserIdentifier(), time() + 31536000, $token->value));
    }

    protected function processRememberMe(RememberMeDetails $rememberMeDetails, UserInterface $user): void
    {
        $days = $this->settings->int('autologin');
        if ($days <= 0 || !$user instanceof User || !$user->isActive()) {
            throw new AuthenticationException('Autologin is disabled.');
        }
        $token = $this->findToken($rememberMeDetails->getValue());
        if (null === $token || $token->user->id !== $user->id || $token->createdOn <= new \DateTimeImmutable('-'.$days.' days')) {
            throw new AuthenticationException('The autologin token is invalid or has expired.');
        }
        // Token.find_active_user: usage of the token is tracked (at most once per minute)
        if (null === $token->updatedOn || $token->updatedOn <= new \DateTimeImmutable('-1 minute')) {
            $this->em->getConnection()->executeStatement('UPDATE tokens SET updated_on = ? WHERE id = ?', [(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), $token->id]);
        }
    }

    /**
     * ApplicationController#try_to_autologin without its side effects: true when the cookie of the request
     * would log a user in (setting enabled, known and unexpired token of an active user).
     */
    public function accepts(?string $rawCookie): bool
    {
        $days = $this->settings->int('autologin');
        if ($days <= 0 || null === $rawCookie || '' === $rawCookie) {
            return false;
        }
        try {
            $details = RememberMeDetails::fromRawCookie($rawCookie);
        } catch (\Throwable) {
            return false;
        }
        $token = $this->findToken($details->getValue());
        if (null === $token || !$token->user->isActive() || $token->createdOn <= new \DateTimeImmutable('-'.$days.' days')) {
            return false;
        }

        return $this->users->findByLogin($details->getUserIdentifier())?->id === $token->user->id;
    }

    /** Logout (and failed logins): the token of the cookie is deleted with the cookie */
    public function clearRememberMeCookie(): void
    {
        $raw = $this->requestStack->getMainRequest()?->cookies->get(self::COOKIE_NAME);
        if (\is_string($raw) && '' !== $raw) {
            try {
                $token = $this->findToken(RememberMeDetails::fromRawCookie($raw)->getValue());
                if (null !== $token) {
                    $this->em->getConnection()->executeStatement('DELETE FROM tokens WHERE id = ?', [$token->id]);
                }
            } catch (AuthenticationException) {
                // malformed cookie: nothing to delete
            }
        }
        parent::clearRememberMeCookie();
    }

    private function findToken(string $value): ?Token
    {
        if (!preg_match('/\A[a-z0-9]+\z/i', $value)) {
            return null;
        }
        $token = $this->em->getRepository(Token::class)->findOneBy(['action' => 'autologin', 'value' => $value]);

        return null !== $token && hash_equals($token->value, $value) ? $token : null;
    }
}
