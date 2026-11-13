<?php

declare(strict_types=1);

namespace App\Service\Scm;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Port of Redmine::Scm::Adapters::Revision (and the GitAdapter / MercurialAdapter / CvsAdapter subclasses) */
#[Exclude]
final class ScmRevision
{
    public const string FORMAT_DEFAULT = 'default';
    public const string FORMAT_GIT = 'git';
    public const string FORMAT_MERCURIAL = 'mercurial';
    public const string FORMAT_CVS = 'cvs';

    /**
     * @param list<array{action: string, path: string, from_path?: ?string, from_revision?: ?string, revision?: ?string, branch?: ?string}> $paths
     * @param list<string>                                                                                                                 $parents
     */
    public function __construct(
        public ?string $identifier = null,
        public ?string $scmid = null,
        public ?string $author = null,
        public ?\DateTimeImmutable $time = null,
        public ?string $message = null,
        public array $paths = [],
        public array $parents = [],
        public ?string $revision = null,
        public ?string $name = null,
        public ?string $branch = null,
        public string $format = self::FORMAT_GIT,
    ) {
        $this->name ??= $this->identifier;
    }

    /** Readable identifier (Revision#format_identifier of each adapter) */
    public function formatIdentifier(): string
    {
        return match ($this->format) {
            self::FORMAT_GIT => substr((string) $this->identifier, 0, 8),
            self::FORMAT_MERCURIAL => $this->revision.':'.$this->scmid,
            self::FORMAT_CVS => (string) $this->revision,
            default => (string) $this->identifier,
        };
    }

    /** Revision#== */
    public function equals(?self $other): bool
    {
        if (null === $other) {
            return false;
        }
        if (null !== $this->scmid && '' !== $this->scmid) {
            return $this->scmid === $other->scmid;
        }
        if (null !== $this->identifier && '' !== $this->identifier) {
            return $this->identifier === $other->identifier;
        }
        if (null !== $this->revision && '' !== $this->revision) {
            return $this->revision === $other->revision;
        }

        return false;
    }
}
