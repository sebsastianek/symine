<?php

declare(strict_types=1);

namespace App\Tests\Api;

/**
 * Port of api_test/authentication_test.rb, disabled_rest_api_test.rb, api_test.rb and jsonp_test.rb.
 */
final class AuthenticationApiTest extends ApiTestCase
{
    public function testDenyWithoutCredentials(): void
    {
        $this->request('GET', '/users/current.xml', null, null);
        $this->assertStatus(401);
        self::assertTrue($this->response()->headers->has('WWW-Authenticate'));
        $this->request('GET', '/users/current.json', null, null);
        $this->assertStatus(401);
    }

    public function testHttpBasicWithLoginAndPassword(): void
    {
        $xml = $this->xml('GET', '/users/current.xml', null, null, self::basic('jsmith'));
        $this->assertXmlResponse();
        self::assertSame('jsmith', (string) $xml->login);
    }

    public function testHttpBasicWithWrongPassword(): void
    {
        $this->request('GET', '/users/current.xml', null, null, self::basic('jsmith', 'wrong'));
        $this->assertStatus(401);
        self::assertTrue($this->response()->headers->has('WWW-Authenticate'));
    }

    public function testHttpBasicDeniedWhenTwofaIsActive(): void
    {
        $this->conn()->executeStatement("UPDATE users SET twofa_scheme = 'totp' WHERE login = 'jsmith'");
        $this->request('GET', '/users/current.xml', null, null, self::basic('jsmith'));
        $this->assertStatus(401);
        // the API key still works
        $this->request('GET', '/users/current.xml', null, 'jsmith');
        $this->assertStatus(200);
    }

    public function testHttpBasicWithApiKeyAsUsername(): void
    {
        $data = $this->json('GET', '/users/current.json', null, null, self::basic(self::JSMITH_KEY, 'X'));
        $this->assertStatus(200);
        self::assertSame(self::JSMITH, $data['user']['id']);
    }

    public function testHttpBasicWithWrongTokenAction(): void
    {
        $this->conn()->executeStatement("INSERT INTO tokens (user_id, action, value, created_on, updated_on) VALUES (5, 'feeds', 'feedsfeedsfeedsfeedsfeedsfeedsfeedsfeeds', datetime('now'), datetime('now'))");
        $this->request('GET', '/users/current.xml', null, null, self::basic('feedsfeedsfeedsfeedsfeedsfeedsfeedsfeeds', 'X'));
        $this->assertStatus(401);
        self::assertTrue($this->response()->headers->has('WWW-Authenticate'));
    }

    public function testApiKeyAsParameter(): void
    {
        $data = $this->json('GET', '/users/current.json?key='.self::JSMITH_KEY, null, null);
        $this->assertStatus(200);
        self::assertSame('jsmith', $data['user']['login']);
        $xml = $this->xml('GET', '/users/current.xml?key='.self::JSMITH_KEY, null, null);
        self::assertSame('5', (string) $xml->id);
    }

    public function testWrongApiKeyAsParameterOrHeader(): void
    {
        $this->conn()->executeStatement("INSERT INTO tokens (user_id, action, value, created_on, updated_on) VALUES (5, 'feeds', 'feedsfeedsfeedsfeedsfeedsfeedsfeedsfeeds', datetime('now'), datetime('now'))");
        $this->request('GET', '/users/current.xml?key=feedsfeedsfeedsfeedsfeedsfeedsfeedsfeeds', null, null);
        $this->assertStatus(401);
        self::assertFalse($this->response()->headers->has('WWW-Authenticate'));
        $this->request('GET', '/users/current.xml', null, null, ['HTTP_X_REDMINE_API_KEY' => 'feedsfeedsfeedsfeedsfeedsfeedsfeedsfeeds']);
        $this->assertStatus(401);
        self::assertFalse($this->response()->headers->has('WWW-Authenticate'));
        $this->request('GET', '/users/current.json', null, null, ['HTTP_X_REDMINE_API_KEY' => 'nope']);
        $this->assertStatus(401);
    }

    /** Redmine ignores invalid credentials: public resources are served to the anonymous user */
    public function testInvalidCredentialsOnPublicResourceAreAnonymous(): void
    {
        $data = $this->json('GET', '/projects.json?key=wrong', null, null);
        $this->assertStatus(200);
        self::assertSame([1, 3], self::ids($data['projects']));
        $data = $this->json('GET', '/projects.json', null, null, self::basic('jsmith', 'wrong'));
        $this->assertStatus(200);
        self::assertSame([1, 3], self::ids($data['projects']));
        $this->json('GET', '/issues/18.json?key=wrong', null, null);
        $this->assertStatus(401);
    }

    public function testApiKeyAsHeader(): void
    {
        $data = $this->json('GET', '/users/current.json', null, 'jsmith');
        $this->assertJsonResponse();
        self::assertSame('jsmith', $data['user']['login']);
    }

    public function testLockedUserKeyIsRejected(): void
    {
        $key = $this->key('locked');
        $this->request('GET', '/users/current.json?key='.$key, null, null);
        $this->assertStatus(401);
        $this->request('GET', '/users/current.json', null, null, self::basic('locked'));
        $this->assertStatus(401);
    }

    public function testNonBasicAuthorizationHeaderIsAnonymous(): void
    {
        $this->request('GET', '/users/current.xml', null, null, ['HTTP_AUTHORIZATION' => 'Digest foo bar']);
        $this->assertStatus(401);
    }

    public function testInvalidUtf8CredentialsDoNotRaise(): void
    {
        $this->request('GET', '/users/current.xml', null, null, self::basic("\x82", 'foo'));
        $this->assertStatus(401);
    }

    public function testApiRequestDoesNotUseTheSession(): void
    {
        $user = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->find(\App\Entity\User::class, self::JSMITH);
        $user->hashedPassword = password_hash('password123', \PASSWORD_BCRYPT, ['cost' => 4]);
        $user->salt = null;
        static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->flush();
        $this->client->loginUser($user, 'main');
        $this->client->request('GET', '/my/account');
        $this->assertStatus(200);
        $this->client->request('GET', '/users/current.json');
        $this->assertStatus(401);
        // private data is not readable with the session cookie through the API (nor through JSONP)
        $this->setting('jsonp_enabled', '1');
        $this->client->request('GET', '/issues/18.json?callback=steal');
        $this->assertStatus(401);
        $this->client->request('GET', '/issues.json?callback=steal');
        $this->assertStatus(200);
        self::assertStringNotContainsString('Internal tool crash', $this->body());
        // the browser session is still valid
        $this->client->request('GET', '/issues/18');
        $this->assertStatus(200);
    }

    public function testAdminCanSwitchUser(): void
    {
        $data = $this->json('GET', '/users/current.json', null, 'admin', ['HTTP_X_REDMINE_SWITCH_USER' => 'anna']);
        $this->assertStatus(200);
        self::assertSame('anna', $data['user']['login']);
        // the switched user's permissions apply: anna (non member) cannot see the private issue of checkout
        $this->json('GET', '/issues/17.json', null, 'admin', ['HTTP_X_REDMINE_SWITCH_USER' => 'anna']);
        $this->assertStatus(403);
        $this->json('GET', '/issues/17.json', null, 'admin');
        $this->assertStatus(200);
    }

    public function testSwitchToUnknownOrLockedUserIs412(): void
    {
        $this->request('GET', '/users/current.json', null, 'admin', ['HTTP_X_REDMINE_SWITCH_USER' => 'foobar']);
        $this->assertStatus(412);
        $this->request('GET', '/users/current.json', null, 'admin', ['HTTP_X_REDMINE_SWITCH_USER' => 'locked']);
        $this->assertStatus(412);
    }

    public function testNonAdminCannotSwitchUser(): void
    {
        $data = $this->json('GET', '/users/current.json', null, 'jsmith', ['HTTP_X_REDMINE_SWITCH_USER' => 'rhill']);
        $this->assertStatus(200);
        self::assertSame('jsmith', $data['user']['login']);
    }

    public function testApiKeyUsageUpdatesTokenUpdatedOn(): void
    {
        $this->conn()->executeStatement("UPDATE tokens SET updated_on = '2020-01-01 00:00:00' WHERE action = 'api' AND user_id = 5");
        $this->request('GET', '/users/current.json', null, 'jsmith');
        $this->assertStatus(200);
        self::assertGreaterThan('2020-01-01 00:00:00', (string) $this->dbOne("SELECT updated_on FROM tokens WHERE action = 'api' AND user_id = 5"));

        $this->conn()->executeStatement("UPDATE tokens SET updated_on = '2020-01-01 00:00:00' WHERE action = 'api' AND user_id = 5");
        $this->request('GET', '/users/current.json?key='.self::JSMITH_KEY, null, null);
        self::assertGreaterThan('2020-01-01 00:00:00', (string) $this->dbOne("SELECT updated_on FROM tokens WHERE action = 'api' AND user_id = 5"));

        $this->conn()->executeStatement("UPDATE tokens SET updated_on = '2020-01-01 00:00:00' WHERE action = 'api' AND user_id = 5");
        $this->request('GET', '/users/current.json', null, null, self::basic(self::JSMITH_KEY, 'X'));
        self::assertGreaterThan('2020-01-01 00:00:00', (string) $this->dbOne("SELECT updated_on FROM tokens WHERE action = 'api' AND user_id = 5"));
    }

    public function testFailedAuthDoesNotUpdateTokens(): void
    {
        $this->conn()->executeStatement("UPDATE tokens SET updated_on = '2020-01-01 00:00:00' WHERE action = 'api'");
        $this->request('GET', '/users/current.json', null, null, ['HTTP_X_REDMINE_API_KEY' => 'wrong_key']);
        $this->assertStatus(401);
        self::assertStringStartsWith('2020-01-01 00:00:00', (string) $this->dbOne("SELECT updated_on FROM tokens WHERE action = 'api' AND user_id = 5"));
    }

    /** disabled_rest_api_test.rb: every credential type gets 403 when the REST API is disabled */
    public function testDisabledRestApiReturns403(): void
    {
        $this->setting('rest_api_enabled', '0');
        // login not required: credentials are ignored, public data is served to the anonymous user
        $data = $this->json('GET', '/projects.json', null, 'jsmith');
        $this->assertStatus(200);
        self::assertSame([1, 3], self::ids($data['projects']));
        $this->request('GET', '/issues/18.json', null, 'jsmith');
        $this->assertStatus(403);
        $this->setting('login_required', '1');
        foreach (['/news.xml', '/news.json'] as $url) {
            $this->request('GET', $url.'?key='.self::JSMITH_KEY, null, null);
            $this->assertStatus(403, "key param $url");
            $this->request('GET', $url, null, null, self::basic('jsmith'));
            $this->assertStatus(403, "basic login $url");
            $this->request('GET', $url, null, null, self::basic(self::JSMITH_KEY, 'X'));
            $this->assertStatus(403, "basic token $url");
            $this->request('GET', $url, null, 'jsmith');
            $this->assertStatus(403, "header $url");
        }
    }

    /** api_test.rb: forgery protection does not apply to API requests */
    public function testApiWorksWithForgeryProtection(): void
    {
        $before = $this->rows('users');
        $this->request('POST', '/users.xml', '<user><login>foo</login><firstname>Firstname</firstname><lastname>Lastname</lastname><mail>foo@example.net</mail><password>secret123</password></user>', 'admin');
        $this->assertStatus(201);
        self::assertSame($before + 1, $this->rows('users'));
    }

    public function testDatetimeFormat(): void
    {
        $this->request('GET', '/users/5.json', null, 'admin');
        self::assertMatchesRegularExpression('/"created_on":"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ"/', $this->body());
        $this->request('GET', '/users/5.xml', null, 'admin');
        self::assertMatchesRegularExpression('#<created_on>\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ</created_on>#', $this->body());
    }

    public function testNoContentResponsesHaveAnEmptyBody(): void
    {
        $this->request('PUT', '/users/7.xml', '<user><firstname>Bob</firstname></user>', 'admin');
        $this->assertStatus(204);
        self::assertSame('', $this->body());
    }

    public function testJsonpCallback(): void
    {
        $this->setting('jsonp_enabled', '1');
        $this->request('GET', '/trackers.json?callback=handler', null, 'jsmith');
        $this->assertStatus(200);
        self::assertMatchesRegularExpression('/^handler\(\{"trackers":.+\}\)$/', $this->body());
        self::assertSame('application/javascript; charset=utf-8', $this->response()->headers->get('Content-Type'));
        $this->request('GET', '/trackers.json?jsonp=handler', null, 'jsmith');
        self::assertStringStartsWith('handler({"trackers":', $this->body());
        $this->request('GET', '/trackers.json?callback=%2B-aA$1_.', null, 'jsmith');
        self::assertStringStartsWith('aA1_.({"trackers":', $this->body());
        $this->request('GET', '/trackers.json?callback=', null, 'jsmith');
        self::assertStringStartsWith('{"trackers":', $this->body());
    }

    public function testJsonpCallbackIgnoredWhenDisabled(): void
    {
        $this->setting('jsonp_enabled', '0');
        $this->request('GET', '/trackers.json?jsonp=handler', null, 'jsmith');
        $this->assertStatus(200);
        self::assertStringStartsWith('{"trackers":', $this->body());
        self::assertStringStartsWith('application/json', (string) $this->response()->headers->get('Content-Type'));
    }

    public function testAnonymousReadsPublicData(): void
    {
        $data = $this->json('GET', '/projects.json', null, null);
        $this->assertStatus(200);
        self::assertSame([1, 3], self::ids($data['projects']));
    }

    public function testAnonymousOnLoginRequiredIs401(): void
    {
        $this->setting('login_required', '1');
        $this->request('GET', '/projects.json', null, null);
        $this->assertStatus(401);
        $this->request('GET', '/projects.json', null, 'jsmith');
        $this->assertStatus(200);
    }

    /** OAuth2 access token of jsmith with the given scopes (Doorkeeper: hashed token secret) */
    private function bearerToken(string $scopes, ?string $revokedAt = null, ?int $expiresIn = null, string $createdAt = 'now'): string
    {
        $conn = $this->conn();
        if (false === $conn->fetchOne('SELECT id FROM oauth_applications WHERE uid = ?', ['test-app'])) {
            $conn->executeStatement("INSERT INTO oauth_applications (name, uid, secret, redirect_uri, scopes, confidential, created_at, updated_at) VALUES ('Test', 'test-app', 'x', 'urn:ietf:wg:oauth:2.0:oob', ?, 1, datetime('now'), datetime('now'))", [$scopes]);
        }
        $appId = $conn->fetchOne('SELECT id FROM oauth_applications WHERE uid = ?', ['test-app']);
        $plain = bin2hex(random_bytes(16));
        $conn->executeStatement('INSERT INTO oauth_access_tokens (resource_owner_id, application_id, token, expires_in, revoked_at, created_at, scopes) VALUES (5, ?, ?, ?, ?, ?, ?)',
            [$appId, hash('sha256', $plain), $expiresIn, $revokedAt, gmdate('Y-m-d H:i:s', strtotime($createdAt)), $scopes]);

        return $plain;
    }

    public function testBearerTokenAuthenticatesWithinItsScopes(): void
    {
        $token = $this->bearerToken('view_project view_issues');
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
        $data = $this->json('GET', '/users/current.json', null, null, $auth);
        $this->assertStatus(200);
        self::assertSame('jsmith', $data['user']['login']);
        // jsmith sees the private project through the token
        $data = $this->json('GET', '/issues/18.json', null, null, $auth);
        $this->assertStatus(200);
        self::assertSame('Internal tool crash', $data['issue']['subject']);
        // a permission outside of the scopes is denied
        $this->request('GET', '/time_entries.json', null, null, $auth);
        $this->assertStatus(403);
        // access_token parameter
        $this->request('GET', '/issues/18.json?access_token='.$token, null, null);
        $this->assertStatus(200);
        // writes need their scope too
        $this->request('POST', '/projects/ecommerce/issues.json', ['issue' => ['tracker_id' => 1, 'subject' => 'via bearer']], null, $auth);
        self::assertGreaterThanOrEqual(403, $this->response()->getStatusCode());
        self::assertSame(0, $this->rows('issues', "subject = 'via bearer'"));
    }

    public function testRevokedOrExpiredBearerTokenIs401(): void
    {
        foreach (['revoked' => $this->bearerToken('view_project view_issues', gmdate('Y-m-d H:i:s', time() - 60)),
            'expired' => $this->bearerToken('view_project view_issues', null, 60, '-2 hours')] as $reason => $token) {
            $this->request('GET', '/issues.json', null, null, ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
            $this->assertStatus(401, $reason);
            self::assertStringContainsString('error="invalid_token"', (string) $this->response()->headers->get('WWW-Authenticate'), $reason);
        }
    }

    public function testUnknownBearerTokenIsAnonymous(): void
    {
        $data = $this->json('GET', '/projects.json', null, null, ['HTTP_AUTHORIZATION' => 'Bearer unknown']);
        $this->assertStatus(200);
        self::assertSame([1, 3], self::ids($data['projects']));
        $this->request('GET', '/issues/18.json', null, null, ['HTTP_AUTHORIZATION' => 'Bearer unknown']);
        $this->assertStatus(401);
    }

    public function testBearerTokenIgnoredWhenRestApiIsDisabled(): void
    {
        $token = $this->bearerToken('view_project view_issues');
        $this->setting('rest_api_enabled', '0');
        $this->request('GET', '/issues/18.json', null, null, ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $this->assertStatus(403);
    }
}
