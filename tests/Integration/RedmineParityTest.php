<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Issue;
use App\Entity\IssueStatus;
use App\Entity\Principal;
use App\Entity\Project;
use App\Entity\User;
use App\Security\Authorization;
use App\Security\CurrentUser;
use App\Service\IssueService;

/**
 * Compares Symine's behaviour with the reference Redmine on the same (migrated) data.
 * Expectations are produced by tests/fixtures/redmine_expectations.rb run inside the original Redmine.
 */
final class RedmineParityTest extends SymineTestCase
{
    private array $exp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exp = json_decode((string) file_get_contents(\dirname(__DIR__).'/fixtures/redmine-expectations.json'), true);
    }

    /** @return iterable<array{0: string, 1: User}> */
    private function pairs(): iterable
    {
        $anon = $this->service(CurrentUser::class)->anonymous();
        foreach ($this->em->getRepository(Issue::class)->findAll() as $issue) {
            foreach (['admin', 'jsmith', 'dlopper', 'rhill', 'anna', 'anonymous'] as $login) {
                $user = 'anonymous' === $login ? $anon : $this->user($login);
                yield [$issue, $user, $issue->subject.'|'.$login];
            }
        }
    }

    public function testIssueVisibility(): void
    {
        $svc = $this->service(IssueService::class);
        foreach ($this->pairs() as [$issue, $user, $key]) {
            $this->actAs($user);
            self::assertSame($this->exp['visible'][$key], $svc->isVisible($issue, $user), "visible? $key");
        }
    }

    public function testStatusesAllowed(): void
    {
        $svc = $this->service(IssueService::class);
        foreach ($this->pairs() as [$issue, $user, $key]) {
            $this->actAs($user);
            $names = array_map(static fn (IssueStatus $s) => $s->name, $svc->newStatusesAllowedTo($issue, $user));
            self::assertSame($this->exp['statuses'][$key], $names, "new_statuses_allowed_to $key");
        }
    }

    public function testWorkflowFieldRules(): void
    {
        $svc = $this->service(IssueService::class);
        foreach ($this->pairs() as [$issue, $user, $key]) {
            $this->actAs($user);
            $req = $svc->requiredAttributeNames($issue, $user);
            $ro = $svc->readOnlyAttributeNames($issue, $user);
            sort($req);
            sort($ro);
            $er = $this->exp['required'][$key];
            $eo = $this->exp['readonly'][$key];
            sort($er);
            sort($eo);
            self::assertSame($er, $req, "required $key");
            self::assertSame($eo, array_map('strval', $ro), "readonly $key");
        }
    }

    public function testEditPermissions(): void
    {
        $svc = $this->service(IssueService::class);
        foreach ($this->pairs() as [$issue, $user, $key]) {
            $this->actAs($user);
            self::assertSame($this->exp['editable'][$key], [$svc->isAttributesEditable($issue, $user), $svc->isNotesAddable($issue, $user), $svc->isDeletable($issue, $user)], "editable/notes/deletable $key");
        }
    }

    public function testAssignableUsers(): void
    {
        $svc = $this->service(IssueService::class);
        foreach ($this->em->getRepository(Issue::class)->findAll() as $issue) {
            $names = array_map(static fn (Principal $p) => $p->getName(), $svc->assignableUsers($issue));
            $expected = $this->exp['assignable'][$issue->subject];
            sort($names);
            sort($expected);
            self::assertSame($expected, $names, 'assignable users of '.$issue->subject);
        }
    }

    public function testProjectPermissions(): void
    {
        $auth = $this->service(Authorization::class);
        $anon = $this->service(CurrentUser::class)->anonymous();
        $perms = ['view_issues', 'add_issues', 'edit_issues', 'delete_issues', 'log_time', 'view_wiki_pages', 'manage_members', 'view_news', 'add_messages', 'view_private_notes'];
        foreach (['admin', 'jsmith', 'dlopper', 'rhill', 'anna', 'anonymous'] as $login) {
            $user = 'anonymous' === $login ? $anon : $this->user($login);
            $this->actAs($user);
            foreach ($this->em->getRepository(Project::class)->findAll() as $p) {
                $allowed = array_values(array_filter($perms, static fn ($perm) => $auth->allowedTo($perm, $p, $user)));
                self::assertSame($this->exp['allowed'][$p->identifier.'|'.$login], $allowed, "allowed_to $login on {$p->identifier}");
            }
            $global = array_values(array_filter($perms, static fn ($perm) => $auth->allowedToGlobally($perm, $user)));
            self::assertSame($this->exp['allowed']['*global*|'.$login], $global, "allowed_to_globally $login");
        }
    }
}
