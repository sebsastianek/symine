<?php

declare(strict_types=1);

namespace App\Service\Scm\Model;

use App\Entity\Changeset;
use App\Entity\Repository;
use App\Service\Scm\AbstractAdapter;
use App\Service\Scm\MercurialAdapter;
use App\Service\Scm\ScmEntry;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Port of app/models/repository/mercurial.rb (changesets are sorted by revision number = id) */
#[Exclude]
final class MercurialModel extends AbstractModel
{
    /** number of changesets to fetch at once */
    public const int FETCH_AT_ONCE = 100;

    /** @var array<int, bool> */
    private array $shortIdInDb = [];

    public function scmName(): string
    {
        return 'Mercurial';
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

    public function changesetIdentifier(Changeset $c): string
    {
        return (string) $c->scmid;
    }

    public function formatChangesetIdentifier(Changeset $c): string
    {
        return $c->revision.':'.substr((string) $c->scmid, 0, 12);
    }

    public function diffFormatRevisions(?Changeset $cs, ?Changeset $csTo, string $sep = ':'): string
    {
        return parent::diffFormatRevisions($cs, $csTo, ' ');
    }

    public function changesetsOrder(): string
    {
        return 'c.id DESC';
    }

    private function modifyEntryLastrevIdentifier(Repository $repository, ScmEntry $entry): void
    {
        if (null !== $entry->lastrev && null !== $entry->lastrev->identifier) {
            $entry->lastrev->identifier = $this->scmidForInsertingDb($repository, $entry->lastrev->identifier);
        }
    }

    public function entry(Repository $repository, ?string $path, ?string $identifier): ?ScmEntry
    {
        $entry = $this->scm($repository)->entry($path, $identifier);
        if (null !== $entry) {
            $this->modifyEntryLastrevIdentifier($repository, $entry);
        }

        return $entry;
    }

    protected function scmEntries(Repository $repository, ?string $path, ?string $identifier): ?array
    {
        $entries = $this->scm($repository)->entries($path, $identifier);
        foreach ($entries ?? [] as $entry) {
            $this->modifyEntryLastrevIdentifier($repository, $entry);
        }

        return $entries;
    }

    public function findChangesetByName(Repository $repository, ?string $name): ?Changeset
    {
        if (null === $name || '' === trim($name)) {
            return null;
        }
        if (preg_match('/[^\d]/', $name) || \strlen($name) > 8) {
            $cs = $this->firstWhere($repository, 'c.scmid = :s', ['s' => $name]);
        } else {
            $cs = $this->findChangesetByRevision($repository, $name);
        }

        return $cs ?? $this->firstWhere($repository, 'c.scmid LIKE :s', ['s' => $name.'%']);
    }

    public function latestChangesets(Repository $repository, ?string $path, ?string $rev, int $limit = 10): array
    {
        $qb = $this->em->createQueryBuilder()->select('c')->from(Changeset::class, 'c')
            ->where('c.repository = :r')->setParameter('r', $repository)->orderBy('c.id', 'DESC')->setMaxResults($limit);
        /** @var MercurialAdapter $scm */
        $scm = $this->scm($repository);
        if (null !== $rev && \array_key_exists($rev, $scm->branchmap())) {
            // Mercurial named branch is *stable* in each revision: it can be stored in database.
            $branchLimit = null === $path || '' === $path ? $limit : $limit * 5;
            $nodes = $this->nodesInBranch($repository, $rev, $branchLimit);
            $qb->andWhere('c.scmid IN (:nodes)')->setParameter('nodes', [] === $nodes ? [''] : $nodes);
        } elseif (null !== $rev && null !== ($last = $this->findChangesetByName($repository, $this->tagScmid($repository, $rev) ?? $rev))) {
            $qb->andWhere('c.id <= :last')->setParameter('last', $last->id);
        }
        if (null !== $path && '' !== $path) {
            $p = AbstractAdapter::withLeadingSlash($path);
            $qb->andWhere("EXISTS (SELECT ch.id FROM App\\Entity\\Change ch WHERE ch.changeset = c AND (ch.path = :p OR ch.path LIKE :plike ESCAPE '\\'))")
                ->setParameter('p', $p)->setParameter('plike', addcslashes($p, '%_\\').'/%');
        }

        return $qb->getQuery()->getResult();
    }

    public function previousChangeset(Changeset $c): ?Changeset
    {
        return $this->firstWhere($c->repository, 'c.id < :id', ['id' => $c->id], 'c.id DESC');
    }

    public function nextChangeset(Changeset $c): ?Changeset
    {
        return $this->firstWhere($c->repository, 'c.id > :id', ['id' => $c->id], 'c.id ASC');
    }

    private function isShortIdInDb(Repository $repository): bool
    {
        $key = (int) $repository->id;
        if (!isset($this->shortIdInDb[$key])) {
            $cs = $this->latestChangeset($repository);
            $this->shortIdInDb[$key] = null !== $cs && 40 !== \strlen((string) $cs->scmid);
        }

        return $this->shortIdInDb[$key];
    }

    public function scmidForInsertingDb(Repository $repository, string $scmid): string
    {
        return $this->isShortIdInDb($repository) ? substr($scmid, 0, 12) : $scmid;
    }

    /** @return list<string> */
    private function nodesInBranch(Repository $repository, string $rev, int $branchLimit): array
    {
        /** @var MercurialAdapter $scm */
        $scm = $this->scm($repository);

        return array_map(fn ($b) => $this->scmidForInsertingDb($repository, $b), $scm->nodesInBranch($rev, ['limit' => $branchLimit]));
    }

    private function tagScmid(Repository $repository, string $rev): ?string
    {
        /** @var MercurialAdapter $scm */
        $scm = $this->scm($repository);
        $scmid = $scm->tagmap()[$rev] ?? null;

        return null === $scmid ? null : $this->scmidForInsertingDb($repository, $scmid);
    }

    public function fetchChangesets(Repository $repository): void
    {
        /** @var MercurialAdapter $scm */
        $scm = $this->scm($repository);
        $info = $scm->info();
        if (null === $info) {
            return;
        }
        $scmRev = (int) $info->lastrev?->revision;
        $latest = $this->latestChangeset($repository);
        $dbRev = null !== $latest ? (int) $latest->revision : -1;
        if ($dbRev >= $scmRev) {
            return; // already up-to-date
        }
        unset($this->shortIdInDb[(int) $repository->id]);
        for ($i = $dbRev + 1; $i <= $scmRev; $i += self::FETCH_AT_ONCE) {
            foreach ($scm->revisions('', (string) $i, (string) min($i + self::FETCH_AT_ONCE - 1, $scmRev)) as $re) {
                $parents = [];
                foreach ($re->parents as $rp) {
                    $p = $this->findChangesetByName($repository, $this->scmidForInsertingDb($repository, $rp));
                    if (null !== $p) {
                        $parents[] = $p;
                    }
                }
                $paths = array_map(function (array $e) use ($repository) {
                    if (null !== ($e['from_revision'] ?? null)) {
                        $e['from_revision'] = $this->scmidForInsertingDb($repository, $e['from_revision']);
                    }

                    return $e;
                }, $re->paths);
                $this->service->createChangeset($repository, [
                    'revision' => (string) $re->revision,
                    'scmid' => $this->scmidForInsertingDb($repository, (string) $re->scmid),
                    'committer' => $re->author,
                    'committed_on' => $re->time,
                    'comments' => $re->message,
                ], $parents, $paths);
            }
        }
    }
}
