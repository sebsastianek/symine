<?php

declare(strict_types=1);

namespace App\Service\Scm\Model;

use App\Entity\Changeset;
use App\Entity\Repository;
use App\Service\Scm\GitAdapter;
use App\Service\Scm\ScmBranch;
use App\Service\Scm\ScmRevision;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Port of app/models/repository/git.rb */
#[Exclude]
final class GitModel extends AbstractModel
{
    public function scmName(): string
    {
        return 'Git';
    }

    public function supportsDirectoryRevisions(): bool
    {
        return true;
    }

    public function supportsRevisionGraph(): bool
    {
        return true;
    }

    public function repoLogEncoding(Repository $repository): string
    {
        return 'UTF-8';
    }

    public function reportLastCommit(Repository $repository): bool
    {
        $info = \is_array($repository->extraInfo) ? $repository->extraInfo : null;
        if (null === $info || !\array_key_exists('extra_report_last_commit', $info) || null === $info['extra_report_last_commit']) {
            return false;
        }

        return '0' !== (string) $info['extra_report_last_commit'];
    }

    public function defaultBranch(Repository $repository): ?string
    {
        try {
            return $this->scm($repository)->defaultBranch();
        } catch (\Throwable) {
            return null;
        }
    }

    public function changesetIdentifier(Changeset $c): string
    {
        return (string) ($c->scmid ?: $c->revision);
    }

    public function formatChangesetIdentifier(Changeset $c): string
    {
        return substr($c->revision, 0, 8);
    }

    public function findChangesetByName(Repository $repository, ?string $name): ?Changeset
    {
        if (null === $name || '' === trim($name)) {
            return null;
        }

        return $this->findChangesetByRevision($repository, $name)
            ?? $this->firstWhere($repository, 'c.scmid LIKE :s', ['s' => $name.'%']);
    }

    protected function scmEntries(Repository $repository, ?string $path, ?string $identifier): ?array
    {
        return $this->scm($repository)->entries($path, $identifier, ['report_last_commit' => $this->reportLastCommit($repository)]);
    }

    protected function loadEntriesChangesets(Repository $repository, ?array $entries): void
    {
        $ids = [];
        foreach ($entries ?? [] as $e) {
            if (null !== $e->lastrev?->identifier) {
                $ids[] = $e->lastrev->identifier;
            }
        }
        if ([] === $ids) {
            return;
        }
        $map = [];
        foreach ($this->em->createQuery('SELECT c FROM App\Entity\Changeset c WHERE c.repository = :r AND c.revision IN (:ids)')->setParameter('r', $repository)->setParameter('ids', array_values(array_unique($ids)))->getResult() as $c) {
            $map[$c->revision] = $c;
        }
        foreach ($entries as $e) {
            if (null !== $e->lastrev?->identifier) {
                $e->changeset = $map[$e->lastrev->identifier] ?? null;
            }
        }
    }

    public function latestChangesets(Repository $repository, ?string $path, ?string $rev, int $limit = 10): array
    {
        $revisions = $this->scm($repository)->revisions($path, null, $rev, ['limit' => $limit, 'all' => false]);
        if (null === $revisions || [] === $revisions) {
            return [];
        }
        $scmids = array_map(static fn (ScmRevision $r) => $r->scmid, $revisions);

        return $this->em->createQuery('SELECT c FROM App\Entity\Changeset c WHERE c.repository = :r AND c.scmid IN (:s) ORDER BY c.committedOn DESC, c.id DESC')
            ->setParameter('r', $repository)->setParameter('s', $scmids)->getResult();
    }

    /**
     * Git has no sequential numbering: the heads of the branches are saved in extra_info and only
     * the revisions reachable from the new heads (and not from the previous ones) are fetched.
     */
    public function fetchChangesets(Repository $repository): void
    {
        /** @var GitAdapter $adapter */
        $adapter = $this->scm($repository);
        $branches = $adapter->branches();
        if (null === $branches || [] === $branches) {
            return;
        }
        $info = \is_array($repository->extraInfo) ? $repository->extraInfo : [];
        $repoHeads = array_map(static fn (ScmBranch $b) => $b->scmid, $branches);
        $prevHeads = \is_array($info['heads'] ?? null) ? $info['heads'] : [];
        if ([] === $prevHeads) {
            foreach ((array) ($info['branches'] ?? []) as $h) {
                if (isset($h['last_scmid'])) {
                    $prevHeads[] = $h['last_scmid'];
                }
            }
        }
        $a = $prevHeads;
        $b = $repoHeads;
        sort($a);
        sort($b);
        if ($a === $b) {
            return;
        }
        $consistent = \is_array($info['db_consistent'] ?? null) ? $info['db_consistent'] : [];
        if (0 === $this->changesetCount($repository)) {
            $consistent['ordering'] = 1;
            $this->service->mergeExtraInfo($repository, ['db_consistent' => $consistent]);
        } elseif (!\array_key_exists('ordering', $consistent)) {
            $consistent['ordering'] = 0;
            $this->service->mergeExtraInfo($repository, ['db_consistent' => $consistent]);
        }
        $this->saveRevisions($repository, $prevHeads, $repoHeads);
    }

    private function saveRevisions(Repository $repository, array $prevHeads, array $repoHeads): void
    {
        $revisions = $this->scm($repository)->revisions('', null, null, ['reverse' => true, 'excludes' => $prevHeads, 'includes' => $repoHeads]) ?? [];
        if ([] === $revisions) {
            return;
        }
        $conn = $this->em->getConnection();
        $known = [];
        foreach (array_chunk(array_map(static fn (ScmRevision $r) => $r->scmid, $revisions), 100) as $chunk) {
            $rows = $conn->fetchFirstColumn('SELECT scmid FROM changesets WHERE repository_id = ? AND scmid IN ('.implode(',', array_fill(0, \count($chunk), '?')).')', [(int) $repository->id, ...$chunk]);
            foreach ($rows as $s) {
                $known[$s] = true;
            }
        }
        foreach ($revisions as $rev) {
            if (isset($known[$rev->scmid])) {
                continue;
            }
            $parents = array_values(array_filter(array_map(fn ($p) => $this->findChangesetByName($repository, $p), $rev->parents)));
            $this->service->createChangeset($repository, [
                'revision' => (string) $rev->identifier,
                'scmid' => $rev->scmid,
                'committer' => mb_substr((string) $rev->author, 0, 255),
                'committed_on' => $rev->time,
                'comments' => $rev->message,
            ], $parents, $rev->paths);
            $known[$rev->scmid] = true;
        }
        $this->service->mergeExtraInfo($repository, ['heads' => array_values($repoHeads)]);
        $this->em->persist($repository);
        $this->em->flush();
    }

    /** clear_extra_info_of_changesets: keeps only extra_report_last_commit */
    public function clearChangesets(Repository $repository): void
    {
        if (!\is_array($repository->extraInfo)) {
            return;
        }
        $v = $repository->extraInfo['extra_report_last_commit'] ?? null;
        $repository->extraInfo = ['extra_report_last_commit' => $v];
    }
}
