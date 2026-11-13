<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\Role;
use App\Entity\User;
use App\Security\Authorization;
use App\Service\IssueService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Port of Project.load_last_activity_date / Project#last_activity_date:
 * Redmine::Activity::Fetcher.new(user).events(nil, nil, last_by_project: true), i.e. for every
 * activity provider the maximum event timestamp per project among the events visible by the user.
 * Uses the same providers / visibility conditions as ActivityFetcher.
 */
final class ProjectLastActivity
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Authorization $auth,
        private readonly IssueService $issues,
    ) {
    }

    /**
     * @param list<int>|null $projectIds restrict the computation to these projects (null = all)
     *
     * @return array<int, \DateTimeImmutable> project id => last activity time (UTC)
     */
    public function lastActivityDates(User $user, ?array $projectIds = null): array
    {
        if (null !== $projectIds && [] === $projectIds) {
            return [];
        }
        $restrict = null === $projectIds ? null : Authorization::in('p.id', array_values(array_map('intval', $projectIds)));
        $allowed = fn (string $perm): string => $this->auth->allowedToCondition($perm, 'p.id', $user);
        $privateNotes = $this->auth->allowedToCondition('view_private_notes', 'p.id', $user, null, false, true);
        $timeEntries = $this->auth->allowedToCondition('view_time_entries', 'p.id', $user, static function (Role $role, User $u): ?string {
            if ('all' === $role->timeEntriesVisibility) {
                return null;
            }
            if ('own' === $role->timeEntriesVisibility && $u->id && $u->isLogged()) {
                return 'IDENTITY(t.user) = '.(int) $u->id;
            }

            return '1=0';
        });
        $issueVisible = $this->issues->visibleCondition($user, 'i');
        $uid = (int) ($user->isLogged() ? $user->id : 0);

        // [FROM/JOIN DQL, timestamp, visibility]
        $providers = [
            // issues
            ['App\Entity\Issue i JOIN i.project p', 'i.createdOn', $issueVisible],
            ['App\Entity\Journal j JOIN j.issue i JOIN i.project p LEFT JOIN j.details d', 'j.createdOn',
                "j.journalizedType = 'Issue' AND (d.propKey = 'status_id' OR j.notes <> '') AND ($issueVisible)"
                ." AND (j.privateNotes = false OR IDENTITY(j.user) = $uid OR ($privateNotes))"],
            // changesets
            ['App\Entity\Changeset c JOIN c.repository r JOIN r.project p', 'c.committedOn', $allowed('view_changesets')],
            // news
            ['App\Entity\News n JOIN n.project p', 'n.createdOn', $allowed('view_news')],
            // documents
            ['App\Entity\Document d JOIN d.project p', 'd.createdOn', $allowed('view_documents')],
            ["App\Entity\Attachment a JOIN App\Entity\Document d WITH a.containerType = 'Document' AND d.id = a.containerId JOIN d.project p", 'a.createdOn', $allowed('view_documents')],
            // files
            ["App\Entity\Attachment a JOIN App\Entity\Project p WITH a.containerType = 'Project' AND p.id = a.containerId", 'a.createdOn', $allowed('view_files')],
            ["App\Entity\Attachment a JOIN App\Entity\Version v WITH a.containerType = 'Version' AND v.id = a.containerId JOIN v.project p", 'a.createdOn', $allowed('view_files')],
            // wiki edits
            ['App\Entity\WikiContentVersion v JOIN App\Entity\WikiPage wp WITH wp.id = v.pageId JOIN wp.wiki w JOIN w.project p', 'v.updatedOn', $allowed('view_wiki_edits')],
            // messages
            ['App\Entity\Message m JOIN m.board b JOIN b.project p', 'm.createdOn', $allowed('view_messages')],
            // time entries
            ['App\Entity\TimeEntry t JOIN t.project p', 't.createdOn', $timeEntries],
        ];

        $out = [];
        $utc = new \DateTimeZone('UTC');
        foreach ($providers as [$from, $timestamp, $visibility]) {
            if ('1=0' === $visibility) {
                continue;
            }
            $where = "($visibility)".(null !== $restrict ? " AND $restrict" : '');
            $rows = $this->em->createQuery("SELECT p.id AS pid, MAX($timestamp) AS last FROM $from WHERE $where GROUP BY p.id")->getArrayResult();
            foreach ($rows as $row) {
                if (null === $row['last'] || '' === $row['last']) {
                    continue;
                }
                $time = $row['last'] instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($row['last'])
                    : new \DateTimeImmutable((string) $row['last'], $utc);
                $pid = (int) $row['pid'];
                if (!isset($out[$pid]) || $time > $out[$pid]) {
                    $out[$pid] = $time;
                }
            }
        }

        return $out;
    }
}
