<?php

declare(strict_types=1);

namespace App\Service\Scm;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Port of Redmine::Scm::Adapters::Annotate */
#[Exclude]
final class ScmAnnotate
{
    /** @var list<string> */
    public array $lines = [];
    /** @var list<ScmRevision|null> */
    public array $revisions = [];
    /** @var list<string|null> */
    public array $previousAnnotations = [];

    public function addLine(string $line, ?ScmRevision $revision, ?string $previous = null): void
    {
        $this->lines[] = $line;
        $this->revisions[] = $revision;
        $this->previousAnnotations[] = $previous;
    }

    public function content(): string
    {
        return implode("\n", $this->lines);
    }

    public function isEmpty(): bool
    {
        return [] === $this->lines;
    }

    public function hasPrevious(): bool
    {
        return [] !== array_filter($this->previousAnnotations);
    }
}
