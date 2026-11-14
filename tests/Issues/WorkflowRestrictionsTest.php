<?php

declare(strict_types=1);

namespace App\Tests\Issues;

use App\Entity\IssueStatus;
use App\Service\IssueService;

/** Status transitions, read-only and required fields from the workflow, applied by the issue form. */
final class WorkflowRestrictionsTest extends IssuesWebTestCase
{
    private function addFieldRule(string $field, string $rule, int $roleId = 4, int $trackerId = 1, int $statusId = 1): void
    {
        $this->em->getConnection()->executeStatement(
            "INSERT INTO workflows (tracker_id, old_status_id, new_status_id, role_id, assignee, author, type, field_name, rule) VALUES (?, ?, 0, ?, 0, 0, 'WorkflowPermission', ?, ?)",
            [$trackerId, $statusId, $roleId, $field, $rule]
        );
    }

    public function testStatusSelectOnlyOffersAllowedStatuses(): void
    {
        $dev = $this->login('dlopper');
        $crawler = $this->client->request('GET', '/issues/15/edit');
        $offered = $crawler->filter('#issue_status_id option')->each(static fn ($o) => $o->text());
        $svc = static::getContainer()->get(IssueService::class);
        static::getContainer()->get(\App\Security\CurrentUser::class)->set($dev);
        $expected = array_map(static fn (IssueStatus $s) => $s->name, $svc->newStatusesAllowedTo($this->issue(15), $dev));
        self::assertSame($expected, $offered);
    }

    public function testDisallowedTransitionIsIgnored(): void
    {
        // Reporter (rhill) has no workflow transition on Bug from "In Progress"
        $this->login('rhill');
        $this->updateIssue(13, ['status_id' => '5', 'notes' => 'trying to close']);
        $issue = $this->issue(13);
        self::assertSame('In Progress', $issue->status->name);
        self::assertSame('trying to close', $issue->journals->last()->notes);
    }

    public function testRequiredFieldIsMarkedAndEnforced(): void
    {
        $this->addFieldRule('due_date', 'required');
        $this->login('dlopper');
        $crawler = $this->client->request('GET', '/projects/ecommerce/issues/new?issue[tracker_id]=1');
        self::assertCount(1, $crawler->filter('label[for="issue_due_date"] span.required'));
        self::assertCount(0, $crawler->filter('label[for="issue_start_date"] span.required'));

        $this->submit('/projects/ecommerce/issues', ['issue' => ['tracker_id' => '1', 'subject' => 'Needs due date']]);
        self::assertSame(200, $this->code());
        self::assertStringContainsString('Due date cannot be blank', (string) $this->client->getResponse()->getContent());

        $this->submit('/projects/ecommerce/issues', ['issue' => ['tracker_id' => '1', 'subject' => 'Needs due date', 'due_date' => '2030-01-01']]);
        self::assertSame(302, $this->code());
    }

    public function testReadOnlyFieldIsNotEditable(): void
    {
        $this->addFieldRule('priority_id', 'readonly', 4, 2, 1);
        $this->login('dlopper');
        $crawler = $this->client->request('GET', '/issues/15/edit');
        self::assertCount(0, $crawler->filter('#issue_priority_id'), 'read-only field is not rendered');
        self::assertCount(1, $crawler->filter('#issue_subject'));
        $before = $this->issue(15)->priority->id;
        $this->updateIssue(15, ['priority_id' => '5', 'subject' => 'Renamed']);
        $issue = $this->issue(15);
        self::assertSame($before, $issue->priority->id);
        self::assertSame('Renamed', $issue->subject);
    }

    /** Custom fields are named by their id in the workflow rules: a read-only one is not on the form and keeps its value */
    public function testReadOnlyCustomFieldIsNotEditable(): void
    {
        // Developer / Bug / In Progress (#13, Database = PostgreSQL)
        $this->addFieldRule('1', 'readonly', 4, 1, 2);
        $value = fn (): string => (string) $this->em->getConnection()->fetchOne("SELECT value FROM custom_values WHERE customized_type = 'Issue' AND customized_id = 13 AND custom_field_id = 1");
        $before = $value();
        self::assertNotSame('Oracle', $before);

        $this->login('dlopper');
        $crawler = $this->client->request('GET', '/issues/13/edit');
        self::assertCount(0, $crawler->filter('#issue_custom_field_values_1'), 'read-only custom field is not rendered');
        self::assertCount(1, $crawler->filter('#issue_custom_field_values_2'), 'a custom field without a rule stays');

        $this->updateIssue(13, ['custom_field_values' => ['1' => 'Oracle'], 'notes' => 'trying to change the database']);
        self::assertSame($before, $value());
    }

    public function testNotesOnlyUserCannotChangeAttributes(): void
    {
        $this->login('rhill');
        $crawler = $this->client->request('GET', '/issues/13');
        self::assertCount(0, $crawler->filter('#update #all_attributes'), 'no properties fieldset');
        self::assertCount(1, $crawler->filter('#update #issue_notes'));
        $this->updateIssue(13, ['subject' => 'Hacked', 'notes' => 'comment']);
        self::assertSame('Cart total is wrong with discounts', $this->issue(13)->subject);
    }

    public function testParentCannotBeClosedWithOpenSubtasks(): void
    {
        $this->login('jsmith');
        $crawler = $this->client->request('GET', '/issues/14/edit');
        $offered = $crawler->filter('#issue_status_id option')->each(static fn ($o) => $o->text());
        self::assertNotContains('Closed', $offered);
    }
}
