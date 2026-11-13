<?php

declare(strict_types=1);

namespace App\Service\Scm\Model;

use App\Entity\Repository;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Port of app/models/repository/filesystem.rb */
#[Exclude]
final class FilesystemModel extends AbstractModel
{
    public function scmName(): string
    {
        return 'Filesystem';
    }

    public function urlLabel(): string
    {
        return 'field_root_directory';
    }

    public function supportsHistory(): bool
    {
        return false;
    }

    public function fetchChangesets(Repository $repository): void
    {
    }
}
