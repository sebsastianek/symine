<?php

declare(strict_types=1);

namespace App\Service\Scm;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use App\Entity\Changeset;

/** Port of Redmine::Scm::Adapters::Entry */
#[Exclude]
final class ScmEntry
{
    public ?Changeset $changeset = null;

    public function __construct(
        public string $name,
        public string $path,
        public string $kind,
        public ?int $size = null,
        public ?ScmRevision $lastrev = null,
    ) {
    }

    public function isDir(): bool
    {
        return 'dir' === $this->kind;
    }

    public function isFile(): bool
    {
        return 'file' === $this->kind;
    }

    /** Changeset author (user) or committer name */
    public function author(): ?string
    {
        if (null !== $this->changeset) {
            return null !== $this->changeset->user ? $this->changeset->user->getName() : explode('<', (string) $this->changeset->committer)[0];
        }
        if (null !== $this->lastrev && null !== $this->lastrev->author) {
            return trim(explode('<', $this->lastrev->author)[0]);
        }

        return null;
    }
}
