<?php

declare(strict_types=1);

namespace App\Tests\Admin;

/** Helpers shared by the roles / trackers / issue statuses / workflows tests. */
trait WorkflowAdminTestTrait
{
    /**
     * Logs in without password check. The session token user only serializes id/login/hash/status, so a legacy
     * (SHA1 + salt) account would be seen as "changed" on the next request; clear the unused salt first.
     */
    protected function loginAs(string $login): void
    {
        $this->em->getConnection()->executeStatement('UPDATE users SET salt = NULL WHERE login = ?', [$login]);
        $this->em->clear();
        $this->client->loginUser($this->user($login), 'main');
    }

    /** CSRF token read from an admin page (independent from the home page) */
    protected function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/issue_statuses/new');

        return (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
    }

    protected function db(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }

    /** @return list<string> "old-new-author-assignee" rows of a tracker/role, sorted */
    protected function transitionRows(int $trackerId, int $roleId): array
    {
        $rows = $this->db()->fetchAllNumeric("SELECT old_status_id, new_status_id, author, assignee FROM workflows WHERE type = 'WorkflowTransition' AND tracker_id = ? AND role_id = ? ORDER BY old_status_id, new_status_id, author, assignee", [$trackerId, $roleId]);

        return array_map(static fn ($r) => implode('-', array_map('intval', $r)), $rows);
    }

    /** Asserts every listed page is 200 for admin and 403 for jsmith */
    protected function assertAdminOnly(array $urls): void
    {
        $this->loginAs('jsmith');
        foreach ($urls as $u) {
            $this->get($u);
            $this->assertStatus(403, $u);
        }
        $this->loginAs('admin');
        foreach ($urls as $u) {
            $this->get($u);
            $this->assertStatus(200, $u);
        }
    }
}
