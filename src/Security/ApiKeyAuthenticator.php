<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\OauthAccessToken;
use App\Entity\Token;
use App\Entity\User;
use App\OAuth\OAuthServer;
use App\Security\Twofa\TwofaManager;
use App\Service\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Redmine REST API authentication: "key" parameter, "X-Redmine-API-Key" header, OAuth2 Bearer token
 * (Doorkeeper) or HTTP Basic (login/password or api key as username), accepted only by the actions that
 * declare accept_api_auth. Atom feeds accept the user's feeds key on GET requests of the actions that
 * declare accept_atom_auth (see KeyAuthScope). Users with two-factor authentication cannot use
 * login/password (HTTP 401). Bearer token users are restricted to the token scopes (User#oauth_scope).
 */
final class ApiKeyAuthenticator extends AbstractAuthenticator
{
    private const string OAUTH_TOKEN_ATTRIBUTE = '_oauth_access_token';
    private const string TWOFA_BASIC_ERROR = 'HTTP Basic authentication is not allowed. Use API key instead';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly UserProvider $users,
        private readonly ?\App\Service\LdapService $ldap = null,
        private readonly ?OAuthServer $oauth = null,
        private readonly ?Authorization $authorization = null,
        private readonly ?ApiRequestMatcher $matcher = null,
    ) {
    }

    /** The matcher decided that the request may be authenticated by the feeds key (no session user, no autologin) */
    private static function feedsKeyRequest(Request $request): bool
    {
        return 'atom' === $request->attributes->get(ApiRequestMatcher::MODE_ATTRIBUTE) && KeyAuthScope::hasFeedsKey($request);
    }

    public function supports(Request $request): ?bool
    {
        if (!($this->matcher ?? new ApiRequestMatcher($this->settings))->matches($request)) {
            return false;
        }
        if (self::feedsKeyRequest($request)) {
            // accept_atom_auth: GET request answered as Atom by an action that accepts the feeds key
            return true;
        }
        if (!$this->settings->restApiEnabled() || !KeyAuthScope::acceptsApiAuth($request)) {
            // REST API disabled, or action without accept_api_auth: credentials are ignored, the request is
            // anonymous (401/403 on protected resources)
            return false;
        }
        if (null !== KeyAuthScope::apiKey($request) || KeyAuthScope::hasBasicCredentials($request)) {
            return true;
        }
        $bearer = OAuthServer::bearerToken($request);
        if (null !== $bearer && null !== $this->oauth && KeyAuthScope::isApiRequest($request)) {
            // Doorkeeper.authenticate: unknown tokens (or inactive owners) leave the request anonymous
            $token = $this->oauth->authenticate($bearer);
            if (null === $token) {
                return false;
            }
            if ($token->isAccessible()) {
                $owner = null === $token->resourceOwnerId ? null : $this->em->find(User::class, $token->resourceOwnerId);
                if (!$owner instanceof User || !$owner->isActive()) {
                    return false;
                }
            }
            $request->attributes->set(self::OAUTH_TOKEN_ATTRIBUTE, $token);

            return true;
        }

        return false;
    }

    public function authenticate(Request $request): Passport
    {
        if (self::feedsKeyRequest($request)) {
            $key = (string) $request->query->get('key');
            // find_current_user: the feeds key first, then the API key when the action accepts it too
            $apiKeyAccepted = $this->settings->restApiEnabled() && KeyAuthScope::acceptsApiAuth($request);

            return new SelfValidatingPassport(new UserBadge('feeds:'.$key, function () use ($key, $apiKeyAccepted): User {
                try {
                    return $this->userByToken('feeds', $key);
                } catch (CustomUserMessageAuthenticationException $e) {
                    if (!$apiKeyAccepted) {
                        throw $e;
                    }

                    return $this->userByToken('api', $key);
                }
            }));
        }
        if (!$this->settings->restApiEnabled() || !KeyAuthScope::acceptsApiAuth($request)) {
            throw new CustomUserMessageAuthenticationException('REST API is disabled');
        }
        $key = KeyAuthScope::apiKey($request);
        if (null !== $key) {
            return new SelfValidatingPassport(new UserBadge('api:'.$key, fn () => $this->userByToken('api', $key)));
        }
        $oauthToken = $request->attributes->get(self::OAUTH_TOKEN_ATTRIBUTE);
        if ($oauthToken instanceof OauthAccessToken) {
            if (!$oauthToken->isAccessible()) {
                throw new OAuthTokenException(OAuthServer::invalidTokenReason($oauthToken));
            }

            return new SelfValidatingPassport(new UserBadge('oauth:'.$oauthToken->id, fn () => $this->userByOauthToken($oauthToken)));
        }
        $login = (string) $request->getUser();
        $password = (string) $request->getPassword();
        $user = $this->users->findByLogin($login);
        if (null !== $user && '' !== $password && TwofaManager::isActive($user)) {
            // Don't allow using username/password when two-factor auth is active
            throw new CustomUserMessageAuthenticationException(self::TWOFA_BASIC_ERROR);
        }
        if (null !== $user && '' !== $password && null !== $user->authSourceId && null !== $this->ldap) {
            // User.try_to_login: users bound to an LDAP source are checked against it
            return new Passport(new UserBadge($user->login, fn () => $user), new CustomCredentials(
                fn (string $password, User $user): bool => null !== $this->ldap->tryToLogin($user->login, $password), $password));
        }
        if (null !== $user && '' !== $password) {
            return new Passport(new UserBadge($user->login, fn () => $user), new PasswordCredentials($password));
        }
        if (null === $user && '' !== $login && '' !== $password && null !== $this->ldap && $this->ldap->hasOnTheFlySources()
            && null !== ($created = $this->ldap->tryToLogin($login, $password))) {
            // on-the-fly registration of LDAP users (User.try_to_login)
            return new SelfValidatingPassport(new UserBadge($created->login, fn () => $created));
        }

        return new SelfValidatingPassport(new UserBadge('api:'.$login, fn () => $this->userByToken('api', $login)));
    }

    private function userByToken(string $action, string $value): User
    {
        $token = $this->em->getRepository(Token::class)->findOneBy(['action' => $action, 'value' => $value]);
        if (null === $token || !$token->user->isActive() || !hash_equals((string) $token->value, $value)) {
            throw new CustomUserMessageAuthenticationException('Invalid API key');
        }
        // Token.find_active_user: usage of the key is tracked (at most once per minute)
        if (null === $token->updatedOn || $token->updatedOn <= new \DateTimeImmutable('-1 minute')) {
            $this->em->getConnection()->executeStatement('UPDATE tokens SET updated_on = ? WHERE id = ?', [(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), $token->id]);
        }

        return $token->user;
    }

    /**
     * User of an OAuth access token, restricted to the token scopes: the user only acts as an
     * administrator when the "admin" scope was granted (User#admin? with oauth_scope).
     */
    private function userByOauthToken(OauthAccessToken $token): User
    {
        $user = null === $token->resourceOwnerId ? null : $this->em->find(User::class, $token->resourceOwnerId);
        if (!$user instanceof User || !$user->isActive()) {
            throw new CustomUserMessageAuthenticationException('Invalid access token');
        }
        $scopes = $token->getScopes();
        $this->authorization?->setOauthScope($user, $scopes);
        if ($user->admin && !\in_array('admin', $scopes, true)) {
            // in-memory only: Doctrine's original data is updated too, so the change is never flushed
            $user->admin = false;
            $this->em->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($user), 'admin', false);
        }

        return $user;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        if ($exception instanceof OAuthTokenException) {
            // doorkeeper_render_error: head 401 with the Bearer challenge
            $description = $this->oauth?->errorDescription('invalid_token', null, $exception->reason) ?? '';

            return new Response('', Response::HTTP_UNAUTHORIZED, [
                'Cache-Control' => 'no-store',
                'WWW-Authenticate' => \sprintf('Bearer realm="%s", error="invalid_token", error_description="%s"', OAuthServer::REALM, $description),
            ]);
        }
        if ($exception instanceof CustomUserMessageAuthenticationException && self::TWOFA_BASIC_ERROR === $exception->getMessageKey()) {
            // render_error :status => 401 (head 401 for API formats)
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }
        // Redmine: invalid credentials leave the request anonymous (public data stays readable,
        // protected resources answer 401 through the entry point)
        return null;
    }
}
