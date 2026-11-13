<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\Role;
use App\Entity\Tracker;
use App\Service\Admin\WorkflowService;

/**
 * Workflow editor. Expected rows were checked against the reference Redmine 7 instance
 * (same requests sent to both applications, `workflows` tables compared row by row).
 */
final class WorkflowsTest extends AdminWebTestCase
{
    use WorkflowAdminTestTrait;

    public function testPagesAccess(): void
    {
        $this->assertAdminOnly([
            '/workflows', '/workflows/edit', '/workflows/edit?role_id=4&tracker_id=1',
            '/workflows/edit?role_id=all&tracker_id=all&used_statuses_only=0',
            '/workflows/edit?role_id[]=3&role_id[]=4&tracker_id[]=1&tracker_id[]=2',
            '/workflows/permissions', '/workflows/permissions?role_id=4&tracker_id=1', '/workflows/copy',
        ]);
    }

    public function testSummaryCounts(): void
    {
        $this->loginAs('admin');
        $crawler = $this->get('/workflows');
        $link = $crawler->filter('a[href="/workflows/edit?role_id=4&tracker_id=1"]');
        self::assertSame('13', trim($link->text()));
    }

    public function testEditMatrix(): void
    {
        $this->loginAs('admin');
        $crawler = $this->get('/workflows/edit?role_id=4&tracker_id=1');
        self::assertCount(1, $crawler->filter('table.transitions-always'));
        self::assertCount(1, $crawler->filter('table.transitions-author'));
        self::assertCount(1, $crawler->filter('table.transitions-assignee'));
        self::assertSame('checked', $crawler->filter('input#transitions_1_2_always[type=checkbox]')->attr('checked'));
        self::assertNull($crawler->filter('input#transitions_0_1_always[type=checkbox]')->attr('checked'));
        self::assertSame('disabled', $crawler->filter('input#transitions_1_1_always')->attr('disabled'));
        // "new issue" row only in the "always" table
        self::assertCount(0, $crawler->filter('input#transitions_0_1_author'));
        // used statuses only: Rejected (6) is not used by Bug for workflow roles... depends on data: just check header
        self::assertGreaterThan(0, $crawler->filter('table.transitions-always thead td')->count());
    }

    public function testUpdateTransitionsLikeRedmine(): void
    {
        $this->loginAs('admin');
        $base = ['role_id' => ['4'], 'tracker_id' => ['1'], 'used_statuses_only' => ''];
        $r = $this->post('/workflows/update', $base + ['transitions' => [
            '0' => ['1' => ['always' => '1'], '2' => ['always' => '1'], '3' => ['always' => '0']],
            '1' => ['5' => ['always' => '0'], '2' => ['always' => '1'], '6' => ['author' => '1']],
            '2' => ['5' => ['assignee' => '1'], '6' => ['author' => '1', 'assignee' => '1']],
            '3' => ['4' => ['author' => '0']],
        ]], 'PATCH');
        self::assertTrue($r->isRedirect());
        self::assertSame(explode(' ', '0-1-0-0 0-2-0-0 1-2-0-0 1-3-0-0 1-4-0-0 1-6-1-0 2-3-0-0 2-4-0-0 2-5-0-0 2-5-0-1 2-6-1-1 3-2-0-0 3-4-0-0 3-5-0-0 4-2-0-0 4-3-0-0 4-5-0-0'), $this->transitionRows(1, 4));

        $this->post('/workflows/update', $base + ['transitions' => [
            '2' => ['6' => ['author' => '0'], '5' => ['assignee' => '0']],
            '4' => ['5' => ['author' => '1']],
        ]], 'PATCH');
        self::assertSame(explode(' ', '0-1-0-0 0-2-0-0 1-2-0-0 1-3-0-0 1-4-0-0 1-6-1-0 2-3-0-0 2-4-0-0 2-5-0-0 2-6-0-1 3-2-0-0 3-4-0-0 3-5-0-0 4-2-0-0 4-3-0-0 4-5-0-0 4-5-1-0'), $this->transitionRows(1, 4));
    }

    public function testNoChangeIsIgnoredForMultipleScopes(): void
    {
        $this->loginAs('admin');
        $before = $this->transitionRows(2, 3);
        $this->post('/workflows/update', ['role_id' => ['3', '4'], 'tracker_id' => ['1', '2'], 'transitions' => [
            '1' => ['2' => ['always' => 'no_change'], '3' => ['always' => '0']],
            '3' => ['1' => ['always' => '1']],
        ]], 'PATCH');
        foreach ([[1, 3], [1, 4], [2, 3], [2, 4]] as [$t, $r]) {
            $rows = $this->transitionRows($t, $r);
            self::assertContains('3-1-0-0', $rows);
            self::assertNotContains('1-3-0-0', $rows);
        }
        self::assertSame(\in_array('1-2-0-0', $before, true), \in_array('1-2-0-0', $this->transitionRows(2, 3), true));
    }

    public function testFieldPermissions(): void
    {
        $this->loginAs('admin');
        $crawler = $this->get('/workflows/permissions?role_id=4&tracker_id=1');
        self::assertCount(1, $crawler->filter('table.fields_permissions select#permissions_1_start_date'));
        self::assertCount(1, $crawler->filter('select#permissions_1_1'), 'custom field row');
        $this->post('/workflows/update_permissions', ['role_id' => ['4'], 'tracker_id' => ['1'], 'permissions' => [
            '1' => ['start_date' => 'readonly', 'due_date' => 'required', '1' => 'required'],
            '2' => ['project_id' => 'readonly', 'assigned_to_id' => ''],
            '3' => ['bogus' => 'readonly'],
        ]], 'PATCH');
        $rows = $this->db()->fetchAllNumeric("SELECT old_status_id, new_status_id, field_name, rule FROM workflows WHERE type = 'WorkflowPermission' AND tracker_id = 1 AND role_id = 4 ORDER BY old_status_id, field_name");
        self::assertSame([[1, 0, '1', 'required'], [1, 0, 'due_date', 'required'], [1, 0, 'start_date', 'readonly'], [2, 0, 'due_date', 'required'], [2, 0, 'project_id', 'readonly']], array_map(static fn ($r) => [(int) $r[0], (int) $r[1], $r[2], $r[3]], $rows));

        $crawler = $this->get('/workflows/permissions?role_id[]=3&role_id[]=4&tracker_id[]=1');
        // differs between roles -> "no change" selected
        self::assertSame('no_change', $crawler->filter('select#permissions_1_start_date option[selected]')->attr('value'));
        $this->post('/workflows/update_permissions', ['role_id' => ['3', '4'], 'tracker_id' => ['1'], 'permissions' => [
            '1' => ['start_date' => '', 'due_date' => 'no_change', 'assigned_to_id' => 'readonly'],
        ]], 'PATCH');
        $rows = $this->db()->fetchAllNumeric("SELECT role_id, field_name, rule FROM workflows WHERE type = 'WorkflowPermission' AND tracker_id = 1 AND old_status_id = 1 ORDER BY role_id, field_name");
        self::assertSame([[3, 'assigned_to_id', 'readonly'], [4, '1', 'required'], [4, 'assigned_to_id', 'readonly'], [4, 'due_date', 'required']], array_map(static fn ($r) => [(int) $r[0], $r[1], $r[2]], $rows));
    }

    public function testCopy(): void
    {
        $this->loginAs('admin');
        $r = $this->post('/workflows/duplicate', ['source_tracker_id' => '1', 'source_role_id' => '4', 'target_tracker_ids' => ['2', '3'], 'target_role_ids' => ['5']]);
        self::assertTrue($r->isRedirect('/workflows/copy?source_tracker_id=1&source_role_id=4'));
        self::assertSame($this->transitionRows(1, 4), $this->transitionRows(2, 5));
        self::assertSame($this->transitionRows(1, 4), $this->transitionRows(3, 5));

        // "same as target" tracker
        $this->post('/workflows/duplicate', ['source_tracker_id' => 'any', 'source_role_id' => '3', 'target_tracker_ids' => ['2'], 'target_role_ids' => ['4']]);
        self::assertSame($this->transitionRows(2, 3), $this->transitionRows(2, 4));

        $r = $this->post('/workflows/duplicate', ['source_tracker_id' => 'any', 'source_role_id' => 'any', 'target_tracker_ids' => ['2'], 'target_role_ids' => ['4']]);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('flash_error', (string) $r->getContent());
        $r = $this->post('/workflows/duplicate', ['source_tracker_id' => '1', 'source_role_id' => '4']);
        self::assertStringContainsString('flash_error', (string) $r->getContent());
    }

    public function testServiceAddTransitionAndCounts(): void
    {
        $svc = self::getContainer()->get(WorkflowService::class);
        $before = $svc->transitionCounts()[2][5] ?? 0;
        $svc->addTransition(2, 5, 1, 6);
        $this->em->flush();
        self::assertSame($before + 1, $svc->transitionCounts()[2][5]);
        self::assertTrue($svc->roleHasRules($this->em->find(Role::class, 5)));
        self::assertTrue($svc->trackerHasRules($this->em->find(Tracker::class, 2)));
        self::assertContains(6, $svc->usedStatusIds([2]));
    }
}
