<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TimeEntryAndUserImportTest extends ImportWebTestCase
{
    public function testTimeEntryImport(): void
    {
        $this->login('jsmith');
        $this->client->request('GET', '/time_entries/imports/new');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('Import time entries', (string) $this->client->getResponse()->getContent());

        $csv = "Date,Hours,Activity,Issue,Comment,User\n"
            ."05/01/2026,2.5,Development,#13,Did work,dlopper\n"
            ."06/01/2026,1:30,design,,Designing,jsmith\n"
            ."07/01/2026,abc,Development,,Bad hours,jsmith\n"
            ."08/01/2026,1,Unknown activity,,No activity,jsmith\n"
            ."09/01/2026,1,Development,#9999,Unknown issue,jsmith\n";
        $id = $this->upload('TimeEntryImport', $csv, '1');
        $this->postSettings($id, ['date_format' => '%d/%m/%Y']);
        self::assertStringEndsWith("/imports/$id/mapping", $this->location());

        $crawler = $this->client->request('GET', "/imports/$id/mapping");
        self::assertSame(200, $this->code());
        self::assertSame('0', $crawler->filter('#import_mapping_spent_on option[selected]')->attr('value'));
        self::assertSame('1', $crawler->filter('#import_mapping_hours option[selected]')->attr('value'));
        self::assertSame('5', $crawler->filter('#import_mapping_user option[selected]')->attr('value'));
        self::assertGreaterThan(0, $crawler->filter('#import_mapping_activity option[value="value:9"]')->count());

        $this->postMapping($id, ['project_id' => '1', 'activity' => '2', 'user' => '5', 'issue_id' => '3', 'spent_on' => '0', 'hours' => '1', 'comments' => '4']);
        $html = $this->runImport($id);
        self::assertStringContainsString('2 items have been imported', $html);
        self::assertStringContainsString('3 out of 5 items could not be imported', $html);

        $items = $this->items($id);
        self::assertStringContainsString('Hours is invalid', (string) $items[2]['message']);
        self::assertStringContainsString('Activity cannot be blank', (string) $items[3]['message']);
        self::assertNull($items[4]['obj_id']);

        $conn = $this->em->getConnection();
        $te = $conn->fetchAssociative('SELECT * FROM time_entries WHERE id = ?', [$items[0]['obj_id']]);
        self::assertSame(6, (int) $te['user_id']);
        self::assertSame(5, (int) $te['author_id']);
        self::assertSame(13, (int) $te['issue_id']);
        self::assertSame(1, (int) $te['project_id']);
        self::assertSame(9, (int) $te['activity_id']);
        self::assertSame(2.5, (float) $te['hours']);
        self::assertSame('2026-01-05', substr((string) $te['spent_on'], 0, 10));
        self::assertSame(2026, (int) $te['tyear']);
        $te2 = $conn->fetchAssociative('SELECT * FROM time_entries WHERE id = ?', [$items[1]['obj_id']]);
        self::assertSame(1.5, (float) $te2['hours']);
        self::assertSame(8, (int) $te2['activity_id']);
        self::assertSame(5, (int) $te2['user_id']);
        self::assertStringContainsString('Did work', $html);
    }

    public function testUserImport(): void
    {
        $this->login('jsmith');
        $this->client->request('GET', '/users/imports/new');
        self::assertSame(403, $this->code());

        $this->login('admin');
        $this->client->request('GET', '/users/imports/new');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('Import users', (string) $this->client->getResponse()->getContent());

        $csv = "Login,Firstname,Lastname,Email,Language,Administrator,Password,Status\n"
            ."newbie,New,Bie,newbie@example.net,fr,yes,Secret123,active\n"
            ."JSMITH,Dup,Login,dup@example.net,,,,\n"
            ."nomail,No,Mail,,,,,\n"
            ."locked1,Lock,Ed,locked1@example.net,,no,,locked\n"
            ."short,Short,Pass,short@example.net,,,abc,\n";
        $id = $this->upload('UserImport', $csv);
        $this->postSettings($id, []);
        $crawler = $this->client->request('GET', "/imports/$id/mapping");
        self::assertSame(200, $this->code());
        self::assertSame('0', $crawler->filter('#import_mapping_login option[selected]')->attr('value'));
        self::assertSame('3', $crawler->filter('#import_mapping_mail option[selected]')->attr('value'));
        self::assertSame('5', $crawler->filter('#import_mapping_admin option[selected]')->attr('value'));

        $this->postMapping($id, ['login' => '0', 'firstname' => '1', 'lastname' => '2', 'mail' => '3', 'language' => '4', 'admin' => '5', 'password' => '6', 'status' => '7']);
        $html = $this->runImport($id);
        self::assertStringContainsString('2 items have been imported', $html);
        self::assertStringContainsString('3 out of 5 items could not be imported', $html);

        $items = $this->items($id);
        self::assertStringContainsString('Login has already been taken', (string) $items[1]['message']);
        self::assertStringContainsString('Email cannot be blank', (string) $items[2]['message']);
        self::assertStringContainsString('Password is too short', (string) $items[4]['message']);

        $this->em->clear();
        $newbie = $this->em->getRepository(User::class)->find($items[0]['obj_id']);
        self::assertSame('newbie', $newbie->login);
        self::assertSame('newbie@example.net', $newbie->getMail());
        self::assertSame('fr', $newbie->language);
        self::assertTrue($newbie->admin);
        self::assertSame(User::STATUS_ACTIVE, $newbie->status);
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($newbie, 'Secret123'));
        $locked = $this->em->getRepository(User::class)->find($items[3]['obj_id']);
        self::assertSame(User::STATUS_LOCKED, $locked->status);
        self::assertFalse($locked->admin);
        self::assertStringContainsString('locked1', $html);
    }
}
