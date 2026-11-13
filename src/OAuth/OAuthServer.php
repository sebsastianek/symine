<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Entity\OauthAccessGrant;
use App\Entity\OauthAccessToken;
use App\Entity\OauthApplication;
use App\Entity\User;
use App\I18n\Localizer;
use App\Security\AccessControl;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * OAuth2 authorization server: a port of Doorkeeper 5.8 as configured by Redmine
 * (config/initializers/30-redmine.rb):
 *  - grant flows: authorization_code (+ PKCE plain/S256) and refresh_token, no client_credentials,
 *  - hash_token_secrets: access tokens, refresh tokens and authorization codes are stored as SHA256 hex digests,
 *  - hash_application_secrets using BCrypt,
 *  - default scopes = public permissions, optional scopes = every permission + "admin", enforce_configured_scopes,
 *  - access tokens expire after 2 hours, codes after 10 minutes; refresh tokens are revoked on use
 *    of the new access token (previous_refresh_token column).
 */
final class OAuthServer
{
    public const string REALM = 'Redmine';
    public const int ACCESS_TOKEN_EXPIRES_IN = 7200;
    public const int AUTHORIZATION_CODE_EXPIRES_IN = 600;
    public const array OOB_URIS = ['urn:ietf:wg:oauth:2.0:oob', 'urn:ietf:wg:oauth:2.0:oob:auto'];
    public const array PKCE_METHODS = ['plain', 'S256'];
    public const array GRANT_TYPES = ['authorization_code', 'refresh_token'];
    public const string NO_STORE = 'no-store';

    /** ErrorResponse::NON_REDIRECTABLE_STATES */
    private const array NON_REDIRECTABLE = ['invalid_redirect_uri', 'invalid_client', 'unauthorized_client'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Localizer $i18n,
    ) {
    }

    // --- Scopes ------------------------------------------------------------------------

    /** @return list<string> default_scopes: Redmine::AccessControl.public_permissions */
    public static function defaultScopes(): array
    {
        return array_values(array_keys(array_filter(AccessControl::permissions(), static fn (array $p) => !empty($p['public']))));
    }

    /** @return list<string> default + optional scopes (every permission and "admin") */
    public static function serverScopes(): array
    {
        return array_values(array_unique([...self::defaultScopes(), ...array_keys(AccessControl::permissions()), 'admin']));
    }

    /** @return list<string> Doorkeeper::OAuth::Scopes.from_string */
    public static function scopesFromString(?string $s): array
    {
        return array_values(array_unique(preg_split('/\s+/', trim((string) $s), -1, \PREG_SPLIT_NO_EMPTY) ?: []));
    }

    /** ScopeChecker.valid?: every requested scope must exist in the application scopes (or server scopes) */
    public static function scopesValid(?string $scopeStr, array $serverScopes, array $appScopes = []): bool
    {
        if (null === $scopeStr || '' === trim($scopeStr) || preg_match('/[\n\r\t]/', $scopeStr)) {
            return false;
        }
        $valid = [] !== $appScopes ? $appScopes : $serverScopes;
        foreach (self::scopesFromString($scopeStr) as $s) {
            if (!\in_array($s, $valid, true)) {
                return false;
            }
        }

        return true;
    }

    // --- Secrets -------------------------------------------------------------------------

    /** SecureRandom.urlsafe_base64(32) (UniqueToken.generate) */
    public static function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** Doorkeeper::SecretStoring::Sha256Hash */
    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** Doorkeeper::SecretStoring::BCrypt (bcrypt-ruby default cost 12) */
    public static function hashSecret(string $plain): string
    {
        return password_hash($plain, \PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function secretMatches(OauthApplication $app, ?string $input): bool
    {
        if (null === $input || '' === $app->secret) {
            return false;
        }

        return password_verify($input, $app->secret);
    }

    public function renewSecret(OauthApplication $app): string
    {
        $plain = self::generateToken();
        $app->secret = self::hashSecret($plain);
        $app->plaintextSecret = $plain;

        return $plain;
    }

    // --- Applications ----------------------------------------------------------------

    public function applicationByUid(?string $uid): ?OauthApplication
    {
        if (null === $uid || '' === $uid) {
            return null;
        }

        return $this->em->getRepository(OauthApplication::class)->findOneBy(['uid' => $uid]);
    }

    /** Application.by_uid_and_secret */
    public function applicationByUidAndSecret(?string $uid, ?string $secret): ?OauthApplication
    {
        $app = $this->applicationByUid($uid);
        if (null === $app) {
            return null;
        }
        if ((null === $secret || '' === $secret) && !$app->confidential) {
            return $app;
        }

        return self::secretMatches($app, $secret) ? $app : null;
    }

    /**
     * Client::Credentials.from_request (from_basic, then from_params).
     *
     * @return array{0: string, 1: ?string}|null
     */
    public static function clientCredentials(Request $request): ?array
    {
        $auth = (string) $request->headers->get('Authorization');
        if (preg_match('/^Basic (.*)/s', $auth, $m)) {
            $decoded = (string) base64_decode($m[1]);
            $parts = explode(':', $decoded, 2);
            if ('' !== $parts[0]) {
                return [$parts[0], $parts[1] ?? null];
            }
        }
        $uid = $request->request->get('client_id', $request->query->get('client_id'));
        if (\is_string($uid) && '' !== $uid) {
            $secret = $request->request->get('client_secret', $request->query->get('client_secret'));

            return [$uid, \is_string($secret) ? $secret : null];
        }

        return null;
    }

    /** Server#client */
    public function client(Request $request): ?OauthApplication
    {
        $credentials = self::clientCredentials($request);

        return null === $credentials ? null : $this->applicationByUidAndSecret($credentials[0], $credentials[1]);
    }

    /**
     * Validates application attributes (Doorkeeper Application validations + RedirectUriValidator).
     *
     * @return list<string> full error messages
     */
    public function applicationErrors(OauthApplication $app): array
    {
        $errors = [];
        $nameLabel = $this->i18n->l('activerecord.attributes.doorkeeper/application.name');
        $uriLabel = $this->i18n->l('activerecord.attributes.doorkeeper/application.redirect_uri');
        if ('' === trim($app->name)) {
            $errors[] = $nameLabel.' '.$this->i18n->l('activerecord.errors.messages.blank');
        }
        if ('' === trim($app->redirectUri)) {
            $errors[] = $uriLabel.' '.$this->i18n->l('activerecord.errors.messages.blank');
        } else {
            foreach ($app->getRedirectUris() as $uri) {
                if (\in_array($uri, self::OOB_URIS, true)) {
                    continue;
                }
                foreach (self::redirectUriErrors($uri) as $key) {
                    $msg = $uriLabel.' '.$this->i18n->l('activerecord.errors.models.doorkeeper/application.attributes.redirect_uri.'.$key);
                    if (!\in_array($msg, $errors, true)) {
                        $errors[] = $msg;
                    }
                }
            }
        }
        if ('' !== trim($app->scopes) && !self::scopesValid($app->scopes, self::serverScopes())) {
            $errors[] = $this->i18n->l('activerecord.attributes.doorkeeper/application.scopes').' '.$this->i18n->l('activerecord.errors.models.doorkeeper/application.attributes.scopes.not_match_configured');
        }

        return $errors;
    }

    /**
     * Doorkeeper::RedirectUriValidator with Redmine's forbid_redirect_uri (data/vbscript/javascript)
     * and force_ssl_in_redirect_uri (except for loopback hosts).
     *
     * @return list<string> error keys
     */
    public static function redirectUriErrors(string $uri): array
    {
        $parts = parse_url($uri);
        if (false === $parts || preg_match('/\s/', $uri)) {
            return ['invalid_uri'];
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : null;
        $host = $parts['host'] ?? null;
        $opaque = null !== $scheme && !str_starts_with(substr($uri, \strlen($scheme) + 1), '/');
        $errors = [];
        if (\in_array($scheme, ['data', 'vbscript', 'javascript'], true)) {
            $errors[] = 'forbidden_uri';
        }
        if (isset($parts['fragment'])) {
            $errors[] = 'fragment_present';
        }
        if ($opaque || 'localhost' === $scheme) {
            $errors[] = 'unspecified_scheme';
        }
        if (null === $scheme && (null === $host || '' === $host)) {
            $errors[] = 'relative_uri';
        }
        $hostname = null === $host ? null : trim($host, '[]');
        if ('http' === $scheme && !\in_array($hostname, ['localhost', '127.0.0.1', '::1'], true)) {
            $errors[] = 'secured_uri';
        }
        if (\in_array($scheme, ['http', 'https'], true) && (null === $host || '' === $host)) {
            $errors[] = 'invalid_uri';
        }

        return $errors;
    }

    // --- Redirect URIs ---------------------------------------------------------------

    /** URIChecker.valid_for_authorization? */
    public static function redirectUriValidForAuthorization(?string $url, string $clientUrls): bool
    {
        if (null === $url || '' === $url || !self::uriValid($url)) {
            return false;
        }
        foreach (preg_split('/\s+/', trim($clientUrls), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $other) {
            if (self::uriMatches($url, $other)) {
                return true;
            }
        }

        return false;
    }

    /** URIChecker.valid? */
    public static function uriValid(string $url): bool
    {
        if (\in_array($url, self::OOB_URIS, true)) {
            return true;
        }
        $p = parse_url($url);
        if (false === $p || !isset($p['scheme']) || '' === $p['scheme'] || 'localhost' === $p['scheme']) {
            return false;
        }
        if (\in_array($p['scheme'], ['http', 'https'], true) && (!isset($p['host']) || '' === $p['host'])) {
            return false;
        }
        $opaque = !str_starts_with(substr($url, \strlen($p['scheme']) + 1), '/');

        return !isset($p['fragment']) && !$opaque;
    }

    /** URIChecker.matches? (query parameters order-insensitive, loopback ports ignored) */
    public static function uriMatches(string $url, string $clientUrl): bool
    {
        if (\in_array($url, self::OOB_URIS, true) || \in_array($clientUrl, self::OOB_URIS, true)) {
            return $url === $clientUrl;
        }
        $a = parse_url($url);
        $b = parse_url($clientUrl);
        if (false === $a || false === $b) {
            return false;
        }
        if (isset($b['query'])) {
            $qa = isset($a['query']) ? explode('&', $a['query']) : null;
            $qb = explode('&', $b['query']);
            if (null === $qa) {
                return false;
            }
            sort($qa);
            sort($qb);
            if ($qa !== $qb) {
                return false;
            }
        }
        $loopback = static fn (array $p) => isset($p['host']) && (\in_array(trim($p['host'], '[]'), ['::1'], true) || str_starts_with($p['host'], '127.'));
        if ($loopback($a) && $loopback($b)) {
            unset($a['port'], $b['port']);
        }
        unset($a['query'], $b['query']);
        $norm = static fn (array $p) => [strtolower($p['scheme'] ?? ''), $p['user'] ?? null, $p['pass'] ?? null, strtolower($p['host'] ?? ''), $p['port'] ?? null, '' === ($p['path'] ?? '') ? '' : $p['path'], $p['fragment'] ?? null];
        $na = $norm($a);
        $nb = $norm($b);
        // URI#== compares normalized URIs: default ports are implied
        foreach ([&$na, &$nb] as &$n) {
            if (null === $n[4]) {
                $n[4] = 'https' === $n[0] ? 443 : ('http' === $n[0] ? 80 : null);
            }
        }
        unset($n);

        return $na === $nb;
    }

    public static function isOob(?string $uri): bool
    {
        return \in_array($uri, self::OOB_URIS, true);
    }

    /** Authorization::URIBuilder.uri_with_query / uri_with_fragment (Rack::Utils.build_query) */
    public static function uriWith(string $url, array $params, bool $fragment = false): string
    {
        $params = array_filter($params, static fn ($v) => null !== $v && '' !== $v);
        if ($fragment) {
            $base = explode('#', $url, 2)[0];

            return $base.'#'.self::buildQuery($params);
        }
        $parts = explode('#', $url, 2);
        $q = explode('?', $parts[0], 2);
        $original = [];
        if (isset($q[1]) && '' !== $q[1]) {
            foreach (explode('&', $q[1]) as $pair) {
                if ('' === $pair) {
                    continue;
                }
                $kv = explode('=', $pair, 2);
                $original[urldecode($kv[0])] = urldecode($kv[1] ?? '');
            }
        }
        $merged = array_merge($original, $params);
        $merged = array_filter($merged, static fn ($v) => null !== $v && '' !== $v);

        return $q[0].'?'.self::buildQuery($merged).(isset($parts[1]) ? '#'.$parts[1] : '');
    }

    private static function buildQuery(array $params): string
    {
        $out = [];
        foreach ($params as $k => $v) {
            $out[] = urlencode((string) $k).'='.urlencode((string) $v);
        }

        return implode('&', $out);
    }

    // --- Pre-authorization (GET/POST/DELETE /oauth/authorize) -----------------------

    /** @param array<string, mixed> $params */
    public function preAuthorize(array $params): PreAuthorization
    {
        $p = new PreAuthorization();
        foreach (['client_id', 'response_type', 'response_mode', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method'] as $k) {
            $v = $params[$k] ?? null;
            $p->{lcfirst(str_replace('_', '', ucwords($k, '_')))} = \is_scalar($v) ? (string) $v : null;
        }
        $p->error = $this->validatePreAuthorization($p);

        return $p;
    }

    private function validatePreAuthorization(PreAuthorization $p): ?string
    {
        if (null === $p->clientId || '' === $p->clientId) {
            $p->missingParam = 'client_id';

            return 'invalid_request';
        }
        $p->client = $this->applicationByUid($p->clientId);
        if (null === $p->client) {
            return 'invalid_client';
        }
        if (!self::redirectUriValidForAuthorization($p->redirectUri, $p->client->redirectUri)) {
            return 'invalid_redirect_uri';
        }
        // scope: param or default scopes allowed by the client
        if (null === $p->scope || '' === $p->scope) {
            $clientScopes = $p->client->getScopes();
            $defaults = self::defaultScopes();
            $p->scope = implode(' ', [] === $clientScopes ? $defaults : array_values(array_filter($clientScopes, static fn ($s) => \in_array($s, $defaults, true))));
        }
        if (null === $p->responseType || '' === $p->responseType) {
            $p->missingParam = 'response_type';

            return 'invalid_request';
        }
        if ('code' !== $p->responseType) {
            return 'unsupported_response_type';
        }
        if (null === $p->responseMode || '' === $p->responseMode) {
            $p->responseMode = 'query';
        } elseif (!\in_array($p->responseMode, ['query', 'fragment', 'form_post'], true)) {
            return 'unsupported_response_mode';
        }
        if (!self::scopesValid($p->scope, self::serverScopes(), $p->client->getScopes())) {
            return 'invalid_scope';
        }
        if (null !== $p->codeChallenge && '' !== $p->codeChallenge
            && (null === $p->codeChallengeMethod || !\in_array($p->codeChallengeMethod, self::PKCE_METHODS, true))) {
            return 'invalid_code_challenge_method';
        }

        return null;
    }

    /** AuthorizationsController#can_authorize_response?: confidential app with a matching token */
    public function canAuthorizeWithoutConsent(PreAuthorization $p, User $owner): bool
    {
        if (null === $p->client || !$p->client->confidential) {
            return false;
        }
        $requested = self::scopesFromString($p->scope);
        sort($requested);
        $tokens = $this->em->getRepository(OauthAccessToken::class)->findBy(['applicationId' => $p->client->id, 'resourceOwnerId' => $owner->id, 'revokedAt' => null]);
        foreach ($tokens as $t) {
            $scopes = $t->getScopes();
            sort($scopes);
            if ($scopes === $requested && self::scopesValid((string) $p->scope, self::serverScopes(), $p->client->getScopes())) {
                return true;
            }
        }

        return false;
    }

    /** Authorization::Code#issue_token!: returns the plain authorization code */
    public function issueGrant(PreAuthorization $p, User $owner): string
    {
        $plain = self::generateToken();
        $grant = new OauthAccessGrant();
        $grant->applicationId = (int) $p->client?->id;
        $grant->resourceOwnerId = (int) $owner->id;
        $grant->expiresIn = self::AUTHORIZATION_CODE_EXPIRES_IN;
        $grant->redirectUri = (string) $p->redirectUri;
        $grant->scopes = implode(' ', self::scopesFromString($p->scope));
        $grant->codeChallenge = null === $p->codeChallenge || '' === $p->codeChallenge ? null : $p->codeChallenge;
        $grant->codeChallengeMethod = null === $p->codeChallengeMethod || '' === $p->codeChallengeMethod ? null : $p->codeChallengeMethod;
        $grant->token = self::hashToken($plain);
        $this->em->persist($grant);
        $this->em->flush();

        return $plain;
    }

    /**
     * Error response of a pre-authorization (ErrorResponse / InvalidRequestResponse).
     *
     * @return array{error: string, error_description: string, state?: string}
     */
    public function errorBody(string $error, ?string $state = null, ?string $missingParam = null, ?string $reason = null): array
    {
        $body = ['error' => $error, 'error_description' => $this->errorDescription($error, $missingParam, $reason)];
        if (null !== $state && '' !== $state) {
            $body['state'] = $state;
        }

        return $body;
    }

    public function errorDescription(string $error, ?string $missingParam = null, ?string $reason = null): string
    {
        $scope = 'doorkeeper.errors.messages.';
        if ('invalid_request' === $error) {
            if (null !== $missingParam) {
                return $this->i18n->l($scope.'invalid_request.missing_param', ['value' => $missingParam]);
            }
            $key = $scope.'invalid_request.'.($reason ?? 'unknown');

            return $this->i18n->has($key) ? $this->i18n->l($key) : $this->i18n->l($scope.'invalid_request.unknown');
        }
        if ('invalid_code_challenge_method' === $error) {
            return $this->i18n->l($scope.'invalid_code_challenge_method', ['count' => \count(self::PKCE_METHODS), 'challenge_methods' => implode(', ', self::PKCE_METHODS)]);
        }
        if ('invalid_token' === $error) {
            return $this->i18n->l($scope.'invalid_token.'.($reason ?? 'unknown'));
        }
        $key = $scope.$error;

        return $this->i18n->has($key) || $this->i18n->has($key, 'en') ? $this->i18n->l($key) : $this->i18n->l($scope.'server_error');
    }

    public static function isRedirectable(string $error, ?string $redirectUri, ?string $missingParam = null): bool
    {
        return !\in_array($error, self::NON_REDIRECTABLE, true) && !self::isOob($redirectUri) && 'client_id' !== $missingParam;
    }

    public static function errorStatus(string $error): int
    {
        return \in_array($error, ['invalid_client', 'unauthorized_client'], true) ? 401 : 400;
    }

    /** ErrorResponse#headers */
    public function errorHeaders(string $error, string $description): array
    {
        return [
            'Cache-Control' => 'no-store',
            'Content-Type' => 'application/json; charset=utf-8',
            'WWW-Authenticate' => \sprintf('Bearer realm="%s", error="%s", error_description="%s"', self::REALM, $error, $description),
        ];
    }

    // --- Token endpoint ---------------------------------------------------------------

    /**
     * TokensController#create.
     *
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    public function tokenRequest(Request $request): array
    {
        $param = static fn (string $k): ?string => \is_scalar($v = $request->request->get($k, $request->query->get($k))) ? (string) $v : null;
        $grantType = $param('grant_type');
        if (null === $grantType || '' === $grantType) {
            return $this->tokenError('invalid_request', $param('state'), 'grant_type');
        }
        if (!\in_array($grantType, self::GRANT_TYPES, true)) {
            return $this->tokenError('unsupported_grant_type', $param('state'));
        }

        return 'authorization_code' === $grantType ? $this->authorizationCodeRequest($request, $param) : $this->refreshTokenRequest($request, $param);
    }

    /** @param callable(string): ?string $param */
    private function authorizationCodeRequest(Request $request, callable $param): array
    {
        $code = $param('code');
        if (null === $code || '' === $code) {
            return $this->tokenError('invalid_request', $param('state'), 'code');
        }
        $grant = $this->em->getRepository(OauthAccessGrant::class)->findOneBy(['token' => self::hashToken($code)]);
        $client = $this->client($request);
        $verifier = $param('code_verifier');
        $redirectUri = $param('redirect_uri');
        // validate :params
        if (null !== $grant && null !== $grant->codeChallenge && '' !== $grant->codeChallenge && (null === $verifier || '' === $verifier)) {
            return $this->tokenError('invalid_request', null, 'code_verifier');
        }
        if (null === $redirectUri || '' === $redirectUri) {
            return $this->tokenError('invalid_request', null, 'redirect_uri');
        }
        // validate :client
        if (null === $client) {
            return $this->tokenError('invalid_client');
        }
        // validate :grant, :redirect_uri, :code_verifier
        if (null === $grant || $grant->applicationId !== $client->id || !$grant->isAccessible()) {
            return $this->tokenError('invalid_grant');
        }
        if (!self::redirectUriValidForAuthorization($redirectUri, $grant->redirectUri)) {
            return $this->tokenError('invalid_grant');
        }
        if (!self::codeVerifierValid($grant, $verifier)) {
            return $this->tokenError('invalid_grant');
        }

        // before_successful_response: lock, revoke the grant, create the token
        $conn = $this->em->getConnection();
        $revoked = $conn->executeStatement('UPDATE oauth_access_grants SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [gmdate('Y-m-d H:i:s'), $grant->id]);
        if (1 !== $revoked) {
            return $this->tokenError('invalid_grant');
        }
        $this->em->refresh($grant);
        [$token, $plain, $plainRefresh] = $this->createAccessToken($client->id, $grant->resourceOwnerId, $grant->getScopes(), self::ACCESS_TOKEN_EXPIRES_IN);

        return $this->tokenResponse($token, $plain, $plainRefresh);
    }

    /** @param callable(string): ?string $param */
    private function refreshTokenRequest(Request $request, callable $param): array
    {
        $plainRefresh = $param('refresh_token');
        $refresh = null === $plainRefresh || '' === $plainRefresh ? null
            : $this->em->getRepository(OauthAccessToken::class)->findOneBy(['refreshToken' => self::hashToken($plainRefresh)]);
        if (null === $refresh && (null === $plainRefresh || '' === $plainRefresh)) {
            return $this->tokenError('invalid_request', null, 'refresh_token');
        }
        if (null === $refresh || $refresh->isRevoked()) {
            return $this->tokenError('invalid_grant');
        }
        $credentials = self::clientCredentials($request);
        $client = null === $credentials ? null : $this->applicationByUidAndSecret($credentials[0], $credentials[1]);
        if (null !== $credentials && null === $client) {
            return $this->tokenError('invalid_client');
        }
        if (null !== $refresh->applicationId && (null === $client || $refresh->applicationId !== $client->id)) {
            return $this->tokenError('invalid_grant');
        }
        $originalScopes = $param('scope') ?? $param('scopes');
        if (null !== $originalScopes && '' !== $originalScopes) {
            if (!self::scopesValid($originalScopes, $refresh->getScopes())) {
                return $this->tokenError('invalid_scope');
            }
            $scopes = self::scopesFromString($originalScopes);
        } else {
            $scopes = $refresh->getScopes();
        }
        // refresh_token_revoked_on_use?: the old token is not revoked now but when the new one is first used
        [$token, $plain, $plainNewRefresh] = $this->createAccessToken($refresh->applicationId, $refresh->resourceOwnerId, $scopes, $refresh->expiresIn, (string) $refresh->refreshToken);

        return $this->tokenResponse($token, $plain, $plainNewRefresh);
    }

    public static function codeVerifierValid(OauthAccessGrant $grant, ?string $verifier): bool
    {
        if (null === $verifier || '' === $verifier) {
            return null === $grant->codeChallenge || '' === $grant->codeChallenge;
        }

        return match ($grant->codeChallengeMethod) {
            'S256' => hash_equals((string) $grant->codeChallenge, self::codeChallenge($verifier)),
            'plain' => hash_equals((string) $grant->codeChallenge, $verifier),
            default => false,
        };
    }

    /** AccessGrant.generate_code_challenge: base64url(SHA256(verifier)) without padding */
    public static function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * AccessToken.create_for (with use_refresh_token).
     *
     * @param list<string> $scopes
     *
     * @return array{0: OauthAccessToken, 1: string, 2: string}
     */
    public function createAccessToken(?int $applicationId, ?int $ownerId, array $scopes, ?int $expiresIn, string $previousRefreshToken = ''): array
    {
        $plain = self::generateToken();
        $plainRefresh = self::generateToken();
        $token = new OauthAccessToken();
        $token->applicationId = $applicationId;
        $token->resourceOwnerId = $ownerId;
        $token->scopes = implode(' ', $scopes);
        $token->expiresIn = $expiresIn;
        $token->token = self::hashToken($plain);
        $token->refreshToken = self::hashToken($plainRefresh);
        $token->previousRefreshToken = $previousRefreshToken;
        $this->em->persist($token);
        $this->em->flush();

        return [$token, $plain, $plainRefresh];
    }

    private function tokenResponse(OauthAccessToken $token, string $plain, string $plainRefresh): array
    {
        $body = array_filter([
            'access_token' => $plain,
            'token_type' => 'Bearer',
            'expires_in' => $token->expiresInSeconds(),
            'refresh_token' => $plainRefresh,
            'scope' => $token->scopes,
            'created_at' => $token->createdAt->getTimestamp(),
        ], static fn ($v) => null !== $v && '' !== $v);

        return ['status' => 200, 'body' => $body, 'headers' => ['Cache-Control' => 'no-store, no-cache', 'Pragma' => 'no-cache']];
    }

    private function tokenError(string $error, ?string $state = null, ?string $missingParam = null): array
    {
        $body = $this->errorBody($error, $state, $missingParam);

        return ['status' => self::errorStatus($error), 'body' => $body, 'headers' => $this->errorHeaders($error, $body['error_description'])];
    }

    // --- Revocation (TokensController#revoke) ----------------------------------------------

    /** @return array{status: int, body: array<string, mixed>} */
    public function revoke(Request $request): array
    {
        $client = $this->client($request);
        $unauthorized = ['error' => 'unauthorized_client', 'error_description' => $this->i18n->l('doorkeeper.errors.messages.revoke.unauthorized')];
        if (null === $client) {
            return ['status' => 403, 'body' => $unauthorized];
        }
        $plain = $request->request->get('token', $request->query->get('token'));
        $plain = \is_scalar($plain) ? (string) $plain : '';
        $repo = $this->em->getRepository(OauthAccessToken::class);
        $hash = self::hashToken($plain);
        $byRefresh = static fn () => $repo->findOneBy(['refreshToken' => $hash]);
        if ('refresh_token' === $request->request->get('token_type_hint', $request->query->get('token_type_hint'))) {
            $token = '' === $plain ? null : $byRefresh();
            $isRefresh = true;
        } else {
            $token = '' === $plain ? null : $repo->findOneBy(['token' => $hash]);
            $isRefresh = false;
            if (null === $token && '' !== $plain) {
                $token = $byRefresh();
                $isRefresh = true;
            }
        }
        if (null === $token) {
            return ['status' => 200, 'body' => []];
        }
        if (null !== $token->applicationId) {
            $app = $this->em->find(OauthApplication::class, $token->applicationId);
            if (null !== $app && $app->confidential && $app->id !== $client->id) {
                return ['status' => 403, 'body' => $unauthorized];
            }
        }
        if ($isRefresh ? !$token->isRevoked() : $token->isAccessible()) {
            $token->revokedAt = new \DateTimeImmutable();
            $this->em->flush();
        }

        return ['status' => 200, 'body' => []];
    }

    // --- Bearer authentication ---------------------------------------------------------

    /** OAuth::Token.from_request: Authorization: Bearer header, access_token or bearer_token parameter */
    public static function bearerToken(Request $request): ?string
    {
        $auth = (string) $request->headers->get('Authorization');
        if (preg_match('/^Bearer /i', $auth)) {
            $t = (string) preg_replace('/^Bearer /i', '', $auth);

            return '' === $t ? null : $t;
        }
        foreach (['access_token', 'bearer_token'] as $k) {
            $v = $request->query->get($k) ?? $request->request->get($k);
            if (\is_string($v) && '' !== $v) {
                return $v;
            }
        }

        return null;
    }

    /** OAuth::Token.authenticate: finds the token and revokes the previous refresh token on first use */
    public function authenticate(string $plain): ?OauthAccessToken
    {
        $token = $this->em->getRepository(OauthAccessToken::class)->findOneBy(['token' => self::hashToken($plain)]);
        if (null !== $token && '' !== $token->previousRefreshToken) {
            $this->em->getConnection()->executeStatement(
                'UPDATE oauth_access_tokens SET revoked_at = ? WHERE refresh_token = ? AND revoked_at IS NULL',
                [gmdate('Y-m-d H:i:s'), $token->previousRefreshToken]
            );
            $token->previousRefreshToken = '';
            $this->em->flush();
        }

        return $token;
    }

    /** InvalidTokenResponse.from_access_token reason */
    public static function invalidTokenReason(?OauthAccessToken $token): string
    {
        return match (true) {
            null !== $token && $token->isRevoked() => 'revoked',
            null !== $token && $token->isExpired() => 'expired',
            default => 'unknown',
        };
    }

    // --- Authorized applications -------------------------------------------------------

    /** @return list<OauthApplication> Application.authorized_for */
    public function authorizedApplications(User $user): array
    {
        $ids = $this->em->getConnection()->fetchFirstColumn('SELECT DISTINCT application_id FROM oauth_access_tokens WHERE resource_owner_id = ? AND revoked_at IS NULL AND application_id IS NOT NULL', [$user->id]);
        if ([] === $ids) {
            return [];
        }

        return $this->em->getRepository(OauthApplication::class)->findBy(['id' => array_map('intval', $ids)], ['id' => 'ASC']);
    }

    /** Application.revoke_tokens_and_grants_for */
    public function revokeTokensAndGrantsFor(int $applicationId, User $user): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE oauth_access_tokens SET revoked_at = ? WHERE application_id = ? AND resource_owner_id = ? AND revoked_at IS NULL', [$now, $applicationId, $user->id]);
        $conn->executeStatement('UPDATE oauth_access_grants SET revoked_at = ? WHERE application_id = ? AND resource_owner_id = ? AND revoked_at IS NULL', [$now, $applicationId, $user->id]);
    }

    public function destroyApplication(OauthApplication $app): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM oauth_access_grants WHERE application_id = ?', [$app->id]);
        $conn->executeStatement('DELETE FROM oauth_access_tokens WHERE application_id = ?', [$app->id]);
        $this->em->remove($app);
        $this->em->flush();
    }
}
