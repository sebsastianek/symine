<?php

declare(strict_types=1);

namespace App\Tests\Admin;

final class EnumerationsTest extends AdminWebTestCase
{
    use LoginWithModernHashTrait;

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }

    /** @return array<string, string> name => position_name */
    private function positionNames(): array
    {
        return $this->db()->fetchAllKeyValue("SELECT name, position_name FROM enumerations WHERE type = 'IssuePriority' ORDER BY position");
    }

    public function testPagesForAdminAndNonAdmin(): void
    {
        $urls = ['/enumerations', '/enumerations/new?type=IssuePriority', '/enumerations/new?type=TimeEntryActivity', '/enumerations/new?type=DocumentCategory', '/enumerations/1/edit', '/enumerations/9/edit'];
        $this->loginAs('admin');
        foreach ($urls as $url) {
            $this->get($url);
            $this->assertStatus(200, $url);
        }
        $this->get('/enumerations/new?type=Foo');
        $this->assertStatus(404);
        $this->loginAs('jsmith');
        foreach ($urls as $url) {
            $this->get($url);
            $this->assertStatus(403, $url);
        }
    }

    public function testIndexSections(): void
    {
        $this->loginAs('admin');
        $crawler = $this->get('/enumerations');
        self::assertSame(['Document categories', 'Issue priorities', 'Activities (time tracking)'], $crawler->filter('#content h3')->each(static fn ($n) => $n->text()));
        self::assertSame(3, $crawler->filter('table.enumerations')->count());
        self::assertSame('/enumerations/1', $crawler->filter('span.sort-handle')->eq(2)->attr('data-reorder-url'));
    }

    public function testCreatePriorityRecomputesPositionNames(): void
    {
        $this->loginAs('admin');
        self::assertSame(['Low' => 'lowest', 'Normal' => 'default', 'High' => 'high3', 'Urgent' => 'high2', 'Immediate' => 'highest'], $this->positionNames());
        $r = $this->post('/enumerations', ['enumeration' => ['type' => 'IssuePriority', 'name' => 'Critical', 'active' => '1', 'is_default' => '0']]);
        self::assertSame('/enumerations', $r->headers->get('Location'));
        self::assertSame(['Low' => 'lowest', 'Normal' => 'default', 'High' => 'high4', 'Urgent' => 'high3', 'Immediate' => 'high2', 'Critical' => 'highest'], $this->positionNames());
        // new default => single default per type
        $id = (int) $this->db()->fetchOne("SELECT id FROM enumerations WHERE name = 'High'");
        $r = $this->post('/enumerations/'.$id, ['enumeration' => ['name' => 'High', 'active' => '1', 'is_default' => '1']], 'PUT');
        self::assertTrue($r->isRedirect());
        self::assertSame(['High'], $this->db()->fetchFirstColumn("SELECT name FROM enumerations WHERE type = 'IssuePriority' AND is_default = 1"));
        self::assertSame(['Low' => 'lowest', 'Normal' => 'low2', 'High' => 'default', 'Urgent' => 'high3', 'Immediate' => 'high2', 'Critical' => 'highest'], $this->positionNames());
        // deactivating
        $r = $this->post('/enumerations/'.$id, ['enumeration' => ['name' => 'High', 'active' => '0', 'is_default' => '1']], 'PUT');
        self::assertTrue($r->isRedirect());
        self::assertSame('high3', $this->positionNames()['Urgent']);
    }

    public function testValidation(): void
    {
        $this->loginAs('admin');
        $r = $this->post('/enumerations', ['enumeration' => ['type' => 'IssuePriority', 'name' => 'Normal']]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('errorExplanation', (string) $r->getContent());
        // same name in another type is fine
        $r = $this->post('/enumerations', ['enumeration' => ['type' => 'DocumentCategory', 'name' => 'Normal']]);
        self::assertTrue($r->isRedirect());
    }

    public function testReorderViaXhr(): void
    {
        $this->loginAs('admin');
        $token = $this->csrfToken();
        $this->client->request('POST', '/enumerations/5', ['_method' => 'PUT', 'enumeration' => ['position' => '1']], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'HTTP_X-CSRF-Token' => $token]);
        $this->assertStatus(200);
        self::assertSame(['Immediate', 'Low', 'Normal', 'High', 'Urgent'], array_keys($this->positionNames()));
        self::assertSame(['1', '2', '3', '4', '5'], array_map('strval', $this->db()->fetchFirstColumn("SELECT position FROM enumerations WHERE type = 'IssuePriority' ORDER BY position")));
        self::assertSame(['Immediate' => 'lowest', 'Low' => 'low2', 'Normal' => 'default', 'High' => 'high2', 'Urgent' => 'highest'], $this->positionNames());
    }

    public function testDestroyUnusedAndReassign(): void
    {
        $this->loginAs('admin');
        // unused document category
        $this->db()->executeStatement('DELETE FROM documents WHERE category_id = 7');
        $r = $this->post('/enumerations/7', [], 'DELETE');
        self::assertSame('/enumerations', $r->headers->get('Location'));
        self::assertFalse((bool) $this->db()->fetchOne('SELECT 1 FROM enumerations WHERE id = 7'));

        // priority in use => reassign form
        $used = (int) $this->db()->fetchOne('SELECT priority_id FROM issues GROUP BY priority_id ORDER BY COUNT(*) DESC LIMIT 1');
        $count = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM issues WHERE priority_id = ?', [$used]);
        self::assertGreaterThan(0, $count);
        $r = $this->post('/enumerations/'.$used, [], 'DELETE');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('reassign_to_id', (string) $r->getContent());
        $target = 1 === $used ? 2 : 1;
        $r = $this->post('/enumerations/'.$used, ['reassign_to_id' => (string) $target], 'DELETE');
        self::assertTrue($r->isRedirect());
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM issues WHERE priority_id = ?', [$used]));
        self::assertFalse((bool) $this->db()->fetchOne('SELECT 1 FROM enumerations WHERE id = ?', [$used]));
        $positions = array_map('intval', $this->db()->fetchFirstColumn("SELECT position FROM enumerations WHERE type = 'IssuePriority' ORDER BY position"));
        self::assertSame(range(1, 4), $positions);
    }

    public function testApi(): void
    {
        $this->client->request('GET', '/enumerations/issue_priorities.json');
        $r = $this->client->getResponse();
        self::assertSame(200, $r->getStatusCode());
        self::assertSame(['issue_priorities' => [
            ['id' => 1, 'name' => 'Low', 'is_default' => false, 'active' => true],
            ['id' => 2, 'name' => 'Normal', 'is_default' => true, 'active' => true],
            ['id' => 3, 'name' => 'High', 'is_default' => false, 'active' => true],
            ['id' => 4, 'name' => 'Urgent', 'is_default' => false, 'active' => true],
            ['id' => 5, 'name' => 'Immediate', 'is_default' => false, 'active' => true],
        ]], json_decode((string) $r->getContent(), true));
        $this->client->request('GET', '/enumerations/time_entry_activities.xml');
        self::assertStringContainsString('<time_entry_activities type="array"><time_entry_activity><id>8</id>', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/enumerations/document_categories.json');
        self::assertCount(2, json_decode((string) $this->client->getResponse()->getContent(), true)['document_categories']);
        $this->client->request('GET', '/enumerations/foos.json');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }
}
