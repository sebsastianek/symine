<?php

declare(strict_types=1);

namespace App\Service\Scm\Model;

use App\Entity\Changeset;
use App\Entity\Repository;
use App\Service\Scm\AbstractAdapter;
use App\Service\Scm\RepositoryService;
use App\Service\Scm\ScmAnnotate;
use App\Service\Scm\ScmEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Behaviour of app/models/repository.rb that depends on the repository type (Repository::Git,
 * Repository::Subversion, ...). One subclass per SCM, created by RepositoryService::model().
 */
#[Exclude]
abstract class AbstractModel
{
    public function __construct(
        protected readonly RepositoryService $service,
        protected readonly EntityManagerInterface $em,
    ) {
    }

    /** Repository::Xxx.scm_name */
    abstract public function scmName(): string;

    protected function scm(Repository $repository): AbstractAdapter
    {
        return $this->service->adapter($repository);
    }

    // ---------------------------------------------------------------- capabilities

    public function supportsCat(Repository $repository): bool
    {
        return $this->scm($repository)->supportsCat();
    }

    public function supportsAnnotate(Repository $repository): bool
    {
        return $this->scm($repository)->supportsAnnotate();
    }

    public function supportsHistory(): bool
    {
        return true;
    }

    public function supportsDirectoryRevisions(): bool
    {
        return false;
    }

    public function supportsRevisionGraph(): bool
    {
        return false;
    }

    public function reportLastCommit(Repository $repository): bool
    {
        return true;
    }

    public function repoLogEncoding(Repository $repository): string
    {
        $enc = trim((string) $repository->logEncoding);

        return '' !== $enc ? $enc : 'UTF-8';
    }

    /** Attribute validated against scm_xxx_path_regexp */
    public function pathAttribute(): string
    {
        return 'url';
    }

    /**
     * Type specific validations (presence/format), as [field label key, error key].
     *
     * @return list<array{0: string, 1: string}>
     */
    public function validate(Repository $repository): array
    {
        return '' === trim($repository->url) ? [[$this->urlLabel(), 'blank']] : [];
    }

    /** Label of the url attribute (human_attribute_name) */
    public function urlLabel(): string
    {
        return 'field_path_to_repository';
    }

    // ---------------------------------------------------------------- browsing

    public function entry(Repository $repository, ?string $path, ?string $identifier): ?ScmEntry
    {
        return $this->scm($repository)->entry($path, $identifier);
    }

    /** @return list<ScmEntry>|null */
    protected function scmEntries(Repository $repository, ?string $path, ?string $identifier): ?array
    {
        return $this->scm($repository)->entries($path, $identifier);
    }

    /** Repository#entries (scm_entries + load_entries_changesets) */
    public function entries(Repository $repository, ?string $path, ?string $identifier): ?array
    {
        $entries = $this->scmEntries($repository, $path, $identifier);
        $this->loadEntriesChangesets($repository, $entries);

        return $entries;
    }

    /** @param list<ScmEntry>|null $entries */
    protected function loadEntriesChangesets(Repository $repository, ?array $entries): void
    {
        foreach ($entries ?? [] as $entry) {
            if (null !== $entry->lastrev && null !== $entry->lastrev->identifier) {
                $entry->changeset = $this->findChangesetByName($repository, $entry->lastrev->identifier);
            }
        }
    }

    public function cat(Repository $repository, string $path, ?string $identifier): ?string
    {
        return $this->scm($repository)->cat($path, $identifier);
    }

    /** @return list<string>|null */
    public function diff(Repository $repository, ?string $path, ?string $rev, ?string $revTo): ?array
    {
        return $this->scm($repository)->diff($path, $rev, $revTo);
    }

    public function annotate(Repository $repository, string $path, ?string $identifier): ?ScmAnnotate
    {
        return $this->scm($repository)->annotate($path, $identifier);
    }

    /** @return array<string, string>|null */
    public function properties(Repository $repository, ?string $path, ?string $identifier): ?array
    {
        return $this->scm($repository)->properties($path, $identifier);
    }

    public function defaultBranch(Repository $repository): ?string
    {
        return null;
    }

    /** Path relative to the url of the repository */
    public function relativePath(Repository $repository, string $path): string
    {
        return $path;
    }

    // ---------------------------------------------------------------- changesets

    /** DQL ORDER BY of Repository#changesets */
    public function changesetsOrder(): string
    {
        return 'c.committedOn DESC, c.id DESC';
    }

    public function latestChangeset(Repository $repository): ?Changeset
    {
        $r = $this->em->createQuery('SELECT c FROM App\Entity\Changeset c WHERE c.repository = :r ORDER BY '.$this->changesetsOrder())
            ->setParameter('r', $repository)->setMaxResults(1)->getResult();

        return $r[0] ?? null;
    }

    /** Finds a changeset with a number or the beginning of a hash */
    public function findChangesetByName(Repository $repository, ?string $name): ?Changeset
    {
        if (null === $name || '' === trim($name)) {
            return null;
        }
        if (preg_match('/^\d*$/', $name)) {
            return $this->em->getRepository(Changeset::class)->findOneBy(['repository' => $repository, 'revision' => $name]);
        }

        return $this->firstWhere($repository, 'c.revision LIKE :s', ['s' => $name.'%']);
    }

    public function findChangesetByRevision(Repository $repository, ?string $revision): ?Changeset
    {
        if (null === $revision) {
            return null;
        }

        return $this->em->getRepository(Changeset::class)->findOneBy(['repository' => $repository, 'revision' => $revision]);
    }

    public function previousChangeset(Changeset $c): ?Changeset
    {
        $r = $this->em->createQuery('SELECT c FROM App\Entity\Changeset c WHERE c.repository = :r AND (c.committedOn < :d OR (c.committedOn = :d AND c.id < :id)) ORDER BY c.committedOn DESC, c.id DESC')
            ->setParameters(['r' => $c->repository, 'd' => $c->committedOn, 'id' => $c->id])->setMaxResults(1)->getResult();

        return $r[0] ?? null;
    }

    public function nextChangeset(Changeset $c): ?Changeset
    {
        $r = $this->em->createQuery('SELECT c FROM App\Entity\Changeset c WHERE c.repository = :r AND (c.committedOn > :d OR (c.committedOn = :d AND c.id > :id)) ORDER BY c.committedOn ASC, c.id ASC')
            ->setParameters(['r' => $c->repository, 'd' => $c->committedOn, 'id' => $c->id])->setMaxResults(1)->getResult();

        return $r[0] ?? null;
    }

    /**
     * Latest changesets for $path (default: cached changesets).
     *
     * @return list<Changeset>
     */
    public function latestChangesets(Repository $repository, ?string $path, ?string $rev, int $limit = 10): array
    {
        if (null === $path || '' === trim($path)) {
            return $this->em->createQuery('SELECT c FROM App\Entity\Changeset c WHERE c.repository = :r ORDER BY c.committedOn DESC, c.id DESC')
                ->setParameter('r', $repository)->setMaxResults($limit)->getResult();
        }

        return $this->em->createQuery('SELECT c FROM App\Entity\Changeset c WHERE c.repository = :r AND EXISTS (SELECT ch.id FROM App\Entity\Change ch WHERE ch.changeset = c AND ch.path = :p) ORDER BY c.committedOn DESC, c.id DESC')
            ->setParameter('r', $repository)->setParameter('p', AbstractAdapter::withLeadingSlash($path))->setMaxResults($limit)->getResult();
    }

    /** Fetches new changesets from the repository */
    abstract public function fetchChangesets(Repository $repository): void;

    /** Changeset#identifier */
    public function changesetIdentifier(Changeset $c): string
    {
        return $c->revision;
    }

    /** Changeset#format_identifier */
    public function formatChangesetIdentifier(Changeset $c): string
    {
        return $this->changesetIdentifier($c);
    }

    public function diffFormatRevisions(?Changeset $cs, ?Changeset $csTo, string $sep = ':'): string
    {
        $text = '';
        if (null !== $csTo) {
            $text .= $this->formatChangesetIdentifier($csTo).$sep;
        }
        if (null !== $cs) {
            $text .= $this->formatChangesetIdentifier($cs);
        }

        return $text;
    }

    /** Called before the changesets are deleted (Repository#clear_changesets) */
    public function clearChangesets(Repository $repository): void
    {
    }

    // ---------------------------------------------------------------- helpers

    protected function firstWhere(Repository $repository, string $cond, array $params, ?string $order = null): ?Changeset
    {
        $q = $this->em->createQuery('SELECT c FROM App\Entity\Changeset c WHERE c.repository = :r AND '.$cond.' ORDER BY '.($order ?? $this->changesetsOrder()))
            ->setParameter('r', $repository)->setMaxResults(1);
        foreach ($params as $k => $v) {
            $q->setParameter($k, $v);
        }

        return $q->getResult()[0] ?? null;
    }

    protected function changesetCount(Repository $repository): int
    {
        return $this->service->changesetCount($repository);
    }
}
