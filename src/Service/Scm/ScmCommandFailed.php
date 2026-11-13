<?php

declare(strict_types=1);

namespace App\Service\Scm;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Raised when the SCM command cannot be run (Redmine::Scm::Adapters::CommandFailed) */
#[Exclude]
final class ScmCommandFailed extends \RuntimeException
{
}
