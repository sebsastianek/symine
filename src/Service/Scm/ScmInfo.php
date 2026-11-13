<?php

declare(strict_types=1);

namespace App\Service\Scm;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Port of Redmine::Scm::Adapters::Info */
#[Exclude]
final class ScmInfo
{
    public function __construct(
        public ?string $rootUrl = null,
        public ?ScmRevision $lastrev = null,
    ) {
    }
}
