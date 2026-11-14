<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\KeyAuthScope;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Routing\RouterInterface;

/**
 * Scope of the key based authentication (ApplicationController#find_current_user):
 *
 * - the Atom feeds key only authenticates GET requests answered as Atom by the actions Redmine declares with
 *   accept_atom_auth; it is never a login for another page, format or verb, and it never exempts a request
 *   from the CSRF check;
 * - API credentials only authenticate the actions Redmine declares with accept_api_auth.
 *
 * Every project is made private and "login required" is switched on, so that an unauthenticated request can
 * never answer 200: a 200 proves that the key authenticated the request.
 */
final class FeedsKeyScopeTest extends SecurityWebTestCase
{
    private const string FEEDS_KEY = 'feedfeedfeedfeedfeedfeedfeedfeedfeedfeed';
    private const string ADMIN_FEEDS_KEY = 'adadadadadadadadadadadadadadadadadadadad';
    private const string API_KEY = '3de6a93a7c5f8921fa98e0fecd9ae25695aa7536'; // jsmith

    protected function setUp(): void
    {
        parent::setUp();
        $c = $this->conn();
        $c->executeStatement("DELETE FROM tokens WHERE action = 'feeds'");
        foreach (['jsmith' => self::FEEDS_KEY, 'admin' => self::ADMIN_FEEDS_KEY] as $login => $key) {
            $c->executeStatement("INSERT INTO tokens (user_id, action, value, created_on, updated_on) SELECT id, 'feeds', ?, datetime('now'), datetime('now') FROM users WHERE login = ?", [$key, $login]);
        }
        $c->executeStatement('UPDATE projects SET is_public = 0');
        $this->setting('login_required', '1');
        $this->as(null);
        // the login throttling (failed logins of the IP address, kept in a cache pool shared by the test runs)
        // also applies to the autologin cookie: start from a clean state
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('cache.rate_limiter')->clear();
        parent::tearDown();
    }

    private function request(string $method, string $url, array $params = [], array $server = []): int
    {
        $this->client->request($method, $url, $params, [], $server);

        return $this->code();
    }

    private function contentType(): string
    {
        return (string) $this->client->getResponse()->headers->get('Content-Type');
    }

    /** Rows whose change would prove that a request was executed as an authenticated user */
    private function writes(): string
    {
        $c = $this->conn();

        return json_encode([
            $c->fetchOne('SELECT COUNT(*) FROM journals'),
            $c->fetchOne('SELECT COUNT(*) FROM issues'),
            $c->fetchOne('SELECT COUNT(*) FROM projects'),
            $c->fetchOne('SELECT COUNT(*) FROM members'),
            $c->fetchOne('SELECT COUNT(*) FROM watchers'),
            $c->fetchOne('SELECT COUNT(*) FROM time_entries'),
            $c->fetchOne("SELECT GROUP_CONCAT(value) FROM (SELECT value FROM tokens WHERE action IN ('api', 'feeds') ORDER BY id)"),
            $c->fetchOne("SELECT GROUP_CONCAT(firstname || hashed_password) FROM (SELECT firstname, hashed_password FROM users ORDER BY id)"),
            $c->fetchOne('SELECT subject FROM issues WHERE id = 18'),
        ]);
    }

    // --- the legitimate feeds keep working ---------------------------------------------------------

    /** @return iterable<string, array{0: string}> */
    public static function feeds(): iterable
    {
        $urls = [
            '/activity.atom',
            '/projects/ecommerce/activity.atom',
            '/projects/internal/activity.atom',
            '/projects.atom',
            '/issues.atom',
            '/projects/internal/issues.atom',
            '/issues/18.atom',
            '/issues/changes.atom',
            '/news.atom',
            '/projects/ecommerce/news.atom',
            '/time_entries.atom',
            '/projects/ecommerce/time_entries.atom',
            '/projects/ecommerce/boards.atom',
            '/projects/ecommerce/boards/1.atom',
            // Rails style: the format given as a parameter
            '/projects/internal/activity?format=atom',
            '/projects/internal/issues?format=atom',
            '/issues/18?format=atom',
            '/projects?format=atom',
        ];
        foreach ($urls as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('feeds')]
    public function testFeedsKeyAuthenticatesTheAtomFeed(string $url): void
    {
        $sep = str_contains($url, '?') ? '&' : '?';

        self::assertNotSame(200, $this->request('GET', $url), 'anonymous request must not be served');
        self::assertNotSame(200, $this->request('GET', $url.$sep.'key=0000000000000000000000000000000000000000'), 'unknown key');

        self::assertSame(200, $this->request('GET', $url.$sep.'key='.self::FEEDS_KEY), 'feeds key of jsmith');
        self::assertStringContainsString('application/atom+xml', $this->contentType());
        self::assertStringContainsString('<feed', $this->body());
    }

    public function testFeedContainsThePrivateDataOfTheKeyOwner(): void
    {
        $this->request('GET', '/projects/internal/issues.atom?key='.self::FEEDS_KEY);
        self::assertStringContainsString('Internal tool crash', $this->body());

        // anna is not a member of the private project
        $this->conn()->executeStatement("INSERT INTO tokens (user_id, action, value, created_on, updated_on) SELECT id, 'feeds', ?, datetime('now'), datetime('now') FROM users WHERE login = 'anna'", [str_repeat('9', 40)]);
        self::assertNotSame(200, $this->request('GET', '/projects/internal/issues.atom?key='.str_repeat('9', 40)));
    }

    public function testFeedsKeyOfALockedUserIsRefused(): void
    {
        $this->conn()->executeStatement("UPDATE users SET status = 3 WHERE login = 'jsmith'");
        self::assertNotSame(200, $this->request('GET', '/projects/internal/activity.atom?key='.self::FEEDS_KEY));
    }

    // --- the feeds key is not a login -----------------------------------------------------------------

    /** @return iterable<string, array{0: string}> every route shape that is not an Atom feed */
    public static function notFeeds(): iterable
    {
        $urls = [
            // HTML pages, with and without a (fake) format
            '/my/page?key=%s',
            '/my/account?key=%s',
            '/my/api_key?key=%s',
            '/my/api_key?format=atom&key=%s',
            '/my/api_key.atom?key=%s',
            '/my/account?format=atom&key=%s',
            '/projects/internal?key=%s',
            '/projects/internal?format=atom&key=%s',
            '/projects/internal.atom?key=%s',
            '/projects/internal/settings?format=atom&key=%s',
            '/projects/internal/settings.atom?key=%s',
            '/projects/internal/activity?key=%s',
            '/projects/internal/issues?key=%s',
            '/issues/18?key=%s',
            '/issues/18/edit?format=atom&key=%s',
            '/projects/internal/wiki?format=atom&key=%s',
            '/projects/internal/wiki.atom?key=%s',
            '/projects/internal/roadmap?format=atom&key=%s',
            '/admin?format=atom&key=%s',
            '/users?format=atom&key=%s',
            '/settings?format=atom&key=%s',
            '/search?q=internal&format=atom&key=%s',
            // other formats of the actions that have a feed
            '/issues.csv?key=%s',
            '/issues.pdf?key=%s',
            '/issues/18.pdf?key=%s',
            '/projects.csv?key=%s',
            '/time_entries.csv?key=%s',
            '/issues?format=csv&key=%s',
            // REST API
            '/issues.json?key=%s',
            '/issues/18.json?key=%s',
            '/issues/18.xml?key=%s',
            '/projects/internal.json?key=%s',
            '/projects.xml?key=%s',
            '/users/current.json?key=%s',
            '/my/account.json?key=%s',
            '/users.json?key=%s',
            '/projects/internal/settings?format=json&key=%s',
            '/my/api_key?format=json&key=%s',
            // files
            '/attachments/download/1/notes.txt?key=%s',
            '/attachments/1?key=%s',
            '/attachments/1.json?key=%s',
        ];
        foreach ($urls as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('notFeeds')]
    public function testFeedsKeyDoesNotAuthenticateOtherRoutes(string $url): void
    {
        foreach ([self::FEEDS_KEY, self::ADMIN_FEEDS_KEY] as $key) {
            $status = $this->request('GET', \sprintf($url, $key));
            self::assertContains($status, [302, 401, 403, 404, 406], "GET $url answered $status");
            if (302 === $status) {
                self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
            }
            self::assertStringNotContainsString(self::API_KEY, $this->body());
        }
        // also as a header (the header is an API credential, the feeds key is not an API key)
        $status = $this->request('GET', str_replace(['?key=%s', '&key=%s'], '', $url), [], ['HTTP_X_REDMINE_API_KEY' => self::ADMIN_FEEDS_KEY]);
        self::assertContains($status, [302, 401, 403, 404, 406], "GET $url with the key as header answered $status");
    }

    /** @return iterable<string, array{0: string, 1: string, 2: array}> */
    public static function nonGetRequests(): iterable
    {
        $cases = [
            ['POST', '/issues/18?format=atom&key=%s', ['_method' => 'patch', 'issue' => ['notes' => 'written with the feeds key', 'subject' => 'hacked']]],
            ['PATCH', '/issues/18?format=atom&key=%s', ['issue' => ['notes' => 'written with the feeds key']]],
            ['PUT', '/issues/18.atom?key=%s', ['issue' => ['notes' => 'written with the feeds key']]],
            ['POST', '/issues/18.atom?key=%s', ['_method' => 'patch', 'issue' => ['notes' => 'written with the feeds key']]],
            ['DELETE', '/issues/18?format=atom&key=%s', []],
            ['DELETE', '/issues/18.atom?key=%s', []],
            ['POST', '/projects/internal/issues?format=atom&key=%s', ['issue' => ['tracker_id' => '1', 'subject' => 'feeds key']]],
            ['POST', '/projects/internal/issues.atom?key=%s', ['issue' => ['tracker_id' => '1', 'subject' => 'feeds key']]],
            ['POST', '/projects/internal/activity.atom?key=%s', []],
            ['POST', '/projects?format=atom&key=%s', ['project' => ['name' => 'Feeds', 'identifier' => 'feeds']]],
            ['POST', '/projects.atom?key=%s', ['project' => ['name' => 'Feeds', 'identifier' => 'feeds']]],
            ['POST', '/my/api_key?format=atom&key=%s', []],
            ['POST', '/my/atom_key?format=atom&key=%s', []],
            ['POST', '/my/account?format=atom&key=%s', ['user' => ['firstname' => 'Hacked']]],
            ['POST', '/my/password?format=atom&key=%s', ['password' => 'password123', 'new_password' => 'hacked12345', 'new_password_confirmation' => 'hacked12345']],
            ['POST', '/projects/internal/memberships?format=atom&key=%s', ['membership' => ['user_ids' => ['9'], 'role_ids' => ['5']]]],
            ['POST', '/watchers/watch?format=atom&key=%s', ['object_type' => 'issue', 'object_id' => '18']],
            ['POST', '/projects/internal/time_entries?format=atom&key=%s', ['time_entry' => ['hours' => '1', 'activity_id' => '8', 'spent_on' => '2026-01-01']]],
            // the feeds key is not an API key either
            ['PUT', '/issues/18.json?key=%s', ['issue' => ['notes' => 'written with the feeds key']]],
            ['POST', '/projects.json?key=%s', ['project' => ['name' => 'Feeds', 'identifier' => 'feeds']]],
            ['DELETE', '/issues/18.xml?key=%s', []],
            ['POST', '/issues/18?format=json&key=%s', ['_method' => 'patch', 'issue' => ['notes' => 'written with the feeds key']]],
        ];
        foreach ($cases as [$method, $url, $params]) {
            yield "$method $url" => [$method, $url, $params];
        }
    }

    #[DataProvider('nonGetRequests')]
    public function testFeedsKeyNeverAuthenticatesANonGetRequest(string $method, string $url, array $params): void
    {
        $before = $this->writes();
        foreach ([self::FEEDS_KEY, self::ADMIN_FEEDS_KEY] as $key) {
            $status = $this->request($method, \sprintf($url, $key), $params);
            self::assertContains($status, [302, 401, 403, 404, 405, 422], "$method $url answered $status");
            self::assertSame($before, $this->writes(), "$method $url must not write anything");
        }
    }

    #[DataProvider('nonGetRequests')]
    public function testKeyInTheUrlDoesNotExemptABrowserSessionFromTheCsrfCheck(string $method, string $url, array $params): void
    {
        if (str_contains($url, 'json') || str_contains($url, 'xml')) {
            // API requests never use the session: covered by testFeedsKeyNeverAuthenticatesANonGetRequest
            $this->expectNotToPerformAssertions();

            return;
        }
        // a logged-in browser is sent to a forged URL carrying a key: without the authenticity token of the
        // session the request is rejected like any other
        $this->setting('login_required', '0');
        $this->as('jsmith');
        $before = $this->writes();
        $status = $this->request($method, \sprintf($url, self::FEEDS_KEY), $params);
        self::assertContains($status, [404, 405, 422], "$method $url answered $status");
        self::assertSame($before, $this->writes());
    }

    // --- find_current_user: session, then autologin cookie, then feeds key, then API credentials -------------

    public function testBrowserSessionIsNotDisturbedByAKeyParameter(): void
    {
        // a browser session reading a page with a "key" parameter keeps its session (the parameter is ignored)
        $this->as('jsmith');
        self::assertSame(200, $this->request('GET', '/my/page?key=whatever'));
        self::assertSame(200, $this->request('GET', '/projects/internal/settings?format=atom&key=whatever'));
    }

    public function testFeedWithAnInvalidKeyFallsBackToTheBrowserSession(): void
    {
        $this->as('jsmith');
        foreach (['/projects/internal/activity.atom?key=0000000000000000000000000000000000000000', '/projects/internal/issues.atom?key=', '/issues/18.atom?key=bogus', '/projects/internal/activity?format=atom&key=bogus'] as $url) {
            self::assertSame(200, $this->request('GET', $url), $url);
            self::assertStringContainsString('application/atom+xml', $this->contentType());
        }
        $this->request('GET', '/projects/internal/issues.atom?key=bogus');
        self::assertStringContainsString('Internal tool crash', $this->body());
        // the request did not log the browser out
        self::assertSame(200, $this->request('GET', '/my/page'));
    }

    public function testBrowserSessionTakesPrecedenceOverAValidFeedsKey(): void
    {
        // session[:user_id] is looked at first: anna's browser stays anna, whatever the key in the URL
        $this->as('anna');
        self::assertSame(403, $this->request('GET', '/projects/internal/activity.atom?key='.self::FEEDS_KEY));
        self::assertSame(403, $this->request('GET', '/projects/internal/issues.atom?key='.self::ADMIN_FEEDS_KEY));
        self::assertSame(200, $this->request('GET', '/issues.atom?key='.self::ADMIN_FEEDS_KEY));
        self::assertStringNotContainsString('Internal tool crash', $this->body());
        // the same for API credentials on a browser format
        self::assertSame(200, $this->request('GET', '/issues.csv?set_filter=1&key='.self::API_KEY));
        self::assertStringNotContainsString('Internal tool crash', $this->body());
        self::assertSame(403, $this->request('GET', '/projects/internal', [], ['HTTP_X_REDMINE_API_KEY' => self::API_KEY]));
        // API requests never use the session: the key decides
        self::assertSame(200, $this->request('GET', '/projects/internal.json?key='.self::API_KEY));
        self::assertContains($this->request('GET', '/projects/internal.json'), [401, 403]);
    }

    public function testFeedsKeyIsUsedWhenTheBrowserSessionHasNoUser(): void
    {
        // an anonymous browser session (cookie without a logged-in user)
        $this->request('GET', '/login');
        self::assertNotEmpty($this->client->getCookieJar()->all(), 'a session cookie exists');
        self::assertSame(200, $this->request('GET', '/projects/internal/activity.atom?key='.self::FEEDS_KEY));
        self::assertStringContainsString('application/atom+xml', $this->contentType());
        // the key did not start a session for the user ("ATOM key authentication does not start a session")
        self::assertSame(302, $this->request('GET', '/my/page'));
    }

    public function testInactiveSessionUserIsNotReplacedByTheFeedsKeyButByApiCredentials(): void
    {
        // session[:user_id] of a user who was locked meanwhile: no user from the session, the autologin cookie
        // and the feeds key are not tried (elsif chain), API credentials are (accept_api_auth)
        $this->as('anna');
        self::assertSame(200, $this->request('GET', '/my/page'));
        $this->conn()->executeStatement("UPDATE users SET status = 3 WHERE login = 'anna'");
        self::assertNotSame(200, $this->request('GET', '/projects/internal/activity.atom?key='.self::FEEDS_KEY));
        self::assertSame(200, $this->request('GET', '/projects/internal/issues.atom?key='.self::API_KEY), 'issues#index accepts API credentials');
        self::assertStringContainsString('Internal tool crash', $this->body());
    }

    public function testAutologinCookieTakesPrecedenceOverTheFeedsKey(): void
    {
        $this->setting('autologin', '7');
        $value = str_repeat('7', 40);
        $this->conn()->executeStatement("INSERT INTO tokens (user_id, action, value, created_on, updated_on) SELECT id, 'autologin', ?, datetime('now'), datetime('now') FROM users WHERE login = 'anna'", [$value]);
        $cookie = ':'.strtr(base64_encode('anna'), '+/=', '-_~').':'.(time() + 3600).':'.$value;
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('autologin', $cookie));
        // anna is logged in by her cookie (try_to_autologin): the key of jsmith is not used
        self::assertSame(403, $this->request('GET', '/projects/internal/activity.atom?key='.self::FEEDS_KEY));
        self::assertSame(200, $this->request('GET', '/my/page'), 'the autologin started a session');

        // an autologin cookie that is not accepted does not prevent the key from being used
        $this->as(null);
        $this->setting('autologin', '0');
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('autologin', $cookie));
        self::assertSame(200, $this->request('GET', '/projects/internal/activity.atom?key='.self::FEEDS_KEY));
    }

    // --- API key: accept_api_auth ---------------------------------------------------------------------

    public function testApiKeyIsAcceptedByTheApiActions(): void
    {
        $header = ['HTTP_X_REDMINE_API_KEY' => self::API_KEY];
        foreach (['/projects/internal.json', '/issues/18.json', '/issues.xml', '/my/account.json', '/users/current.json', '/projects/internal/memberships.json', '/projects/internal/versions.json', '/search.json?q=internal'] as $url) {
            self::assertSame(200, $this->request('GET', $url, [], $header), $url);
            self::assertSame(200, $this->request('GET', $url.(str_contains($url, '?') ? '&' : '?').'key='.self::API_KEY), $url.' (key parameter)');
        }
        self::assertSame(200, $this->request('GET', '/projects/internal.json', [], ['PHP_AUTH_USER' => 'jsmith', 'PHP_AUTH_PW' => 'password123', 'HTTP_AUTHORIZATION' => 'Basic '.base64_encode('jsmith:password123')]));

        // write through the API
        $this->client->request('PUT', '/issues/18.json', [], [], $header + ['CONTENT_TYPE' => 'application/json'], json_encode(['issue' => ['notes' => 'API note']]));
        self::assertContains($this->code(), [200, 204]);
        self::assertSame(1, (int) $this->dbOne("SELECT COUNT(*) FROM journals WHERE journalized_id = 18 AND notes = 'API note'"));
    }

    public function testApiKeyIsAcceptedInOtherFormatsOfTheApiActions(): void
    {
        // accept_api_auth does not depend on the format: exports and downloads of the same actions
        self::assertSame(200, $this->request('GET', '/issues.csv?project_id=2&set_filter=1&key='.self::API_KEY));
        self::assertStringContainsString('Internal tool crash', $this->body());
        self::assertSame(200, $this->request('GET', '/issues.atom?key='.self::API_KEY), 'issues#index accepts both keys');
        self::assertSame(200, $this->request('GET', '/attachments/download/1/notes.txt', [], ['HTTP_X_REDMINE_API_KEY' => self::API_KEY]));
        self::assertSame(200, $this->request('GET', '/projects/internal', [], ['HTTP_X_REDMINE_API_KEY' => self::API_KEY]));
    }

    /** @return iterable<string, array{0: string}> actions without accept_api_auth */
    public static function notApiActions(): iterable
    {
        $urls = [
            '/my/page',
            '/my/api_key',
            '/my/api_key?format=json',
            '/my/password',
            '/projects/internal/settings',
            '/projects/internal/settings?format=json',
            '/projects/internal/settings.json',
            '/projects/internal/activity',
            '/projects/internal/activity.atom',
            '/issues/18/edit',
            '/issues/18/edit?format=json',
            '/admin',
            '/admin?format=json',
            '/settings',
            '/projects/internal/issues/report',
            '/projects/internal/issues/gantt',
        ];
        foreach ($urls as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('notApiActions')]
    public function testApiKeyDoesNotAuthenticateOtherActions(string $url): void
    {
        $adminKey = str_repeat('c', 40);
        $this->conn()->executeStatement("DELETE FROM tokens WHERE action = 'api' AND user_id = 1");
        $this->conn()->executeStatement("INSERT INTO tokens (user_id, action, value, created_on, updated_on) VALUES (1, 'api', ?, datetime('now'), datetime('now'))", [$adminKey]);
        $sep = str_contains($url, '?') ? '&' : '?';
        foreach ([self::API_KEY, $adminKey] as $key) {
            foreach ([[$url.$sep.'key='.$key, []], [$url, ['HTTP_X_REDMINE_API_KEY' => $key]], [$url, ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode($key.':x'), 'PHP_AUTH_USER' => $key, 'PHP_AUTH_PW' => 'x']]] as [$u, $server]) {
                $status = $this->request('GET', $u, [], $server);
                self::assertContains($status, [302, 401, 403, 404, 406], "GET $u answered $status");
            }
        }
    }

    public function testApiKeyDoesNotExemptABrowserFormatRequestFromTheCsrfCheck(): void
    {
        $before = $this->writes();
        foreach (['/issues/18?key=', '/issues/18.html?key=', '/projects/internal/memberships?key='] as $url) {
            $status = $this->request('POST', $url.self::API_KEY, ['_method' => 'patch', 'issue' => ['notes' => 'no token'], 'membership' => ['user_ids' => ['9'], 'role_ids' => ['5']]]);
            self::assertContains($status, [404, 405, 422], $url);
        }
        $this->request('POST', '/issues/18', ['_method' => 'patch', 'issue' => ['notes' => 'no token']], ['HTTP_X_REDMINE_API_KEY' => self::API_KEY]);
        self::assertSame(422, $this->code());
        self::assertSame($before, $this->writes());
    }

    public function testApiCredentialsAreIgnoredWhenTheRestApiIsDisabled(): void
    {
        $this->setting('rest_api_enabled', '0');
        self::assertContains($this->request('GET', '/projects/internal.json?key='.self::API_KEY), [401, 403]);
        self::assertSame(302, $this->request('GET', '/issues.csv?key='.self::API_KEY));
        self::assertSame(302, $this->request('GET', '/attachments/download/1/notes.txt', [], ['HTTP_X_REDMINE_API_KEY' => self::API_KEY]));
        // the feeds are not part of the REST API
        self::assertSame(200, $this->request('GET', '/projects/internal/activity.atom?key='.self::FEEDS_KEY));
    }

    // --- "format" parameter ---------------------------------------------------------------------------

    public function testFormatParameterSelectsTheRepresentation(): void
    {
        $this->setting('login_required', '0');
        $this->conn()->executeStatement('UPDATE projects SET is_public = 1 WHERE id = 1');
        self::assertSame(200, $this->request('GET', '/projects?format=json'));
        self::assertStringContainsString('application/json', $this->contentType());
        self::assertArrayHasKey('projects', json_decode($this->body(), true));
        self::assertSame(200, $this->request('GET', '/projects?format=xml'));
        self::assertStringContainsString('xml', $this->contentType());
        self::assertSame(200, $this->request('GET', '/projects?format=csv'));
        self::assertStringContainsString('text/csv', $this->contentType());
        self::assertSame(200, $this->request('GET', '/projects/ecommerce/activity?format=atom'));
        self::assertStringContainsString('application/atom+xml', $this->contentType());
        self::assertSame(200, $this->request('GET', '/projects/ecommerce/issues?format=atom&set_filter=1'));
        self::assertStringContainsString('application/atom+xml', $this->contentType());
        // unknown representation of the action: the page is served as usual
        self::assertSame(200, $this->request('GET', '/projects/ecommerce?format=atom'));
        self::assertStringContainsString('text/html', $this->contentType());
        // an API request never uses the session, whatever the way the format is given
        $this->as('jsmith');
        self::assertSame(200, $this->request('GET', '/projects/internal'));
        self::assertContains($this->request('GET', '/projects/internal?format=json'), [401, 403, 404]);
        self::assertContains($this->request('GET', '/projects/internal.json'), [401, 403, 404]);
    }

    // --- the declarations ---------------------------------------------------------------------------

    public function testDeclaredActionsExist(): void
    {
        foreach (KeyAuthScope::actions() as $kind => $controllers) {
            foreach ($controllers as $controller => $methods) {
                foreach ($methods as $method) {
                    self::assertTrue(method_exists('App\\Controller\\'.$controller, $method), "$kind: $controller::$method");
                }
            }
        }
    }

    /** Every Atom / API route belongs to an action declared in KeyAuthScope (accept_atom_auth / accept_api_auth) */
    public function testEveryFeedAndApiRouteIsDeclared(): void
    {
        $actions = KeyAuthScope::actions();
        $declared = static function (string $kind, string $controller) use ($actions): bool {
            [$class, $method] = explode('::', str_replace('App\\Controller\\', '', $controller), 2);

            return \in_array($method, $actions[$kind][$class] ?? [], true);
        };
        $router = static::getContainer()->get(RouterInterface::class);
        $checked = 0;
        foreach ($router->getRouteCollection() as $name => $route) {
            $controller = (string) $route->getDefault('_controller');
            if (!str_starts_with($controller, 'App\\Controller\\') || str_starts_with($route->getPath(), '/sys/') || '/up.{_format}' === $route->getPath()) {
                continue;
            }
            $formats = str_contains($route->getPath(), '{_format}') ? explode('|', (string) $route->getRequirement('_format')) : [];
            if (preg_match('/\.(json|xml|atom)$/', $route->getPath(), $m)) {
                $formats[] = $m[1];
            }
            if (\in_array('atom', $formats, true)) {
                self::assertTrue($declared('atom', $controller), "$name ($controller) has an Atom representation but does not accept the feeds key");
                ++$checked;
            }
            if ([] !== array_intersect(['json', 'xml'], $formats)) {
                self::assertTrue($declared('api', $controller), "$name ($controller) has an API representation but does not accept the API key");
                ++$checked;
            }
        }
        self::assertGreaterThan(100, $checked);
    }
}
