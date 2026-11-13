<?php

declare(strict_types=1);

namespace App\Service\Scm\Model;

use App\Entity\Repository;
use App\Service\Scm\BazaarAdapter;
use App\Service\Scm\ScmAnnotate;
use App\Service\Scm\ScmEntry;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Port of app/models/repository/bazaar.rb */
#[Exclude]
final class BazaarModel extends AbstractModel
{
    public function scmName(): string
    {
        return 'Bazaar';
    }

    public function validate(Repository $repository): array
    {
        $errors = parent::validate($repository);
        if ('' === trim((string) $repository->logEncoding)) {
            $errors[] = ['field_commit_logs_encoding', 'blank'];
        }

        return $errors;
    }

    /** The adapter with the path encoding set from log_encoding (scm.bzr_path_encodig = log_encoding) */
    private function bzr(Repository $repository): BazaarAdapter
    {
        /** @var BazaarAdapter $scm */
        $scm = $this->scm($repository);
        $scm->setBzrPathEncoding($repository->logEncoding);

        return $scm;
    }

    public function entry(Repository $repository, ?string $path, ?string $identifier): ?ScmEntry
    {
        return $this->bzr($repository)->entry($path, $identifier);
    }

    public function cat(Repository $repository, string $path, ?string $identifier): ?string
    {
        return $this->bzr($repository)->cat($path, $identifier);
    }

    public function annotate(Repository $repository, string $path, ?string $identifier): ?ScmAnnotate
    {
        return $this->bzr($repository)->annotate($path, $identifier);
    }

    public function diff(Repository $repository, ?string $path, ?string $rev, ?string $revTo): ?array
    {
        return $this->bzr($repository)->diff($path, $rev, $revTo);
    }

    protected function scmEntries(Repository $repository, ?string $path, ?string $identifier): ?array
    {
        $entries = $this->bzr($repository)->entries($path, $identifier);
        foreach ($entries ?? [] as $e) {
            if (null === $e->lastrev || null === $e->lastrev->revision || '' === $e->lastrev->revision) {
                continue;
            }
            // Set the filesize unless browsing a specific revision
            if (null === $identifier && $e->isFile()) {
                $fullPath = rtrim((string) ($repository->rootUrl ?: $repository->url), '/').'/'.$e->path;
                if (is_file($fullPath)) {
                    $e->size = (int) filesize($fullPath);
                }
            }
            $row = $this->em->getConnection()->fetchAssociative(
                'SELECT c.revision, c.committer FROM changes ch JOIN changesets c ON c.id = ch.changeset_id WHERE ch.revision = ? AND c.repository_id = ? ORDER BY c.revision DESC LIMIT 1',
                [$e->lastrev->revision, (int) $repository->id],
            );
            if (false !== $row) {
                $e->lastrev->identifier = (string) $row['revision'];
                $e->lastrev->name = (string) $row['revision'];
                $e->lastrev->author = $row['committer'];
            }
        }

        return $entries;
    }

    public function fetchChangesets(Repository $repository): void
    {
        $scm = $this->bzr($repository);
        $scmInfo = $scm->info();
        if (null === $scmInfo) {
            return;
        }
        $dbRevision = (int) ($this->latestChangeset($repository)?->revision ?? 0);
        $scmRevision = (int) $scmInfo->lastrev?->identifier;
        $from = $dbRevision + 1;
        while ($from <= $scmRevision) {
            // loads changesets by batches of 200
            $to = min($from + 199, $scmRevision);
            $revisions = $scm->revisions('', (string) $to, (string) $from);
            foreach (array_reverse($revisions ?? []) as $revision) {
                $cs = $this->service->createChangeset($repository, [
                    'revision' => (string) $revision->identifier,
                    'committer' => $revision->author,
                    'committed_on' => $revision->time,
                    'scmid' => $revision->scmid,
                    'comments' => $revision->message,
                ]);
                if (null === $cs) {
                    continue;
                }
                foreach ($revision->paths as $change) {
                    $this->service->createChange($cs, ['action' => $change['action'], 'path' => $change['path'], 'revision' => $change['revision'] ?? null]);
                }
                $this->em->flush();
            }
            $from = $to + 1;
        }
    }
}
