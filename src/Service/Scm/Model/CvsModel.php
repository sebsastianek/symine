<?php

declare(strict_types=1);

namespace App\Service\Scm\Model;

use App\Entity\Change;
use App\Entity\Changeset;
use App\Entity\Repository;
use App\Service\Scm\AbstractAdapter;
use App\Service\Scm\CvsAdapter;
use App\Service\Scm\ScmAnnotate;
use App\Service\Scm\ScmEntry;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Port of app/models/repository/cvs.rb: CVS has no changesets, they are guessed from the author,
 * the commit log and the commit date (10 seconds delta) of the file revisions.
 */
#[Exclude]
final class CvsModel extends AbstractModel
{
    private const int TIME_DELTA = 10;

    public function scmName(): string
    {
        return 'CVS';
    }

    public function pathAttribute(): string
    {
        return 'root_url';
    }

    public function urlLabel(): string
    {
        return 'field_cvs_module';
    }

    public function validate(Repository $repository): array
    {
        $errors = [];
        if ('' === trim($repository->url)) {
            $errors[] = ['field_cvs_module', 'blank'];
        }
        if ('' === trim((string) $repository->rootUrl)) {
            $errors[] = ['field_cvsroot', 'blank'];
        }
        if ('' === trim((string) $repository->logEncoding)) {
            $errors[] = ['field_commit_logs_encoding', 'blank'];
        }

        return $errors;
    }

    private function cvs(Repository $repository): CvsAdapter
    {
        /** @var CvsAdapter */
        return $this->scm($repository);
    }

    public function entry(Repository $repository, ?string $path, ?string $identifier): ?ScmEntry
    {
        $rev = null === $identifier ? null : $this->findChangesetByRevision($repository, $identifier);

        return $this->cvs($repository)->entry($path, $rev?->committedOn->format(\DATE_ATOM));
    }

    protected function scmEntries(Repository $repository, ?string $path, ?string $identifier): ?array
    {
        $rev = null;
        if (null !== $identifier) {
            $rev = $this->findChangesetByRevision($repository, $identifier);
            if (null === $rev) {
                return null;
            }
        }
        $entries = $this->cvs($repository)->entries($path, $rev?->committedOn->format(\DATE_ATOM));
        foreach ($entries ?? [] as $entry) {
            if (null === $entry->lastrev || null === $entry->lastrev->revision) {
                continue;
            }
            $change = $this->em->createQuery('SELECT ch FROM App\Entity\Change ch JOIN ch.changeset c WHERE c.repository = :r AND ch.revision = :rev AND ch.path = :p ORDER BY ch.id')
                ->setParameters(['r' => $repository, 'rev' => $entry->lastrev->revision, 'p' => AbstractAdapter::withLeadingSlash($entry->path)])
                ->setMaxResults(1)->getResult()[0] ?? null;
            if ($change instanceof Change) {
                $entry->lastrev->identifier = $change->changeset->revision;
                $entry->lastrev->revision = $change->changeset->revision;
                $entry->lastrev->author = $change->changeset->committer;
            }
        }

        return $entries;
    }

    public function cat(Repository $repository, string $path, ?string $identifier): ?string
    {
        $rev = null;
        if (null !== $identifier) {
            $rev = $this->findChangesetByRevision($repository, $identifier);
            if (null === $rev) {
                return null;
            }
        }

        return $this->cvs($repository)->cat($path, $rev?->committedOn->format(\DATE_ATOM));
    }

    public function annotate(Repository $repository, string $path, ?string $identifier): ?ScmAnnotate
    {
        $rev = null;
        if (null !== $identifier) {
            $rev = $this->findChangesetByRevision($repository, $identifier);
            if (null === $rev) {
                return null;
            }
        }

        return $this->cvs($repository)->annotate($path, $rev?->committedOn->format(\DATE_ATOM));
    }

    /** CVS can't handle changesets here: diffs each file revision of the changeset */
    public function diff(Repository $repository, ?string $path, ?string $rev, ?string $revTo): ?array
    {
        $diff = [];
        $from = $this->findChangesetByRevision($repository, $rev);
        if (null === $from) {
            return null;
        }
        $to = (int) $revTo > 0 ? $this->findChangesetByRevision($repository, $revTo) : null;
        foreach ($this->service->changes($from) as $changeFrom) {
            $revisionFrom = null;
            $revisionTo = null;
            if (null === $path || str_starts_with($changeFrom->path, AbstractAdapter::withLeadingSlash($path))) {
                $revisionFrom = $changeFrom->revision;
            }
            if (null === $revisionFrom) {
                continue;
            }
            if (null !== $to) {
                foreach ($this->service->changes($to) as $changeTo) {
                    if ($changeTo->path === $changeFrom->path) {
                        $revisionTo = $changeTo->revision;
                    }
                }
            }
            $revisionTo ??= $this->cvs($repository)->previousRevision($revisionFrom);
            $fileDiff = $this->cvs($repository)->diff($changeFrom->path, $revisionFrom, $revisionTo);
            if (null !== $fileDiff) {
                $diff = array_merge($diff, $fileDiff);
            }
        }

        return $diff;
    }

    public function fetchChangesets(Repository $repository): void
    {
        $latest = $this->latestChangeset($repository);
        $fetchSince = $latest?->committedOn;
        $encoding = $this->repoLogEncoding($repository);
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $tmpRevNum = 1;
            $revisions = $this->cvs($repository)->revisions('', $fetchSince?->format(\DATE_ATOM), null, ['log_encoding' => $encoding]);
            foreach ($revisions as $revision) {
                $p0 = $revision->paths[0];
                $path = AbstractAdapter::withLeadingSlash($p0['path']);
                // only add the change to the database if it doesn't exist: the cvs log is not exclusive at all
                $exists = (int) $conn->fetchOne('SELECT COUNT(*) FROM changes ch JOIN changesets c ON c.id = ch.changeset_id WHERE c.repository_id = ? AND ch.path = ? AND ch.revision = ?', [(int) $repository->id, $path, $p0['revision']]);
                if ($exists > 0) {
                    continue;
                }
                $time = $revision->time ?? new \DateTimeImmutable();
                $cmt = trim((string) AbstractAdapter::toUtf8((string) $revision->message, $encoding));
                $authorUtf8 = (string) AbstractAdapter::toUtf8((string) $revision->author, $encoding);
                $utc = $time->setTimezone(new \DateTimeZone('UTC'));
                $cs = $this->em->createQuery('SELECT c FROM App\Entity\Changeset c WHERE c.repository = :r AND c.committedOn BETWEEN :a AND :b AND c.committer = :u AND c.comments = :m ORDER BY '.$this->changesetsOrder())
                    ->setParameters([
                        'r' => $repository, 'a' => $utc->modify('-'.self::TIME_DELTA.' seconds'), 'b' => $utc->modify('+'.self::TIME_DELTA.' seconds'),
                        'u' => $authorUtf8, 'm' => $cmt,
                    ])->setMaxResults(1)->getResult()[0] ?? null;
                if (!$cs instanceof Changeset) {
                    // temporary revision number (renumbered below)
                    $scmid = $p0['branch'].'-'.$utc->format('Ymd-His');
                    $cs = $this->service->createChangeset($repository, [
                        'revision' => 'tmp'.$tmpRevNum,
                        'scmid' => $scmid,
                        'committer' => $revision->author,
                        'committed_on' => $time,
                        'comments' => $revision->message,
                    ]);
                    ++$tmpRevNum;
                }
                if (null === $cs) {
                    continue;
                }
                // convert CVS-File-States to internal Action-abbreviations, default action is (M)odified
                $action = 'M';
                if ('Exp' === $p0['action'] && '1.1' === $p0['revision']) {
                    $action = 'A'; // add-action always at first revision (= 1.1)
                } elseif ('dead' === $p0['action']) {
                    $action = 'D'; // dead-state is similar to Delete
                }
                $this->service->createChange($cs, ['action' => $action, 'path' => $path, 'revision' => $p0['revision'], 'branch' => $p0['branch']]);
                $this->em->flush();
            }
            // Renumber new changesets in chronological order
            $tmp = $this->em->createQuery("SELECT c FROM App\Entity\Changeset c WHERE c.repository = :r AND c.revision LIKE 'tmp%' ORDER BY c.committedOn ASC, c.id ASC")
                ->setParameter('r', $repository)->getResult();
            if ([] !== $tmp) {
                $current = max(array_map('intval', $conn->fetchFirstColumn("SELECT revision FROM changesets WHERE repository_id = ? AND revision NOT LIKE 'tmp%'", [(int) $repository->id])) ?: [0]);
                foreach ($tmp as $c) {
                    $c->revision = (string) ++$current;
                }
                $this->em->flush();
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }
}
