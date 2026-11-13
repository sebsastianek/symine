<?php

declare(strict_types=1);

namespace App\Tests\Issues;

use App\Entity\Journal;

/** Journals (edit/delete notes, diff, quote), relations and watchers screens. */
final class JournalsRelationsWatchersTest extends IssuesWebTestCase
{
    public function testEditJournalNotes(): void
    {
        $this->login('jsmith');
        $js = $this->xhr('GET', '/journals/15/edit');
        self::assertStringContainsString('journal-15-form', $js);
        $js = $this->xhr('PUT', '/journals/15', ['journal' => ['notes' => 'Edited notes', 'private_notes' => '0']]);
        self::assertStringContainsString('#change-15', $js);
        $this->em->clear();
        $j = $this->em->find(Journal::class, 15);
        self::assertSame('Edited notes', $j->notes);
        self::assertFalse($j->privateNotes);
        self::assertSame('John Smith', $j->updatedBy?->getName());
    }

    public function testDeleteNotesOfJournalWithoutDetailsKeepsIt(): void
    {
        $this->login('jsmith');
        $this->updateIssue(14, ['notes' => 'to be deleted']);
        $id = (int) $this->em->getConnection()->fetchOne("SELECT MAX(id) FROM journals WHERE journalized_id = 14");
        $js = $this->xhr('PUT', '/journals/'.$id.'?journal%5Bnotes%5D=');
        self::assertStringContainsString('$("#change-'.$id.'").remove();', $js);
        $this->em->clear();
        // Redmine 7 (test_update_xhr_with_empty_notes_should_not_delete_the_journal): the journal is kept
        self::assertSame('', $this->em->find(Journal::class, $id)?->notes);
    }

    public function testJournalEditDeniedWithoutPermission(): void
    {
        $this->login('rhill');
        $this->xhr('GET', '/journals/15/edit');
        self::assertSame(404, $this->code(), 'private journal of another user is not visible');
    }

    public function testDescriptionDiff(): void
    {
        $this->login('jsmith');
        $this->updateIssue(14, ['description' => "New description text"]);
        $crawler = $this->client->request('GET', '/issues/14');
        $link = $crawler->filter('#history a[href*="/diff"]');
        self::assertCount(1, $link);
        $html = $this->get($link->attr('href'));
        self::assertSame(200, $this->code());
        self::assertStringContainsString('class="diff_in"', $html);
    }

    public function testQuote(): void
    {
        $this->login('jsmith');
        $js = $this->xhr('POST', '/issues/13/quoted?journal_id=15&journal_indice=1', [], json_encode(['quote' => 'partial text']));
        self::assertStringContainsString('Dave Lopper wrote in #note-1:', $js);
        self::assertStringContainsString('partial text', $js);
        self::assertStringContainsString("#issue_private_notes", $js);
        $js = $this->xhr('POST', '/issues/13/quoted', [], '{}');
        self::assertStringContainsString('John Smith wrote:', $js);
    }

    public function testAddAndRemoveRelations(): void
    {
        $this->login('jsmith');
        $js = $this->xhr('POST', '/issues/14/relations', ['relation' => ['relation_type' => 'blocks', 'issue_to_id' => '13, 999']]);
        self::assertStringStartsWith('$("#relations").html(', $js);
        self::assertStringContainsString('errorExplanation', $js, 'invalid issue id is reported');
        $rel = $this->db("SELECT id, issue_from_id, issue_to_id, relation_type FROM issue_relations WHERE issue_from_id = 14");
        self::assertCount(1, $rel);
        self::assertSame([14, 13, 'blocks'], [(int) $rel[0]['issue_from_id'], (int) $rel[0]['issue_to_id'], $rel[0]['relation_type']]);
        // journal on both issues
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM journal_details WHERE property = 'relation'"));

        $js = $this->xhr('DELETE', '/relations/'.$rel[0]['id'].'?issue_id=14');
        self::assertStringContainsString('#relation-'.$rel[0]['id'], $js);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM issue_relations WHERE issue_from_id = 14'));
    }

    public function testWatchUnwatch(): void
    {
        $this->login('dlopper');
        $js = $this->xhr('POST', '/watchers/watch?object_type=issue&object_id=13');
        self::assertStringContainsString('issue-13-watcher icon icon-fav', $js);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM watchers WHERE watchable_type = 'Issue' AND watchable_id = 13 AND user_id = 6"));
        $this->xhr('DELETE', '/watchers/watch?object_type=issue&object_id=13');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM watchers WHERE watchable_type = 'Issue' AND watchable_id = 13 AND user_id = 6"));
    }

    public function testManageWatchers(): void
    {
        $this->login('jsmith');
        $js = $this->xhr('GET', '/watchers/new?object_type=issue&object_id=14');
        self::assertStringContainsString('new-watcher-form', $js);
        $this->xhr('POST', '/watchers', ['object_type' => 'issue', 'object_id' => ['14'], 'watcher' => ['user_ids' => ['6']]]);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM watchers WHERE watchable_type = 'Issue' AND watchable_id = 14 AND user_id = 6"));
        $crawler = $this->client->request('GET', '/issues/14');
        self::assertCount(1, $crawler->filter('#watchers li.user-6'));
        $this->xhr('DELETE', '/watchers?object_type=issue&object_id=14&user_id=6');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM watchers WHERE watchable_type = 'Issue' AND watchable_id = 14 AND user_id = 6"));
        $html = $this->get('/watchers/autocomplete_for_user?object_type=issue&object_id=14&q=dave');
        self::assertStringContainsString('Dave Lopper', $html);
    }

    public function testDeleteAttachmentIsJournalized(): void
    {
        $this->login('jsmith');
        $this->submit('/attachments/1', ['_method' => 'delete']);
        self::assertSame(302, $this->code());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM attachments WHERE id = 1'));
        $row = $this->db("SELECT old_value, value FROM journal_details WHERE property = 'attachment' AND prop_key = '1'");
        self::assertSame([['old_value' => 'notes.txt', 'value' => null]], $row);
    }
}
