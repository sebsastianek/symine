<?php

declare(strict_types=1);

namespace App\Service\Scm\Model;

use App\Entity\Repository;
use App\Service\Scm\ScmRevision;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Port of app/models/repository/subversion.rb */
#[Exclude]
final class SubversionModel extends AbstractModel
{
    public const string URL_RE = '#\A(http|https|svn(\+[^\s:/\\\\]+)?|file)://.+\z#i';

    public function scmName(): string
    {
        return 'Subversion';
    }

    public function supportsDirectoryRevisions(): bool
    {
        return true;
    }

    public function repoLogEncoding(Repository $repository): string
    {
        return 'UTF-8';
    }

    public function urlLabel(): string
    {
        return 'field_url';
    }

    public function validate(Repository $repository): array
    {
        if ('' === trim($repository->url)) {
            return [[$this->urlLabel(), 'blank']];
        }

        return preg_match(self::URL_RE, $repository->url) ? [] : [[$this->urlLabel(), 'invalid']];
    }

    public function latestChangesets(Repository $repository, ?string $path, ?string $rev, int $limit = 10): array
    {
        $revisions = $this->scm($repository)->revisions($path, $rev, null, ['limit' => $limit]);
        if (null === $revisions) {
            return [];
        }
        $identifiers = array_values(array_filter(array_map(static fn (ScmRevision $r) => $r->identifier, $revisions), static fn ($i) => null !== $i));
        if ([] === $identifiers) {
            return [];
        }

        return $this->em->createQuery('SELECT c FROM App\Entity\Changeset c WHERE c.repository = :r AND c.revision IN (:ids) ORDER BY c.committedOn DESC')
            ->setParameter('r', $repository)->setParameter('ids', $identifiers)->getResult();
    }

    /** Path relative to the url of the repository */
    public function relativePath(Repository $repository, string $path): string
    {
        return (string) preg_replace('#^/?'.preg_quote($this->relativeUrl($repository), '#').'#', '', $path);
    }

    /** Relative url of the repository (root_url = file:///var/svn/foo, url = file:///var/svn/foo/bar => /bar) */
    private function relativeUrl(Repository $repository): string
    {
        $root = rawurldecode((string) ($repository->rootUrl ?: $this->scm($repository)->rootUrl()));

        return (string) preg_replace('#^'.preg_quote($root, '#').'#i', '', $repository->url);
    }

    protected function loadEntriesChangesets(Repository $repository, ?array $entries): void
    {
        $identifiers = [];
        foreach ($entries ?? [] as $e) {
            if (null !== $e->lastrev && null !== $e->lastrev->identifier && '' !== $e->lastrev->identifier) {
                $identifiers[$e->lastrev->identifier] = true;
            }
        }
        if ([] === $identifiers) {
            return;
        }
        $map = [];
        foreach ($this->em->createQuery('SELECT c FROM App\Entity\Changeset c WHERE c.repository = :r AND c.revision IN (:ids)')->setParameter('r', $repository)->setParameter('ids', array_map('strval', array_keys($identifiers)))->getResult() as $c) {
            $map[$c->revision] ??= $c;
        }
        foreach ($entries as $e) {
            if (null !== $e->lastrev?->identifier && isset($map[$e->lastrev->identifier])) {
                $e->changeset = $map[$e->lastrev->identifier];
            }
        }
    }

    public function fetchChangesets(Repository $repository): void
    {
        $scmInfo = $this->scm($repository)->info();
        if (null === $scmInfo) {
            return;
        }
        // latest revision found in database / in the repository
        $dbRevision = (int) ($this->latestChangeset($repository)?->revision ?? 0);
        $scmRevision = (int) $scmInfo->lastrev?->identifier;
        $from = $dbRevision + 1;
        while ($from <= $scmRevision) {
            // loads changesets by batches of 200
            $to = min($from + 199, $scmRevision);
            $revisions = $this->scm($repository)->revisions('', (string) $to, (string) $from, ['with_paths' => true]);
            foreach (array_reverse($revisions ?? []) as $revision) {
                $this->service->createChangeset($repository, [
                    'revision' => (string) $revision->identifier,
                    'committer' => $revision->author,
                    'committed_on' => $revision->time,
                    'comments' => $revision->message,
                ], [], $revision->paths);
            }
            $from = $to + 1;
        }
    }
}
