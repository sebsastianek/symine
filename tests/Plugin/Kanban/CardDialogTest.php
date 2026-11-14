<?php

declare(strict_types=1);

namespace App\Tests\Plugin\Kanban;

use App\Entity\Issue;
use App\Security\Authorization;
use App\Service\AttachmentService;
use App\Service\WatcherService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symine\Kanban\Entity\Board;

/** The dialog of a card: what it shows to whom, changing fields, commenting, files, subtasks, watching, badges */
final class CardDialogTest extends KanbanWebTestCase
{
    private function url(Board $board, int $issueId, string $suffix = ''): string
    {
        return '/kanban/boards/'.$board->id.'/cards/'.$issueId.$suffix;
    }

    private function allowed(string $permission, string $login, int $projectId = 1): bool
    {
        return self::getContainer()->get(Authorization::class)->allowedTo($permission, $this->em->find(\App\Entity\Project::class, $projectId), $this->user($login));
    }

    public function testDialogShowsTheIssue(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $this->loginAs('jsmith');
        $crawler = $this->get($this->url($board, 13));
        $this->assertStatus(200);
        self::assertCount(0, $crawler->filter('#header'), 'only the dialog');
        self::assertSame('Cart total is wrong with discounts', $crawler->filter('.kanban-dialog-title')->text());
        self::assertStringContainsString('Bug #13', $crawler->filter('.kanban-dialog-id')->text());
        self::assertStringContainsString('In Progress', $crawler->filter('.kanban-dialog-kicker')->text());
        self::assertGreaterThan(0, $crawler->filter('.kanban-dialog-description .wiki ol li')->count(), 'the description is formatted');
        self::assertStringContainsString('notes.txt', $crawler->filter('.kanban-dialog-attachments')->text());
        self::assertSame('2', $crawler->filter('#kanban-field-status option[selected]')->attr('value'));
        self::assertGreaterThan(1, $crawler->filter('#kanban-field-status option')->count(), 'the statuses the workflow allows');
        self::assertCount(1, $crawler->filter('select[name="issue[assigned_to_id]"]'));
        self::assertCount(1, $crawler->filter('input[type="date"][name="issue[due_date]"][value="2026-11-12"]'));
        self::assertCount(1, $crawler->filter('.kanban-dialog-comment textarea[name="notes"]'));
        self::assertCount(1, $crawler->filter('a.kanban-dialog-open[href="/issues/13"]'));
        self::assertStringContainsString('Status changed from New to In Progress', $crawler->filter('.kanban-dialog-journals')->text());

        // the private note of #13 is only for those who may read private notes
        $sees = fn (string $login): bool => str_contains($this->loginAndGet($login, $this->url($board, 13)), 'Started working on it');
        self::assertSame($this->allowed('view_private_notes', 'jsmith'), $sees('jsmith'));
        self::assertTrue($sees('dlopper'), 'its author always sees it');
        self::assertSame($this->allowed('view_private_notes', 'rhill'), $sees('rhill'));

        // a reporter can read and comment, and only change what the issue form would let them change
        $this->loginAs('rhill');
        $crawler = $this->get($this->url($board, 16));
        $this->assertStatus(200);
        $safe = self::getContainer()->get(\App\Service\IssueService::class)->safeAttributeNames($this->em->find(Issue::class, 16), $this->user('rhill'));
        self::assertSame(\in_array('assigned_to_id', $safe, true) ? 1 : 0, $crawler->filter('select[name="issue[assigned_to_id]"]')->count());
        self::assertSame(\in_array('subject', $safe, true) ? 1 : 0, $crawler->filter('[data-edit="subject"]')->count());
    }

    private function loginAndGet(string $login, string $url): string
    {
        $this->loginAs($login);
        $this->client->request('GET', $url);
        $this->assertStatus(200, "$login $url");

        return (string) $this->client->getResponse()->getContent();
    }

    public function testOnlyCardsOfTheBoardHaveADialog(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $private = $this->board(['name' => 'Private', 'visibility' => Board::VISIBILITY_PRIVATE]);
        $this->loginAs('dlopper');
        $this->get($this->url($board, 18));
        $this->assertStatus(404, 'an issue of another project');
        $this->get($this->url($board, 17));
        $this->assertStatus(404, 'a private issue the user cannot see');
        $this->get($this->url($board, 99999));
        $this->assertStatus(404);
        $this->get($this->url($private, 13));
        $this->assertStatus(403, 'a board the user cannot see');
        $this->postJson($this->url($private, 13), ['notes' => 'hello']);
        $this->assertStatus(403);
        $this->postJson($this->url($board, 18), ['notes' => 'hello']);
        $this->assertStatus(404);
    }

    public function testFieldsSaveLikeTheIssueForm(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $this->loginAs('jsmith');

        $json = $this->postJson($this->url($board, 16), ['issue' => ['priority_id' => '5']]);
        $this->assertStatus(200);
        self::assertSame(5, $this->issue(16)->priority->id);
        self::assertStringContainsString('id="kanban-card-16"', $json['card']);
        self::assertStringContainsString('kanban-dialog-body', $json['dialog']);
        self::assertArrayHasKey('columns', $json);
        $detail = $this->em->getConnection()->fetchAssociative('SELECT d.prop_key, d.value FROM journal_details d JOIN journals j ON j.id = d.journal_id WHERE j.journalized_id = 16 ORDER BY d.id DESC LIMIT 1');
        self::assertSame(['prop_key' => 'priority_id', 'value' => '5'], $detail, 'journalized');

        // several fields at once, the title and the description
        $this->postJson($this->url($board, 16), ['issue' => ['subject' => 'Wishlist e-mails', 'description' => "Send *one* digest", 'assigned_to_id' => (string) $this->user('dlopper')->id, 'due_date' => '2026-12-24', 'done_ratio' => '40', 'estimated_hours' => '3.5']]);
        $this->assertStatus(200);
        $issue = $this->issue(16);
        self::assertSame(['Wishlist e-mails', 'Send *one* digest', $this->user('dlopper')->id, '2026-12-24', 40, 3.5], [$issue->subject, $issue->description, $issue->assignedTo?->id, $issue->dueDate?->format('Y-m-d'), $issue->doneRatio, $issue->estimatedHours]);

        // the status: moves the card to another column
        $json = $this->postJson($this->url($board, 16), ['issue' => ['status_id' => '3']]);
        self::assertSame(3, $this->issue(16)->status->id);
        self::assertStringContainsString('data-column="'.$this->columnFor($board, 3)->id.'"', $json['card']);
        self::assertSame(1, $json['columns'][$this->columnFor($board, 3)->id]['count']);

        // clearing a field
        $this->postJson($this->url($board, 16), ['issue' => ['assigned_to_id' => '']]);
        self::assertNull($this->issue(16)->assignedTo);

        // validation errors come back, nothing is saved
        $json = $this->postJson($this->url($board, 16), ['issue' => ['subject' => ' ']]);
        $this->assertStatus(422);
        self::assertSame(['Subject cannot be blank'], $json['errors']);
        self::assertSame('Wishlist e-mails', $this->issue(16)->subject);
        $json = $this->postJson($this->url($board, 16), ['issue' => ['due_date' => '2026-01-01', 'start_date' => '2026-06-01']]);
        $this->assertStatus(422);
        self::assertNotEmpty($json['errors']);

        // fields the dialog does not offer are not accepted: with nothing else to save, the request is refused
        $this->postJson($this->url($board, 16), ['issue' => ['project_id' => '2', 'is_private' => '1']]);
        $this->assertStatus(422);
        self::assertSame(1, $this->issue(16)->project->id);
    }

    public function testFieldsFollowPermissionsAndWorkflow(): void
    {
        $this->enableKanban();
        $board = $this->board();

        // a developer cannot reject: the status is not among the ones the workflow gives, so it stays
        $this->loginAs('dlopper');
        $this->postJson($this->url($board, 16), ['issue' => ['status_id' => '6']]);
        self::assertSame(1, $this->issue(16)->status->id);

        // what is not a safe attribute for the user is refused, not silently dropped
        $safe = self::getContainer()->get(\App\Service\IssueService::class)->safeAttributeNames($this->em->find(Issue::class, 16), $this->user('rhill'));
        $this->loginAs('rhill');
        $this->postJson($this->url($board, 16), ['issue' => ['estimated_hours' => '99']]);
        if (\in_array('estimated_hours', $safe, true)) {
            $this->assertStatus(200);
        } else {
            $this->assertStatus(403);
            self::assertNotSame(99.0, $this->issue(16)->estimatedHours);
        }

        // visitors cannot change anything, and the CSRF token is required
        $this->client->request('POST', $this->url($board, 16), ['issue' => ['priority_id' => '1']]);
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        self::assertNotSame(1, $this->issue(16)->priority->id);
    }

    public function testQuickComments(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $this->loginAs('jsmith');
        $json = $this->postJson($this->url($board, 15), ['notes' => 'Looks **good** to me']);
        $this->assertStatus(200);
        $row = $this->em->getConnection()->fetchAssociative("SELECT notes, private_notes, user_id FROM journals WHERE journalized_id = 15 AND journalized_type = 'Issue' ORDER BY id DESC LIMIT 1");
        self::assertSame(['notes' => 'Looks **good** to me', 'private_notes' => 0, 'user_id' => $this->user('jsmith')->id], array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, $row));
        self::assertStringContainsString('<strong>good</strong>', $json['dialog'], 'the comment shows formatted in the dialog');
        self::assertStringContainsString('kanban-badge-comments', $json['card'], 'and counts on the card');

        // private, for those who may
        $this->postJson($this->url($board, 15), ['notes' => 'Between us', 'private_notes' => '1']);
        $private = (int) $this->em->getConnection()->fetchOne("SELECT private_notes FROM journals WHERE journalized_id = 15 ORDER BY id DESC LIMIT 1");
        self::assertSame($this->allowed('set_notes_private', 'jsmith') ? 1 : 0, $private);

        // an empty comment is not one
        $json = $this->postJson($this->url($board, 15), ['notes' => "  \n "]);
        $this->assertStatus(422);
        self::assertSame(['Notes cannot be blank'], $json['errors']);

        // a comment and a field in one request
        $this->postJson($this->url($board, 15), ['notes' => 'Raising it', 'issue' => ['priority_id' => '4']]);
        $this->assertStatus(200);
        self::assertSame(4, $this->issue(15)->priority->id);
        $last = $this->em->getConnection()->fetchAssociative('SELECT j.notes, (SELECT COUNT(*) FROM journal_details d WHERE d.journal_id = j.id) AS details FROM journals j WHERE j.journalized_id = 15 ORDER BY j.id DESC LIMIT 1');
        self::assertSame(['notes' => 'Raising it', 'details' => 1], ['notes' => $last['notes'], 'details' => (int) $last['details']], 'one journal entry with the note and the change');
    }

    public function testBadgesCountWhatTheUserMaySee(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $this->setOptions($board, ['card_fields' => ['tracker', 'badges', 'cover']]);
        $this->loginAs('jsmith');
        $this->postJson($this->url($board, 15), ['notes' => 'Public comment']);
        $this->postJson($this->url($board, 15), ['notes' => 'Private comment', 'private_notes' => '1']);

        $badge = function (string $login, int $issueId, string $kind) use ($board): ?string {
            $this->loginAs($login);
            $node = $this->get('/kanban/boards/'.$board->id)->filter('#kanban-card-'.$issueId.' .kanban-badge-'.$kind);

            return $node->count() ? trim($node->text()) : null;
        };
        $privateSaved = 1 === (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM journals WHERE journalized_id = 15 AND private_notes = 1");
        self::assertSame($privateSaved ? '2' : '2', $badge('jsmith', 15, 'comments'));
        if ($privateSaved && !$this->allowed('view_private_notes', 'rhill')) {
            self::assertSame('1', $badge('rhill', 15, 'comments'), 'private comments are not even counted for those who cannot read them');
        }
        self::assertSame('1', $badge('jsmith', 13, 'files'), 'notes.txt');
        self::assertSame('0/2', $badge('jsmith', 14, 'subtasks'), '#15 and #16, both open');
        self::assertNull($badge('jsmith', 16, 'subtasks'));

        // closing a subtask shows on the parent
        $this->loginAs('jsmith');
        $this->postJson($this->url($board, 16), ['issue' => ['status_id' => '5']]);
        self::assertSame('1/2', $badge('jsmith', 14, 'subtasks'));

        // without the option, no badges and no query for them
        $this->setOptions($board, ['card_fields' => ['tracker']]);
        self::assertNull($badge('jsmith', 13, 'files'));
    }

    public function testSubtasks(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $this->loginAs('jsmith');
        $crawler = $this->get($this->url($board, 14));
        self::assertCount(2, $crawler->filter('.kanban-dialog-checklist li'));
        self::assertStringContainsString('0 / 2', $crawler->filter('.kanban-dialog-subtasks h4')->text());
        self::assertCount(1, $crawler->filter('.kanban-dialog-checklist a[data-open-card="15"]'));

        if (!$this->allowed('manage_subtasks', 'jsmith')) {
            $this->grant('Manager', 'manage_subtasks');
        }
        $json = $this->postJson($this->url($board, 14, '/subtasks'), ['subject' => 'Wishlist export']);
        $this->assertStatus(201);
        $child = $this->em->getRepository(Issue::class)->findOneBy(['subject' => 'Wishlist export']);
        self::assertNotNull($child);
        self::assertSame([14, 1, 2, $this->user('jsmith')->id], [$child->parent?->id, $child->project->id, $child->tracker->id, $child->author->id], 'same project and tracker as its parent');
        self::assertStringContainsString('Wishlist export', $json['dialog']);
        self::assertStringContainsString('0/3', $json['card']);

        $json = $this->postJson($this->url($board, 14, '/subtasks'), ['subject' => '']);
        $this->assertStatus(422);
        self::assertSame(['Subject cannot be blank'], $json['errors']);

        // without the permission to manage subtasks there is no form and no way
        $this->loginAs('rhill');
        if (!$this->allowed('manage_subtasks', 'rhill')) {
            self::assertCount(0, $this->get($this->url($board, 14))->filter('.kanban-dialog-subtask'));
            $this->postJson($this->url($board, 14, '/subtasks'), ['subject' => 'Sneaky']);
            $this->assertStatus(403);
            self::assertNull($this->em->getRepository(Issue::class)->findOneBy(['subject' => 'Sneaky']));
        }
    }

    public function testWatching(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $watchers = self::getContainer()->get(WatcherService::class);
        $this->loginAs('dlopper');
        $before = $watchers->isWatching('Issue', 16, $this->user('dlopper'));
        $json = $this->postJson($this->url($board, 16, '/watch'));
        $this->assertStatus(200);
        self::assertSame(!$before, $watchers->isWatching('Issue', 16, $this->user('dlopper')));
        self::assertStringContainsString('aria-pressed="'.($before ? 'false' : 'true').'"', $json['dialog']);
        $this->postJson($this->url($board, 16, '/watch'));
        self::assertSame($before, $watchers->isWatching('Issue', 16, $this->user('dlopper')));
    }

    public function testFilesAndCover(): void
    {
        $this->enableKanban();
        $board = $this->board();
        $this->loginAs('jsmith');
        $png = sys_get_temp_dir().'/kanban-cover-'.bin2hex(random_bytes(4)).'.png';
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        $token = $this->csrfToken();
        $this->client->request('POST', $this->url($board, 15, '/attachments'), ['_token' => $token], ['attachments' => [new UploadedFile($png, 'mockup.png', 'image/png', null, true)]]);
        $this->assertStatus(200);
        $json = json_decode((string) $this->client->getResponse()->getContent(), true);
        $files = self::getContainer()->get(AttachmentService::class)->forContainer('Issue', 15);
        self::assertCount(1, $files);
        self::assertSame('mockup.png', $files[0]->filename);
        self::assertStringContainsString('mockup.png', $json['dialog']);
        self::assertStringContainsString('kanban-card-cover', $json['card'], 'the first picture is the cover of the card');
        self::assertStringContainsString('/attachments/thumbnail/'.$files[0]->id, $json['card']);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM journal_details d JOIN journals j ON j.id = d.journal_id WHERE j.journalized_id = 15 AND d.property = 'attachment'"), 'journalized like on the issue form');

        // nothing sent
        $this->postJson($this->url($board, 15, '/attachments'));
        $this->assertStatus(422);

        // those who may not edit the issue cannot add files
        $this->loginAs('rhill');
        if (!self::getContainer()->get(\App\Service\IssueService::class)->isAttributesEditable($this->em->find(Issue::class, 15), $this->user('rhill'))) {
            $this->client->request('POST', $this->url($board, 15, '/attachments'), ['_token' => $this->csrfToken()], ['attachments' => [new UploadedFile($png, 'other.png', 'image/png', null, true)]]);
            $this->assertStatus(403);
            self::assertCount(0, $this->get($this->url($board, 15))->filter('.kanban-dialog-attach'));
        }
        @unlink($png);
    }
}
