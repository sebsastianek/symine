<?php

declare(strict_types=1);

namespace App\Tests\Notifications;

use App\Entity\EmailAddress;
use App\Entity\Principal;
use App\Tests\Admin\AdminWebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Security notifications and account mails sent by the administration screens, like Redmine's
 * EmailAddress / User / Setting callbacks and UsersController#update
 * (reference: test/unit/email_address_test.rb, test/functional/users_controller_test.rb,
 * test/functional/settings_controller_test.rb, test/functional/email_addresses_controller_test.rb).
 */
final class SecurityNotificationsTest extends AdminWebTestCase
{
    private const string SUBJECT = '[Redmine Reference] Security notification';

    /** @return list<Email> e-mails sent since the last call */
    private function mails(): array
    {
        $mails = array_values(array_filter(self::getMailerMessages(), static fn ($m) => $m instanceof Email));
        self::getContainer()->get('mailer.message_logger_listener')->reset();

        return $mails;
    }

    /** @return list<string> sorted recipient addresses */
    private function to(Email $e): array
    {
        $to = array_map(static fn ($a) => $a->getAddress(), $e->getTo());
        sort($to);

        return $to;
    }

    /** @param list<Email> $mails @return list<string> sorted "to" lists, one per mail */
    private function recipients(array $mails): array
    {
        $out = array_map(fn (Email $e) => implode(',', $this->to($e)), $mails);
        sort($out);

        return $out;
    }

    private function makeAdmin(string $login): void
    {
        $u = $this->user($login);
        $u->admin = true;
        $this->em->flush();
    }

    // --- settings -----------------------------------------------------------------------------

    public function testChangingSecuritySettingsNotifiesAdministrators(): void
    {
        $this->loginAs('admin');
        $this->makeAdmin('jsmith');
        $this->post('/settings/edit', ['settings' => ['login_required' => '1', 'password_min_length' => '9', 'app_title' => 'Redmine Reference']]);
        $this->assertStatus(302);
        $mails = $this->mails();
        self::assertSame(['admin@dummy.invalid', 'jsmith@example.net'], $this->recipients($mails));
        $body = (string) $mails[0]->getTextBody();
        self::assertSame(self::SUBJECT, $mails[0]->getSubject());
        self::assertStringContainsString('The following settings were changed:', $body);
        self::assertStringContainsString('* Authentication required', $body);
        self::assertStringContainsString('* Minimum password length', $body);
        self::assertStringContainsString('/settings', $body);
        self::assertSame('admin', $mails[0]->getHeaders()->get('X-Redmine-Sender')?->getBodyAsString());

        // same values again: nothing changed, no mail
        $this->post('/settings/edit', ['settings' => ['login_required' => '1', 'password_min_length' => '9']]);
        self::assertSame([], $this->mails());

        // settings that are not flagged security_notifications are not reported
        $this->post('/settings/edit', ['settings' => ['app_title' => 'Other title', 'welcome_text' => 'hi']]);
        self::assertSame([], $this->mails());
    }

    // --- administrators -----------------------------------------------------------------------

    public function testGrantingAndRevokingAdminRightsNotifiesAdministrators(): void
    {
        $this->loginAs('admin');
        $jsmith = $this->user('jsmith');
        $this->post('/users/'.$jsmith->id, ['user' => ['admin' => '1']], 'PUT');
        $this->assertStatus(302);
        $mails = $this->mails();
        // the new administrator is an active administrator too
        self::assertSame(['admin@dummy.invalid', 'jsmith@example.net'], $this->recipients($mails));
        self::assertSame(self::SUBJECT, $mails[0]->getSubject());
        self::assertStringContainsString('Administrator jsmith was added.', (string) $mails[0]->getTextBody());
        self::assertStringContainsString('/users', (string) $mails[0]->getTextBody());

        // unrelated change: no mail
        $this->post('/users/'.$jsmith->id, ['user' => ['firstname' => 'Johnny']], 'PUT');
        self::assertSame([], $this->mails());

        $this->post('/users/'.$jsmith->id, ['user' => ['admin' => '0']], 'PUT');
        $mails = $this->mails();
        self::assertSame(['admin@dummy.invalid'], $this->recipients($mails));
        self::assertStringContainsString('Administrator jsmith was removed.', (string) $mails[0]->getTextBody());

        // regular users do not trigger anything when locked
        $this->post('/users/'.$jsmith->id, ['user' => ['status' => (string) Principal::STATUS_LOCKED]], 'PUT');
        self::assertSame([], $this->mails());
    }

    public function testLockingUnlockingAndDeletingAnAdministratorNotifiesAdministrators(): void
    {
        $this->loginAs('admin');
        $this->makeAdmin('jsmith');
        $id = $this->user('jsmith')->id;

        // "Lock" button of the delete confirmation
        $this->post('/users/'.$id, ['lock' => '1'], 'DELETE');
        $this->assertStatus(302);
        $mails = $this->mails();
        self::assertSame(['admin@dummy.invalid'], $this->recipients($mails));
        self::assertStringContainsString('Administrator jsmith was removed.', (string) $mails[0]->getTextBody());

        // bulk unlock
        $this->post('/users/bulk_unlock', ['ids' => [$id]]);
        $this->assertStatus(302);
        $mails = $this->mails();
        self::assertSame(['admin@dummy.invalid', 'jsmith@example.net'], $this->recipients($mails));
        self::assertStringContainsString('Administrator jsmith was added.', (string) $mails[0]->getTextBody());

        // bulk lock of an administrator and a regular user: one notification (for the administrator)
        $this->post('/users/bulk_lock', ['ids' => [$id, $this->user('dlopper')->id]]);
        $mails = $this->mails();
        self::assertSame(['admin@dummy.invalid'], $this->recipients($mails));
        self::assertStringContainsString('Administrator jsmith was removed.', (string) $mails[0]->getTextBody());

        // unlock through the form, then delete
        $this->post('/users/'.$id, ['user' => ['status' => (string) Principal::STATUS_ACTIVE]], 'PUT');
        self::assertSame(['admin@dummy.invalid', 'jsmith@example.net'], $this->recipients($this->mails()));
        $this->post('/users/'.$id, ['confirm' => 'jsmith'], 'DELETE');
        $this->assertStatus(302);
        $mails = $this->mails();
        self::assertSame(['admin@dummy.invalid'], $this->recipients($mails));
        self::assertStringContainsString('Administrator jsmith was removed.', (string) $mails[0]->getTextBody());

        // deleting a regular user is silent
        $this->post('/users/'.$this->user('rhill')->id, ['confirm' => 'rhill'], 'DELETE');
        self::assertSame([], $this->mails());
    }

    public function testCreatingAnAdministratorNotifiesAdministrators(): void
    {
        $this->loginAs('admin');
        $this->post('/users', ['user' => ['login' => 'newadmin', 'firstname' => 'New', 'lastname' => 'Admin', 'mail' => 'newadmin@example.net',
            'password' => 'password123', 'password_confirmation' => 'password123', 'admin' => '1']]);
        $this->assertStatus(302);
        $mails = $this->mails();
        self::assertSame(['admin@dummy.invalid', 'newadmin@example.net'], $this->recipients($mails));
        self::assertStringContainsString('Administrator newadmin was added.', (string) $mails[0]->getTextBody());

        // a regular user: no security notification (and no "e-mail added" notification for the only address)
        $this->post('/users', ['user' => ['login' => 'plain', 'firstname' => 'Plain', 'lastname' => 'User', 'mail' => 'plain@example.net',
            'password' => 'password123', 'password_confirmation' => 'password123']]);
        $this->assertStatus(302);
        self::assertSame([], $this->mails());
    }

    // --- UsersController#update ---------------------------------------------------------------

    public function testAdminSettingAPasswordNotifiesTheUser(): void
    {
        $this->loginAs('admin');
        $id = $this->user('jsmith')->id;
        $this->post('/users/'.$id, ['user' => ['password' => 'newpassword1', 'password_confirmation' => 'newpassword1']], 'PUT');
        $this->assertStatus(302);
        $mails = $this->mails();
        self::assertSame(['jsmith@example.net'], $this->recipients($mails));
        self::assertSame(self::SUBJECT, $mails[0]->getSubject());
        self::assertStringContainsString('Your password has been changed.', (string) $mails[0]->getTextBody());
        self::assertSame('admin', $mails[0]->getHeaders()->get('X-Redmine-Sender')?->getBodyAsString());

        // blank password: nothing is changed, nothing is sent
        $this->post('/users/'.$id, ['user' => ['password' => '', 'password_confirmation' => '', 'firstname' => 'John']], 'PUT');
        self::assertSame([], $this->mails());

        // API
        // (loginAs() replaced the legacy hash of admin by a hash of "password123")
        $this->api('PUT', '/users/'.$id.'.json', ['user' => ['password' => 'otherpassword1']], 'admin', 'password123');
        $this->assertStatus(204);
        $mails = $this->mails();
        self::assertSame(['jsmith@example.net'], $this->recipients($mails));
        self::assertStringContainsString('Your password has been changed.', (string) $mails[0]->getTextBody());
    }

    public function testActivatingARegisteredAccountNotifiesTheUser(): void
    {
        $this->loginAs('admin');
        $anna = $this->user('anna');
        $anna->status = Principal::STATUS_REGISTERED;
        $anna->language = 'en';
        $this->em->flush();
        // even with "send account information" only the activation mail is sent
        $this->post('/users/'.$anna->id, ['user' => ['status' => (string) Principal::STATUS_ACTIVE], 'send_information' => '1'], 'PUT');
        $this->assertStatus(302);
        $mails = $this->mails();
        self::assertSame(['anna@example.net'], $this->recipients($mails));
        self::assertSame('Your Redmine Reference account activation', $mails[0]->getSubject());
        self::assertStringContainsString('Your account has been activated. You can now log in.', (string) $mails[0]->getTextBody());

        // unlocking a locked account is not an activation
        $locked = $this->user('locked');
        $this->post('/users/'.$locked->id, ['user' => ['status' => (string) Principal::STATUS_ACTIVE]], 'PUT');
        $this->assertStatus(302);
        self::assertSame([], $this->mails());
    }

    // --- e-mail addresses ---------------------------------------------------------------------

    public function testOwnAdditionalEmailAddressNotifications(): void
    {
        $this->loginAs('jsmith');
        $id = $this->user('jsmith')->id;
        $this->post('/users/'.$id.'/email_addresses', ['email_address' => ['address' => 'extra@example.net']]);
        $this->assertStatus(302);
        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame(['extra@example.net', 'jsmith@example.net'], $this->to($mails[0]));
        self::assertSame(self::SUBJECT, $mails[0]->getSubject());
        self::assertStringContainsString('Email extra@example.net was added.', (string) $mails[0]->getTextBody());
        self::assertStringContainsString('/my/account', (string) $mails[0]->getTextBody());
        self::assertSame('jsmith', $mails[0]->getHeaders()->get('X-Redmine-Sender')?->getBodyAsString());

        $extra = $this->em->getRepository(EmailAddress::class)->findOneBy(['address' => 'extra@example.net']);
        self::assertNotNull($extra);

        // notifications switched off: the address is told although it no longer receives notifications
        $this->post('/users/'.$id.'/email_addresses/'.$extra->id, ['notify' => '0'], 'PUT');
        $this->assertStatus(302);
        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame(['extra@example.net', 'jsmith@example.net'], $this->to($mails[0]));
        self::assertStringContainsString('Email address extra@example.net no longer receives notifications.', (string) $mails[0]->getTextBody());

        // same value again: no change, no mail
        $this->post('/users/'.$id.'/email_addresses/'.$extra->id, ['notify' => '0'], 'PUT');
        self::assertSame([], $this->mails());

        $this->post('/users/'.$id.'/email_addresses/'.$extra->id, ['notify' => '1'], 'PUT');
        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertStringContainsString('Email address extra@example.net now receives notifications.', (string) $mails[0]->getTextBody());

        // removal: the removed address is notified too
        $this->post('/users/'.$id.'/email_addresses/'.$extra->id, [], 'DELETE');
        $this->assertStatus(302);
        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame(['extra@example.net', 'jsmith@example.net'], $this->to($mails[0]));
        self::assertStringContainsString('Email extra@example.net was removed.', (string) $mails[0]->getTextBody());

        // an invalid address is not saved and not notified
        $this->post('/users/'.$id.'/email_addresses', ['email_address' => ['address' => 'invalid']]);
        self::assertSame([], $this->mails());
    }

    public function testAdminManagingEmailAddressesOfAUserNotifiesThatUser(): void
    {
        $this->loginAs('admin');
        $id = $this->user('dlopper')->id;
        $this->post('/users/'.$id.'/email_addresses', ['email_address' => ['address' => 'dl2@example.net']]);
        $this->assertStatus(302);
        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame(['dl2@example.net', 'dlopper@example.net'], $this->to($mails[0]));
        self::assertStringContainsString('Email dl2@example.net was added.', (string) $mails[0]->getTextBody());
        self::assertSame('admin', $mails[0]->getHeaders()->get('X-Redmine-Sender')?->getBodyAsString());
    }

    public function testChangingTheMainAddressNotifiesTheOldAndTheNewAddress(): void
    {
        // My account
        $this->loginAs('jsmith');
        $this->post('/my/account', ['user' => ['mail' => 'john.smith@example.net']], 'PUT');
        $this->assertStatus(302);
        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame(['john.smith@example.net', 'jsmith@example.net'], $this->to($mails[0]));
        self::assertSame(self::SUBJECT, $mails[0]->getSubject());
        self::assertStringContainsString('Email was changed to john.smith@example.net.', (string) $mails[0]->getTextBody());
        self::assertStringContainsString('/my/account', (string) $mails[0]->getTextBody());

        // unchanged address: no mail
        $this->post('/my/account', ['user' => ['mail' => 'john.smith@example.net', 'firstname' => 'John']], 'PUT');
        self::assertSame([], $this->mails());

        // administration: users/:id
        $this->loginAs('admin');
        $this->post('/users/'.$this->user('dlopper')->id, ['user' => ['mail' => 'dave@example.net']], 'PUT');
        $this->assertStatus(302);
        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame(['dave@example.net', 'dlopper@example.net'], $this->to($mails[0]));
        self::assertStringContainsString('Email was changed to dave@example.net.', (string) $mails[0]->getTextBody());
        self::assertSame('admin', $mails[0]->getHeaders()->get('X-Redmine-Sender')?->getBodyAsString());

        // REST API
        $this->api('PUT', '/users/'.$this->user('rhill')->id.'.json', ['user' => ['mail' => 'robert@example.net']], 'admin', 'password123');
        $this->assertStatus(204);
        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame(['rhill@example.net', 'robert@example.net'], $this->to($mails[0]));
    }

    public function testAdministratorDeletingHisOwnAccountNotifiesTheOtherAdministrators(): void
    {
        $this->makeAdmin('jsmith');
        $this->loginAs('jsmith');
        $this->post('/my/account/destroy', ['confirm' => '1']);
        $this->assertStatus(302);
        $mails = $this->mails();
        self::assertSame(['admin@dummy.invalid'], $this->recipients($mails));
        self::assertStringContainsString('Administrator jsmith was removed.', (string) $mails[0]->getTextBody());
    }
}
