<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\OAuth\OAuthServer;

/**
 * OAuth2 provider (Doorkeeper as configured by Redmine): applications administration, authorization
 * code flow with PKCE, refresh, revocation, token info, authorized applications and scope-restricted
 * API access with Bearer tokens.
 */
final class OAuthTest extends AuthWebTestCase
{
    private const string CB = 'http://localhost:8888/cb';
    private const string VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const string CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    /** Creates an application through the admin form: [id, uid, secret] */
    private function createApp(array $scopes = ['view_issues', 'add_issues'], string $redirect = self::CB."\nurn:ietf:wg:oauth:2.0:oob"): array
    {
        $this->login('admin');
        $params = ['doorkeeper_application' => ['name' => 'Test client', 'redirect_uri' => $redirect, 'scopes' => [...$scopes, '']]];
        self::assertSame(302, $this->post('/oauth/applications', $params, '/oauth/applications/new'));
        $id = (int) basename($this->location());
        $this->client->followRedirect();
        preg_match('#UID:</span>\s*<code>([^<]+)</code>#', $this->content(), $uid);
        preg_match('#Secret:</span>\s*<code>\s*(\S+)\s*</code>#', $this->content(), $secret);

        return [$id, $uid[1], $secret[1]];
    }

    /** Runs the consent step as $login and returns the issued code */
    private function authorize(string $uid, string $login = 'jsmith', array $extra = []): string
    {
        $this->login($login);
        $params = $extra + ['client_id' => $uid, 'redirect_uri' => self::CB, 'response_type' => 'code', 'scope' => 'view_project view_issues', 'state' => 'st4te', 'code_challenge' => self::CHALLENGE, 'code_challenge_method' => 'S256'];
        $this->client->request('GET', '/oauth/authorize?'.http_build_query($params));
        if (200 === $this->code()) {
            $params['_token'] = $this->csrfFrom();
            $this->client->request('POST', '/oauth/authorize', $params);
        }
        self::assertSame(302, $this->code(), $this->content());
        self::assertStringStartsWith(self::CB.'?code=', $this->location());
        parse_str((string) parse_url($this->location(), \PHP_URL_QUERY), $q);
        self::assertSame($params['state'], $q['state'] ?? null);

        return $q['code'];
    }

    private function tokenRequest(array $params, array $server = []): array
    {
        $this->client->request('POST', '/oauth/token', $params, [], $server);

        return json_decode($this->content(), true) ?? [];
    }

    private function bearer(string $method, string $url, string $token): int
    {
        $this->em->clear();
        $this->client->request($method, $url, [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        return $this->code();
    }

    // --- Applications administration ----------------------------------------------------------

    public function testApplicationsAdministration(): void
    {
        $this->login('admin');
        $html = $this->get('/admin');
        self::assertStringContainsString('<a class="applications icon icon-applications" href="/oauth/applications">', $html);
        $html = $this->get('/oauth/applications');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('<p class="nodata">', $html);

        $html = $this->get('/oauth/applications/new');
        self::assertStringContainsString('<h2><a href="/oauth/applications">Applications</a> &#187; New Application</h2>', $html);
        self::assertStringContainsString('id="doorkeeper_application_scopes_view_project" value="view_project" disabled="disabled" checked="checked"', $html);
        self::assertStringContainsString('id="doorkeeper_application_scopes_admin" value="admin"', $html);
        self::assertStringContainsString('<legend>Issue tracking</legend>', $html);

        // validation
        $this->post('/oauth/applications', ['doorkeeper_application' => ['name' => '', 'redirect_uri' => 'http://example.com/cb#frag', 'scopes' => ['bogus']]], '/oauth/applications/new');
        self::assertSame(200, $this->code());
        $html = html_entity_decode($this->content(), \ENT_QUOTES);
        self::assertStringContainsString('<li>Name cannot be blank</li>', $html);
        self::assertStringContainsString('<li>Redirect URI cannot contain a fragment.</li>', $html);
        self::assertStringContainsString('<li>Redirect URI must be an HTTPS/SSL URI.</li>', $html);
        self::assertStringContainsString("<li>Scopes doesn't match configured on the server.</li>", $html);
        $this->post('/oauth/applications', ['doorkeeper_application' => ['name' => 'x', 'redirect_uri' => 'javascript:alert(1)']], '/oauth/applications/new');
        self::assertStringContainsString('Redirect URI is forbidden by the server.', html_entity_decode($this->content(), \ENT_QUOTES));
        self::assertSame(0, (int) $this->sql('SELECT COUNT(*) FROM oauth_applications'));

        [$id, $uid, $secret] = $this->createApp();
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM oauth_applications WHERE id = ?', [$id]);
        self::assertSame($uid, $row['uid']);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $uid);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $secret);
        self::assertStringStartsWith('$2y$12$', $row['secret'], 'BCrypt hashed secret');
        self::assertTrue(password_verify($secret, $row['secret']));
        self::assertSame('view_project search_project view_members view_issues add_issues', $row['scopes']);
        self::assertSame(self::CB."\nurn:ietf:wg:oauth:2.0:oob", $row['redirect_uri']);
        self::assertSame(1, (int) $row['confidential']);
        self::assertStringContainsString('Copy the secret to a safe place now', $this->content());
        self::assertStringContainsString('Application created.', $this->content());
        // the secret is shown once
        $html = $this->get('/oauth/applications/'.$id);
        self::assertStringContainsString('Secret hashed', $html);
        self::assertStringNotContainsString($secret, $html);
        self::assertStringContainsString('<code>View projects, Search projects, View project members, View Issues, Add issues</code>', $html);
        self::assertStringContainsString('href="/oauth/authorize?client_id='.$uid, $html);

        $html = $this->get('/oauth/applications');
        self::assertStringContainsString('<td class="description">http://localhost:8888/cb, urn:ietf:wg:oauth:2.0...</td>', $html);

        // update
        $html = $this->get('/oauth/applications/'.$id.'/edit');
        self::assertStringContainsString('id="doorkeeper_application_scopes_add_issues" value="add_issues" checked="checked"', $html);
        $this->post('/oauth/applications/'.$id, ['_method' => 'PATCH', 'doorkeeper_application' => ['name' => 'Renamed', 'redirect_uri' => 'https://app.example.com/cb', 'scopes' => ['admin', '']]], '/oauth/applications/'.$id.'/edit');
        self::assertStringEndsWith('/oauth/applications/'.$id, $this->location());
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM oauth_applications WHERE id = ?', [$id]);
        self::assertSame('Renamed', $row['name']);
        self::assertSame('view_project search_project view_members admin', $row['scopes']);
        self::assertTrue(password_verify($secret, $row['secret']), 'secret unchanged');

        // destroy removes tokens and grants
        $this->em->getConnection()->executeStatement("INSERT INTO oauth_access_tokens (resource_owner_id, application_id, token, created_at, previous_refresh_token) VALUES (5, ?, 'x', datetime('now'), '')", [$id]);
        $this->post('/oauth/applications/'.$id, ['_method' => 'DELETE'], '/oauth/applications');
        self::assertStringEndsWith('/oauth/applications', $this->location());
        self::assertSame(0, (int) $this->sql('SELECT COUNT(*) FROM oauth_applications'));
        self::assertSame(0, (int) $this->sql('SELECT COUNT(*) FROM oauth_access_tokens'));
    }

    public function testApplicationsRequireAdminAndRestApi(): void
    {
        $this->client->request('GET', '/oauth/applications');
        self::assertStringContainsString('/login?back_url=', $this->location());
        $this->login('jsmith');
        $this->client->request('GET', '/oauth/applications');
        self::assertSame(403, $this->code());
        $this->login('admin');
        $this->setting('rest_api_enabled', '0');
        $this->client->request('GET', '/oauth/applications');
        self::assertSame(403, $this->code());
        self::assertStringNotContainsString('href="/oauth/applications"', $this->get('/admin'));
    }

    // --- Authorization code flow ------------------------------------------------------------------

    public function testAuthorizationCodeFlowWithPkce(): void
    {
        [, $uid, $secret] = $this->createApp();
        $this->login('jsmith');
        $query = http_build_query(['client_id' => $uid, 'redirect_uri' => self::CB, 'response_type' => 'code', 'scope' => 'view_project view_issues add_issues', 'state' => 'xyz', 'code_challenge' => self::CHALLENGE, 'code_challenge_method' => 'S256']);
        $html = $this->get('/oauth/authorize?'.$query);
        self::assertSame(200, $this->code());
        self::assertStringContainsString('<h2>Authorization required</h2>', $html);
        self::assertStringContainsString('<p>Authorize <strong class="text-info">Test client</strong> to use your account?</p>', $html);
        self::assertStringContainsString('<li>View your name, login and primary email address</li>', $html);
        self::assertStringContainsString('<li>Add issues</li>', $html);
        self::assertStringContainsString('<input type="hidden" name="code_challenge" id="code_challenge" value="'.self::CHALLENGE.'"', $html);
        self::assertStringContainsString('<input type="hidden" name="_method" value="delete"', $html);
        self::assertStringContainsString('controller-authorizations action-new', $html);

        $this->client->request('POST', '/oauth/authorize', ['client_id' => $uid, 'redirect_uri' => self::CB, 'response_type' => 'code', 'scope' => 'view_project view_issues add_issues', 'state' => 'xyz', 'code_challenge' => self::CHALLENGE, 'code_challenge_method' => 'S256', '_token' => $this->csrfFrom()]);
        self::assertSame(302, $this->code());
        parse_str((string) parse_url($this->location(), \PHP_URL_QUERY), $q);
        self::assertSame('xyz', $q['state']);
        $code = $q['code'];
        $grant = $this->em->getConnection()->fetchAssociative('SELECT * FROM oauth_access_grants');
        self::assertSame(hash('sha256', $code), $grant['token'], 'codes are stored as SHA256 digests');
        self::assertSame('view_project view_issues add_issues', $grant['scopes']);
        self::assertSame('S256', $grant['code_challenge_method']);
        self::assertSame(600, (int) $grant['expires_in']);
        self::assertSame(5, (int) $grant['resource_owner_id']);

        $this->client->getCookieJar()->clear();
        $base = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CB];
        // missing code_verifier
        $d = $this->tokenRequest($base + ['client_id' => $uid, 'client_secret' => $secret]);
        self::assertSame(400, $this->code());
        self::assertSame(['error' => 'invalid_request', 'error_description' => 'Missing required parameter: code_verifier.'], $d);
        self::assertSame('Bearer realm="Redmine", error="invalid_request", error_description="Missing required parameter: code_verifier."', $this->client->getResponse()->headers->get('WWW-Authenticate'));
        // wrong verifier, wrong secret, wrong redirect uri
        $this->tokenRequest($base + ['code_verifier' => 'wrong', 'client_id' => $uid, 'client_secret' => $secret]);
        self::assertSame('invalid_grant', json_decode($this->content(), true)['error']);
        $this->tokenRequest($base + ['code_verifier' => self::VERIFIER, 'client_id' => $uid, 'client_secret' => 'nope']);
        self::assertSame(401, $this->code());
        self::assertSame('invalid_client', json_decode($this->content(), true)['error']);
        $this->tokenRequest(['redirect_uri' => 'http://localhost:8888/other'] + $base + ['code_verifier' => self::VERIFIER, 'client_id' => $uid, 'client_secret' => $secret]);
        self::assertSame('invalid_grant', json_decode($this->content(), true)['error']);

        // HTTP basic client authentication
        $d = $this->tokenRequest($base + ['code_verifier' => self::VERIFIER], ['PHP_AUTH_USER' => $uid, 'PHP_AUTH_PW' => $secret, 'HTTP_AUTHORIZATION' => 'Basic '.base64_encode($uid.':'.$secret)]);
        self::assertSame(200, $this->code(), $this->content());
        self::assertSame(['access_token', 'token_type', 'expires_in', 'refresh_token', 'scope', 'created_at'], array_keys($d));
        self::assertSame('Bearer', $d['token_type']);
        self::assertSame(7200, $d['expires_in']);
        self::assertSame('view_project view_issues add_issues', $d['scope']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM oauth_access_tokens');
        self::assertSame(hash('sha256', $d['access_token']), $row['token']);
        self::assertSame(hash('sha256', $d['refresh_token']), $row['refresh_token']);
        self::assertSame(5, (int) $row['resource_owner_id']);
        self::assertNotNull($this->sql('SELECT revoked_at FROM oauth_access_grants'));

        // the code cannot be reused
        $this->tokenRequest($base + ['code_verifier' => self::VERIFIER, 'client_id' => $uid, 'client_secret' => $secret]);
        self::assertSame(400, $this->code());
        self::assertSame('invalid_grant', json_decode($this->content(), true)['error']);

        // token info
        $this->client->request('GET', '/oauth/token/info', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$d['access_token']]);
        $info = json_decode($this->content(), true);
        self::assertSame(5, $info['resource_owner_id']);
        self::assertSame(['view_project', 'view_issues', 'add_issues'], $info['scope']);
        self::assertSame(['uid' => $uid], $info['application']);
        $this->client->request('GET', '/oauth/token/info', [], [], ['HTTP_AUTHORIZATION' => 'Bearer unknown']);
        self::assertSame(401, $this->code());
        self::assertSame(['error' => 'invalid_token', 'error_description' => 'The access token is invalid', 'state' => 'unauthorized'], json_decode($this->content(), true));
    }

    public function testPlainPkceAndExpiredCode(): void
    {
        [, $uid, $secret] = $this->createApp();
        $code = $this->authorize($uid, 'jsmith', ['code_challenge' => 'plain-verifier-0123456789-0123456789-0123456789', 'code_challenge_method' => 'plain']);
        $this->em->getConnection()->executeStatement("UPDATE oauth_access_grants SET created_at = datetime('now', '-11 minutes')");
        $base = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CB, 'client_id' => $uid, 'client_secret' => $secret, 'code_verifier' => 'plain-verifier-0123456789-0123456789-0123456789'];
        $this->tokenRequest($base);
        self::assertSame('invalid_grant', json_decode($this->content(), true)['error'], 'expired code');
        $this->em->getConnection()->executeStatement("UPDATE oauth_access_grants SET created_at = datetime('now')");
        $this->em->clear();
        $d = $this->tokenRequest($base);
        self::assertSame(200, $this->code(), $this->content());
        self::assertArrayHasKey('access_token', $d);
    }

    public function testAuthorizationErrorsDenyAndOob(): void
    {
        [, $uid] = $this->createApp();
        $this->login('jsmith');
        $cases = [
            [['client_id' => 'unknown', 'redirect_uri' => self::CB, 'response_type' => 'code'], 401, 'Client authentication failed due to unknown client'],
            [['redirect_uri' => self::CB, 'response_type' => 'code'], 400, 'Missing required parameter: client_id.'],
            [['client_id' => $uid, 'redirect_uri' => 'http://evil.example/cb', 'response_type' => 'code'], 400, 'The requested redirect uri is malformed or doesn&#039;t match client redirect URI.'],
            [['client_id' => $uid, 'redirect_uri' => self::CB], 400, 'Missing required parameter: response_type.'],
            [['client_id' => $uid, 'redirect_uri' => self::CB, 'response_type' => 'token'], 400, 'The authorization server does not support this response type.'],
            [['client_id' => $uid, 'redirect_uri' => self::CB, 'response_type' => 'code', 'scope' => 'admin'], 400, 'The requested scope is invalid, unknown, or malformed.'],
            [['client_id' => $uid, 'redirect_uri' => self::CB, 'response_type' => 'code', 'code_challenge' => 'abc'], 400, 'The code_challenge_method must be one of plain, S256.'],
        ];
        foreach ($cases as [$params, $status, $message]) {
            $html = $this->get('/oauth/authorize?'.http_build_query($params));
            self::assertSame($status, $this->code(), $message);
            self::assertStringContainsString('<h2>An error has occurred</h2>', $html);
            self::assertStringContainsString('<p id="errorExplanation">'.$message, $html);
        }
        // default scopes allowed by the application
        $html = $this->get('/oauth/authorize?'.http_build_query(['client_id' => $uid, 'redirect_uri' => self::CB, 'response_type' => 'code']));
        self::assertStringContainsString('<input type="hidden" name="scope" id="scope" value="view_project search_project view_members"', $html);

        // deny
        $csrf = $this->csrfFrom();
        $this->client->request('POST', '/oauth/authorize', ['_method' => 'DELETE', 'client_id' => $uid, 'redirect_uri' => self::CB, 'response_type' => 'code', 'scope' => 'view_project', 'state' => 's1', '_token' => $csrf]);
        self::assertSame(302, $this->code());
        self::assertSame(self::CB.'?error=access_denied&error_description=The+resource+owner+or+authorization+server+denied+the+request.&state=s1', $this->location());
        self::assertSame(0, (int) $this->sql('SELECT COUNT(*) FROM oauth_access_grants'));

        // out of band
        $this->client->request('POST', '/oauth/authorize', ['client_id' => $uid, 'redirect_uri' => 'urn:ietf:wg:oauth:2.0:oob', 'response_type' => 'code', 'scope' => 'view_project', '_token' => $csrf]);
        self::assertSame(302, $this->code());
        self::assertMatchesRegularExpression('#^http://localhost/oauth/authorize/native\?code=[A-Za-z0-9_-]{43}$#', $this->location());
        $code = substr($this->location(), \strlen('http://localhost/oauth/authorize/native?code='));
        $html = $this->get('/oauth/authorize/native?code='.$code);
        self::assertStringContainsString('<label>Authorization code:</label>', $html);
        self::assertStringContainsString('<code>'.$code.'</code>', $html);

        // the consent requires login and the REST API
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/oauth/authorize?client_id='.$uid);
        self::assertStringContainsString('/login?back_url=', $this->location());
        $this->login('jsmith');
        $this->setting('rest_api_enabled', '0');
        $this->client->request('GET', '/oauth/authorize?client_id='.$uid);
        self::assertSame(403, $this->code());
    }

    public function testMatchingTokenSkipsConsent(): void
    {
        [, $uid, $secret] = $this->createApp();
        $code = $this->authorize($uid);
        $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CB, 'code_verifier' => self::VERIFIER, 'client_id' => $uid, 'client_secret' => $secret]);
        self::assertSame(200, $this->code());
        // same scopes: redirected without consent page
        $this->client->request('GET', '/oauth/authorize?'.http_build_query(['client_id' => $uid, 'redirect_uri' => self::CB, 'response_type' => 'code', 'scope' => 'view_issues view_project', 'state' => 'again']));
        self::assertSame(302, $this->code());
        self::assertStringStartsWith(self::CB.'?code=', $this->location());
        // other scopes: consent required
        $this->client->request('GET', '/oauth/authorize?'.http_build_query(['client_id' => $uid, 'redirect_uri' => self::CB, 'response_type' => 'code', 'scope' => 'view_issues']));
        self::assertSame(200, $this->code());
    }

    // --- Refresh / revoke ------------------------------------------------------------------------

    public function testRefreshAndRevoke(): void
    {
        [, $uid, $secret] = $this->createApp();
        $code = $this->authorize($uid);
        $t1 = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CB, 'code_verifier' => self::VERIFIER, 'client_id' => $uid, 'client_secret' => $secret]);

        // grant type errors
        $this->tokenRequest(['client_id' => $uid]);
        self::assertSame(['error' => 'invalid_request', 'error_description' => 'Missing required parameter: grant_type.'], json_decode($this->content(), true));
        $this->tokenRequest(['grant_type' => 'client_credentials', 'client_id' => $uid, 'client_secret' => $secret]);
        self::assertSame('unsupported_grant_type', json_decode($this->content(), true)['error']);
        $this->tokenRequest(['grant_type' => 'password', 'username' => 'jsmith', 'password' => 'password123', 'client_id' => $uid, 'client_secret' => $secret]);
        self::assertSame('unsupported_grant_type', json_decode($this->content(), true)['error']);
        $this->tokenRequest(['grant_type' => 'refresh_token', 'client_id' => $uid, 'client_secret' => $secret]);
        self::assertSame('Missing required parameter: refresh_token.', json_decode($this->content(), true)['error_description']);
        // refresh without client authentication
        $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $t1['refresh_token']]);
        self::assertSame('invalid_grant', json_decode($this->content(), true)['error']);
        // scope broader than the original one
        $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $t1['refresh_token'], 'scope' => 'view_issues add_issues', 'client_id' => $uid, 'client_secret' => $secret]);
        self::assertSame('invalid_scope', json_decode($this->content(), true)['error']);

        $t2 = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $t1['refresh_token'], 'client_id' => $uid, 'client_secret' => $secret]);
        self::assertSame(200, $this->code(), $this->content());
        self::assertNotSame($t1['access_token'], $t2['access_token']);
        self::assertSame('view_project view_issues', $t2['scope']);
        self::assertSame(hash('sha256', $t1['refresh_token']), $this->sql('SELECT previous_refresh_token FROM oauth_access_tokens WHERE token = ?', [hash('sha256', $t2['access_token'])]));
        // the old token works until the new one is used
        self::assertSame(200, $this->bearer('GET', '/issues.json', $t1['access_token']));
        self::assertSame(200, $this->bearer('GET', '/issues.json', $t2['access_token']));
        self::assertSame('', $this->sql('SELECT previous_refresh_token FROM oauth_access_tokens WHERE token = ?', [hash('sha256', $t2['access_token'])]));
        self::assertSame(401, $this->bearer('GET', '/issues.json', $t1['access_token']));
        self::assertSame('Bearer realm="Redmine", error="invalid_token", error_description="The access token was revoked"', $this->client->getResponse()->headers->get('WWW-Authenticate'));
        self::assertSame('', $this->content());
        // the old refresh token cannot be used anymore
        $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $t1['refresh_token'], 'client_id' => $uid, 'client_secret' => $secret]);
        self::assertSame('invalid_grant', json_decode($this->content(), true)['error']);

        // revoke: client authentication required
        $this->client->request('POST', '/oauth/revoke', ['token' => $t2['access_token']]);
        self::assertSame(403, $this->code());
        self::assertSame(['error' => 'unauthorized_client', 'error_description' => 'You are not authorized to revoke this token'], json_decode($this->content(), true));
        $this->client->request('POST', '/oauth/revoke', ['token' => 'unknown', 'client_id' => $uid, 'client_secret' => $secret]);
        self::assertSame(200, $this->code());
        self::assertSame('{}', $this->content());
        $this->client->request('POST', '/oauth/revoke', ['token' => $t2['access_token'], 'client_id' => $uid, 'client_secret' => $secret]);
        self::assertSame(200, $this->code());
        self::assertNotNull($this->sql('SELECT revoked_at FROM oauth_access_tokens WHERE token = ?', [hash('sha256', $t2['access_token'])]));
        self::assertSame(401, $this->bearer('GET', '/oauth/token/info', $t2['access_token']));
        // revoking by refresh token
        $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $t2['refresh_token'], 'client_id' => $uid, 'client_secret' => $secret]);
        self::assertSame('invalid_grant', json_decode($this->content(), true)['error'], 'the refresh token of a revoked token is revoked too');
    }

    public function testExpiredTokenAndUnknownToken(): void
    {
        [, $uid, $secret] = $this->createApp();
        $code = $this->authorize($uid);
        $t = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CB, 'code_verifier' => self::VERIFIER, 'client_id' => $uid, 'client_secret' => $secret]);
        $this->em->getConnection()->executeStatement("UPDATE oauth_access_tokens SET created_at = datetime('now', '-3 hours')");
        self::assertSame(401, $this->bearer('GET', '/issues.json', $t['access_token']));
        self::assertStringContainsString('error_description="The access token expired"', (string) $this->client->getResponse()->headers->get('WWW-Authenticate'));
        // unknown tokens are ignored (anonymous access, like Doorkeeper.authenticate returning nil)
        self::assertSame(200, $this->bearer('GET', '/issues.json', 'unknown'));
        self::assertSame(401, $this->bearer('GET', '/my/account.json', 'unknown'));
        // token of a locked user
        $this->em->getConnection()->executeStatement("UPDATE oauth_access_tokens SET created_at = datetime('now')");
        $this->em->getConnection()->executeStatement('UPDATE users SET status = 3 WHERE id = 5');
        self::assertSame(401, $this->bearer('GET', '/my/account.json', $t['access_token']));
    }

    // --- Scope restricted API access --------------------------------------------------------------

    public function testScopesRestrictApiAccess(): void
    {
        [, $uid, $secret] = $this->createApp(['view_issues', 'view_time_entries', 'add_issues']);
        $code = $this->authorize($uid, 'jsmith', ['scope' => 'view_project view_issues']);
        $t = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CB, 'code_verifier' => self::VERIFIER, 'client_id' => $uid, 'client_secret' => $secret]);
        // the same user with his API key
        $this->api('GET', '/time_entries.json');
        self::assertSame(200, $this->code());
        self::assertSame(200, $this->bearer('GET', '/issues.json', $t['access_token']));
        self::assertSame(403, $this->bearer('GET', '/time_entries.json', $t['access_token']));
        self::assertSame(200, $this->bearer('GET', '/projects.json', $t['access_token']));
        $this->bearer('GET', '/users/current.json', $t['access_token']);
        self::assertSame('jsmith', json_decode($this->content(), true)['user']['login']);
        // access_token parameter
        $this->em->clear();
        $this->client->request('GET', '/issues.json?access_token='.$t['access_token']);
        self::assertSame(200, $this->code());
        // writes need the add_issues scope
        $this->em->clear();
        $this->client->request('POST', '/issues.json', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$t['access_token'], 'CONTENT_TYPE' => 'application/json'], json_encode(['issue' => ['project_id' => 1, 'subject' => 'via oauth', 'tracker_id' => 1]]));
        self::assertGreaterThanOrEqual(403, $this->code());
        self::assertSame(0, (int) $this->sql("SELECT COUNT(*) FROM issues WHERE subject = 'via oauth'"));

        $code = $this->authorize($uid, 'jsmith', ['scope' => 'view_project view_issues add_issues']);
        $t2 = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CB, 'code_verifier' => self::VERIFIER, 'client_id' => $uid, 'client_secret' => $secret]);
        $this->em->clear();
        $this->client->request('POST', '/issues.json', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$t2['access_token'], 'CONTENT_TYPE' => 'application/json'], json_encode(['issue' => ['project_id' => 1, 'subject' => 'via oauth', 'tracker_id' => 1]]));
        self::assertSame(201, $this->code(), $this->content());
        self::assertSame(5, (int) $this->sql("SELECT author_id FROM issues WHERE subject = 'via oauth'"));
    }

    public function testAdminScope(): void
    {
        [, $uid, $secret] = $this->createApp(['view_issues', 'admin']);
        // an administrator authorizing without the admin scope does not act as administrator
        $code = $this->authorize($uid, 'admin', ['scope' => 'view_project view_issues']);
        $t = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CB, 'code_verifier' => self::VERIFIER, 'client_id' => $uid, 'client_secret' => $secret]);
        self::assertSame(403, $this->bearer('GET', '/users.json', $t['access_token']));
        self::assertSame(403, $this->bearer('GET', '/time_entries.json', $t['access_token']));
        $this->bearer('GET', '/users/current.json', $t['access_token']);
        self::assertFalse(json_decode($this->content(), true)['user']['admin']);
        // a request that writes data must not persist the in-memory restriction
        $this->em->clear();
        $this->client->request('PUT', '/my/account.json', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$t['access_token'], 'CONTENT_TYPE' => 'application/json'], json_encode(['user' => ['firstname' => 'Oauth']]));
        self::assertSame(204, $this->code(), $this->content());
        self::assertSame(1, (int) $this->sql("SELECT admin FROM users WHERE login = 'admin'"));
        self::assertSame('Oauth', $this->sql("SELECT firstname FROM users WHERE login = 'admin'"));

        // consent page warns about the admin scope
        $this->login('admin');
        $html = $this->get('/oauth/authorize?'.http_build_query(['client_id' => $uid, 'redirect_uri' => self::CB, 'response_type' => 'code', 'scope' => 'view_project admin']));
        self::assertStringContainsString('<li>Administrate this Redmine</li>', $html);
        self::assertStringContainsString('This application requests full administrative access.', $html);
        $code = $this->authorize($uid, 'admin', ['scope' => 'view_project view_issues admin']);
        $t = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CB, 'code_verifier' => self::VERIFIER, 'client_id' => $uid, 'client_secret' => $secret]);
        self::assertSame(200, $this->bearer('GET', '/users.json', $t['access_token']), $this->content());
        self::assertSame(200, $this->bearer('GET', '/time_entries.json', $t['access_token']));
        // the admin scope of a non administrator gives nothing more
        $code = $this->authorize($uid, 'jsmith', ['scope' => 'view_project admin']);
        $t = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CB, 'code_verifier' => self::VERIFIER, 'client_id' => $uid, 'client_secret' => $secret]);
        self::assertSame(403, $this->bearer('GET', '/users.json', $t['access_token']));
    }

    // --- Authorized applications -----------------------------------------------------------------

    public function testAuthorizedApplications(): void
    {
        [$id, $uid, $secret] = $this->createApp();
        $code = $this->authorize($uid);
        $t = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::CB, 'code_verifier' => self::VERIFIER, 'client_id' => $uid, 'client_secret' => $secret]);
        $this->login('jsmith');
        $html = $this->get('/oauth/authorized_applications');
        self::assertStringContainsString('<h2><a href="/my/account">My account</a> &#187; Authorized applications</h2>', $html);
        self::assertStringContainsString('<tr id="application_'.$id.'" class="odd">', $html);
        self::assertStringContainsString('href="/oauth/authorized_applications/'.$id.'"', $html);
        // not authorized by dlopper
        $this->login('dlopper');
        self::assertStringContainsString('<p class="nodata">', $this->get('/oauth/authorized_applications'));
        $this->post('/oauth/authorized_applications/'.$id, ['_method' => 'DELETE']);
        self::assertSame(200, $this->bearer('GET', '/oauth/token/info', $t['access_token']), 'revoking for another user has no effect');

        $this->login('jsmith');
        $this->post('/oauth/authorized_applications/'.$id, ['_method' => 'DELETE']);
        self::assertStringEndsWith('/oauth/authorized_applications', $this->location());
        self::assertStringContainsString('Application revoked.', $this->flash());
        self::assertStringContainsString('<p class="nodata">', $this->content());
        self::assertSame(401, $this->bearer('GET', '/oauth/token/info', $t['access_token']));
        self::assertSame(0, (int) $this->sql('SELECT COUNT(*) FROM oauth_access_grants WHERE revoked_at IS NULL'));
    }

    // --- Data created by Redmine (migrated) --------------------------------------------------------

    public function testApplicationAndTokensCreatedByRedmine(): void
    {
        // rows created by the reference Redmine (Doorkeeper 5.8, hash_token_secrets + BCrypt secrets)
        $conn = $this->em->getConnection();
        $conn->executeStatement("INSERT INTO oauth_applications (id, name, uid, secret, redirect_uri, scopes, confidential, created_at, updated_at) VALUES (1, 'Ref App', 'fI83J560tXfH3uw4Ba-Zzh0HxCAaUS6eQzAdjIfr3B8', '\$2a\$12\$B.fRmonmyl5jlmXSVTs4Ru1dGXZn3l6Rhm6EZh6z/aHSYjQ8vsrLe', 'http://localhost:8888/cb', 'view_project search_project view_members view_issues add_issues', 1, datetime('now'), datetime('now'))");
        $conn->executeStatement("INSERT INTO oauth_access_tokens (resource_owner_id, application_id, token, refresh_token, expires_in, created_at, scopes, previous_refresh_token) VALUES (5, 1, '694e532bc19dfe0213ec8f6b2803c2bef77c1f0d86e4df5e3ef1f35e5da2676a', 'ea55045148587cbd11adabf9a795d74836ae1b5377284cb2b01b4c5bf3d07abe', 7200, datetime('now'), 'view_project search_project view_members view_issues', '')");
        $conn->executeStatement("INSERT INTO oauth_access_grants (resource_owner_id, application_id, token, expires_in, redirect_uri, created_at, scopes) VALUES (5, 1, '2a13630a8da8f159aab4f92ba04429d7b64e5ffafb120a0569b64328445f0734', 600, 'http://localhost:8888/cb', datetime('now'), 'view_project view_issues')");

        self::assertSame(200, $this->bearer('GET', '/issues.json', 'mPhqujqLTBeCspWzPYzKHk8j_EHAZTK6XIV2vH0WEZ0'));
        $d = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => 'f7KV646G8vuDTCZb70lSZN-ArfezvW4vyD84Bzd6jJ4', 'redirect_uri' => self::CB, 'client_id' => 'fI83J560tXfH3uw4Ba-Zzh0HxCAaUS6eQzAdjIfr3B8', 'client_secret' => 'VPazHTL2SKqEVgPE_nN0kFWa5wcyNFLgZkM-AkR4u5o']);
        self::assertSame(200, $this->code(), $this->content());
        self::assertSame('view_project view_issues', $d['scope']);
        $d = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => 'EjfIHW4gMwuAre6YTe8Rm_fDIJmzd_6TCaYzuEl24H4', 'client_id' => 'fI83J560tXfH3uw4Ba-Zzh0HxCAaUS6eQzAdjIfr3B8', 'client_secret' => 'VPazHTL2SKqEVgPE_nN0kFWa5wcyNFLgZkM-AkR4u5o']);
        self::assertSame(200, $this->code(), $this->content());
        self::assertSame(200, $this->bearer('GET', '/issues.json', $d['access_token']));
    }

    public function testServerHelpers(): void
    {
        self::assertSame(self::CHALLENGE, OAuthServer::codeChallenge(self::VERIFIER));
        self::assertTrue(OAuthServer::uriMatches('http://127.0.0.1:1234/cb', 'http://127.0.0.1/cb'), 'loopback ports are ignored');
        self::assertFalse(OAuthServer::uriMatches('http://example.com:1234/cb', 'http://example.com/cb'));
        self::assertTrue(OAuthServer::uriMatches('https://a.example/cb?b=2&a=1', 'https://a.example/cb?a=1&b=2'));
        self::assertSame(['secured_uri'], OAuthServer::redirectUriErrors('http://example.com/cb'));
        self::assertSame([], OAuthServer::redirectUriErrors('http://localhost:3000/cb'));
        self::assertSame([], OAuthServer::redirectUriErrors('com.example.app:/oauth'));
        self::assertSame(['unspecified_scheme'], OAuthServer::redirectUriErrors('mailto:x@example.com'));
        self::assertSame('https://a.example/cb?x=1&code=abc&state=s', OAuthServer::uriWith('https://a.example/cb?x=1', ['code' => 'abc', 'state' => 's']));
        self::assertContains('view_project', OAuthServer::defaultScopes());
        self::assertNotContains('view_issues', OAuthServer::defaultScopes());
        self::assertContains('admin', OAuthServer::serverScopes());
    }
}
