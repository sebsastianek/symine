<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * protect_from_forgery: every state-changing browser request needs the session's authenticity token
 * (form field "_token" or X-CSRF-Token header). Without it (or with a wrong one) the request is rejected
 * with 422 and nothing is written to the database; with it, the same request succeeds.
 */
final class CsrfTest extends SecurityWebTestCase
{
    /** @return iterable<string, array{0: string, 1: string, 2: array, 3: string}> */
    public static function stateChangingRequests(): iterable
    {
        $cases = [
            // issues
            ['POST', '/projects/ecommerce/issues', ['issue' => ['tracker_id' => '1', 'subject' => 'csrf']], 'jsmith'],
            ['PATCH', '/issues/13', ['issue' => ['notes' => 'csrf note']], 'jsmith'],
            ['DELETE', '/issues/16', [], 'admin'],
            ['POST', '/issues/bulk_update', ['ids' => ['15', '16'], 'issue' => ['priority_id' => '4']], 'jsmith'],
            ['DELETE', '/issues', ['ids' => ['15', '16']], 'admin'],
            ['POST', '/issues/14/relations', ['relation' => ['issue_to_id' => '19', 'relation_type' => 'relates']], 'jsmith'],
            ['POST', '/watchers/watch', ['object_type' => 'issue', 'object_id' => '14'], 'dlopper'],
            ['POST', '/watchers', ['object_type' => 'issue', 'object_id' => '14', 'watcher' => ['user_ids' => ['6']]], 'jsmith'],
            ['DELETE', '/attachments/1', [], 'admin'],
            ['POST', '/projects/ecommerce/issue_categories', ['issue_category' => ['name' => 'Csrf']], 'jsmith'],
            ['DELETE', '/issue_categories/2', [], 'jsmith'],
            ['POST', '/projects/ecommerce/queries', ['query' => ['name' => 'Csrf'], 'f' => ['status_id'], 'op' => ['status_id' => 'o']], 'jsmith'],
            ['DELETE', '/queries/1', [], 'admin'],
            // versions, time entries
            ['POST', '/projects/ecommerce/versions', ['version' => ['name' => 'Csrf']], 'jsmith'],
            ['PATCH', '/versions/1', ['version' => ['name' => '1.0 csrf']], 'jsmith'],
            ['POST', '/projects/ecommerce/time_entries', ['time_entry' => ['hours' => '1', 'activity_id' => '8', 'spent_on' => '2026-01-01']], 'jsmith'],
            ['DELETE', '/time_entries/2', [], 'admin'],
            // content
            ['POST', '/projects/ecommerce/news', ['news' => ['title' => 'Csrf', 'description' => 'd']], 'jsmith'],
            ['POST', '/news/1/comments', ['comment' => ['comments' => 'csrf']], 'jsmith'],
            ['DELETE', '/news/1', [], 'admin'],
            ['PUT', '/projects/ecommerce/wiki/Wiki', ['content' => ['text' => 'csrf text', 'version' => '1']], 'jsmith'],
            ['DELETE', '/projects/ecommerce/wiki/Checkout_flow', [], 'admin'],
            ['POST', '/boards/1/topics/new', ['message' => ['subject' => 'csrf', 'content' => 'c']], 'jsmith'],
            ['POST', '/projects/ecommerce/documents', ['document' => ['title' => 'Csrf', 'category_id' => '6']], 'admin'],
            // projects
            ['POST', '/projects', ['project' => ['name' => 'Csrf', 'identifier' => 'csrf']], 'admin'],
            ['PATCH', '/projects/ecommerce', ['project' => ['name' => 'eCommerce csrf']], 'jsmith'],
            ['POST', '/projects/checkout/archive', [], 'admin'],
            ['DELETE', '/projects/checkout', ['confirm' => 'checkout'], 'admin'],
            ['POST', '/projects/ecommerce/memberships', ['membership' => ['user_ids' => ['9'], 'role_ids' => ['5']]], 'jsmith'],
            // administration
            ['POST', '/users', ['user' => ['login' => 'csrf', 'firstname' => 'C', 'lastname' => 'S', 'mail' => 'csrf@example.net', 'password' => 'secret1234', 'password_confirmation' => 'secret1234']], 'admin'],
            ['PATCH', '/users/7', ['user' => ['firstname' => 'Csrf']], 'admin'],
            ['DELETE', '/users/9', ['confirm' => 'anna'], 'admin'],
            ['POST', '/groups', ['group' => ['name' => 'Csrf']], 'admin'],
            ['POST', '/roles', ['role' => ['name' => 'Csrf']], 'admin'],
            ['POST', '/trackers', ['tracker' => ['name' => 'Csrf', 'default_status_id' => '1']], 'admin'],
            ['POST', '/enumerations', ['enumeration' => ['type' => 'IssuePriority', 'name' => 'Csrf']], 'admin'],
            ['POST', '/settings/edit', ['settings' => ['app_title' => 'Csrf']], 'admin'],
            // my account
            ['POST', '/my/account', ['user' => ['firstname' => 'Csrf']], 'jsmith'],
            ['POST', '/my/api_key', [], 'jsmith'],
            ['POST', '/my/add_block', ['block' => 'news'], 'jsmith'],
        ];
        foreach ($cases as [$method, $url, $params, $login]) {
            yield "$method $url" => [$method, $url, $params, $login];
        }
    }

    #[DataProvider('stateChangingRequests')]
    public function testRequestNeedsTheAuthenticityToken(string $method, string $url, array $params, string $login): void
    {
        $this->as($login);
        $token = $this->csrf();
        $before = $this->fingerprint();
        $send = function (?string $tok) use ($method, $url, $params): void {
            $p = $params;
            if ('POST' !== $method) {
                $p['_method'] = $method;
            }
            if (null !== $tok) {
                $p['_token'] = $tok;
            }
            $this->client->request('POST', $url, $p);
        };

        $send(null);
        self::assertSame(422, $this->code(), "$method $url without token");
        self::assertSame('Invalid form authenticity token.', $this->body());
        $send('forged-token');
        self::assertSame(422, $this->code(), "$method $url with a forged token");
        self::assertSame($before, $this->fingerprint(), "$method $url without a valid token changed the database");

        $send($token);
        self::assertNotContains($this->code(), [403, 404, 422, 500], "$method $url with the token: ".mb_substr(strip_tags($this->body()), 0, 600));
        self::assertNotSame($before, $this->fingerprint(), "$method $url with the token did not change anything (invalid scenario?)");
    }

    /** rails-ujs sends the token in the X-CSRF-Token header for XHR requests */
    public function testXhrHeaderToken(): void
    {
        $this->as('dlopper');
        $token = $this->csrf();
        $before = $this->fingerprint();
        $this->client->request('POST', '/watchers/watch', ['object_type' => 'issue', 'object_id' => '14'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'text/javascript']);
        self::assertSame(422, $this->code());
        self::assertSame($before, $this->fingerprint());
        $this->client->request('POST', '/watchers/watch', ['object_type' => 'issue', 'object_id' => '14'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'text/javascript', 'HTTP_X_CSRF_TOKEN' => $token]);
        self::assertSame(200, $this->code());
        self::assertSame(1, (int) $this->dbOne("SELECT COUNT(*) FROM watchers WHERE watchable_type = 'Issue' AND watchable_id = 14 AND user_id = 6"));
    }

    /** The token of one session is not valid for another session */
    public function testTokenIsBoundToTheSession(): void
    {
        $this->as('jsmith');
        $jsmithToken = $this->csrf();
        $this->as('dlopper');
        $before = $this->fingerprint();
        $this->client->request('POST', '/watchers/watch', ['object_type' => 'issue', 'object_id' => '14', '_token' => $jsmithToken]);
        self::assertSame(422, $this->code());
        self::assertSame($before, $this->fingerprint());
    }

    /** GET requests never change data, even with a _method override */
    public function testMethodOverrideIsIgnoredOnGet(): void
    {
        $this->as('admin');
        // viewing pages updates the session tokens and the recently used projects (preferences) only
        $ignore = ['tokens', 'user_preferences'];
        $before = $this->fingerprint($ignore);
        $this->client->request('GET', '/issues/16?_method=delete');
        self::assertSame(200, $this->code());
        $this->client->request('GET', '/projects/checkout?_method=delete&confirm=checkout');
        self::assertSame(200, $this->code());
        self::assertSame($before, $this->fingerprint($ignore));
    }

    /** The session cookie cannot be used to write through the API (json/xml requests ignore the session) */
    public function testSessionCannotWriteThroughTheApi(): void
    {
        $this->as('jsmith');
        // (a first request reboots the test kernel: loginUser() also authenticates the very first request in memory)
        $this->get('/my/page');
        $before = $this->fingerprint();
        $this->client->request('POST', '/projects/ecommerce/issues.json', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['issue' => ['tracker_id' => 1, 'subject' => 'csrf']]));
        self::assertSame(401, $this->code());
        $this->client->request('DELETE', '/issues/16.json');
        self::assertSame(401, $this->code());
        $this->client->request('PUT', '/users/7.json', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['user' => ['firstname' => 'x']]));
        self::assertSame(401, $this->code());
        self::assertSame($before, $this->fingerprint());
    }

    /** Logout is a POST with the token: a forged logout does not end the session */
    public function testLogoutNeedsTheToken(): void
    {
        $this->as('jsmith');
        $this->client->request('POST', '/logout');
        $this->client->request('GET', '/my/page');
        self::assertSame(200, $this->code(), 'still logged in after a logout without token');
        $this->client->request('GET', '/logout');
        self::assertSame(200, $this->code(), 'GET /logout displays the confirmation form');
        $this->get('/my/page');
        self::assertSame(200, $this->code(), 'GET /logout does not log out');
    }

    /** The login form has its own token: a login CSRF (forced login into an attacker account) fails */
    public function testLoginNeedsTheToken(): void
    {
        $this->as(null);
        $this->client->request('POST', '/login', ['username' => 'jsmith', 'password' => 'password123']);
        $this->client->request('GET', '/my/page');
        self::assertSame('login', $this->outcome());
    }
}
