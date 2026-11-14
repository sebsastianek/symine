<?php

declare(strict_types=1);

namespace App\Tests\Account;

use App\Tests\Timelog\TimelogWebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Account behaviours aligned with Redmine (AccountController, ApplicationController):
 * autologin tokens, password expiry, account status disclosure at login, activation e-mail.
 */
final class AccountParityFixesTest extends TimelogWebTestCase
{
    /**
     * The login throttling counts the failed logins of an IP address in a cache pool that outlives the test
     * (var/share): the failed logins of these tests must not add up with those of other tests and test runs.
     */
    private function resetLoginThrottling(): void
    {
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetLoginThrottling();
    }

    protected function tearDown(): void
    {
        $this->resetLoginThrottling();
        parent::tearDown();
    }

    private function loginForm(string $login, string $password, array $extra = []): void
    {
        $crawler = $this->client->request('GET', '/login');
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/login', ['username' => $login, 'password' => $password, '_csrf_token' => $token] + $extra);
    }

    /** The message of the flash, without the icon Redmine puts in front of it (notice_icon) */
    private function flash(): string
    {
        $crawler = $this->client->request('GET', '/login');
        $node = $crawler->filter('div.flash');
        if (0 === $node->count()) {
            return '';
        }
        self::assertCount(1, $node->first()->filter('svg.icon-svg'), 'a flash starts with its icon');

        return trim((string) preg_replace('~^<svg\b.*?</svg>~s', '', trim($node->first()->html())));
    }

    private function autologinTokens(): int
    {
        return (int) $this->sql("SELECT COUNT(*) FROM tokens WHERE action = 'autologin'");
    }

    // --- autologin (AccountController#set_autologin_cookie, ApplicationController#try_to_autologin) ---

    public function testAutologinIsNotIssuedWhenTheSettingIsDisabled(): void
    {
        $this->setting('autologin', '0');
        $this->loginForm('jsmith', 'password123', ['autologin' => '1']);
        self::assertSame(302, $this->code());
        self::assertNull($this->client->getCookieJar()->get('autologin'));
        self::assertSame(0, $this->autologinTokens());
        $this->client->request('GET', '/my/page');
        self::assertSame(200, $this->code(), 'the login itself succeeded');
    }

    public function testAutologinIsNotIssuedWithoutTheCheckbox(): void
    {
        $this->setting('autologin', '7');
        $this->loginForm('jsmith', 'password123');
        self::assertNull($this->client->getCookieJar()->get('autologin'));
        self::assertSame(0, $this->autologinTokens());
    }

    public function testAutologinTokenLifecycle(): void
    {
        $this->setting('autologin', '7');
        $this->loginForm('jsmith', 'password123', ['autologin' => '1']);
        $cookie = $this->client->getCookieJar()->get('autologin');
        self::assertNotNull($cookie, 'cookie issued');
        self::assertTrue($cookie->isHttpOnly());
        // the cookie refers to a token of the tokens table, like Redmine
        self::assertSame(1, $this->autologinTokens());
        $value = (string) $this->sql("SELECT value FROM tokens WHERE action = 'autologin' AND user_id = 5");
        self::assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', $value);
        self::assertStringContainsString($value, rawurldecode((string) $cookie->getValue()));
        $raw = (string) $cookie->getValue();

        $withCookieOnly = function () use ($raw): int {
            $this->client->getCookieJar()->clear();
            $this->client->getCookieJar()->set(new Cookie('autologin', $raw));
            $this->em->clear();
            $this->client->request('GET', '/my/account');

            return $this->code();
        };
        self::assertSame(200, $withCookieOnly(), 'a new browser session is logged in by the cookie');

        // valid for the configured number of days only
        $this->em->getConnection()->executeStatement("UPDATE tokens SET created_on = datetime('now', '-8 days') WHERE action = 'autologin'");
        self::assertSame(302, $withCookieOnly(), 'token older than the "autologin" setting');
        $this->em->getConnection()->executeStatement("DELETE FROM tokens WHERE action = 'autologin'");
        $this->em->getConnection()->executeStatement("INSERT INTO tokens (user_id, action, value, created_on, updated_on) VALUES (5, 'autologin', ?, datetime('now', '-6 days'), datetime('now', '-6 days'))", [$value]);
        self::assertSame(200, $withCookieOnly(), 'token within the validity period');

        // ignored as soon as the setting is disabled
        $this->setting('autologin', '0');
        self::assertSame(302, $withCookieOnly(), 'setting disabled');
    }

    public function testLogoutDeletesTheAutologinToken(): void
    {
        $this->setting('autologin', '7');
        $this->loginForm('jsmith', 'password123', ['autologin' => '1']);
        $raw = (string) $this->client->getCookieJar()->get('autologin')?->getValue();
        self::assertSame(1, $this->autologinTokens());

        $this->client->request('POST', '/logout', ['_token' => $this->csrf('/my/page')]);
        self::assertSame(302, $this->code());
        self::assertSame(0, $this->autologinTokens(), 'User#delete_autologin_token');
        self::assertNull($this->client->getCookieJar()->get('autologin'));

        // a copy of the cookie is useless after the logout
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new Cookie('autologin', $raw));
        $this->client->request('GET', '/my/account');
        self::assertSame(302, $this->code());
        self::assertStringContainsString('/login', $this->location());
    }

    public function testAutologinTokenOfAnotherUserOrAForgedCookieIsRefused(): void
    {
        $this->setting('autologin', '7');
        $this->loginForm('jsmith', 'password123', ['autologin' => '1']);
        $raw = rawurldecode((string) $this->client->getCookieJar()->get('autologin')?->getValue());
        // same token value, other user name
        $parts = explode(':', $raw);
        $parts[\count($parts) - 3] = strtr(base64_encode('admin'), '+/=', '-_~');
        foreach ([implode(':', $parts), 'garbage', ':'.strtr(base64_encode('admin'), '+/=', '-_~').':'.(time() + 1000).':0000000000000000000000000000000000000000'] as $forged) {
            $this->client->getCookieJar()->clear();
            $this->client->getCookieJar()->set(new Cookie('autologin', $forged));
            $this->client->request('GET', '/admin');
            self::assertSame(302, $this->code(), $forged);
            self::assertStringContainsString('/login', $this->location());
        }
    }

    // --- password expiry (User#must_change_password?, ApplicationController#check_password_change) ---

    public function testExpiredPasswordMustBeChangedAfterLogin(): void
    {
        $this->setting('password_max_age', '7');
        $this->em->getConnection()->executeStatement("UPDATE users SET passwd_changed_on = '2020-01-01 00:00:00' WHERE login = 'dlopper'");
        $this->em->clear();
        $this->login('dlopper');
        foreach (['/my/page', '/projects', '/issues/13'] as $url) {
            $this->client->request('GET', $url);
            self::assertSame(302, $this->code(), $url);
            self::assertStringEndsWith('/my/password', $this->location());
        }
        $html = html_entity_decode($this->get('/my/password'), \ENT_QUOTES);
        self::assertSame(200, $this->code());
        self::assertStringContainsString('Your password has expired or the administrator requires you to change it.', $html);

        // changing the password gives the access back
        $this->submit('/my/password', ['password' => 'password123', 'new_password' => 'NewSecret1234', 'new_password_confirmation' => 'NewSecret1234', '_token' => $this->csrf('/my/password')]);
        self::assertSame(302, $this->code());
        $this->client->request('GET', '/my/page');
        self::assertSame(200, $this->code());
    }

    public function testRecentPasswordAndDisabledExpiryDoNotForceAChange(): void
    {
        // recent password
        $this->setting('password_max_age', '7');
        $this->em->getConnection()->executeStatement("UPDATE users SET passwd_changed_on = datetime('now', '-2 days') WHERE login = 'dlopper'");
        $this->em->clear();
        $this->login('dlopper');
        $this->client->request('GET', '/my/page');
        self::assertSame(200, $this->code());

        // expiry disabled
        $this->setting('password_max_age', '0');
        $this->em->getConnection()->executeStatement("UPDATE users SET passwd_changed_on = '2020-01-01 00:00:00' WHERE login = 'rhill'");
        $this->em->clear();
        $this->login('rhill');
        $this->client->request('GET', '/my/page');
        self::assertSame(200, $this->code());
    }

    public function testPasswordExpiryDoesNotApplyToTheApi(): void
    {
        $this->setting('password_max_age', '7');
        $this->em->getConnection()->executeStatement("UPDATE users SET passwd_changed_on = '2020-01-01 00:00:00' WHERE login = 'jsmith'");
        $this->em->clear();
        $this->api('GET', '/projects.json');
        self::assertSame(200, $this->code());
    }

    // --- account status at login (User.try_to_login!) ---------------------------------------------------

    public function testWrongPasswordNeverRevealsTheAccountStatus(): void
    {
        $this->setting('self_registration', '1');
        $this->em->getConnection()->executeStatement("UPDATE users SET status = 2 WHERE login = 'anna'");
        $this->em->clear();
        foreach (['locked', 'anna', 'jsmith', 'nobody'] as $login) {
            $this->client->getCookieJar()->clear();
            $this->loginForm($login, 'not the password');
            self::assertSame(302, $this->code());
            self::assertSame('Invalid user or password', $this->flash(), $login);
            self::assertNull($this->client->getRequest()->getSession()->get('registered_user_id'));
        }
    }

    public function testRightPasswordTellsTheAccountStatus(): void
    {
        $this->setting('self_registration', '2');
        $this->em->getConnection()->executeStatement("UPDATE users SET status = 2 WHERE login = 'anna'");
        $this->em->clear();

        $this->client->getCookieJar()->clear();
        $this->loginForm('locked', 'password123');
        self::assertSame('Your account is locked.', $this->flash());
        $this->client->request('GET', '/my/page');
        self::assertSame(302, $this->code());

        $this->client->getCookieJar()->clear();
        $this->loginForm('anna', 'password123');
        self::assertSame('Your account was created and is now pending administrator approval.', $this->flash());
    }

    // --- activation e-mail (AccountController#account_pending, #activation_email) -------------------------

    public function testActivationEmailCanBeSentAgain(): void
    {
        $this->setting('self_registration', '1');
        $this->em->getConnection()->executeStatement("UPDATE users SET status = 2 WHERE login = 'anna'");
        $this->em->clear();
        $this->client->getCookieJar()->clear();

        // without a valid login there is nothing to send
        $this->client->request('GET', '/account/activation_email');
        self::assertSame(302, $this->code());
        self::assertStringEndsNotWith('/login', $this->location());

        $this->loginForm('anna', 'password123');
        $flash = $this->flash();
        self::assertStringContainsString("You haven't activated your account yet.", html_entity_decode($flash, \ENT_QUOTES));
        self::assertStringContainsString('<a href="/account/activation_email">click this link</a>', $flash);

        $before = \count(self::getMailerMessages());
        $this->client->request('GET', '/account/activation_email');
        self::assertSame(302, $this->code());
        self::assertStringEndsWith('/login', $this->location());
        $token = (string) $this->sql("SELECT value FROM tokens WHERE action = 'register' AND user_id = (SELECT id FROM users WHERE login = 'anna')");
        self::assertNotSame('', $token, 'a new activation token');
        $messages = self::getMailerMessages();
        self::assertGreaterThan($before, \count($messages));
        $mail = end($messages);
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $mail);
        self::assertSame('anna@example.net', $mail->getTo()[0]->getAddress());
        self::assertStringContainsString('/account/activate?token='.$token, (string) $mail->getTextBody());
        self::assertStringContainsString('Account was successfully created.', html_entity_decode($this->flash(), \ENT_QUOTES));

        // the link works once (session.delete(:registered_user_id))
        $this->client->request('GET', '/account/activation_email');
        self::assertStringEndsNotWith('/login', $this->location());
    }

    // --- the login identifies the user (User.try_to_login / User.find_by_login) --------------------------

    public function testEmailAddressIsNotAUserName(): void
    {
        foreach (['jsmith@example.net', 'JSMITH@EXAMPLE.NET'] as $mail) {
            $this->client->getCookieJar()->clear();
            $this->loginForm($mail, 'password123');
            self::assertSame(302, $this->code());
            self::assertSame('Invalid user or password', $this->flash(), $mail);
            $this->client->request('GET', '/my/page');
            self::assertSame(302, $this->code(), 'not logged in');
        }
        // the login still matches, exactly or whatever its case
        foreach (['jsmith', 'JSmith'] as $login) {
            $this->client->getCookieJar()->clear();
            $this->loginForm($login, 'password123');
            $this->client->request('GET', '/my/page');
            self::assertSame(200, $this->code(), $login);
        }
        // a login that looks like an e-mail address is a login like any other
        $this->em->getConnection()->executeStatement("UPDATE users SET login = 'anna@corp.example' WHERE login = 'anna'");
        $this->em->clear();
        $this->client->getCookieJar()->clear();
        $this->loginForm('anna@corp.example', 'password123');
        $this->client->request('GET', '/my/page');
        self::assertSame(200, $this->code());
    }

    public function testEmailAddressIsNotAUserNameForApiBasicAuthentication(): void
    {
        $basic = static fn (string $user, string $password): array => ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode($user.':'.$password), 'PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => $password];
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/users/current.json', [], [], $basic('jsmith', 'password123'));
        self::assertSame(200, $this->code());
        self::assertSame('jsmith', json_decode($this->content(), true)['user']['login']);
        $this->client->request('GET', '/users/current.json', [], [], $basic('jsmith@example.net', 'password123'));
        self::assertSame(401, $this->code());
        // the API key as user name still works
        $this->client->request('GET', '/users/current.json', [], [], $basic(self::JSMITH_KEY, 'x'));
        self::assertSame(200, $this->code());
    }
}
