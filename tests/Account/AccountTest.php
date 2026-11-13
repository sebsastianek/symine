<?php

declare(strict_types=1);

namespace App\Tests\Account;

use App\Entity\Principal;
use App\Entity\Token;
use App\Entity\User;
use App\Tests\Timelog\TimelogWebTestCase;

final class AccountTest extends TimelogWebTestCase
{
    private function registerParams(array $override = []): array
    {
        return ['user' => array_merge([
            'login' => 'newbie',
            'password' => 'Secret1234',
            'password_confirmation' => 'Secret1234',
            'firstname' => 'New',
            'lastname' => 'Bie',
            'mail' => 'newbie@example.net',
            'language' => 'en',
        ], $override), 'pref' => ['hide_mail' => '1']];
    }

    private function lastMail(): ?\Symfony\Component\Mime\Email
    {
        $messages = self::getMailerMessages();
        $m = end($messages);

        return $m instanceof \Symfony\Component\Mime\Email ? $m : null;
    }

    public function testRegisterDisabled(): void
    {
        $this->setting('self_registration', '0');
        $this->client->request('GET', '/account/register');
        self::assertSame(302, $this->code());
    }

    public function testRegisterWithEmailActivation(): void
    {
        $this->setting('self_registration', '1');
        $html = $this->get('/account/register');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('name="user[login]"', $html);
        self::assertStringContainsString('user_custom_field_values_6', $html); // Phone (shown on registration)

        // validation errors
        $this->submit('/account/register', $this->registerParams(['login' => 'jsmith', 'password' => 'short', 'password_confirmation' => 'other', 'mail' => 'bad']));
        $html = html_entity_decode($this->content(), \ENT_QUOTES);
        self::assertStringContainsString('Login has already been taken', $html);
        self::assertStringContainsString('Password is too short (minimum is 8 characters)', $html);
        self::assertStringContainsString("Password doesn't match confirmation", $html);
        self::assertStringContainsString('Email is invalid', $html);

        $this->submit('/account/register', $this->registerParams());
        self::assertSame(302, $this->code());
        self::assertStringEndsWith('/login', $this->location());
        $user = $this->user('newbie');
        self::assertSame(Principal::STATUS_REGISTERED, $user->status);
        self::assertSame('newbie@example.net', $user->getMail());
        $token = $this->em->getRepository(Token::class)->findOneBy(['user' => $user, 'action' => 'register']);
        self::assertNotNull($token);
        self::assertEmailCount(1);
        $mail = $this->lastMail();
        self::assertSame('newbie@example.net', $mail->getTo()[0]->getAddress());
        self::assertStringContainsString('/account/activate?token='.$token->value, (string) $mail->getTextBody());

        // cannot log in before activation
        $this->login('newbie', 'Secret1234');
        $this->client->request('GET', '/my/page');
        self::assertSame(302, $this->code());

        $this->client->request('GET', '/account/activate?token='.$token->value);
        self::assertSame(302, $this->code());
        self::assertStringEndsWith('/login', $this->location());
        $this->em->clear();
        self::assertSame(Principal::STATUS_ACTIVE, $this->user('newbie')->status);
        self::assertSame(0, (int) $this->sql("SELECT COUNT(*) FROM tokens WHERE action = 'register'"));

        $this->login('newbie', 'Secret1234');
        self::assertStringEndsWith('/my/page', $this->location());
        $this->client->request('GET', '/my/page');
        self::assertSame(200, $this->code());
    }

    public function testRegisterAutomaticAndManual(): void
    {
        $this->setting('self_registration', '3');
        $this->submit('/account/register', $this->registerParams());
        self::assertSame(302, $this->code());
        self::assertStringEndsWith('/my/account', $this->location());
        $this->client->request('GET', '/my/account');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('value="newbie@example.net"', $this->content());

        $this->client->getCookieJar()->clear();
        $this->setting('self_registration', '2');
        $this->submit('/account/register', $this->registerParams(['login' => 'pending', 'mail' => 'pending@example.net']));
        self::assertSame(302, $this->code());
        self::assertSame(Principal::STATUS_REGISTERED, $this->user('pending')->status);
        // activation request sent to the administrators
        $mail = $this->lastMail();
        self::assertNotNull($mail);
        self::assertStringContainsString('account activation request', (string) $mail->getSubject());
        self::assertStringContainsString('(pending)', (string) $mail->getTextBody());
    }

    public function testLostPasswordFlow(): void
    {
        $this->get('/account/lost_password');
        self::assertSame(200, $this->code());
        // unknown address: same notice, no mail
        $this->submit('/account/lost_password', ['mail' => 'nobody@example.net']);
        self::assertSame(302, $this->code());
        self::assertEmailCount(0);

        $this->submit('/account/lost_password', ['mail' => 'JSMITH@example.net']);
        self::assertSame(302, $this->code());
        $token = $this->em->getRepository(Token::class)->findOneBy(['action' => 'recovery']);
        self::assertNotNull($token);
        self::assertSame('jsmith', $token->user->login);
        $mail = $this->lastMail();
        self::assertStringContainsString('/account/lost_password?token='.$token->value, (string) $mail->getTextBody());

        // the token is moved to the session
        $this->client->request('GET', '/account/lost_password?token='.$token->value);
        self::assertSame(302, $this->code());
        self::assertStringEndsWith('/account/lost_password', $this->location());
        $html = $this->get('/account/lost_password');
        self::assertStringContainsString('name="new_password"', $html);

        $this->submit('/account/lost_password', ['token' => $token->value, 'new_password' => 'NewPass123', 'new_password_confirmation' => 'nomatch']);
        self::assertStringContainsString('match confirmation', html_entity_decode($this->content(), \ENT_QUOTES));

        $this->submit('/account/lost_password', ['token' => $token->value, 'new_password' => 'NewPass123', 'new_password_confirmation' => 'NewPass123']);
        self::assertSame(302, $this->code());
        self::assertStringEndsWith('/login', $this->location());
        self::assertSame(0, (int) $this->sql("SELECT COUNT(*) FROM tokens WHERE action = 'recovery'"));

        $this->login('jsmith', 'NewPass123');
        self::assertStringEndsWith('/my/page', $this->location());
        // used token is invalid
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/account/lost_password?token='.$token->value);
        self::assertStringEndsWith('/', $this->location());
    }

    public function testExpiredRecoveryToken(): void
    {
        $this->submit('/account/lost_password', ['mail' => 'jsmith@example.net']);
        $this->em->getConnection()->executeStatement("UPDATE tokens SET created_on = '2000-01-01 00:00:00' WHERE action = 'recovery'");
        $value = (string) $this->sql("SELECT value FROM tokens WHERE action = 'recovery'");
        $this->em->clear();
        $this->client->request('GET', '/account/lost_password?token='.$value);
        self::assertStringEndsWith('/account/lost_password', $this->location());
        $this->client->followRedirect();
        self::assertStringContainsString('This password recovery link has expired', $this->content());
    }

    public function testPasswordComplexitySettings(): void
    {
        $this->setting('password_required_char_classes', ['uppercase', 'digits']);
        $this->setting('password_min_length', '10');
        $this->login('jsmith');
        $this->submit('/my/password', ['password' => 'password123', 'new_password' => 'abcdefgh', 'new_password_confirmation' => 'abcdefgh']);
        $html = html_entity_decode($this->content(), \ENT_QUOTES);
        self::assertStringContainsString('Password must contain uppercase letters (A-Z)', $html);
        self::assertStringContainsString('Password must contain digits (0-9)', $html);
        self::assertStringContainsString('Password is too short (minimum is 10 characters)', $html);
        self::assertStringContainsString('Must contain uppercase letters, digits.', $html);
    }

    public function testChangePassword(): void
    {
        $this->login('jsmith');
        $this->submit('/my/password', ['password' => 'wrong', 'new_password' => 'Another123', 'new_password_confirmation' => 'Another123']);
        self::assertStringContainsString('Wrong password', $this->content());
        $this->submit('/my/password', ['password' => 'password123', 'new_password' => 'password123', 'new_password_confirmation' => 'password123']);
        self::assertStringContainsString('The new password must be different', $this->content());
        $this->submit('/my/password', ['password' => 'password123', 'new_password' => 'Another123', 'new_password_confirmation' => 'Another123']);
        self::assertSame(302, $this->code());
        self::assertStringEndsWith('/my/account', $this->location());
        // still logged in
        $this->client->request('GET', '/my/account');
        self::assertSame(200, $this->code());
        $this->login('jsmith', 'Another123');
        self::assertStringEndsWith('/my/page', $this->location());
    }

    public function testForcedPasswordChange(): void
    {
        $this->em->getConnection()->executeStatement("UPDATE users SET must_change_passwd = 1 WHERE login = 'jsmith'");
        $this->login('jsmith');
        $this->client->request('GET', '/my/page');
        self::assertStringEndsWith('/my/password', $this->location());
        $html = $this->get('/my/password');
        self::assertSame(200, $this->code());
        self::assertStringNotContainsString('id="sidebar-switch-panel"', $html);
        $this->submit('/my/password', ['_token' => $this->csrf('/my/password'), 'password' => 'password123', 'new_password' => 'Another123', 'new_password_confirmation' => 'Another123']);
        self::assertSame(302, $this->code());
        $this->client->request('GET', '/my/page');
        self::assertSame(200, $this->code());
    }

    public function testMyAccountUpdate(): void
    {
        $this->login('jsmith');
        $this->submit('/my/account', [
            '_method' => 'PUT',
            'user' => ['firstname' => 'Johnny', 'lastname' => 'Smith', 'mail' => 'johnny@example.net', 'language' => 'fr', 'mail_notification' => 'selected', 'notified_project_ids' => ['1', ''], 'custom_field_values' => ['6' => '+1 555 0199']],
            'pref' => ['hide_mail' => '0', 'time_zone' => 'Paris', 'comments_sorting' => 'desc', 'no_self_notified' => '0', 'auto_watch_on' => ['', 'issue_created'], 'textarea_font' => 'monospace', 'history_default_tab' => 'notes', 'toolbar_language_options' => 'php, ruby', 'warn_on_leaving_unsaved' => '0', 'recently_used_projects' => '5'],
        ]);
        self::assertSame(302, $this->code());
        self::assertStringEndsWith('/my/account', $this->location());
        $this->em->clear();
        $u = $this->user('jsmith');
        self::assertSame('Johnny', $u->firstname);
        self::assertSame('johnny@example.net', $u->getMail());
        self::assertSame('fr', $u->language);
        self::assertSame('selected', $u->mailNotification);
        $pref = $u->getPref();
        self::assertFalse($pref->hideMail);
        self::assertSame('Paris', $pref->timeZone);
        self::assertSame('desc', $pref->get('comments_sorting'));
        self::assertSame(['issue_created'], $pref->get('auto_watch_on'));
        self::assertSame('php,ruby', $pref->get('toolbar_language_options'));
        self::assertSame(5, $pref->get('recently_used_projects'));
        // my page layout is kept
        self::assertIsArray($pref->get('my_page_layout'));
        self::assertSame('1', (string) $this->sql('SELECT mail_notification FROM members WHERE user_id = 5 AND project_id = 1'));
        self::assertSame('0', (string) $this->sql('SELECT mail_notification FROM members WHERE user_id = 5 AND project_id = 2'));
        self::assertSame('+1 555 0199', $this->sql("SELECT value FROM custom_values WHERE customized_type = 'Principal' AND customized_id = 5 AND custom_field_id = 6"));

        // validation
        $this->submit('/my/account', ['_method' => 'PUT', 'user' => ['firstname' => '', 'mail' => 'dlopper@example.net']]);
        self::assertSame(200, $this->code());
        $html = html_entity_decode($this->content(), \ENT_QUOTES);
        self::assertStringContainsString('First name cannot be blank', $html);
        self::assertStringContainsString('Email has already been taken', $html);
    }

    public function testMyAccountApi(): void
    {
        $data = $this->api('GET', '/my/account.json');
        self::assertSame(200, $this->code());
        self::assertSame('jsmith', $data['user']['login']);
        self::assertSame(self::JSMITH_KEY, $data['user']['api_key']);
        self::assertSame([['id' => 6, 'name' => 'Phone', 'value' => '+1 555 0100']], $data['user']['custom_fields']);
        $this->api('PUT', '/my/account.json', ['user' => ['firstname' => 'Jo'], 'pref' => ['comments_sorting' => 'desc']]);
        self::assertSame(204, $this->code());
        $this->em->clear();
        self::assertSame('Jo', $this->user('jsmith')->firstname);
        $data = $this->api('PUT', '/my/account.json', ['user' => ['mail' => 'not-an-email']]);
        self::assertSame(422, $this->code());
        self::assertSame(['Email is invalid'], $data['errors']);
        $this->client->request('GET', '/my/account.json');
        self::assertSame(401, $this->code());
    }

    public function testKeys(): void
    {
        $this->login('jsmith');
        $this->client->request('GET', '/my/api_key', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertStringContainsString(self::JSMITH_KEY, $this->content());
        self::assertStringContainsString(self::JSMITH_KEY, $this->get('/my/api_key'));
        $this->submit('/my/api_key');
        self::assertStringEndsWith('/my/account', $this->location());
        $new = (string) $this->sql("SELECT value FROM tokens WHERE action = 'api' AND user_id = 5");
        self::assertNotSame(self::JSMITH_KEY, $new);
        self::assertSame(40, \strlen($new));

        $this->submit('/my/atom_key');
        $atom = (string) $this->sql("SELECT value FROM tokens WHERE action = 'feeds' AND user_id = 5");
        self::assertSame(40, \strlen($atom));
        self::assertStringContainsString('Atom access key created', $this->get('/my/account'));
    }

    public function testDestroyOwnAccount(): void
    {
        $this->login('rhill');
        self::assertStringContainsString('Delete my account', $this->get('/my/account'));
        $this->get('/my/account/destroy');
        self::assertSame(200, $this->code());
        $this->submit('/my/account/destroy', ['confirm' => '1']);
        self::assertSame(302, $this->code());
        self::assertFalse($this->sql("SELECT id FROM users WHERE login = 'rhill'"));
        // the only admin cannot delete his account
        $this->login('admin');
        $this->client->request('GET', '/my/account/destroy');
        self::assertStringEndsWith('/my/account', $this->location());
        // disabled by setting
        $this->setting('unsubscribe', '0');
        $this->login('dlopper');
        $this->client->request('GET', '/my/account/destroy');
        self::assertSame(302, $this->code());
    }

    public function testEmailAddresses(): void
    {
        $this->login('jsmith');
        $this->submit('/users/5/email_addresses', ['email_address' => ['address' => 'other@example.net']]);
        self::assertSame(302, $this->code());
        $id = (int) $this->sql("SELECT id FROM email_addresses WHERE address = 'other@example.net'");
        self::assertGreaterThan(0, $id);
        self::assertStringContainsString('other@example.net', $this->get('/users/5/email_addresses'));
        $this->submit('/users/5/email_addresses/'.$id.'?notify=0', ['_method' => 'PUT']);
        self::assertSame('0', (string) $this->sql('SELECT notify FROM email_addresses WHERE id = ?', [$id]));
        $this->submit('/users/5/email_addresses/'.$id, ['_method' => 'DELETE']);
        self::assertFalse($this->sql('SELECT id FROM email_addresses WHERE id = ?', [$id]));
        // other users' addresses are admin only
        $this->client->request('GET', '/users/6/email_addresses');
        self::assertSame(403, $this->code());
    }
}
