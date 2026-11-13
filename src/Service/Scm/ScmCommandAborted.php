<?php

declare(strict_types=1);

namespace App\Service\Scm;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** The SCM command exited with an error, e.g. unknown revision (AbstractAdapter::ScmCommandAborted) */
#[Exclude]
final class ScmCommandAborted extends \RuntimeException
{
}
