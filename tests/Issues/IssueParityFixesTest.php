<?php

declare(strict_types=1);

namespace App\Tests\Issues;

use App\Entity\IssueCustomField;
use App\Entity\TimeEntryCustomField;
use App\Service\IssueService;

/**
 * Behaviours of Redmine's Issue model/controllers that were missing or different (issue-tracking parity audit).
 * Expected results come from reference/redmine/test (unit/issue_test.rb, functional/issues_controller_test.rb, ...).
 */
final class IssueParityFixesTest extends IssuesWebTestCase
{
    private function setting(string $name, string $value): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM settings WHERE name = ?', [$name]);
        $conn->executeStatement("INSERT INTO settings (name, value, updated_on) VALUES (?, ?, datetime('now'))", [$name, $value]);
    }

    /** Creates an issue through the API as admin and returns its id */
    private function create(array $attrs): int
    {
        $r = $this->api('POST', '/issues.json', ['issue' => $attrs + ['project_id' => 1, 'tracker_id' => 1, 'subject' => 'Parity']], $this->adminKey());
        self::assertSame(201, $this->code(), json_encode($r));

        return (int) $r['issue']['id'];
    }

    private function update(int $id, array $attrs): array
    {
        return $this->api('PUT', '/issues/'.$id.'.json', ['issue' => $attrs], $this->adminKey());
    }

    /** @return list<array<string, mixed>> journal details of an issue: journal id, notes, private flag, property, key, old, new */
    private function details(int $issueId): array
    {
        return $this->db("SELECT j.id, j.notes, j.private_notes, j.user_id, d.property, d.prop_key, d.old_value, d.value FROM journals j LEFT JOIN journal_details d ON d.journal_id = j.id WHERE j.journalized_type = 'Issue' AND j.journalized_id = ? ORDER BY j.id, d.id", [$issueId]);
    }

    private function row(int $id): array
    {
        return $this->db('SELECT * FROM issues WHERE id = ?', [$id])[0];
    }

    // ------------------------------------------------------------------------------------------
    // Finding 4 / 18: copy
    // ------------------------------------------------------------------------------------------

    /** Issue#after_create_from_copy: "Do not copy subtasks that are not visible to avoid potential disclosure of private data" */
    public function testCopyDoesNotCopyInvisibleSubtasksAndLinksTheCopiedOnes(): void
    {
        // managers only see the private issues they created or are assigned to
        $this->em->getConnection()->executeStatement("UPDATE roles SET issues_visibility = 'default' WHERE id = 3");
        $parent = $this->create(['subject' => 'Copy parent', 'done_ratio' => 0]);
        $public = $this->create(['subject' => 'Public child', 'parent_issue_id' => $parent, 'done_ratio' => 80]);
        $private = $this->create(['subject' => 'SECRET private child', 'description' => 'secret', 'parent_issue_id' => $parent, 'is_private' => true]);
        $this->api('GET', '/issues/'.$private.'.json');
        self::assertSame(403, $this->code(), 'jsmith cannot see the private subtask');

        $this->login('jsmith');
        self::assertSame(302, $this->submit('/projects/ecommerce/issues', [
            'copy_from' => $parent, 'copy_subtasks' => '1', 'link_copy' => '1',
            'issue' => ['subject' => 'Copy of parent', 'tracker_id' => 1, 'status_id' => 1, 'priority_id' => 2],
        ]));
        $copy = (int) $this->em->getConnection()->fetchOne("SELECT id FROM issues WHERE subject = 'Copy of parent'");
        $children = $this->db('SELECT id, subject, author_id, done_ratio FROM issues WHERE parent_id = ?', [$copy]);
        self::assertSame(['Public child'], array_column($children, 'subject'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM issues WHERE subject = 'SECRET private child' AND id <> ?", [$private]));
        self::assertSame(5, (int) $children[0]['author_id'], 'the subtask copy has the author of the copy');
        // test_copy_should_reset_done_ratio_to_default_value
        self::assertSame(0, (int) $children[0]['done_ratio']);
        // the copied subtask is linked to its original like the copied issue itself
        $relations = $this->db("SELECT issue_from_id, issue_to_id FROM issue_relations WHERE relation_type = 'copied_to' ORDER BY issue_from_id");
        self::assertSame([[$parent, $copy], [$public, (int) $children[0]['id']]], array_map(static fn ($r) => [(int) $r['issue_from_id'], (int) $r['issue_to_id']], $relations));
    }

    /** test_copy_should_reset_done_ratio_to_default_value + status reset, on the copy form and on bulk copy */
    public function testCopyResetsDoneRatioAndBulkCopyDoesNotKeepTheParent(): void
    {
        $this->login('admin');
        $crawler = $this->client->request('GET', '/projects/ecommerce/issues/13/copy');
        self::assertSame(200, $this->code());
        self::assertSame('0', $crawler->filter('#issue_done_ratio option[selected]')->attr('value'), 'issue #13 is 30% done, its copy starts at 0%');
        self::assertSame('1', $crawler->filter('#issue_status_id option[selected]')->attr('value'), 'issue #13 is In Progress, its copy is New');

        // bulk copy of a subtask: Issue#copy does not copy the parent
        self::assertSame(302, $this->submit('/issues/bulk_update', ['ids' => [15], 'copy' => '1', 'link_copy' => '0']));
        $copy = $this->db("SELECT id, parent_id, done_ratio FROM issues WHERE subject = 'Wishlist sharing' AND id <> 15");
        self::assertCount(1, $copy);
        self::assertNull($copy[0]['parent_id']);
        self::assertSame(0, (int) $copy[0]['done_ratio']);
    }

    /** test_new_as_copy_should_preserve_parent_id */
    public function testCopyFormKeepsTheParent(): void
    {
        $this->login('admin');
        $crawler = $this->client->request('GET', '/projects/ecommerce/issues/15/copy');
        self::assertSame('14', $crawler->filter('#issue_parent_issue_id')->attr('value'));
    }

    /** test_copy_should_only_copy_editable_custom_fields (Issue#force_default_value_on_noneditable_custom_fields) */
    public function testCopyOnlyCopiesEditableCustomFields(): void
    {
        $conn = $this->em->getConnection();
        // "Database" becomes visible to developers only: jsmith (manager of eCommerce) cannot edit it
        $conn->executeStatement('UPDATE custom_fields SET visible = 0 WHERE id = 1');
        $conn->executeStatement('INSERT INTO custom_fields_roles (custom_field_id, role_id) VALUES (1, 4)');
        self::assertSame('PostgreSQL', $conn->fetchOne("SELECT value FROM custom_values WHERE customized_type = 'Issue' AND customized_id = 13 AND custom_field_id = 1"));
        $this->login('jsmith');
        self::assertSame(302, $this->submit('/projects/ecommerce/issues', ['copy_from' => 13, 'issue' => ['subject' => 'Copy without database', 'tracker_id' => 1, 'status_id' => 1, 'priority_id' => 2]]));
        $copy = (int) $conn->fetchOne("SELECT id FROM issues WHERE subject = 'Copy without database'");
        self::assertGreaterThan(0, $copy);
        $value = $conn->fetchOne("SELECT value FROM custom_values WHERE customized_type = 'Issue' AND customized_id = ? AND custom_field_id = 1", [$copy]);
        self::assertContains($value, [false, null, ''], 'the value the user cannot edit is not copied');
    }

    // ------------------------------------------------------------------------------------------
    // Finding 5: private notes in bulk edit, B: split of private notes
    // ------------------------------------------------------------------------------------------

    public function testBulkEditKeepsNotesPrivate(): void
    {
        $this->login('jsmith');
        self::assertSame(302, $this->submit('/issues/bulk_update', ['ids' => [15], 'notes' => 'bulk private note', 'issue' => ['private_notes' => '1', 'priority_id' => '5']]));
        $details = $this->details(15);
        $notes = array_values(array_filter($details, static fn ($d) => 'bulk private note' === $d['notes']));
        self::assertCount(1, $notes);
        self::assertSame(1, (int) $notes[0]['private_notes'], 'the note stays private');
        self::assertNull($notes[0]['property'], 'Journal#split_private_notes: the private journal has no details');
        $changes = array_values(array_filter($details, static fn ($d) => 'priority_id' === $d['prop_key']));
        self::assertCount(1, $changes);
        self::assertSame(0, (int) $changes[0]['private_notes'], 'the changes are in a public journal');
        self::assertNotSame($notes[0]['id'], $changes[0]['id']);
    }

    public function testBulkEditNotesArePublicByDefault(): void
    {
        $this->login('jsmith');
        self::assertSame(302, $this->submit('/issues/bulk_update', ['ids' => [14], 'notes' => 'bulk public note', 'issue' => ['private_notes' => '0']]));
        $notes = array_values(array_filter($this->details(14), static fn ($d) => 'bulk public note' === $d['notes']));
        self::assertSame(0, (int) $notes[0]['private_notes']);
    }

    // ------------------------------------------------------------------------------------------
    // Finding 2: required custom fields only block the updates that touch custom fields
    // ------------------------------------------------------------------------------------------

    /** Issue#validate_custom_field_values: only when custom field values are assigned, only the editable ones */
    public function testRequiredCustomFieldDoesNotBlockUnrelatedUpdates(): void
    {
        $conn = $this->em->getConnection();
        // "Database" (trackers Bug and Feature) is blank on #14 and becomes required afterwards
        $conn->executeStatement('UPDATE custom_fields SET is_required = 1 WHERE id = 1');

        // a reporter (add_issue_notes only) can still comment
        $this->login('rhill');
        self::assertSame(302, $this->updateIssue(14, ['notes' => 'note by a reporter']));
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM journals WHERE journalized_id = 14 AND notes = 'note by a reporter'"));

        // context menu / bulk edit of another attribute
        $this->login('jsmith');
        self::assertSame(302, $this->submit('/issues/bulk_update', ['ids' => [16], 'issue' => ['priority_id' => '3']]));
        self::assertSame(3, (int) $this->row(16)['priority_id']);

        // partial API update
        $this->api('PUT', '/issues/14.json', ['issue' => ['notes' => 'note through the API']]);
        self::assertSame(204, $this->code());

        // submitting the (blank) custom field is still refused
        $this->api('PUT', '/issues/14.json', ['issue' => ['custom_fields' => [['id' => 1, 'value' => '']]]]);
        self::assertSame(422, $this->code());
        $crawler = $this->client->request('POST', '/issues/14', ['_method' => 'patch', '_token' => $this->csrf(), 'issue' => ['subject' => 'Wishlist', 'custom_field_values' => ['1' => ''], 'lock_version' => (string) $this->lockVersion(14)]]);
        self::assertStringContainsString('Database cannot be blank', $crawler->filter('#errorExplanation')->text());

        // and a new issue must have it
        $r = $this->api('POST', '/issues.json', ['issue' => ['project_id' => 1, 'tracker_id' => 1, 'subject' => 'Without database']]);
        self::assertSame(422, $this->code());
        self::assertSame(['Database cannot be blank'], $r['errors']);
    }

    // ------------------------------------------------------------------------------------------
    // Finding 3: Issue#after_project_change
    // ------------------------------------------------------------------------------------------

    /** test_move_to_another_project_should_update_time_entries_activity + subtasks moved along */
    public function testMoveToAnotherProjectMovesSubtasksAndTimeEntries(): void
    {
        $conn = $this->em->getConnection();
        // project specific activity of Checkout overriding "Design" (#8)
        $conn->executeStatement("INSERT INTO enumerations (name, position, is_default, type, active, project_id, parent_id) VALUES ('Design', 1, 0, 'TimeEntryActivity', 1, 3, 8)");
        $override = (int) $conn->lastInsertId();
        foreach ([14, 15] as $issueId) {
            $conn->executeStatement("INSERT INTO time_entries (project_id, user_id, author_id, issue_id, hours, activity_id, spent_on, tyear, tmonth, tweek, created_on, updated_on) VALUES (1, 5, 5, ?, 1, 8, '2026-11-02', 2026, 11, 45, datetime('now'), datetime('now'))", [$issueId]);
        }

        $this->update(14, ['project_id' => 3]);
        self::assertSame(204, $this->code());
        self::assertSame([3, 3, 3], array_map('intval', array_column($this->db('SELECT project_id FROM issues WHERE id IN (14, 15, 16) ORDER BY id'), 'project_id')), 'the subtasks follow their parent');
        self::assertSame([[3, $override], [3, $override]], array_map(static fn ($r) => [(int) $r['project_id'], (int) $r['activity_id']], $this->db('SELECT project_id, activity_id FROM time_entries WHERE issue_id IN (14, 15) ORDER BY issue_id')));
        // the subtasks are saved without a journal (child.save without init_journal)
        self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM journal_details d JOIN journals j ON j.id = d.journal_id WHERE j.journalized_id = 15 AND d.prop_key = 'project_id'"));
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM journal_details d JOIN journals j ON j.id = d.journal_id WHERE j.journalized_id = 14 AND d.prop_key = 'project_id'"));

        // and back: the shared activity is used again
        $this->update(14, ['project_id' => 1]);
        self::assertSame(204, $this->code());
        self::assertSame([[1, 8], [1, 8]], array_map(static fn ($r) => [(int) $r['project_id'], (int) $r['activity_id']], $this->db('SELECT project_id, activity_id FROM time_entries WHERE issue_id IN (14, 15) ORDER BY issue_id')));
        self::assertSame([1, 1, 1], array_map('intval', array_column($this->db('SELECT project_id FROM issues WHERE id IN (14, 15, 16) ORDER BY id'), 'project_id')));
    }

    /** test_move_to_another_project_should_set_error_message_on_child_failure */
    public function testMoveToAnotherProjectFailsWhenASubtaskCannotBeMoved(): void
    {
        $conn = $this->em->getConnection();
        // Internal Tools only has the Bug tracker: #14 (Feature) gets it, its subtasks keep theirs and are refused
        $conn->executeStatement('DELETE FROM projects_trackers WHERE project_id = 2 AND tracker_id <> 1');
        $r = $this->update(14, ['project_id' => 2]);
        self::assertSame(422, $this->code());
        self::assertSame(['Subtask #15 could not be moved to the new project: Tracker is not included in the list'], $r['errors']);
        self::assertSame([1, 1, 1], array_map('intval', array_column($this->db('SELECT project_id FROM issues WHERE id IN (14, 15, 16) ORDER BY id'), 'project_id')), 'nothing was moved');
        self::assertSame(2, (int) $this->row(14)['tracker_id']);
    }

    /** Issue#after_project_change: "Delete issue relations" unless Setting.cross_project_issue_relations? */
    public function testMoveToAnotherProjectRemovesRelationsWithoutCrossProjectRelations(): void
    {
        $conn = $this->em->getConnection();
        self::assertSame(2, (int) $conn->fetchOne('SELECT COUNT(*) FROM issue_relations WHERE issue_from_id = 13 OR issue_to_id = 13'));
        $this->update(13, ['project_id' => 3]);
        self::assertSame(204, $this->code());
        self::assertSame(2, (int) $conn->fetchOne('SELECT COUNT(*) FROM issue_relations WHERE issue_from_id = 13 OR issue_to_id = 13'), 'kept when cross-project relations are allowed');

        $this->setting('cross_project_issue_relations', '0');
        $this->update(13, ['project_id' => 1]);
        self::assertSame(204, $this->code());
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM issue_relations WHERE issue_from_id = 13 OR issue_to_id = 13'));
    }

    // ------------------------------------------------------------------------------------------
    // Finding 6: tracker change
    // ------------------------------------------------------------------------------------------

    /** Issue#clear_disabled_fields */
    public function testTrackerChangeClearsDisabledCoreFields(): void
    {
        $conn = $this->em->getConnection();
        $id = $this->create(['start_date' => '2026-11-02', 'due_date' => '2026-11-20', 'category_id' => 1, 'estimated_hours' => 4]);
        // Support: category (bit 1) and due date (bit 5) disabled
        $conn->executeStatement('UPDATE trackers SET fields_bits = 34 WHERE id = 3');
        $this->update($id, ['tracker_id' => 3]);
        self::assertSame(204, $this->code());
        $row = $this->row($id);
        self::assertSame(3, (int) $row['tracker_id']);
        self::assertNull($row['due_date']);
        self::assertNull($row['category_id']);
        self::assertSame('2026-11-02', substr((string) $row['start_date'], 0, 10));
        self::assertSame(4.0, (float) $row['estimated_hours']);
        $keys = array_column($this->details($id), 'prop_key');
        self::assertContains('due_date', $keys);
        self::assertContains('category_id', $keys);
    }

    /** Issue#tracker=: "set the status to the default status of the new tracker if the status was the default for the previous tracker" */
    public function testTrackerChangeResetsTheDefaultStatus(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE trackers SET default_status_id = 4 WHERE id = 3');
        $new = $this->create([]);
        $this->update($new, ['tracker_id' => 3]);
        self::assertSame(204, $this->code());
        self::assertSame(4, (int) $this->row($new)['status_id'], 'New was the default status of Bug: Feedback, the default of Support, replaces it');

        // a status that is not the previous default and belongs to the new tracker is kept
        $this->update(13, ['tracker_id' => 3]);
        self::assertSame(204, $this->code());
        self::assertSame(2, (int) $this->row(13)['status_id']);
    }

    /** reassign_custom_field_values: the values of the fields that do not apply to the new tracker are removed and journalized */
    public function testTrackerChangeRemovesTheValuesOfFieldsThatDoNotApply(): void
    {
        $conn = $this->em->getConnection();
        // Severity (#7) only applies to Bug
        $id = $this->create(['custom_fields' => [['id' => 7, 'value' => '2'], ['id' => 1, 'value' => 'MySQL']]]);
        $this->update($id, ['tracker_id' => 2]);
        self::assertSame(204, $this->code());
        self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM custom_values WHERE customized_type = 'Issue' AND customized_id = ? AND custom_field_id = 7", [$id]));
        self::assertSame('MySQL', $conn->fetchOne("SELECT value FROM custom_values WHERE customized_type = 'Issue' AND customized_id = ? AND custom_field_id = 1", [$id]));
        $cf = array_values(array_filter($this->details($id), static fn ($d) => 'cf' === $d['property']));
        self::assertSame([['7', '2', null]], array_map(static fn ($d) => [$d['prop_key'], $d['old_value'], $d['value']], $cf));
        // back to Bug: the value does not come back
        $this->update($id, ['tracker_id' => 1]);
        $r = $this->api('GET', '/issues/'.$id.'.json', null, $this->adminKey());
        $severity = array_values(array_filter($r['issue']['custom_fields'], static fn ($c) => 7 === $c['id']));
        self::assertContains($severity[0]['value'], [null, ''], json_encode($severity));
    }

    // ------------------------------------------------------------------------------------------
    // Finding 7: Issue#close_duplicates
    // ------------------------------------------------------------------------------------------

    public function testClosingAnIssueClosesItsDuplicatesWithTheSameNotes(): void
    {
        $original = $this->create(['subject' => 'Original']);
        $duplicate = $this->create(['subject' => 'Duplicate']);
        $this->api('POST', '/issues/'.$duplicate.'/relations.json', ['relation' => ['issue_to_id' => $original, 'relation_type' => 'duplicates']], $this->adminKey());
        self::assertSame(201, $this->code());
        $this->update($original, ['status_id' => 5, 'notes' => 'Fixed in the last build']);
        self::assertSame(204, $this->code());
        self::assertSame(5, (int) $this->row($duplicate)['status_id']);
        self::assertNotNull($this->row($duplicate)['closed_on']);
        $status = array_values(array_filter($this->details($duplicate), static fn ($d) => 'status_id' === $d['prop_key']));
        self::assertCount(1, $status);
        self::assertSame('Fixed in the last build', $status[0]['notes'], 'same user and notes as the closed issue');
        self::assertSame(1, (int) $status[0]['user_id']);
    }

    public function testDuplicatesAreNotClosedWhenTheSettingIsOff(): void
    {
        $this->setting('close_duplicate_issues', '0');
        $original = $this->create(['subject' => 'Original']);
        $duplicate = $this->create(['subject' => 'Duplicate']);
        $this->api('POST', '/issues/'.$duplicate.'/relations.json', ['relation' => ['issue_to_id' => $original, 'relation_type' => 'duplicates']], $this->adminKey());
        $this->update($original, ['status_id' => 5]);
        self::assertSame(204, $this->code());
        self::assertSame(5, (int) $this->row($original)['status_id']);
        self::assertSame(1, (int) $this->row($duplicate)['status_id']);
    }

    // ------------------------------------------------------------------------------------------
    // Finding 8: Issue#create_parent_issue_journal
    // ------------------------------------------------------------------------------------------

    public function testParentHistoryRecordsAddedAndRemovedSubtasks(): void
    {
        $parent = $this->create(['subject' => 'Parent']);
        $other = $this->create(['subject' => 'Other parent']);
        $child = $this->create(['subject' => 'Child', 'parent_issue_id' => $parent]);
        $childDetails = static fn (array $rows) => array_values(array_map(static fn ($d) => [$d['old_value'], $d['value']], array_filter($rows, static fn ($d) => 'attr' === $d['property'] && 'child_id' === $d['prop_key'])));
        self::assertSame([[null, (string) $child]], $childDetails($this->details($parent)));

        // moved to another parent
        $this->update($child, ['parent_issue_id' => $other]);
        self::assertSame(204, $this->code());
        self::assertSame([[null, (string) $child], [(string) $child, null]], $childDetails($this->details($parent)));
        self::assertSame([[null, (string) $child]], $childDetails($this->details($other)));

        // deleted
        $this->api('DELETE', '/issues/'.$child.'.json', null, $this->adminKey());
        self::assertSame(204, $this->code());
        self::assertSame([[null, (string) $child], [(string) $child, null]], $childDetails($this->details($other)));

        $this->login('admin');
        $html = $this->get('/issues/'.$parent.'?tab=history');
        self::assertStringContainsString('Subtask', $html);
    }

    // ------------------------------------------------------------------------------------------
    // Finding 9: rescheduling of following issues
    // ------------------------------------------------------------------------------------------

    private function precedes(int $from, int $to, ?int $delay = null): void
    {
        $this->api('POST', '/issues/'.$from.'/relations.json', ['relation' => ['issue_to_id' => $to, 'relation_type' => 'precedes'] + (null !== $delay ? ['delay' => $delay] : [])], $this->adminKey());
        self::assertSame(201, $this->code());
    }

    private function dates(int $id): array
    {
        $r = $this->row($id);

        return [null === $r['start_date'] ? null : substr((string) $r['start_date'], 0, 10), null === $r['due_date'] ? null : substr((string) $r['due_date'], 0, 10)];
    }

    /** test_rescheduling_an_issue_to_an_earlier_due_date_should_reschedule_following_issue */
    public function testReschedulingToAnEarlierDateReschedulesTheFollowingIssue(): void
    {
        $issue1 = $this->create(['start_date' => '2012-10-15', 'due_date' => '2012-10-17']);
        $issue2 = $this->create(['start_date' => '2012-10-15', 'due_date' => '2012-10-17']);
        $this->precedes($issue1, $issue2);
        self::assertSame('2012-10-18', $this->dates($issue2)[0]);

        $this->update($issue1, ['start_date' => '2012-09-17', 'due_date' => '2012-09-18']);
        self::assertSame(204, $this->code());
        self::assertSame(['2012-09-19', '2012-09-21'], $this->dates($issue2));
    }

    /** test_rescheduling_reschedule_following_issue_earlier_should_consider_other_preceding_issues */
    public function testReschedulingEarlierConsidersTheOtherPrecedingIssues(): void
    {
        $issue1 = $this->create(['start_date' => '2012-10-15', 'due_date' => '2012-10-17']);
        $issue2 = $this->create(['start_date' => '2012-10-15', 'due_date' => '2012-10-17']);
        $issue3 = $this->create(['start_date' => '2012-10-01', 'due_date' => '2012-10-02']);
        $this->precedes($issue1, $issue2);
        $this->precedes($issue3, $issue2);
        self::assertSame('2012-10-18', $this->dates($issue2)[0]);

        $this->update($issue1, ['start_date' => '2012-09-17', 'due_date' => '2012-09-18']);
        // issue 2 must start after issue 3
        self::assertSame(['2012-10-03', '2012-10-05'], $this->dates($issue2));
    }

    /** test_rescheduling_an_issue_to_a_later_due_date_should_reschedule_following_issue (with the default non working days) */
    public function testReschedulingToALaterDateAndDelay(): void
    {
        $issue1 = $this->create(['start_date' => '2014-03-10', 'due_date' => '2014-03-12']);
        $issue2 = $this->create(['start_date' => '2014-03-10', 'due_date' => '2014-03-12']);
        $this->precedes($issue1, $issue2, 8);
        self::assertSame('2014-03-25', $this->dates($issue2)[0]);
    }

    /** test_rescheduling_an_issue_to_a_different_due_date_should_add_journal_to_following_issue */
    public function testReschedulingAddsAJournalToTheFollowingIssue(): void
    {
        $this->setting('non_working_week_days', '[]');
        $issue1 = $this->create(['start_date' => '2012-10-15', 'due_date' => '2012-10-17']);
        $issue2 = $this->create(['start_date' => '2012-10-18', 'due_date' => '2012-10-20']);
        $this->precedes($issue1, $issue2);
        self::assertSame(['2012-10-18', '2012-10-20'], $this->dates($issue2), 'already on its soonest start date');
        $before = \count(array_unique(array_column($this->details($issue2), 'id')));

        $this->api('PUT', '/issues/'.$issue1.'.json', ['issue' => ['due_date' => '2012-10-23']]);
        self::assertSame(204, $this->code());
        $details = $this->details($issue2);
        self::assertSame($before + 1, \count(array_unique(array_column($details, 'id'))));
        $last = array_values(array_filter($details, static fn ($d) => $d['id'] === end($details)['id']));
        $byKey = array_column($last, null, 'prop_key');
        self::assertSame(['2012-10-18', '2012-10-24'], [$byKey['start_date']['old_value'], $byKey['start_date']['value']]);
        self::assertSame(['2012-10-20', '2012-10-26'], [$byKey['due_date']['old_value'], $byKey['due_date']['value']]);
        self::assertSame(5, (int) $last[0]['user_id'], 'the journal belongs to the user who changed the preceding issue');
    }

    /** test_reschedule_an_issue_with_start_date / without_dates: Issue#reschedule_on sets both dates */
    public function testRescheduleSetsTheDueDateOfAnIssueWithoutDuration(): void
    {
        $issue1 = $this->create(['start_date' => '2012-10-08', 'due_date' => '2012-10-10']);
        $withStart = $this->create(['start_date' => '2012-10-09']);
        $withoutDates = $this->create([]);
        $this->precedes($issue1, $withStart);
        $this->precedes($issue1, $withoutDates);
        self::assertSame(['2012-10-11', '2012-10-11'], $this->dates($withStart));
        self::assertSame(['2012-10-11', '2012-10-11'], $this->dates($withoutDates));
    }

    /** IssueRelation#set_issue_to_dates + Issue#reschedule_on!: a following issue that starts later is moved back to its soonest start */
    public function testNewPrecedesRelationMovesALaterFollowerBack(): void
    {
        $issue1 = $this->create(['start_date' => '2012-10-15', 'due_date' => '2012-10-17']);
        $issue2 = $this->create(['start_date' => '2012-11-19', 'due_date' => '2012-11-21']);
        $this->precedes($issue1, $issue2);
        self::assertSame(['2012-10-18', '2012-10-22'], $this->dates($issue2));
        // the dates and the relation are in the same journal of the following issue
        $details = $this->details($issue2);
        self::assertCount(1, array_unique(array_column($details, 'id')));
        self::assertSame(['start_date', 'due_date', 'follows'], array_column($details, 'prop_key'));
    }

    // ------------------------------------------------------------------------------------------
    // Finding 12 / C: invalid input
    // ------------------------------------------------------------------------------------------

    public function testInvalidInputIsRefused(): void
    {
        $post = fn (array $attrs) => $this->api('POST', '/issues.json', ['issue' => $attrs + ['project_id' => 1, 'tracker_id' => 1, 'subject' => 'Invalid input']], $this->adminKey());
        self::assertSame(['Start date is not a valid date'], $post(['start_date' => '2026-13-45'])['errors']);
        self::assertSame(422, $this->code());
        self::assertSame(['Due date is not a valid date'], $post(['due_date' => '2026-02-30'])['errors']);
        self::assertSame(['Start date is not a valid date'], $post(['start_date' => '11/07/2026'])['errors']);
        self::assertSame(['Estimated time is invalid'], $post(['estimated_hours' => 'abc'])['errors']);
        self::assertSame(['Estimated time is invalid'], $post(['estimated_hours' => '-1'])['errors']);
        self::assertSame(['Priority cannot be blank'], $post(['priority_id' => 999])['errors']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM issues WHERE subject = 'Invalid input'"));

        // an existing issue keeps its values
        $r = $this->update(13, ['estimated_hours' => 'two hours', 'start_date' => 'tomorrow']);
        self::assertSame(422, $this->code());
        self::assertEqualsCanonicalizing(['Estimated time is invalid', 'Start date is not a valid date'], $r['errors']);

        // String#to_hours formats
        $id = (int) $post(['estimated_hours' => '2h30'])['issue']['id'];
        self::assertSame(2.5, (float) $this->row($id)['estimated_hours']);
        $this->update($id, ['estimated_hours' => '1:15']);
        self::assertSame(1.25, (float) $this->row($id)['estimated_hours']);
        // a blank priority falls back to the default one (Issue#clear_disabled_fields)
        $id = (int) $post(['priority_id' => ''])['issue']['id'];
        self::assertSame(2, (int) $this->row($id)['priority_id']);
    }

    /** Redmine String#to_hours (lib/redmine/core_ext/string/conversions.rb) */
    public function testParseHoursFollowsStringToHours(): void
    {
        $cases = [
            '2' => 2.0, '2.5' => 2.5, '2,5' => 2.5, '2h' => 2.0, '2.5h' => 2.5, '2:30' => 2.5, '0:45' => 0.75, '2:00' => 2.0, '10:00' => 10.0,
            '2h30' => 2.5, '2h30m' => 2.5, '2h 30m' => 2.5, '2 hours 30 min' => 2.5, '2hour' => 2.0, '30m' => 0.5, '30min' => 0.5,
            ' 3 ' => 3.0, '-1' => -1.0,
        ];
        foreach ($cases as $input => $expected) {
            self::assertSame($expected, IssueService::parseHours((string) $input), "to_hours of '$input'");
        }
        foreach (['abc', '2x', '1.5h30', '2h30x', 'h', ''] as $input) {
            self::assertNull(IssueService::parseHours($input), "to_hours of '$input'");
        }
        self::assertNull(IssueService::parseDate('2026-13-45'));
        self::assertNull(IssueService::parseDate('2026-1-5'));
        self::assertSame('2026-01-05', IssueService::parseDate('2026-01-05')?->format('Y-m-d'));
    }

    // ------------------------------------------------------------------------------------------
    // Finding 10: /issues/changes.atom
    // ------------------------------------------------------------------------------------------

    public function testChangesFeedAppliesTheQuery(): void
    {
        $this->update(19, ['notes' => 'note on the closed issue']);
        $this->update(13, ['notes' => 'note on an open issue']);
        $this->login('admin');
        $entries = fn (string $url) => array_values(array_unique(array_map(static fn ($t) => (int) preg_replace('/.*#(\d+):.*/', '$1', $t), (new \Symfony\Component\DomCrawler\Crawler($this->get($url)))->filterXPath('//*[local-name()="entry"]/*[local-name()="title"]')->each(static fn ($n) => $n->text()))));

        $all = $entries('/issues/changes.atom?project_id=ecommerce&set_filter=1&f[]=');
        self::assertContains(13, $all);
        self::assertContains(19, $all);
        // filter parameters
        self::assertSame([19], $entries('/issues/changes.atom?project_id=ecommerce&set_filter=1&f[]=status_id&op[status_id]=c'));
        self::assertSame([13], $entries('/issues/changes.atom?set_filter=1&issue_id=13'));
        // saved query: "Open bugs" of eCommerce (#8)
        $open = $entries('/issues/changes.atom?project_id=ecommerce&query_id=8');
        self::assertContains(13, $open);
        self::assertNotContains(19, $open);
        self::assertStringContainsString('<title>eCommerce: Open bugs</title>', $this->get('/issues/changes.atom?project_id=ecommerce&query_id=8'));
        self::assertStringContainsString('<title>eCommerce: Details of all changes</title>', $this->get('/issues/changes.atom?project_id=ecommerce&set_filter=1'));
        // the subprojects are included like in the issue list: #17 belongs to Checkout
        $this->update(17, ['notes' => 'note on a subproject issue']);
        self::assertContains(17, $entries('/issues/changes.atom?project_id=ecommerce&set_filter=1&f[]='));
    }

    // ------------------------------------------------------------------------------------------
    // Finding 11: time entry custom fields in the "Log time" fieldset of the issue form
    // ------------------------------------------------------------------------------------------

    public function testTimeEntryCustomFieldsOnTheIssueForm(): void
    {
        $field = new TimeEntryCustomField();
        $field->name = 'Billing reference';
        $field->fieldFormat = 'string';
        $field->isRequired = true;
        $field->position = 1;
        $this->em->persist($field);
        $this->em->flush();
        $conn = $this->em->getConnection();

        $this->login('jsmith');
        $crawler = $this->client->request('GET', '/issues/15/edit');
        self::assertCount(1, $crawler->filter('#log_time input[name="time_entry[custom_field_values]['.$field->id.']"]'));

        // the required field is validated: nothing is saved
        $this->client->request('POST', '/issues/15', ['_method' => 'patch', '_token' => $this->csrf(), 'issue' => ['notes' => 'logging time', 'lock_version' => (string) $this->lockVersion(15)],
            'time_entry' => ['hours' => '1.5', 'activity_id' => '8', 'comments' => 'from the issue form', 'custom_field_values' => [$field->id => '']]]);
        self::assertSame(200, $this->code());
        self::assertStringContainsString('Billing reference cannot be blank', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM time_entries WHERE issue_id = 15'));
        self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM journals WHERE journalized_id = 15 AND notes = 'logging time'"));

        self::assertSame(302, $this->submit('/issues/15', ['_method' => 'patch', 'issue' => ['notes' => 'logging time', 'lock_version' => (string) $this->lockVersion(15)],
            'time_entry' => ['hours' => '1h30', 'activity_id' => '8', 'comments' => 'from the issue form', 'custom_field_values' => [$field->id => 'PO-42']]]));
        $entry = $this->db('SELECT id, hours, comments, project_id, user_id FROM time_entries WHERE issue_id = 15');
        self::assertCount(1, $entry);
        self::assertSame([1.5, 'from the issue form', 1, 5], [(float) $entry[0]['hours'], $entry[0]['comments'], (int) $entry[0]['project_id'], (int) $entry[0]['user_id']]);
        self::assertSame('PO-42', $conn->fetchOne("SELECT value FROM custom_values WHERE customized_type = 'TimeEntry' AND customized_id = ? AND custom_field_id = ?", [$entry[0]['id'], $field->id]));
    }

    // ------------------------------------------------------------------------------------------
    // Finding 13: auto watch
    // ------------------------------------------------------------------------------------------

    private function watcherIds(int $issueId): array
    {
        $ids = array_map('intval', array_column($this->db("SELECT user_id FROM watchers WHERE watchable_type = 'Issue' AND watchable_id = ? ORDER BY user_id", [$issueId]), 'user_id'));

        return $ids;
    }

    private function autoWatchOn(int $userId, array $actions): void
    {
        $conn = $this->em->getConnection();
        $others = json_decode((string) $conn->fetchOne('SELECT others FROM user_preferences WHERE user_id = ?', [$userId]), true) ?: [];
        $others['auto_watch_on'] = $actions;
        $conn->executeStatement('UPDATE user_preferences SET others = ? WHERE user_id = ?', [json_encode($others), $userId]);
    }

    /** Issue#add_auto_watcher / Journal#add_watcher with the "issue_assigned_to_me" preference */
    public function testAssigneeIsAddedAsWatcherAccordingToHisPreference(): void
    {
        $this->autoWatchOn(5, ['issue_assigned_to_me']);
        $created = $this->create(['assigned_to_id' => 5]);
        self::assertContains(5, $this->watcherIds($created), 'assigned on creation');

        $later = $this->create([]);
        self::assertNotContains(5, $this->watcherIds($later));
        $this->update($later, ['assigned_to_id' => 5]);
        self::assertContains(5, $this->watcherIds($later), 'assigned by an update');

        // without the preference the assignee is not added
        $this->autoWatchOn(5, []);
        $none = $this->create(['assigned_to_id' => 5]);
        self::assertNotContains(5, $this->watcherIds($none));
    }

    /** Issue#auto_watcher? / Journal#valid_watcher?: user.allowed_to?(:add_issue_watchers, project) */
    public function testAutoWatchRequiresThePermissionToAddWatchers(): void
    {
        // rhill is a reporter: no add_issue_watchers
        $this->login('rhill');
        self::assertSame(302, $this->submit('/projects/ecommerce/issues', ['issue' => ['subject' => 'Reported by rhill', 'tracker_id' => 1, 'status_id' => 1, 'priority_id' => 2]]));
        $id = (int) $this->em->getConnection()->fetchOne("SELECT id FROM issues WHERE subject = 'Reported by rhill'");
        self::assertSame([], $this->watcherIds($id));
        self::assertSame(302, $this->updateIssue(16, ['notes' => 'a note by rhill']));
        self::assertNotContains(7, $this->watcherIds(16));

        // jsmith is a manager: added when he creates or contributes
        $this->login('jsmith');
        self::assertSame(302, $this->submit('/projects/ecommerce/issues', ['issue' => ['subject' => 'Reported by jsmith', 'tracker_id' => 1, 'status_id' => 1, 'priority_id' => 2]]));
        $id = (int) $this->em->getConnection()->fetchOne("SELECT id FROM issues WHERE subject = 'Reported by jsmith'");
        self::assertSame([5], $this->watcherIds($id));
        self::assertSame(302, $this->updateIssue(16, ['notes' => 'a note by jsmith']));
        self::assertContains(5, $this->watcherIds(16));
    }

    // ------------------------------------------------------------------------------------------
    // Finding 14: bulk edit error messages
    // ------------------------------------------------------------------------------------------

    /** bulk_edit.html.erb + ApplicationHelper#bulk_edit_error_messages */
    /** bulk_edit.html.erb: the assignee, category and version that were chosen are still selected when the form comes back */
    public function testBulkEditFormKeepsTheChosenValues(): void
    {
        $this->login('jsmith');
        $crawler = $this->client->request('GET', '/issues/bulk_edit', ['ids' => ['15', '16'], 'issue' => ['assigned_to_id' => '6', 'category_id' => '1', 'fixed_version_id' => '1', 'priority_id' => '4']]);
        self::assertSame(200, $this->code());
        foreach (['assigned_to_id' => '6', 'category_id' => '1', 'fixed_version_id' => '1', 'priority_id' => '4'] as $field => $value) {
            self::assertSame([$value], $crawler->filter('#issue_'.$field.' option[selected]')->each(static fn ($o) => $o->attr('value')), $field);
        }
        $crawler = $this->client->request('GET', '/issues/bulk_edit', ['ids' => ['15', '16']]);
        self::assertCount(0, $crawler->filter('#issue_assigned_to_id option[selected], #issue_category_id option[selected], #issue_fixed_version_id option[selected]'));
    }

    public function testBulkEditFailureListsTheErrorsPerIssue(): void
    {
        $this->login('admin');
        // #15 starts on 11/07, #16 on 11/19 (after #15): a due date before both is refused; #19 has no start date
        $crawler = $this->client->request('POST', '/issues/bulk_update', ['_token' => $this->csrf(), 'ids' => [15, 16, 19], 'issue' => ['due_date' => '2001-01-01']]);
        self::assertSame(200, $this->code());
        $box = $crawler->filter('#errorExplanation');
        self::assertStringContainsString('Failed to save 2 issue(s) on 3 selected: #15, #16.', $box->text());
        self::assertSame(['Due date must be greater than start date: #15, #16'], $box->filter('ul li')->each(static fn ($li) => trim($li->text())));
        self::assertCount(0, $crawler->filter('#flash_error'));
        self::assertSame('2001-01-01', substr((string) $this->row(19)['due_date'], 0, 10));
    }

    // ------------------------------------------------------------------------------------------
    // Finding 15: tracker and status descriptions
    // ------------------------------------------------------------------------------------------

    public function testTrackerAndStatusDescriptionDialogs(): void
    {
        $this->login('jsmith');
        $crawler = $this->client->request('GET', '/projects/ecommerce/issues/new');
        self::assertCount(0, $crawler->filter('#trackers_description'), 'no dialog without descriptions');
        self::assertCount(0, $crawler->filter('#issue_statuses_description'));
        self::assertCount(0, $crawler->filter('a.icon-help[onclick*="trackers_description"]'));

        $conn = $this->em->getConnection();
        $conn->executeStatement("UPDATE trackers SET description = 'A defect in the product' WHERE id = 1");
        $conn->executeStatement("UPDATE issue_statuses SET description = 'Not triaged yet' WHERE id = 1");
        $crawler = $this->client->request('GET', '/projects/ecommerce/issues/new');
        self::assertCount(1, $crawler->filter('a.icon-help[title="View all trackers description"][onclick*="showModal(\'trackers_description\'"]'));
        self::assertSame('Trackers description', $crawler->filter('#trackers_description h3.title')->text());
        self::assertSame(['Bug'], $crawler->filter('#trackers_description dt a[title="Select tracker"]')->each(static fn ($a) => $a->text()));
        self::assertSame(['A defect in the product'], $crawler->filter('#trackers_description dd')->each(static fn ($d) => $d->text()));
        self::assertStringContainsString("selectTracker('1')", (string) $crawler->filter('#trackers_description dt a')->attr('onclick'));
        self::assertCount(1, $crawler->filter('a.icon-help[title="View all issue statuses description"][onclick*="showModal(\'issue_statuses_description\'"]'));
        self::assertSame(['New'], $crawler->filter('#issue_statuses_description dt a[title="Select issue status"]')->each(static fn ($a) => $a->text()));
        self::assertSame(['Not triaged yet'], $crawler->filter('#issue_statuses_description dd')->each(static fn ($d) => $d->text()));
    }

    // ------------------------------------------------------------------------------------------
    // Finding 16: download of the attachments added with a journal
    // ------------------------------------------------------------------------------------------

    public function testJournalAttachmentsDownload(): void
    {
        $tokens = [];
        foreach (['first.txt' => 'first file', 'second.txt' => 'second file'] as $name => $content) {
            $this->client->request('POST', '/uploads.json?filename='.$name, [], [], ['HTTP_X_REDMINE_API_KEY' => $this->adminKey(), 'CONTENT_TYPE' => 'application/octet-stream'], $content);
            self::assertSame(201, $this->code());
            $tokens[$name] = json_decode((string) $this->client->getResponse()->getContent(), true)['upload']['token'];
        }
        $this->update(16, ['notes' => 'two files', 'uploads' => [
            ['token' => $tokens['first.txt'], 'filename' => 'first.txt', 'content_type' => 'text/plain'],
            ['token' => $tokens['second.txt'], 'filename' => 'second.txt', 'content_type' => 'text/plain'],
        ]]);
        self::assertSame(204, $this->code());
        $this->update(16, ['notes' => 'no file']);
        $journals = $this->db("SELECT id, notes FROM journals WHERE journalized_id = 16 AND notes IN ('two files', 'no file') ORDER BY id");
        [$withFiles, $withoutFiles] = [(int) $journals[0]['id'], (int) $journals[1]['id']];

        $this->login('jsmith');
        $crawler = $this->client->request('GET', '/issues/16?tab=history');
        self::assertCount(1, $crawler->filter('#change-'.$withFiles.' a.icon-download[title="Download all files"][href="/attachments/journals/'.$withFiles.'/download"]'));
        self::assertCount(0, $crawler->filter('#change-'.$withoutFiles.' a[href*="/attachments/journals/"]'));

        $this->client->request('GET', '/attachments/journals/'.$withFiles.'/download');
        self::assertSame(200, $this->code());
        self::assertSame('application/zip', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('journal-'.$withFiles.'-attachments.zip', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $tmp = (string) tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($tmp, (string) $this->client->getResponse()->getContent());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($tmp));
        self::assertSame('first file', $zip->getFromName('first.txt'));
        self::assertSame('second file', $zip->getFromName('second.txt'));
        self::assertSame(2, $zip->numFiles);
        $zip->close();
        unlink($tmp);

        $this->client->request('GET', '/attachments/journals/'.$withoutFiles.'/download');
        self::assertSame(404, $this->code());
        // anna is not a member of the private Internal Tools project... and cannot read a journal of an issue she cannot see
        $this->update(18, ['notes' => 'internal']);
        $internal = (int) $this->em->getConnection()->fetchOne("SELECT id FROM journals WHERE journalized_id = 18 AND notes = 'internal'");
        $this->login('anna');
        $this->client->request('GET', '/attachments/journals/'.$internal.'/download');
        self::assertSame(404, $this->code());
    }

    // ------------------------------------------------------------------------------------------
    // Finding 17: issue auto completion
    // ------------------------------------------------------------------------------------------

    /** Issue.like => Query.tokenized_like_conditions */
    public function testIssueAutoCompleteMatchesEveryWord(): void
    {
        $this->login('jsmith');
        $ids = fn (string $q) => array_column(json_decode($this->get('/issues/auto_complete?project_id=ecommerce&q='.rawurlencode($q)), true), 'id');
        self::assertSame([16], $ids('emails wishlist'));
        self::assertSame([16], $ids('wishlist emails'));
        self::assertSame([16, 15, 14], $ids('wishlist'));
        self::assertSame([], $ids('"emails wishlist"'), 'a quoted phrase is searched as a whole');
        self::assertSame([16], $ids('"wishlist emails"'));
        self::assertSame([], $ids('wishlist nothing'));
    }

    // ------------------------------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------------------------------

    /** IssueRelation: validates_uniqueness_of :issue_to_id, :scope => :issue_from_id (per direction) + circular dependencies */
    public function testRelationUniquenessIsPerDirection(): void
    {
        $post = fn (int $from, int $to, string $type) => $this->api('POST', '/issues/'.$from.'/relations.json', ['relation' => ['issue_to_id' => $to, 'relation_type' => $type]], $this->adminKey());
        // #13 blocks #19 exists
        self::assertSame(['Related issue has already been taken'], $post(13, 19, 'duplicates')['errors']);
        self::assertSame(['Related issue has already been taken', 'This relation would create a circular dependency'], $post(13, 19, 'blocked')['errors']);
        // circular dependencies
        self::assertSame(['This relation would create a circular dependency'], $post(19, 13, 'blocks')['errors']);
        self::assertSame(['This relation would create a circular dependency'], $post(17, 13, 'relates')['errors'], '#13 relates to #17 exists');
        // "is blocked by" entered from the blocked issue is valid as entered, but is stored reversed and collides
        // with the existing row (ActiveRecord::RecordNotUnique => errors.add :base, :taken)
        self::assertSame(['has already been taken'], $post(19, 13, 'blocked')['errors']);
        self::assertSame(422, $this->code());
        // the reverse direction with another type is another pair
        $r = $post(19, 13, 'duplicates');
        self::assertSame(201, $this->code(), json_encode($r));
        self::assertSame([19, 13, 'duplicates'], [$r['relation']['issue_id'], $r['relation']['issue_to_id'], $r['relation']['relation_type']]);
    }

    // ------------------------------------------------------------------------------------------
    // D: full width custom field with text formatting
    // ------------------------------------------------------------------------------------------

    /** IssuesHelper#render_full_width_custom_fields_rows */
    public function testFullWidthFormattedCustomFieldIsNotEscaped(): void
    {
        $field = new IssueCustomField();
        $field->name = 'Release notes';
        $field->fieldFormat = 'text';
        $field->isForAll = true;
        $field->position = 10;
        $field->formatStore = ['text_formatting' => 'full', 'full_width_layout' => '1'];
        foreach ($this->em->getRepository(\App\Entity\Tracker::class)->findAll() as $tracker) {
            $field->trackers->add($tracker);
        }
        $this->em->persist($field);
        $this->em->flush();
        $this->update(13, ['custom_fields' => [['id' => $field->id, 'value' => 'Some **bold** text']]]);
        self::assertSame(204, $this->code());

        $this->login('admin');
        $crawler = $this->client->request('GET', '/issues/13');
        $value = $crawler->filter('div.cf_'.$field->id.'.attribute div.value');
        self::assertCount(1, $value);
        self::assertSame('bold', $value->filter('div.wiki strong')->text());
        self::assertStringNotContainsString('&lt;', $value->html());
    }

    // ------------------------------------------------------------------------------------------
    // E: Setting.assignee_dropdown_display_format
    // ------------------------------------------------------------------------------------------

    /** ApplicationHelper#principals_options_for_select */
    public function testAssigneeDropdownDisplayFormat(): void
    {
        $this->login('jsmith');
        $groups = function (): array {
            $crawler = $this->client->request('GET', '/projects/ecommerce/issues/new');
            self::assertSame(200, $this->code());

            return $crawler->filter('#issue_assigned_to_id optgroup')->each(static fn ($g) => [$g->attr('label'), $g->filter('option')->each(static fn ($o) => $o->text())]);
        };
        // default: users_then_groups
        self::assertSame([['Users', ['Dave Lopper', 'John Smith', 'Robert Hill']], ['Groups', ['Developers']]], $groups());

        $this->setting('assignee_dropdown_display_format', 'groups_then_users');
        self::assertSame([['Groups', ['Developers']], ['Users', ['Dave Lopper', 'John Smith', 'Robert Hill']]], $groups());

        // the groups, the users of each group, then the users without group (dlopper is a member of Developers)
        $this->setting('assignee_dropdown_display_format', 'users_by_group');
        self::assertSame([['Groups', ['Developers']], ['Developers', ['Dave Lopper']], ['Users', ['John Smith', 'Robert Hill']]], $groups());
    }

    // ------------------------------------------------------------------------------------------
    // Second round
    // ------------------------------------------------------------------------------------------

    /**
     * acts_as_customizable: "after_save :save_custom_field_values" runs on every save and
     * "self.custom_values = target_custom_values" only keeps the values of Issue#available_custom_fields
     * (has_many :custom_values, :dependent => :delete_all). No journal detail: the field was not part of
     * the values recorded by init_journal.
     */
    public function testValuesOfCustomFieldsThatNoLongerApplyAreRemovedByAnySave(): void
    {
        $conn = $this->em->getConnection();
        $count = fn (int $issue, int $field) => (int) $conn->fetchOne("SELECT COUNT(*) FROM custom_values WHERE customized_type = 'Issue' AND customized_id = ? AND custom_field_id = ?", [$issue, $field]);
        self::assertSame(1, $count(13, 1));
        // "Database" is not used by the Bug tracker any more
        $conn->executeStatement('DELETE FROM custom_fields_trackers WHERE custom_field_id = 1 AND tracker_id = 1');
        self::assertSame(1, $count(13, 1), 'nothing happens until the issue is saved');

        $this->update(13, ['notes' => 'a save that does not touch the custom fields']);
        self::assertSame(204, $this->code());
        self::assertSame(0, $count(13, 1));
        self::assertSame([], array_values(array_filter($this->details(13), static fn ($d) => 'cf' === $d['property'])));
        // the values of the fields that still apply are kept
        self::assertGreaterThan(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM custom_values WHERE customized_type = 'Issue' AND customized_id = 13"));

        // a parent saved because a subtask changed (recalculate_attributes_for => p.save) loses them too
        $conn->executeStatement("INSERT INTO custom_values (customized_type, customized_id, custom_field_id, value) VALUES ('Issue', 14, 7, '2')");
        self::assertSame(1, $count(14, 7), 'Severity does not apply to the Feature tracker');
        $this->update(15, ['done_ratio' => 70]);
        self::assertSame(204, $this->code());
        self::assertSame(0, $count(14, 7));
    }

    /** Issue#reschedule_on! => save: the following issue is left as it was when it would not be valid */
    public function testFollowerIsNotRescheduledWhenItWouldBeInvalid(): void
    {
        $conn = $this->em->getConnection();
        $issue1 = $this->create(['start_date' => '2012-10-15', 'due_date' => '2012-10-17', 'category_id' => 2]);
        $invalid = $this->create(['start_date' => '2012-10-18', 'due_date' => '2012-10-19']);
        $valid = $this->create(['start_date' => '2012-10-18', 'due_date' => '2012-10-19', 'category_id' => 2]);
        $this->precedes($issue1, $invalid);
        $this->precedes($issue1, $valid);
        // the category becomes required for new bugs, for every role
        foreach ([1, 2, 3, 4, 5] as $role) {
            $conn->executeStatement("INSERT INTO workflows (tracker_id, old_status_id, new_status_id, role_id, assignee, author, field_name, rule, type) VALUES (1, 1, 0, ?, 0, 0, 'category_id', 'required', 'WorkflowPermission')", [$role]);
        }
        $this->update($issue1, ['due_date' => '2012-10-24']);
        self::assertSame(204, $this->code());
        self::assertSame(['2012-10-18', '2012-10-19'], $this->dates($invalid), 'Category cannot be blank: not saved');
        self::assertSame(['2012-10-25', '2012-10-26'], $this->dates($valid));
        self::assertSame([], array_values(array_filter($this->details($invalid), static fn ($d) => 'start_date' === $d['prop_key'])));
    }

    /** Attachment#copy keeps the author and the date; Issue#copy_from assigns the watchers before the copy is saved */
    public function testCopyKeepsAttachmentAuthorAndDateAndCopiesTheWatchers(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE attachments SET downloads = 4 WHERE id = 1');
        $original = $conn->fetchAssociative('SELECT * FROM attachments WHERE id = 1');
        $this->login('admin');
        self::assertSame(302, $this->submit('/projects/ecommerce/issues', ['copy_from' => 13, 'copy_attachments' => '1', 'issue' => ['subject' => 'Copy with file', 'tracker_id' => 1, 'status_id' => 1, 'priority_id' => 2]]));
        $copy = (int) $conn->fetchOne("SELECT id FROM issues WHERE subject = 'Copy with file'");
        $copied = $this->db("SELECT * FROM attachments WHERE container_type = 'Issue' AND container_id = ?", [$copy]);
        self::assertCount(1, $copied);
        self::assertSame($original['filename'], $copied[0]['filename']);
        self::assertSame($original['disk_filename'], $copied[0]['disk_filename']);
        self::assertSame((int) $original['author_id'], (int) $copied[0]['author_id'], 'jsmith stays the author, not the user who copies');
        self::assertSame(substr((string) $original['created_on'], 0, 19), substr((string) $copied[0]['created_on'], 0, 19));
        self::assertSame(0, (int) $copied[0]['downloads']);
        // #13 is watched by jsmith (5) and rhill (7); admin (1) watches what he creates
        self::assertSame([1, 5, 7], $this->watcherIds($copy));

        // test_create_as_copy_with_watcher_user_ids_should_copy_watchers / without_watcher_user_ids_should_not_copy_watchers
        self::assertSame(302, $this->submit('/projects/ecommerce/issues', ['copy_from' => 13, 'issue' => ['subject' => 'Copy with chosen watchers', 'tracker_id' => 1, 'status_id' => 1, 'priority_id' => 2, 'watcher_user_ids' => ['', '6']]]));
        self::assertSame([1, 6], $this->watcherIds((int) $conn->fetchOne("SELECT id FROM issues WHERE subject = 'Copy with chosen watchers'")));
        self::assertSame(302, $this->submit('/projects/ecommerce/issues', ['copy_from' => 13, 'issue' => ['subject' => 'Copy without watchers', 'tracker_id' => 1, 'status_id' => 1, 'priority_id' => 2, 'watcher_user_ids' => ['']]]));
        self::assertSame([1], $this->watcherIds((int) $conn->fetchOne("SELECT id FROM issues WHERE subject = 'Copy without watchers'")));
    }

    /** TimeEntry#validate_time_entry: !Setting.timelog_accept_closed_issues? && issue&.closed? && issue.was_closed? */
    public function testTimeCanBeLoggedWhileClosingAnIssueButNotOnAClosedOne(): void
    {
        $this->setting('timelog_accept_closed_issues', '0');
        $conn = $this->em->getConnection();
        $this->login('jsmith');
        self::assertSame(302, $this->submit('/issues/15', ['_method' => 'patch', 'issue' => ['status_id' => '5', 'lock_version' => (string) $this->lockVersion(15)],
            'time_entry' => ['hours' => '1', 'activity_id' => '8', 'comments' => 'while closing']]));
        self::assertSame(5, (int) $this->row(15)['status_id']);
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM time_entries WHERE issue_id = 15 AND comments = 'while closing'"));

        // #19 is already closed
        $this->client->request('POST', '/projects/ecommerce/time_entries', ['_token' => $this->csrf(), 'time_entry' => ['issue_id' => '19', 'hours' => '1', 'activity_id' => '8', 'spent_on' => '2026-11-02']]);
        self::assertStringContainsString('Cannot log time on a closed issue', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM time_entries WHERE issue_id = 19'));
    }

    /** IssueImport#build_relations: IssueRelation.new(...).save! without init_journals */
    public function testRelationsCreatedByTheIssueImportHaveNoJournal(): void
    {
        $conn = $this->em->getConnection();
        $follower = $this->create(['subject' => 'Follower of the import', 'start_date' => '2026-12-01', 'due_date' => '2026-12-02']);
        $this->login('jsmith');
        $path = sys_get_temp_dir().'/symine-parity-import-'.bin2hex(random_bytes(4)).'.csv';
        file_put_contents($path, "Tracker,Subject,Blocks,Start date,Due date,Precedes\nBug,Imported blocker,#13,2026-11-02,2026-11-04,#$follower\n");
        try {
            $this->client->request('POST', '/imports', ['_token' => $this->csrf(), 'type' => 'IssueImport', 'project_id' => 'ecommerce'],
                ['file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'import.csv', 'text/csv', null, true)]);
            self::assertSame(302, $this->code());
            self::assertSame(1, preg_match('#/imports/([0-9a-f]{32})/settings$#', (string) $this->client->getResponse()->headers->get('Location'), $m));
            $id = $m[1];
            $this->client->request('POST', "/imports/$id/settings", ['_token' => $this->csrf(), 'import_settings' => ['separator' => ',', 'wrapper' => '"', 'encoding' => 'UTF-8', 'date_format' => '%Y-%m-%d', 'notifications' => '0']]);
            self::assertSame(302, $this->code());
            $this->client->request('POST', "/imports/$id/mapping", ['_token' => $this->csrf(), 'import_settings' => ['mapping' => [
                'project_id' => '1', 'tracker' => '0', 'subject' => '1', 'relation_blocks' => '2', 'start_date' => '3', 'due_date' => '4', 'relation_precedes' => '5',
            ]]]);
            self::assertSame(302, $this->code());
            $this->client->request('GET', "/imports/$id/run");
            for ($i = 0; $i < 10; ++$i) {
                $this->client->request('POST', "/imports/$id/run", ['_token' => $this->csrf()]);
                if (str_ends_with((string) $this->client->getResponse()->headers->get('Location'), "/imports/$id")) {
                    break;
                }
            }
        } finally {
            @unlink($path);
        }
        $imported = (int) $conn->fetchOne("SELECT id FROM issues WHERE subject = 'Imported blocker'");
        self::assertGreaterThan(0, $imported);
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM issue_relations WHERE issue_from_id = ? AND issue_to_id = 13 AND relation_type = 'blocks'", [$imported]));
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM issue_relations WHERE issue_from_id = ? AND issue_to_id = ? AND relation_type = 'precedes'", [$imported, $follower]));
        self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM journal_details d JOIN journals j ON j.id = d.journal_id WHERE d.property = 'relation' AND j.journalized_id IN (?, 13, ?)", [$imported, $follower]));
        // the following issue is rescheduled right after the imported one, without a journal either
        self::assertSame(['2026-11-05', '2026-11-06'], $this->dates($follower));
        self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM journals WHERE journalized_type = 'Issue' AND journalized_id = ?", [$follower]));
    }

    /** The form shown again after "Estimated time is invalid" keeps what the user typed */
    public function testInvalidEstimatedTimeIsDisplayedAgain(): void
    {
        $this->login('jsmith');
        $crawler = $this->client->request('POST', '/issues/15', ['_method' => 'patch', '_token' => $this->csrf(), 'issue' => ['estimated_hours' => 'two days', 'lock_version' => (string) $this->lockVersion(15)]]);
        self::assertSame(200, $this->code());
        self::assertStringContainsString('Estimated time is invalid', $crawler->filter('#errorExplanation')->text());
        self::assertSame('two days', $crawler->filter('#issue_estimated_hours')->attr('value'));

        $crawler = $this->client->request('POST', '/projects/ecommerce/issues', ['_token' => $this->csrf(), 'issue' => ['subject' => 'New with bad estimate', 'tracker_id' => 1, 'status_id' => 1, 'priority_id' => 2, 'estimated_hours' => '3 apples']]);
        self::assertSame('3 apples', $crawler->filter('#issue_estimated_hours')->attr('value'));
        // a valid value is displayed formatted as before
        $crawler = $this->client->request('GET', '/issues/15/edit');
        self::assertNotSame('two days', $crawler->filter('#issue_estimated_hours')->attr('value'));
    }

    /** JournalDetail values are Ruby strings: Float#to_s gives "4.0", not "4" */
    public function testEstimatedTimeJournalDetailUsesRubyFloatFormat(): void
    {
        $id = $this->create(['estimated_hours' => 4]);
        $this->update($id, ['estimated_hours' => '2:30']);
        $this->update($id, ['estimated_hours' => '3']);
        $hours = array_values(array_filter($this->details($id), static fn ($d) => 'estimated_hours' === $d['prop_key']));
        self::assertSame([['4.0', '2.5'], ['2.5', '3.0']], array_map(static fn ($d) => [$d['old_value'], $d['value']], $hours));
        // unchanged value: no detail
        $this->update($id, ['estimated_hours' => '3:00', 'notes' => 'same estimate']);
        self::assertCount(2, array_filter($this->details($id), static fn ($d) => 'estimated_hours' === $d['prop_key']));
    }

    /**
     * Issue#validate_issue, parent assignment (test_setting_parent_to_a_an_issue_that_precedes_should_not_validate,
     * ..._that_follows_..., ..._precedes_through_hierarchy_..., test_issue_and_following_issue_should_be_able_to_be_moved_to_the_same_parent)
     */
    public function testParentAssignmentAndPrecedesRelations(): void
    {
        // 1 -> 2 -> 3
        [$i1, $i2, $i3] = [$this->create([]), $this->create([]), $this->create([])];
        $this->precedes($i1, $i2);
        $this->precedes($i2, $i3);
        self::assertSame(['Parent task is invalid'], $this->update($i3, ['parent_issue_id' => $i1])['errors'], '3 cannot have 1 as parent');
        self::assertSame(['Parent task is invalid'], $this->update($i1, ['parent_issue_id' => $i3])['errors'], '1 cannot have 3 as parent');

        //      3 -> 4
        //      ^
        // 1 -> 2
        [$h1, $h2, $h3, $h4] = [$this->create([]), $this->create([]), $this->create([]), $this->create([])];
        $this->precedes($h1, $h2);
        $this->update($h2, ['parent_issue_id' => $h3]);
        self::assertSame(204, $this->code());
        $this->precedes($h3, $h4);
        self::assertSame(['Parent task is invalid'], $this->update($h4, ['parent_issue_id' => $h1])['errors']);

        // an issue and the issue it follows / relates to can be moved to the same parent
        [$a, $b, $parent] = [$this->create([]), $this->create([]), $this->create([])];
        $this->api('POST', '/issues/'.$b.'/relations.json', ['relation' => ['issue_to_id' => $a, 'relation_type' => 'follows']], $this->adminKey());
        self::assertSame(201, $this->code());
        $this->update($a, ['parent_issue_id' => $parent]);
        self::assertSame(204, $this->code());
        $this->update($b, ['parent_issue_id' => $parent]);
        self::assertSame(204, $this->code());
        self::assertSame([$parent, $parent], [(int) $this->row($a)['parent_id'], (int) $this->row($b)['parent_id']]);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM issue_relations WHERE issue_from_id = ? AND issue_to_id = ?', [$a, $b]));

        // a "relates" relation does not prevent an issue from becoming the parent of the other
        [$r1, $r2] = [$this->create([]), $this->create([])];
        $this->api('POST', '/issues/'.$r1.'/relations.json', ['relation' => ['issue_to_id' => $r2, 'relation_type' => 'relates']], $this->adminKey());
        $this->update($r2, ['parent_issue_id' => $r1]);
        self::assertSame(204, $this->code());
        // moving an issue under itself or one of its subtasks is refused
        self::assertSame(['Parent task is invalid'], $this->update($r1, ['parent_issue_id' => $r2])['errors']);
        self::assertSame(['Parent task is invalid'], $this->update($r1, ['parent_issue_id' => $r1])['errors']);
    }

    /** test_category_should_not_be_required_if_project_has_no_categories / test_fixed_version_should_not_be_required_no_assignable_versions */
    public function testRequiredCategoryAndVersionAreSkippedWhenThereIsNothingToChoose(): void
    {
        $conn = $this->em->getConnection();
        foreach ([1, 2, 3, 4, 5] as $role) {
            foreach (['category_id', 'fixed_version_id'] as $field) {
                $conn->executeStatement("INSERT INTO workflows (tracker_id, old_status_id, new_status_id, role_id, assignee, author, field_name, rule, type) VALUES (1, 1, 0, ?, 0, 0, ?, 'required', 'WorkflowPermission')", [$role, $field]);
            }
        }
        $post = fn (int $project) => $this->api('POST', '/issues.json', ['issue' => ['project_id' => $project, 'tracker_id' => 1, 'subject' => 'Required fields']], $this->adminKey());
        // eCommerce has categories and an open version
        self::assertEqualsCanonicalizing(['Category cannot be blank', 'Target version cannot be blank'], $post(1)['errors']);
        // Internal Tools has neither
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM issue_categories WHERE project_id = 2') + (int) $conn->fetchOne('SELECT COUNT(*) FROM versions WHERE project_id = 2'));
        $post(2);
        self::assertSame(201, $this->code());
    }

    /** Issue#validate_issue: a category, version or assignee id that does not exist is refused, not ignored */
    public function testUnknownCategoryVersionAndAssigneeAreRefused(): void
    {
        self::assertSame(['Category is not included in the list'], $this->update(13, ['category_id' => 999])['errors']);
        self::assertSame(422, $this->code());
        self::assertSame(['Target version is not included in the list'], $this->update(13, ['fixed_version_id' => 999])['errors']);
        self::assertSame(['Assignee is invalid'], $this->update(13, ['assigned_to_id' => 999])['errors']);
        $before = $this->row(13);
        self::assertSame([10, 1], [(int) $before['assigned_to_id'], (int) $before['fixed_version_id']], 'nothing was changed');
        // blank values still clear the attributes
        $this->update(13, ['category_id' => '', 'fixed_version_id' => '', 'assigned_to_id' => '']);
        self::assertSame(204, $this->code());
        $after = $this->row(13);
        self::assertSame([null, null, null], [$after['category_id'], $after['fixed_version_id'], $after['assigned_to_id']]);
    }

    /** test_create_as_copy_should_fail_without_add_issue_permission_on_original_tracker (Issue#validate_permissions) */
    public function testCopyRequiresThePermissionToAddIssuesWithTheTracker(): void
    {
        $role = $this->em->find(\App\Entity\Role::class, 3);
        $settings = \is_array($role->settings) ? $role->settings : [];
        $settings['permissions_all_trackers'] = ['add_issues' => '0'] + ($settings['permissions_all_trackers'] ?? []);
        $settings['permissions_tracker_ids'] = ['add_issues' => ['1', '3']] + ($settings['permissions_tracker_ids'] ?? []);
        $role->settings = $settings;
        $this->em->flush();

        $this->login('jsmith');
        // #14 is a Feature (tracker 2): managers may now only add bugs and support requests
        $crawler = $this->client->request('POST', '/projects/ecommerce/issues', ['_token' => $this->csrf(), 'copy_from' => 14, 'copy_subtasks' => '', 'issue' => ['subject' => 'Copy of a feature']]);
        self::assertSame(200, $this->code());
        self::assertStringContainsString('Tracker is invalid', $crawler->filter('#errorExplanation')->text());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM issues WHERE subject = 'Copy of a feature'"));
        // with an allowed tracker the copy is created
        self::assertSame(302, $this->submit('/projects/ecommerce/issues', ['copy_from' => 14, 'issue' => ['subject' => 'Copy as a bug', 'tracker_id' => 1]]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT tracker_id FROM issues WHERE subject = 'Copy as a bug'"));
    }

    /** validate_issue: due_date < start_date is only checked when one of the dates changes */
    public function testInconsistentDatesOnlyBlockTheUpdatesThatChangeThem(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement("UPDATE issues SET start_date = '2026-11-20', due_date = '2026-11-10' WHERE id = 19");
        $this->update(19, ['notes' => 'a note on an issue with legacy dates']);
        self::assertSame(204, $this->code());
        self::assertSame(['Due date must be greater than start date'], $this->update(19, ['due_date' => '2026-11-11'])['errors']);
        $this->update(19, ['due_date' => '2026-11-21']);
        self::assertSame(204, $this->code());
    }

    /** issues#show.atom renders journals/index with @journals: oldest first unless the user reverses the comments */
    public function testIssueAtomFeedOrder(): void
    {
        $this->update(16, ['notes' => 'first note']);
        $this->update(16, ['notes' => 'second note']);
        $this->login('jsmith');
        $xml = $this->get('/issues/16.atom');
        self::assertSame(200, $this->code());
        self::assertLessThan(strpos($xml, 'second note'), strpos($xml, 'first note'));
        $this->login('anna'); // comments_sorting: desc
        $xml = $this->get('/issues/16.atom');
        self::assertGreaterThan(strpos($xml, 'second note'), strpos($xml, 'first note'));
    }

    /**
     * Issue#force_updated_on_change: the form submitted as it is writes nothing (no journal, updated_on and
     * lock_version untouched), so nobody else gets an edit conflict for a change that does not exist.
     */
    public function testAnUpdateThatChangesNothingDoesNotTouchTheIssue(): void
    {
        $row = fn (int $id): array => $this->db('SELECT lock_version, updated_on, (SELECT COUNT(*) FROM journals WHERE journalized_id = issues.id) AS journals FROM issues WHERE id = ?', [$id])[0];
        $this->login('jsmith');
        foreach ([13, 14, 16] as $id) {
            $before = $row($id);
            $crawler = $this->client->request('GET', '/issues/'.$id.'/edit');
            $form = $crawler->filter('#issue-form')->form();
            $values = $form->getPhpValues();
            // a browser sends the selected options of a multiple select and the hidden field behind it
            foreach ($crawler->filter('#issue-form select[multiple]') as $select) {
                $selected = array_map(static fn (\DOMElement $o) => $o->getAttribute('value'), iterator_to_array((new \Symfony\Component\DomCrawler\Crawler($select))->filter('option[selected]')));
                if (preg_match('/^issue\[custom_field_values\]\[(\d+)\]\[\]$/', $select->getAttribute('name'), $m)) {
                    $values['issue']['custom_field_values'][$m[1]] = [...$selected, ''];
                }
            }
            $this->client->request('POST', $form->getUri(), $values);
            self::assertSame(302, $this->code(), '#'.$id);
            self::assertSame($before, $row($id), '#'.$id);
        }

        // notes are a change: the issue is touched once
        $before = $row(16);
        $this->updateIssue(16, ['notes' => 'A note and nothing else']);
        $after = $row(16);
        self::assertSame((int) $before['lock_version'] + 1, (int) $after['lock_version']);
        self::assertSame((int) $before['journals'] + 1, (int) $after['journals']);
        self::assertGreaterThanOrEqual($before['updated_on'], $after['updated_on']);

        // so is a custom value alone
        $before = $row(13);
        $this->updateIssue(13, ['custom_field_values' => ['1' => 'MySQL']]);
        self::assertSame((int) $before['lock_version'] + 1, (int) $row(13)['lock_version']);
    }
}
