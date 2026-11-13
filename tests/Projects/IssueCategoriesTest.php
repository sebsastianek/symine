<?php

declare(strict_types=1);

namespace App\Tests\Projects;

use App\Entity\IssueCategory;

final class IssueCategoriesTest extends ProjectsWebTestCase
{
    public function testPagesReturn200(): void
    {
        foreach (['admin', 'jsmith'] as $login) {
            $this->login($login);
            foreach (['/projects/ecommerce/issue_categories/new', '/issue_categories/1/edit'] as $url) {
                $html = $this->get($url);
                self::assertSame(200, $this->code(), "$login $url");
                self::assertStringContainsString('issue_category[assigned_to_id]', $html);
            }
        }
        $html = $this->get('/issue_categories/1/edit');
        self::assertStringContainsString('<option value="6" selected="selected">Dave Lopper</option>', $html);
        self::assertStringContainsString('<optgroup label="Groups">', $html);
    }

    public function testReporterIsForbidden(): void
    {
        $this->login('rhill');
        $this->get('/projects/ecommerce/issue_categories/new');
        self::assertSame(403, $this->code());
    }

    public function testCreateUpdate(): void
    {
        $this->login('jsmith');
        $code = $this->submit('/projects/ecommerce/issue_categories', ['issue_category' => ['name' => 'Docs', 'assigned_to_id' => '5']]);
        self::assertSame(302, $code);
        self::assertStringContainsString('/settings', (string) $this->client->getResponse()->headers->get('Location'));
        $this->em->clear();
        $c = $this->em->getRepository(IssueCategory::class)->findOneBy(['name' => 'Docs']);
        self::assertNotNull($c);
        self::assertSame(5, $c->assignedTo?->id);

        self::assertSame(422, $this->submit('/projects/ecommerce/issue_categories', ['issue_category' => ['name' => 'Docs']]));
        self::assertSame(422, $this->submit('/projects/ecommerce/issue_categories', ['issue_category' => ['name' => '']]));

        $this->submit('/issue_categories/'.$c->id, ['_method' => 'PUT', 'issue_category' => ['name' => 'Documentation', 'assigned_to_id' => '']]);
        self::assertSame(302, $this->code());
        $this->em->clear();
        $c = $this->em->find(IssueCategory::class, $c->id);
        self::assertSame('Documentation', $c->name);
        self::assertNull($c->assignedTo);
    }

    public function testDestroyWithReassignment(): void
    {
        $this->login('jsmith');
        // category 1 (UI) is used by issue 13: confirmation screen
        $html = $this->get('/issue_categories/1');
        $this->submit('/issue_categories/1', ['_method' => 'DELETE']);
        self::assertSame(200, $this->code());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('name="todo"', $html);
        self::assertStringContainsString('<option value="2">Backend</option>', $html);

        $this->submit('/issue_categories/1', ['_method' => 'DELETE', 'todo' => 'reassign', 'reassign_to_id' => '2']);
        self::assertSame(302, $this->code());
        $this->em->clear();
        self::assertNull($this->em->find(IssueCategory::class, 1));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT category_id FROM issues WHERE id = 13'));

        $this->submit('/issue_categories/2', ['_method' => 'DELETE', 'todo' => 'nullify']);
        self::assertSame(302, $this->code());
        self::assertNull($this->em->getConnection()->fetchOne('SELECT category_id FROM issues WHERE id = 13'));
    }

    public function testApi(): void
    {
        $data = $this->api('GET', '/projects/ecommerce/issue_categories.json');
        self::assertSame(2, $data['total_count']);
        self::assertSame('Backend', $data['issue_categories'][0]['name']);
        self::assertSame(['id' => 6, 'name' => 'Dave Lopper'], $data['issue_categories'][1]['assigned_to']);

        $data = $this->api('GET', '/issue_categories/1.json');
        self::assertSame('UI', $data['issue_category']['name']);

        $data = $this->api('POST', '/projects/ecommerce/issue_categories.json', ['issue_category' => ['name' => 'API cat', 'assigned_to_id' => 5]]);
        self::assertSame(201, $this->code());
        $id = $data['issue_category']['id'];
        self::assertSame(['id' => 5, 'name' => 'John Smith'], $data['issue_category']['assigned_to']);

        $data = $this->api('POST', '/projects/ecommerce/issue_categories.json', ['issue_category' => ['name' => 'UI']]);
        self::assertSame(422, $this->code());
        self::assertSame(['Name has already been taken'], $data['errors']);

        $this->api('PUT', '/issue_categories/'.$id.'.json', ['issue_category' => ['name' => 'Renamed']]);
        self::assertSame(204, $this->code());

        // API destroy reassigns without confirmation
        $this->api('DELETE', '/issue_categories/1.json?reassign_to_id='.$id);
        self::assertSame(204, $this->code());
        self::assertSame($id, (int) $this->em->getConnection()->fetchOne('SELECT category_id FROM issues WHERE id = 13'));

        $this->api('GET', '/issue_categories/1.json');
        self::assertSame(404, $this->code());
    }
}
