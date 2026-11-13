<?php

declare(strict_types=1);

namespace App\Tests\Admin;

final class IssueStatusesTest extends AdminWebTestCase
{
    use WorkflowAdminTestTrait;

    public function testPagesAccess(): void
    {
        $this->assertAdminOnly(['/issue_statuses', '/issue_statuses/new', '/issue_statuses/1/edit']);
    }

    public function testCreateUpdateDestroy(): void
    {
        $this->loginAs('admin');
        $r = $this->post('/issue_statuses', ['issue_status' => ['name' => 'Waiting', 'description' => 'On hold', 'is_closed' => '0']]);
        self::assertTrue($r->isRedirect('/issue_statuses'));
        $s = $this->db()->fetchAssociative("SELECT * FROM issue_statuses WHERE name = 'Waiting'");
        self::assertSame(7, (int) $s['position']);
        self::assertSame('On hold', $s['description']);
        $id = (int) $s['id'];

        $r = $this->post('/issue_statuses/'.$id, ['issue_status' => ['name' => 'Waiting', 'is_closed' => '1']], 'PATCH');
        self::assertTrue($r->isRedirect('/issue_statuses'));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT is_closed FROM issue_statuses WHERE id = ?', [$id]));

        $r = $this->post('/issue_statuses', ['issue_status' => ['name' => '']]);
        self::assertSame(422, $r->getStatusCode());

        $r = $this->post('/issue_statuses/'.$id, [], 'DELETE');
        self::assertTrue($r->isRedirect('/issue_statuses'));
        self::assertFalse($this->db()->fetchOne('SELECT id FROM issue_statuses WHERE id = ?', [$id]));
    }

    public function testCannotDestroyUsedStatus(): void
    {
        $this->loginAs('admin');
        $this->post('/issue_statuses/1', [], 'DELETE');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Unable to delete issue status', $crawler->filter('#flash_error')->text());
        self::assertNotFalse($this->db()->fetchOne('SELECT id FROM issue_statuses WHERE id = 1'));
    }

    public function testClosingStatusSetsClosedOn(): void
    {
        $this->loginAs('admin');
        $this->db()->executeStatement('UPDATE issues SET closed_on = NULL WHERE status_id = 2');
        $n = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM issues WHERE status_id = 2');
        $this->post('/issue_statuses/2', ['issue_status' => ['is_closed' => '1']], 'PATCH');
        self::assertSame($n, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM issues WHERE status_id = 2 AND closed_on IS NOT NULL'));
    }

    public function testDoneRatioUpdateRefusedWithIssueField(): void
    {
        $this->loginAs('admin');
        $this->post('/issue_statuses/update_issue_done_ratio');
        $crawler = $this->client->followRedirect();
        self::assertSame(1, $crawler->filter('#flash_error')->count());
    }

    public function testDoneRatioByStatus(): void
    {
        // set before the first request: Settings values are cached for the kernel lifetime
        $this->db()->executeStatement("DELETE FROM settings WHERE name = 'issue_done_ratio'");
        $this->db()->executeStatement("INSERT INTO settings (name, value, updated_on) VALUES ('issue_done_ratio', 'issue_status', CURRENT_TIMESTAMP)");
        $this->loginAs('admin');
        $crawler = $this->get('/issue_statuses/3/edit');
        self::assertCount(1, $crawler->filter('#issue_status_default_done_ratio'));
        $this->post('/issue_statuses/3', ['issue_status' => ['default_done_ratio' => '80']], 'PATCH');
        $this->post('/issue_statuses/update_issue_done_ratio');
        $crawler = $this->client->followRedirect();
        self::assertSame(1, $crawler->filter('#flash_notice')->count());
        $n = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM issues WHERE status_id = 3');
        self::assertSame($n, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM issues WHERE status_id = 3 AND done_ratio = 80'));
    }

    public function testApi(): void
    {
        $r = $this->api('GET', '/issue_statuses.json');
        $data = json_decode((string) $r->getContent(), true);
        self::assertSame(['id' => 5, 'name' => 'Closed', 'is_closed' => true, 'description' => null], $data['issue_statuses'][4]);
        $r = $this->api('GET', '/issue_statuses.xml');
        self::assertStringContainsString('<issue_statuses type="array"><issue_status><id>1</id>', (string) $r->getContent());
    }
}
