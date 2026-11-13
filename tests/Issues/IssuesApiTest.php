<?php

declare(strict_types=1);

namespace App\Tests\Issues;

/** Issue REST API (structure of issues/show.api.rsb, relations, watchers, uploads, attachments). */
final class IssuesApiTest extends IssuesWebTestCase
{
    public function testShowMatchesRedmineStructure(): void
    {
        $data = $this->api('GET', '/issues/13.json?include=children,attachments,relations,changesets,journals,watchers,allowed_statuses');
        self::assertSame(200, $this->code());
        $i = $data['issue'];
        self::assertSame(['id', 'project', 'tracker', 'status', 'priority', 'author', 'assigned_to', 'category', 'fixed_version', 'subject', 'description',
            'start_date', 'due_date', 'done_ratio', 'is_private', 'estimated_hours', 'total_estimated_hours', 'spent_hours', 'total_spent_hours', 'custom_fields',
            'created_on', 'updated_on', 'closed_on', 'attachments', 'relations', 'changesets', 'journals', 'watchers', 'allowed_statuses'], array_keys($i));
        self::assertSame(['id' => 2, 'name' => 'In Progress', 'is_closed' => false], $i['status']);
        self::assertSame(['id' => 2, 'name' => 'Platforms', 'multiple' => true, 'value' => ['Linux', 'macOS']], $i['custom_fields'][1]);
        self::assertSame([3, 1], array_column($i['relations'], 'id'));
        self::assertSame(['property' => 'attr', 'name' => 'status_id', 'old_value' => '1', 'new_value' => '2'], $i['journals'][0]['details'][0]);
        self::assertSame('http://localhost/attachments/download/1/notes.txt', $i['attachments'][0]['content_url']);
        self::assertSame([5, 7], array_column($i['watchers'], 'id'));
        $raw = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('"estimated_hours":8.0', $raw);
    }

    public function testShowXml(): void
    {
        $this->client->request('GET', '/issues/13.xml?include=relations,journals', [], [], ['HTTP_X_REDMINE_API_KEY' => '3de6a93a7c5f8921fa98e0fecd9ae25695aa7536']);
        $xml = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<status id="2" name="In Progress" is_closed="false"/>', $xml);
        self::assertStringContainsString('<custom_field id="2" name="Platforms" multiple="true"><value type="array"><value>Linux</value><value>macOS</value></value></custom_field>', $xml);
        self::assertStringContainsString('<relation id="3" issue_id="13" issue_to_id="17" relation_type="relates" delay=""/>', $xml);
        self::assertStringContainsString('<journal id="15"><user id="6" name="Dave Lopper"/>', $xml);
        self::assertStringContainsString('<detail property="attr" name="status_id"><old_value>1</old_value><new_value>2</new_value></detail>', $xml);
        self::assertStringContainsString('<estimated_hours>8.0</estimated_hours>', $xml);
    }

    public function testChildrenAndPrivateIssueVisibility(): void
    {
        $data = $this->api('GET', '/issues/14.json?include=children');
        self::assertSame([15, 16], array_column($data['issue']['children'], 'id'));
        $this->api('GET', '/issues/17.json', null, $this->adminKey());
        self::assertSame(200, $this->code());
        $this->client->request('GET', '/issues/18.json');
        self::assertSame(401, $this->code());
    }

    public function testCreateUpdateDeleteLifecycle(): void
    {
        $up = $this->client->request('POST', '/uploads.json?filename=log.txt', [], [], ['HTTP_X_REDMINE_API_KEY' => '3de6a93a7c5f8921fa98e0fecd9ae25695aa7536', 'CONTENT_TYPE' => 'application/octet-stream'], 'some log');
        self::assertSame(201, $this->code());
        $token = json_decode((string) $this->client->getResponse()->getContent(), true)['upload']['token'];

        $data = $this->api('POST', '/projects/ecommerce/issues.json', ['issue' => [
            'tracker_id' => 1, 'subject' => 'API created', 'priority_id' => 3, 'watcher_user_ids' => [6],
            'custom_fields' => [['id' => 1, 'value' => 'MySQL']],
            'uploads' => [['token' => $token, 'filename' => 'log.txt', 'content_type' => 'text/plain']],
        ]]);
        self::assertSame(201, $this->code());
        $id = $data['issue']['id'];
        self::assertSame('MySQL', $data['issue']['custom_fields'][0]['value']);
        self::assertStringEndsWith('/issues/'.$id, (string) $this->client->getResponse()->headers->get('Location'));

        foreach ([['assigned_to_id' => 6, 'notes' => 'assigned'], ['status_id' => 2], ['status_id' => 3], ['status_id' => 5, 'notes' => 'done', 'private_notes' => true]] as $attrs) {
            $this->api('PUT', '/issues/'.$id.'.json', ['issue' => $attrs]);
            self::assertSame(204, $this->code());
        }
        $data = $this->api('GET', '/issues/'.$id.'.json?include=journals,attachments,watchers');
        self::assertSame('Closed', $data['issue']['status']['name']);
        self::assertNotNull($data['issue']['closed_on']);
        self::assertSame('log.txt', $data['issue']['attachments'][0]['filename']);
        self::assertContains(6, array_column($data['issue']['watchers'], 'id'));
        self::assertSame('assigned', $data['issue']['journals'][0]['notes']);

        $this->api('PUT', '/issues/'.$id.'.json', ['issue' => ['subject' => '']]);
        self::assertSame(422, $this->code());

        $this->api('DELETE', '/issues/'.$id.'.json');
        self::assertSame(204, $this->code());
        $this->api('GET', '/issues/'.$id.'.json');
        self::assertSame(404, $this->code());
    }

    public function testCreateErrors(): void
    {
        $data = $this->api('POST', '/issues.json', ['issue' => ['subject' => 'No project']]);
        self::assertSame(422, $this->code());
        self::assertSame(['Project cannot be blank', 'Tracker cannot be blank', 'Status cannot be blank'], $data['errors']);
        $data = $this->api('POST', '/issues.json', ['issue' => ['project_id' => 1, 'subject' => '']]);
        self::assertSame(422, $this->code());
        self::assertContains('Subject cannot be blank', $data['errors']);
    }

    public function testRelationsApi(): void
    {
        $data = $this->api('GET', '/issues/13/relations.json');
        self::assertSame([['id' => 3, 'issue_id' => 13, 'issue_to_id' => 17, 'relation_type' => 'relates', 'delay' => null], ['id' => 1, 'issue_id' => 13, 'issue_to_id' => 19, 'relation_type' => 'blocks', 'delay' => null]], $data['relations']);
        $data = $this->api('POST', '/issues/16/relations.json', ['relation' => ['issue_to_id' => 13, 'relation_type' => 'precedes', 'delay' => 2]]);
        self::assertSame(201, $this->code());
        self::assertSame(['issue_id' => 16, 'issue_to_id' => 13, 'relation_type' => 'precedes', 'delay' => 2], array_diff_key($data['relation'], ['id' => 1]));
        $rid = $data['relation']['id'];
        $this->api('GET', '/relations/'.$rid.'.json');
        self::assertSame(200, $this->code());
        $data = $this->api('POST', '/issues/16/relations.json', ['relation' => ['issue_to_id' => 13, 'relation_type' => 'relates']]);
        self::assertSame(422, $this->code());
        $this->api('DELETE', '/relations/'.$rid.'.json');
        self::assertSame(204, $this->code());
    }

    public function testWatchersApi(): void
    {
        $this->api('POST', '/issues/14/watchers.json', ['user_id' => 6]);
        self::assertSame(204, $this->code());
        $data = $this->api('GET', '/issues/14.json?include=watchers');
        self::assertContains(6, array_column($data['issue']['watchers'], 'id'));
        $this->api('DELETE', '/issues/14/watchers/6.json');
        self::assertSame(204, $this->code());
    }

    public function testAttachmentApi(): void
    {
        $data = $this->api('GET', '/attachments/1.json');
        self::assertSame(['id', 'filename', 'filesize', 'content_type', 'description', 'content_url', 'author', 'created_on'], array_keys($data['attachment']));
        $this->api('PATCH', '/attachments/1.json', ['attachment' => ['description' => 'Notes file']]);
        self::assertSame(204, $this->code());
        self::assertSame('Notes file', $this->api('GET', '/attachments/1.json')['attachment']['description']);
        $this->api('DELETE', '/attachments/1.json');
        self::assertSame(204, $this->code());
        $this->api('GET', '/attachments/1.json');
        self::assertSame(404, $this->code());
    }

    public function testJournalApiUpdate(): void
    {
        $this->api('PUT', '/journals/15.json', ['journal' => ['notes' => 'API edited']]);
        self::assertSame(204, $this->code());
        self::assertSame('API edited', $this->api('GET', '/issues/13.json?include=journals')['issue']['journals'][0]['notes']);
    }
}
