<?php

declare(strict_types=1);

namespace App\Service\Scm\Http;

use App\Entity\Project;
use App\Entity\Repository;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** What a request of the repository browser points at: a repository of a project, a path in it and revisions. */
#[Exclude]
final class RepositoryLocation
{
    /**
     * @param string  $path  without leading or trailing slash ('' for the root)
     * @param ?string $rev   the revision asked (rev, branch or tag), else the default branch of the repository
     * @param ?string $revTo the revision a diff is made against (rev_to)
     */
    public function __construct(
        public readonly Project $project,
        public readonly Repository $repository,
        public readonly string $path,
        public readonly ?string $rev,
        public readonly ?string $revTo,
    ) {
    }
}
