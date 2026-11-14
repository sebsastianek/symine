<?php

declare(strict_types=1);

namespace App\Tests\Performance;

use App\Entity\Issue;
use App\Entity\IssueCustomField;
use App\Entity\Project;
use App\Entity\TimeEntry;
use App\Entity\Version;
use App\Entity\Watcher;
use App\Security\Authorization;
use App\Service\AttachmentService;
use App\Service\CustomFieldService;
use App\Service\IssueService;
use App\Service\ProjectService;
use App\Service\WatcherService;
use App\Tests\Integration\SymineTestCase;

/**
 * Services remember what they read for the rest of the request (QueryMemo). Within one request — here one
 * kernel — they must still answer like the database after every kind of write: through the unit of work,
 * through DQL and through plain SQL.
 */
final class RememberedReadsTest extends SymineTestCase
{
    public function testCustomFieldsFollowWhatIsWritten(): void
    {
        $fields = $this->service(CustomFieldService::class);
        $before = $fields->fieldsOf(IssueCustomField::class);
        self::assertSame($before, $fields->fieldsOf(IssueCustomField::class));

        $field = new IssueCustomField();
        $field->name = 'Added during the request';
        $field->fieldFormat = 'string';
        $field->position = 99;
        $this->em->persist($field);
        self::assertCount(\count($before), $fields->fieldsOf(IssueCustomField::class), 'not stored yet');
        $this->em->flush();
        $names = array_map(static fn ($f) => $f->name, $fields->fieldsOf(IssueCustomField::class));
        self::assertContains('Added during the request', $names);

        $this->em->getConnection()->executeStatement('UPDATE custom_fields SET position = 0 WHERE id = ?', [$field->id]);
        $this->em->clear();
        self::assertSame('Added during the request', $fields->fieldsOf(IssueCustomField::class)[0]->name, 'plain SQL is seen too');

        $this->em->createQuery('DELETE FROM '.IssueCustomField::class.' f WHERE f.id = :id')->setParameter('id', $field->id)->execute();
        $this->em->clear();
        self::assertNotContains('Added during the request', array_map(static fn ($f) => $f->name, $fields->fieldsOf(IssueCustomField::class)));
    }

    public function testCustomValuesFollowWhatIsWritten(): void
    {
        $fields = $this->service(CustomFieldService::class);
        $issueFields = $fields->fieldsOf(IssueCustomField::class);
        $database = array_values(array_filter($issueFields, static fn ($f) => 'Database' === $f->name))[0];
        $before = $fields->values('Issue', 13);

        $fields->saveValues('Issue', 13, [$database], [$database->id => 'PostgreSQL']);
        self::assertSame($before, $fields->values('Issue', 13), 'not stored yet');
        $this->em->flush();
        self::assertSame('PostgreSQL', $fields->values('Issue', 13)[$database->id]);

        $this->em->getConnection()->executeStatement("UPDATE custom_values SET value = 'MySQL' WHERE customized_type = 'Issue' AND customized_id = 13 AND custom_field_id = ?", [$database->id]);
        $this->em->clear();
        self::assertSame('MySQL', $fields->values('Issue', 13)[$database->id]);

        // the values of a list read at once are the ones each object would have read
        $this->em->clear();
        $one = [13 => $fields->values('Issue', 13), 14 => $fields->values('Issue', 14), 15 => $fields->values('Issue', 15)];
        $this->em->clear();
        $fields->preloadValues('Issue', [13, 14, 15]);
        foreach ($one as $id => $values) {
            self::assertSame($values, $fields->values('Issue', $id), '#'.$id);
        }
        $fields->deleteValues('Issue', 13);
        self::assertSame([], $fields->values('Issue', 13));
    }

    public function testWatchersFollowWhatIsWritten(): void
    {
        $watchers = $this->service(WatcherService::class);
        $rhill = $this->user('rhill');
        $logins = static fn (array $list) => array_map(static fn ($u) => $u->login, $list);
        $before = $logins($watchers->watchers('Issue', 14));
        self::assertNotContains('rhill', $before);
        self::assertFalse($watchers->isWatching('Issue', 14, $rhill));

        $watchers->add('Issue', 14, $rhill);
        $this->em->flush();
        self::assertContains('rhill', $logins($watchers->watchers('Issue', 14)));
        self::assertTrue($watchers->isWatching('Issue', 14, $rhill));
        self::assertFalse($watchers->isWatching('Issue', 14, $this->user('dlopper')), 'one answer per user');

        $watchers->removeAll('Issue', 14);
        self::assertSame([], $watchers->watchers('Issue', 14));
        self::assertFalse($watchers->isWatching('Issue', 14, $rhill));

        $this->em->getConnection()->insert('watchers', ['watchable_type' => 'Issue', 'watchable_id' => 14, 'user_id' => $rhill->id]);
        self::assertSame(['rhill'], $logins($watchers->watchers('Issue', 14)));
    }

    public function testSpentHoursVersionsAndAttachmentsFollowWhatIsWritten(): void
    {
        $issues = $this->service(IssueService::class);
        $projects = $this->service(ProjectService::class);
        $attachments = $this->service(AttachmentService::class);
        $issue = $this->em->find(Issue::class, 13);
        $project = $this->em->getRepository(Project::class)->findOneBy(['identifier' => 'ecommerce']);

        $spent = $issues->spentHours($issue);
        $this->em->getConnection()->executeStatement('UPDATE time_entries SET hours = hours + 1.5 WHERE issue_id = 13');
        self::assertSame($spent + 1.5, $issues->spentHours($issue));
        $this->em->createQuery('DELETE FROM '.TimeEntry::class.' t WHERE t.issue = :i')->setParameter('i', 13)->execute();
        self::assertSame(0.0, $issues->spentHours($issue));

        $names = static fn (array $versions) => array_map(static fn (Version $v) => $v->name, $versions);
        $before = $names($projects->sharedVersions($project));
        $version = new Version();
        $version->project = $project;
        $version->name = '9.9';
        $this->em->persist($version);
        $this->em->flush();
        self::assertSame([...$before, '9.9'], $names($projects->sharedVersions($project)));
        self::assertSame($projects->sharedVersions($project), $projects->sharedVersions($project));

        self::assertSame([], $attachments->forContainer('Issue', 14));
        $attachment = $attachments->createFromContent('hello', 'hello.txt', $this->user('jsmith'));
        $attachments->attachTo([$attachment], 'Issue', 14);
        $this->em->flush();
        self::assertSame(['hello.txt'], array_map(static fn ($a) => $a->filename, $attachments->forContainer('Issue', 14)));
        $attachments->delete($attachment);
        $this->em->flush();
        self::assertSame([], $attachments->forContainer('Issue', 14));
    }

    public function testTheMenuAsksTheAuthorizationWhichModulesAreEnabled(): void
    {
        $projects = $this->service(ProjectService::class);
        $auth = $this->service(Authorization::class);
        foreach (['jsmith', 'rhill', 'admin'] as $login) {
            $this->actAs($this->user($login));
            $visible = $auth->visibleProjectIds();
            foreach (['issue_tracking', 'time_tracking', 'gantt', 'calendar', 'news', 'no_such_module'] as $module) {
                // what the menu used to ask the database for each item
                $expected = [] !== $visible && (int) $this->em->getConnection()->fetchOne(
                    'SELECT COUNT(*) FROM enabled_modules WHERE name = ? AND project_id IN ('.implode(',', $visible).')', [$module]
                ) > 0;
                self::assertSame($expected, $projects->anyVisibleWithModule($module), $login.' '.$module);
            }
        }
        $this->actAs(null);
        self::assertTrue($projects->anyVisibleWithModule('issue_tracking'));
        $this->em->getConnection()->executeStatement("DELETE FROM enabled_modules WHERE name = 'issue_tracking'");
        $auth->reset();
        self::assertFalse($projects->anyVisibleWithModule('issue_tracking'));
    }

    public function testProjectsAnswerForTheirModulesHoweverTheyWereLoaded(): void
    {
        $auth = $this->service(Authorization::class);
        $this->actAs($this->user('admin'));
        $expected = [];
        foreach ($this->em->getRepository(Project::class)->findAll() as $project) {
            $expected[$project->identifier] = $project->getEnabledModuleNames();
        }
        $this->em->clear();
        $auth->reset();

        $projects = $this->em->getRepository(Project::class)->findAll();
        foreach ($projects as $project) {
            self::assertFalse($project->enabledModules->isInitialized());
        }
        // one permission check loads the modules of every project in memory...
        $auth->allowedTo('view_issues', $projects[0]);
        foreach ($projects as $project) {
            self::assertTrue($project->enabledModules->isInitialized(), $project->identifier);
            // ...and they are the ones each project would have loaded for itself
            self::assertSame($expected[$project->identifier], $project->getEnabledModuleNames(), $project->identifier);
            self::assertSame(\in_array('issue_tracking', $expected[$project->identifier], true) && !$project->isArchived(), $auth->allowedTo('view_issues', $project), $project->identifier);
        }
    }
}
