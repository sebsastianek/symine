<?php

declare(strict_types=1);

namespace App\Tests\Issues;

use App\Entity\Issue;

/** The ticketing flow through the UI: create, assign, workflow transitions, notes, time, conflicts, delete. */
final class IssueFlowTest extends IssuesWebTestCase
{
    private function createIssue(array $attrs, array $extra = []): int
    {
        $this->login('jsmith');
        $code = $this->submit('/projects/ecommerce/issues', ['issue' => $attrs + ['tracker_id' => '1', 'priority_id' => '2']] + $extra);
        self::assertSame(302, $code, substr((string) $this->client->getResponse()->getContent(), 0, 2000));
        self::assertMatchesRegularExpression('#/issues/(\d+)$#', (string) $this->client->getResponse()->headers->get('Location'));
        preg_match('#/issues/(\d+)$#', (string) $this->client->getResponse()->headers->get('Location'), $m);

        return (int) $m[1];
    }

    public function testFullLifecycle(): void
    {
        $id = $this->createIssue(['subject' => 'Lifecycle', 'description' => 'Steps', 'watcher_user_ids' => ['', '6']]);
        $issue = $this->issue($id);
        self::assertSame('New', $issue->status->name);
        self::assertSame('John Smith', $issue->author->getName());
        $watchers = array_column($this->db("SELECT user_id FROM watchers WHERE watchable_type = 'Issue' AND watchable_id = ?", [$id]), 'user_id');
        self::assertContains(6, array_map('intval', $watchers));

        self::assertSame(302, $this->updateIssue($id, ['assigned_to_id' => '6', 'notes' => 'Please fix']));
        self::assertSame(302, $this->updateIssue($id, ['status_id' => '2', 'done_ratio' => '50'], ['time_entry' => ['hours' => '1:30', 'activity_id' => '9', 'comments' => 'Investigation']]));
        self::assertSame(302, $this->updateIssue($id, ['status_id' => '3', 'notes' => 'Fixed']));
        self::assertSame(302, $this->updateIssue($id, ['status_id' => '5']));

        $issue = $this->issue($id);
        self::assertSame('Closed', $issue->status->name);
        self::assertSame('Dave Lopper', $issue->assignedTo?->getName());
        self::assertNotNull($issue->closedOn);
        $te = $this->db('SELECT hours, comments FROM time_entries WHERE issue_id = ?', [$id]);
        self::assertSame(1.5, (float) $te[0]['hours']);

        $details = $this->db("SELECT d.prop_key, d.old_value, d.value FROM journal_details d JOIN journals j ON j.id = d.journal_id WHERE j.journalized_id = ? AND d.property = 'attr' ORDER BY d.id", [$id]);
        self::assertSame([['assigned_to_id', null, '6'], ['status_id', '1', '2'], ['done_ratio', '0', '50'], ['status_id', '2', '3'], ['status_id', '3', '5']],
            array_map(static fn ($r) => array_values($r), $details));

        $crawler = $this->client->request('GET', '/issues/'.$id);
        self::assertStringContainsString('Status</strong> changed from <i>Resolved</i> to <i>Closed</i>', $crawler->filter('#history')->html());
        self::assertSame('Closed', $crawler->filter('.attributes .status .value')->text());
    }

    public function testCreateValidationErrorsAndContinue(): void
    {
        $this->login('jsmith');
        $this->submit('/projects/ecommerce/issues', ['issue' => ['tracker_id' => '1', 'subject' => '']]);
        self::assertSame(200, $this->code());
        self::assertStringContainsString('Subject cannot be blank', (string) $this->client->getResponse()->getContent());

        $this->submit('/projects/ecommerce/issues', ['issue' => ['tracker_id' => '2', 'subject' => 'One'], 'continue' => '1']);
        self::assertStringContainsString('/projects/ecommerce/issues/new?issue%5Btracker_id%5D=2', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testCreateWithAttachment(): void
    {
        $this->login('jsmith');
        $file = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($file, 'attached content');
        $uploaded = new \Symfony\Component\HttpFoundation\File\UploadedFile($file, 'doc.txt', 'text/plain', null, true);
        $token = $this->csrf('/projects/ecommerce/issues/new');
        $this->client->request('POST', '/projects/ecommerce/issues', ['_token' => $token, 'issue' => ['tracker_id' => '1', 'subject' => 'With file'], 'attachments' => ['1' => ['description' => 'a file']]], ['attachments' => ['1' => ['file' => $uploaded]]]);
        self::assertSame(302, $this->code());
        preg_match('#/issues/(\d+)$#', (string) $this->client->getResponse()->headers->get('Location'), $m);
        $rows = $this->db("SELECT filename, description, filesize FROM attachments WHERE container_type = 'Issue' AND container_id = ?", [(int) $m[1]]);
        self::assertSame([['filename' => 'doc.txt', 'description' => 'a file', 'filesize' => 16]], array_map(static fn ($r) => ['filename' => $r['filename'], 'description' => $r['description'], 'filesize' => (int) $r['filesize']], $rows));
    }

    public function testGlobalNewIssueChoosesProject(): void
    {
        $this->login('jsmith');
        $crawler = $this->client->request('GET', '/issues/new');
        self::assertCount(1, $crawler->filter('select#issue_project_id'));
        $this->submit('/issues', ['issue' => ['project_id' => '3', 'tracker_id' => '1', 'subject' => 'In checkout']]);
        self::assertSame(302, $this->code());
        preg_match('#/issues/(\d+)$#', (string) $this->client->getResponse()->headers->get('Location'), $m);
        self::assertSame('checkout', $this->issue((int) $m[1])->project->identifier);
    }

    public function testCopyWithLink(): void
    {
        $this->login('jsmith');
        $crawler = $this->client->request('GET', '/projects/ecommerce/issues/14/copy');
        self::assertSame('Wishlist', $crawler->filter('#issue_subject')->attr('value'));
        self::assertCount(1, $crawler->filter('#copy_subtasks'));
        $this->submit('/projects/ecommerce/issues', ['copy_from' => '14', 'link_copy' => '1', 'copy_subtasks' => '1', 'issue' => ['tracker_id' => '2', 'subject' => 'Wishlist copy']]);
        self::assertSame(302, $this->code());
        preg_match('#/issues/(\d+)$#', (string) $this->client->getResponse()->headers->get('Location'), $m);
        $copy = $this->issue((int) $m[1]);
        self::assertSame(2, $copy->children->count(), 'subtasks copied');
        $rel = $this->db("SELECT relation_type FROM issue_relations WHERE issue_from_id = 14 AND issue_to_id = ?", [$copy->id]);
        self::assertSame('copied_to', $rel[0]['relation_type'] ?? null);
    }

    public function testOptimisticLockingConflict(): void
    {
        $this->login('jsmith');
        $stale = $this->lockVersion(13);
        self::assertSame(302, $this->updateIssue(13, ['subject' => 'First change']));
        $this->updateIssue(13, ['subject' => 'Second change', 'notes' => 'my notes', 'lock_version' => (string) $stale]);
        self::assertSame(200, $this->code());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('class="conflict"', $html);
        self::assertStringContainsString('conflict_resolution_add_notes', $html);
        self::assertSame('First change', $this->issue(13)->subject);

        // keep only the notes
        $this->updateIssue(13, ['subject' => 'Second change', 'notes' => 'my notes', 'lock_version' => (string) $stale], ['conflict_resolution' => 'add_notes']);
        self::assertSame(302, $this->code());
        $issue = $this->issue(13);
        self::assertSame('First change', $issue->subject);
        self::assertSame('my notes', $issue->journals->last()->notes);
    }

    public function testPrivateNotesWithChangesAreSplit(): void
    {
        $this->login('jsmith');
        $this->updateIssue(14, ['priority_id' => '3', 'notes' => 'secret', 'private_notes' => '1']);
        $rows = $this->db("SELECT j.notes, j.private_notes, (SELECT COUNT(*) FROM journal_details d WHERE d.journal_id = j.id) AS details FROM journals j WHERE journalized_id = 14 ORDER BY j.id DESC LIMIT 2");
        self::assertSame('secret', $rows[0]['notes']);
        self::assertSame(1, (int) $rows[0]['private_notes']);
        self::assertSame(0, (int) $rows[0]['details']);
        self::assertSame(0, (int) $rows[1]['private_notes']);
        self::assertSame(1, (int) $rows[1]['details']);
    }

    public function testDestroyWithSpentTimeAsksConfirmation(): void
    {
        $this->login('admin');
        $this->submit('/issues/13', ['_method' => 'delete']);
        self::assertSame(200, $this->code());
        self::assertStringContainsString('name="todo"', (string) $this->client->getResponse()->getContent());
        $this->submit('/issues', ['_method' => 'delete', 'ids' => ['13'], 'todo' => 'reassign', 'reassign_to_id' => '14']);
        self::assertSame(302, $this->code());
        $this->em->clear();
        self::assertNull($this->em->find(Issue::class, 13));
        self::assertSame(2.5, (float) $this->em->getConnection()->fetchOne('SELECT SUM(hours) FROM time_entries WHERE issue_id = 14'));
    }

    public function testBulkUpdateDoesNotWriteTheIssuesItRejects(): void
    {
        // #13 cannot be its own parent: it is rejected, while #15 takes it as parent. The rejected change must not be
        // written along with the accepted one (it used to be, which made #13 its own parent and hung the tree rebuild)
        $this->login('jsmith');
        $this->submit('/issues/bulk_update', ['ids' => ['13', '15'], 'issue' => ['parent_issue_id' => '13']]);
        self::assertContains($this->code(), [200, 302]);
        $conn = $this->em->getConnection();
        self::assertNull($conn->fetchOne('SELECT parent_id FROM issues WHERE id = 13'), '#13 is unchanged');
        self::assertSame(13, (int) $conn->fetchOne('SELECT parent_id FROM issues WHERE id = 15'));
        self::assertSame(13, (int) $conn->fetchOne('SELECT root_id FROM issues WHERE id = 15'));
        $row = $conn->fetchAssociative('SELECT lft, rgt FROM issues WHERE id = 13');
        self::assertGreaterThan(1, (int) $row['rgt'] - (int) $row['lft'], '#13 now has a subtask in its tree');
    }

    public function testBulkUpdate(): void
    {
        $this->login('jsmith');
        $this->submit('/issues/bulk_update', ['ids' => ['15', '16'], 'issue' => ['priority_id' => '4', 'assigned_to_id' => '6', 'status_id' => ''], 'notes' => 'bulk']);
        self::assertSame(302, $this->code());
        foreach ([15, 16] as $id) {
            $issue = $this->issue($id);
            self::assertSame(4, $issue->priority->id);
            self::assertSame(6, $issue->assignedTo?->id);
            self::assertSame('bulk', $issue->journals->last()->notes);
        }
    }

    public function testRemoveSubtaskLink(): void
    {
        $this->login('jsmith');
        $this->submit('/issues/15', ['_method' => 'put', 'issue' => ['parent_issue_id' => ''], 'back_url' => '/issues/14', 'no_flash' => '1']);
        self::assertSame('/issues/14', $this->client->getResponse()->headers->get('Location'));
        self::assertNull($this->issue(15)->parent);
    }
}
