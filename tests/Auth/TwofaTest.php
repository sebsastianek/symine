<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Migration\RedmineMigrator;

/**
 * Two-factor authentication (Redmine's TwofaController, TwofaBackupCodesController and the 2FA login
 * step of AccountController).
 */
final class TwofaTest extends AuthWebTestCase
{
    private function mailBodies(): string
    {
        $out = '';
        foreach (self::getMailerMessages() as $m) {
            if ($m instanceof \Symfony\Component\Mime\Email) {
                $out .= $m->getTextBody()."\n";
            }
        }

        return $out;
    }

    // --- Activation --------------------------------------------------------------------------

    public function testActivationFlow(): void
    {
        $this->login('jsmith');
        $html = $this->get('/my/account');
        self::assertStringContainsString('href="/my/twofa/totp/activate/init">Enable authenticator app</a>', $html);
        self::assertStringContainsString('href="/oauth/authorized_applications"', $html);

        self::assertSame(302, $this->post('/my/twofa/totp/activate/init'));
        self::assertStringEndsWith('/my/twofa/totp/activate/confirm', $this->location());
        $row = $this->userRow('jsmith');
        self::assertMatchesRegularExpression('/\A[A-Z2-7]{32}\z/', (string) $row['twofa_totp_key']);
        self::assertNull($row['twofa_scheme']);
        $key = $row['twofa_totp_key'];

        $html = $this->get('/my/twofa/totp/activate/confirm');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('<img src="data:image/svg+xml;base64,', $html);
        self::assertStringContainsString('<code>'.implode(' ', str_split($key, 4)).'</code>', $html);
        self::assertStringContainsString('action="/my/twofa/totp/activate"', $html);
        // the QR code encodes the provisioning URI
        preg_match('/base64,([^"]+)"/', $html, $m);
        self::assertStringContainsString('<svg', (string) base64_decode($m[1]));

        // wrong code
        self::assertSame(302, $this->post('/my/twofa/totp/activate', ['twofa_code' => '000000']));
        self::assertStringEndsWith('/my/twofa/totp/activate/confirm', $this->location());
        self::assertStringContainsString('Code is invalid or outdated.', $this->flash());
        self::assertNull($this->userRow('jsmith')['twofa_scheme']);

        // a backup code cannot be used to pair
        $this->em->getConnection()->executeStatement("INSERT INTO tokens (user_id, action, value, created_on, updated_on) VALUES (5, 'twofa_backup_code', 'aaaabbbbcccc', datetime('now'), datetime('now'))");
        $this->post('/my/twofa/totp/activate', ['twofa_code' => 'aaaabbbbcccc']);
        self::assertNull($this->userRow('jsmith')['twofa_scheme']);

        // valid code (white spaces are ignored)
        $code = $this->totpCode($key);
        self::assertSame(302, $this->post('/my/twofa/totp/activate', ['twofa_code' => substr($code, 0, 3).' '.substr($code, 3)]));
        self::assertStringEndsWith('/my/account', $this->location());
        self::assertEmailCount(1);
        self::assertStringContainsString('Two-factor authentication successfully enabled using Authenticator app.', $this->mailBodies());
        $row = $this->userRow('jsmith');
        self::assertSame('totp', $row['twofa_scheme']);
        self::assertSame($key, $row['twofa_totp_key']);
        self::assertGreaterThan(time() - 90, (int) $row['twofa_totp_last_used_at']);
        $this->client->followRedirect();
        $html = $this->content();
        self::assertStringContainsString('It is recommended to <a data-method="post" href="/my/twofa/backup_codes/init">generate backup codes</a>', $html);
        self::assertStringContainsString('Currently active: Authenticator app', $html);
        self::assertStringContainsString('href="/my/twofa/totp/deactivate/init">Disable</a>', $html);
        self::assertStringContainsString('data-confirm="This will invalidate all existing backup codes', $html);

        // already set up
        self::assertSame(302, $this->post('/my/twofa/totp/activate/init'));
        self::assertStringEndsWith('/my/account', $this->location());
        self::assertStringContainsString('Two-factor authentication already set up', $this->flash());
        $this->client->request('GET', '/my/twofa/select_scheme');
        self::assertStringEndsWith('/my/account', $this->location());
    }

    public function testSelectSchemeAndUnknownScheme(): void
    {
        $this->login('jsmith');
        $html = $this->get('/my/twofa/select_scheme');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('<input type="radio" name="scheme" id="scheme_totp" value="totp" checked="checked" /> Authenticator app', $html);
        self::assertStringContainsString('action="/my/twofa/activate/init"', $html);
        self::assertSame(302, $this->post('/my/twofa/activate/init', ['scheme' => 'totp']));
        self::assertStringEndsWith('/my/twofa/totp/activate/confirm', $this->location());
        self::assertSame(302, $this->post('/my/twofa/sms/activate/init'));
        self::assertStringEndsWith('/my/account', $this->location());
    }

    public function testTwofaPagesRequireLoginAndEnabledSetting(): void
    {
        $this->client->request('GET', '/my/twofa/select_scheme');
        self::assertStringContainsString('/login?back_url=', $this->location());
        $this->login('jsmith');
        $this->setting('twofa', '0');
        $this->client->request('GET', '/my/twofa/select_scheme');
        self::assertSame(403, $this->code());
        self::assertStringNotContainsString('Enable authenticator app', $this->get('/my/account'));
    }

    // --- Backup codes ---------------------------------------------------------------------------

    public function testBackupCodes(): void
    {
        $this->login('jsmith');
        // without an active scheme
        self::assertSame(302, $this->post('/my/twofa/backup_codes/init'));
        self::assertStringEndsWith('/my/account', $this->location());

        $this->pairTotp('jsmith');
        self::assertSame(302, $this->post('/my/twofa/backup_codes/init'));
        self::assertStringEndsWith('/my/twofa/backup_codes/confirm', $this->location());
        $html = $this->get('/my/twofa/backup_codes/confirm');
        self::assertStringContainsString('<h2>Generate backup codes</h2>', $html);
        self::assertStringContainsString('action="/my/twofa/backup_codes/create"', $html);

        self::assertSame(302, $this->post('/my/twofa/backup_codes/create', ['twofa_code' => '123456']));
        self::assertStringEndsWith('/my/twofa/backup_codes/confirm', $this->location());
        self::assertSame(0, (int) $this->sql("SELECT COUNT(*) FROM tokens WHERE action = 'twofa_backup_code'"));

        self::assertSame(302, $this->post('/my/twofa/backup_codes/create', ['twofa_code' => $this->totpCode()]));
        self::assertStringEndsWith('/my/twofa/backup_codes', $this->location());
        self::assertEmailCount(1);
        self::assertStringContainsString('New two-factor authentication backup codes generated.', $this->mailBodies());
        $codes = $this->em->getConnection()->fetchFirstColumn("SELECT value FROM tokens WHERE action = 'twofa_backup_code' AND user_id = 5 ORDER BY id");
        self::assertCount(10, $codes);
        foreach ($codes as $c) {
            self::assertMatchesRegularExpression('/\A[0-9a-f]{12}\z/', $c);
        }
        $this->client->followRedirect();
        $html = $this->content();
        self::assertStringContainsString('Your backup codes have been generated.', $html);
        self::assertStringContainsString('<ul class="twofa_backup_codes">', $html);
        foreach ($codes as $c) {
            self::assertStringContainsString('<li><code>'.implode(' ', str_split($c, 4)).'</code></li>', $html);
        }
        // shown only once
        $this->client->request('GET', '/my/twofa/backup_codes');
        self::assertStringEndsWith('/my/account', $this->location());
        self::assertStringContainsString('Backup codes cannot be shown again', $this->flash());

        // regeneration invalidates the previous codes
        $this->resetTotpReplay('jsmith');
        $this->post('/my/twofa/backup_codes/create', ['twofa_code' => $this->totpCode()]);
        self::assertStringContainsString('New backup codes have been generated. Your existing codes from', $this->flash());
        $new = $this->em->getConnection()->fetchFirstColumn("SELECT value FROM tokens WHERE action = 'twofa_backup_code' AND user_id = 5");
        self::assertCount(10, $new);
        self::assertSame([], array_intersect($codes, $new));

        // a backup code also confirms the generation (and is consumed)
        $this->post('/my/twofa/backup_codes/create', ['twofa_code' => strtoupper($new[0])]);
        self::assertStringEndsWith('/my/twofa/backup_codes', $this->location());
    }

    // --- Login ------------------------------------------------------------------------------

    public function testLoginWithoutTwofaIsUnchanged(): void
    {
        $this->login('jsmith');
        self::assertStringEndsWith('/my/page', $this->location());
        self::assertTrue($this->isLoggedIn());
    }

    public function testLoginWithTotpCode(): void
    {
        $this->setting('autologin', '7');
        $this->pairTotp('jsmith');
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/login');
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/login', ['username' => 'jsmith', 'password' => 'password123', '_csrf_token' => $token, 'back_url' => '/projects', 'autologin' => '1']);
        self::assertSame(302, $this->code());
        self::assertStringEndsWith('/account/twofa/confirm', $this->location());
        // no remember-me cookie and not authenticated yet
        self::assertNull($this->client->getCookieJar()->get('autologin'));
        self::assertFalse($this->isLoggedIn());
        self::assertSame(1, (int) $this->sql("SELECT COUNT(*) FROM tokens WHERE action = 'twofa_session' AND user_id = 5"));

        $html = $this->get('/account/twofa/confirm');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('<div id="login-form">', $html);
        self::assertStringContainsString('<h3>Two-factor authentication</h3>', $html);
        self::assertStringContainsString('action="/account/twofa"', $html);
        self::assertStringNotContainsString('Resend code', $html);

        $code = $this->totpCode();
        $this->client->request('POST', '/account/twofa', ['twofa_code' => $code, '_token' => $this->csrfFrom()]);
        self::assertSame(302, $this->code());
        self::assertStringEndsWith('/projects', $this->location());
        self::assertNotNull($this->client->getCookieJar()->get('autologin'), 'remember-me cookie set after the second factor');
        self::assertTrue($this->isLoggedIn());
        self::assertSame(0, (int) $this->sql("SELECT COUNT(*) FROM tokens WHERE action = 'twofa_session'"));
        self::assertGreaterThan(time() - 90, (int) $this->userRow('jsmith')['twofa_totp_last_used_at']);

        // the same code cannot be replayed
        $this->login('jsmith');
        $this->client->request('POST', '/account/twofa', ['twofa_code' => $code, '_token' => $this->csrfFrom('/account/twofa/confirm')]);
        self::assertStringEndsWith('/account/twofa/confirm', $this->location());
        self::assertStringContainsString('Code is invalid or outdated.', $this->flash());
        self::assertFalse($this->isLoggedIn());
    }

    public function testTooManyTries(): void
    {
        $this->pairTotp('jsmith');
        $this->login('jsmith');
        $csrf = $this->csrfFrom('/account/twofa/confirm');
        for ($i = 0; $i < 2; ++$i) {
            $this->client->request('POST', '/account/twofa', ['twofa_code' => '000000', '_token' => $csrf]);
            self::assertStringEndsWith('/account/twofa/confirm', $this->location());
        }
        $this->client->request('POST', '/account/twofa', ['twofa_code' => '000000', '_token' => $csrf]);
        self::assertStringEndsWith('/', $this->location());
        self::assertStringContainsString('Too many tries.', $this->flash());
        self::assertSame(0, (int) $this->sql("SELECT COUNT(*) FROM tokens WHERE action = 'twofa_session'"));
        // the pending session is gone: even a valid code does not log in
        $this->client->request('POST', '/account/twofa', ['twofa_code' => $this->totpCode(), '_token' => $csrf]);
        self::assertStringEndsWith('/', $this->location());
        self::assertFalse($this->isLoggedIn());
        // resends count too
        $this->login('jsmith');
        $csrf = $this->csrfFrom('/account/twofa/confirm');
        $this->client->request('POST', '/account/twofa/resend', ['_token' => $csrf]);
        self::assertStringEndsWith('/account/twofa/confirm', $this->location());
        $this->client->request('POST', '/account/twofa/resend', ['_token' => $csrf]);
        $this->client->request('POST', '/account/twofa/resend', ['_token' => $csrf]);
        self::assertStringContainsString('Too many tries.', $this->flash());
    }

    public function testPendingSessionExpiresAfterTwoMinutes(): void
    {
        $this->pairTotp('jsmith');
        $this->login('jsmith');
        $this->em->getConnection()->executeStatement("UPDATE tokens SET created_on = datetime('now', '-3 minutes') WHERE action = 'twofa_session'");
        $this->client->request('GET', '/account/twofa/confirm');
        self::assertSame(302, $this->code());
        self::assertStringEndsWith('/', $this->location());
        self::assertSame(0, (int) $this->sql("SELECT COUNT(*) FROM tokens WHERE action = 'twofa_session'"));
        // without any pending login
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/account/twofa/confirm');
        self::assertStringEndsWith('/', $this->location());
    }

    public function testLoginWithBackupCode(): void
    {
        $this->pairTotp('jsmith');
        $this->pairTotp('dlopper', 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
        $conn = $this->em->getConnection();
        $conn->executeStatement("INSERT INTO tokens (user_id, action, value, created_on, updated_on) VALUES (5, 'twofa_backup_code', 'abcdef123456', datetime('now'), datetime('now')), (6, 'twofa_backup_code', 'fedcba654321', datetime('now'), datetime('now'))");

        $this->login('jsmith');
        $csrf = $this->csrfFrom('/account/twofa/confirm');
        // a code of another user is rejected and not consumed
        $this->client->request('POST', '/account/twofa', ['twofa_code' => 'fedcba654321', '_token' => $csrf]);
        self::assertStringEndsWith('/account/twofa/confirm', $this->location());
        self::assertSame(1, (int) $this->sql("SELECT COUNT(*) FROM tokens WHERE value = 'fedcba654321'"));

        // case and white-space insensitive
        $this->client->request('POST', '/account/twofa', ['twofa_code' => ' ABCD EF12 3456 ', '_token' => $csrf]);
        self::assertStringEndsWith('/my/page', $this->location());
        self::assertEmailCount(1);
        self::assertStringContainsString('A two-factor authentication backup code has been used.', $this->mailBodies());
        self::assertSame(0, (int) $this->sql("SELECT COUNT(*) FROM tokens WHERE value = 'abcdef123456'"));
        self::assertTrue($this->isLoggedIn());

        // single use
        $this->login('jsmith');
        $this->client->request('POST', '/account/twofa', ['twofa_code' => 'abcdef123456', '_token' => $this->csrfFrom('/account/twofa/confirm')]);
        self::assertStringEndsWith('/account/twofa/confirm', $this->location());
        self::assertFalse($this->isLoggedIn());
    }

    public function testMigratedEncryptedKey(): void
    {
        // users.twofa_totp_key still encrypted by Redmine::Ciphering (migrated without --cipher-key)
        $enc = 'aes-256-cbc:avZFg50gD9d7HBZiSGhPdkcYcwt7irGPd32J0hHA/kXPhbQUOx6z5J61JWp2r5Yv--wkaL8vt+LfTuRZKPxCq85w==';
        $this->pairTotp('jsmith', $enc);
        $_SERVER['REDMINE_CIPHER_KEY'] = 'symine-test-cipher-key';
        try {
            $this->login('jsmith');
            $this->client->request('POST', '/account/twofa', ['twofa_code' => $this->totpCode(), '_token' => $this->csrfFrom('/account/twofa/confirm')]);
            self::assertStringEndsWith('/my/page', $this->location());
            self::assertSame(self::KEY, RedmineMigrator::decryptRedmineText($enc, 'symine-test-cipher-key'));
        } finally {
            unset($_SERVER['REDMINE_CIPHER_KEY']);
        }
    }

    // --- Deactivation -----------------------------------------------------------------------------

    public function testDeactivation(): void
    {
        $this->login('jsmith');
        $this->pairTotp('jsmith');
        $this->em->getConnection()->executeStatement("INSERT INTO tokens (user_id, action, value, created_on, updated_on) VALUES (5, 'twofa_backup_code', 'abcdef123456', datetime('now'), datetime('now'))");
        self::assertSame(302, $this->post('/my/twofa/totp/deactivate/init'));
        self::assertStringEndsWith('/my/twofa/totp/deactivate/confirm', $this->location());
        $html = $this->get('/my/twofa/totp/deactivate/confirm');
        self::assertStringContainsString('<h2>Disable two-factor authentication</h2>', $html);
        self::assertStringContainsString('action="/my/twofa/totp/deactivate"', $html);
        // another scheme
        $this->client->request('GET', '/my/twofa/sms/deactivate/confirm');
        self::assertStringEndsWith('/my/account', $this->location());

        $this->post('/my/twofa/totp/deactivate', ['twofa_code' => '111111']);
        self::assertStringEndsWith('/my/twofa/totp/deactivate/confirm', $this->location());
        self::assertSame('totp', $this->userRow('jsmith')['twofa_scheme']);

        $this->post('/my/twofa/totp/deactivate', ['twofa_code' => $this->totpCode()]);
        self::assertStringEndsWith('/my/account', $this->location());
        self::assertEmailCount(1);
        self::assertStringContainsString('Two-factor authentication disabled for your account.', $this->mailBodies());
        $row = $this->userRow('jsmith');
        self::assertNull($row['twofa_scheme']);
        self::assertNull($row['twofa_totp_key']);
        self::assertNull($row['twofa_totp_last_used_at']);
        self::assertSame(0, (int) $this->sql("SELECT COUNT(*) FROM tokens WHERE action = 'twofa_backup_code'"));
        self::assertStringContainsString('Two-factor authentication disabled.', $this->flash());
    }

    public function testAdminDeactivation(): void
    {
        $this->pairTotp('jsmith');
        $this->pairTotp('admin');
        $this->login('admin');
        $this->client->request('POST', '/account/twofa', ['twofa_code' => $this->totpCode(), '_token' => $this->csrfFrom('/account/twofa/confirm')]);
        self::assertTrue($this->isLoggedIn());

        $html = $this->get('/users/5/edit');
        self::assertStringContainsString('Currently active: Authenticator app', $html);
        self::assertStringContainsString('href="/users/5/twofa/deactivate">Disable</a>', $html);
        // own account: confirmation code required
        self::assertStringContainsString('href="/my/twofa/totp/deactivate/init">Disable</a>', $this->get('/users/1/edit'));
        self::assertSame(403, $this->post('/users/1/twofa/deactivate'));
        self::assertSame('totp', $this->userRow('admin')['twofa_scheme']);

        self::assertSame(302, $this->post('/users/5/twofa/deactivate'));
        self::assertStringEndsWith('/users/5/edit', $this->location());
        self::assertNull($this->userRow('jsmith')['twofa_scheme']);
        self::assertNull($this->userRow('jsmith')['twofa_totp_key']);
        self::assertSame(404, $this->post('/users/999/twofa/deactivate'));

        // non administrators
        $this->pairTotp('jsmith');
        $this->login('dlopper');
        self::assertSame(403, $this->post('/users/5/twofa/deactivate'));
        self::assertSame('totp', $this->userRow('jsmith')['twofa_scheme']);
    }

    public function testDisablingTwofaUnpairsAllUsers(): void
    {
        $this->pairTotp('jsmith');
        $this->pairTotp('rhill');
        $this->login('admin');
        $this->post('/settings/edit?tab=authentication', ['settings' => ['twofa' => '0'], 'tab' => 'authentication'], '/settings');
        self::assertSame(302, $this->code());
        self::assertSame(0, (int) $this->sql('SELECT COUNT(*) FROM users WHERE twofa_scheme IS NOT NULL OR twofa_totp_key IS NOT NULL'));
        self::assertSame('0', (string) $this->sql("SELECT value FROM settings WHERE name = 'twofa'"));
    }

    // --- Required 2FA ---------------------------------------------------------------------------

    public function testActivationRequiredForAllUsers(): void
    {
        $this->setting('twofa', '2');
        $this->login('dlopper');
        $this->client->request('GET', '/my/page');
        self::assertSame(302, $this->code());
        self::assertStringEndsWith('/my/twofa/totp/activate/confirm', $this->location());
        $key = (string) $this->userRow('dlopper')['twofa_totp_key'];
        self::assertSame(32, \strlen($key));
        $this->client->followRedirect();
        self::assertSame(200, $this->code());
        self::assertStringContainsString('The administrator requires you to enable two-factor authentication.', $this->content());
        // no sidebar while the activation is required
        self::assertStringContainsString('class="nosidebar"', $this->content());
        // the password page and logout stay reachable
        $this->client->request('GET', '/my/password');
        self::assertSame(200, $this->code());

        $this->client->request('POST', '/my/twofa/totp/activate', ['twofa_code' => (new \App\Security\Twofa\Totp((string) $this->userRow('dlopper')['twofa_totp_key']))->now(), '_token' => $this->csrfFrom()]);
        self::assertStringEndsWith('/my/account', $this->location());
        $this->client->request('GET', '/my/page');
        self::assertSame(200, $this->code());
    }

    public function testActivationRequiredForAdministratorsAndGroups(): void
    {
        $this->setting('twofa', '3');
        $this->login('jsmith');
        $this->client->request('GET', '/my/page');
        self::assertSame(200, $this->code());
        $this->login('admin');
        $this->client->request('GET', '/projects');
        self::assertStringEndsWith('/my/twofa/totp/activate/confirm', $this->location());

        // optional, but required by a group of the user (dlopper is in Developers)
        $this->setting('twofa', '1');
        $this->em->getConnection()->executeStatement("UPDATE users SET twofa_required = 1 WHERE lastname = 'Developers'");
        $this->login('dlopper');
        $this->client->request('GET', '/my/page');
        self::assertStringEndsWith('/my/twofa/totp/activate/confirm', $this->location());
        $this->login('jsmith');
        $this->client->request('GET', '/my/page');
        self::assertSame(200, $this->code());
    }

    // --- API ------------------------------------------------------------------------------------

    public function testApiBasicAuthIsRefusedForTwofaUsers(): void
    {
        $this->client->request('GET', '/users/current.json', [], [], ['PHP_AUTH_USER' => 'jsmith', 'PHP_AUTH_PW' => 'password123']);
        self::assertSame(200, $this->code());
        $this->pairTotp('jsmith');
        $this->client->request('GET', '/users/current.json', [], [], ['PHP_AUTH_USER' => 'jsmith', 'PHP_AUTH_PW' => 'password123']);
        self::assertSame(401, $this->code());
        self::assertSame('', $this->content());
        // API keys still work
        $d = $this->api('GET', '/users/current.json');
        self::assertSame('jsmith', $d['user']['login']);
        self::assertSame('totp', $d['user']['twofa_scheme'] ?? 'totp');
    }
}
