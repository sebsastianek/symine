<?php

declare(strict_types=1);

namespace App\Service\Scm;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Port of Redmine::Scm::Adapters::Branch / GitAdapter::GitBranch */
#[Exclude]
final class ScmBranch implements \Stringable
{
    public function __construct(
        public string $name,
        public string $scmid,
        public bool $isDefault = false,
        public ?string $revision = null,
    ) {
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
