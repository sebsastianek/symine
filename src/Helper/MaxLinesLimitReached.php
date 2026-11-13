<?php

declare(strict_types=1);

namespace App\Helper;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Raised when the gantt_items_limit is reached (Redmine::Helpers::Gantt::MaxLinesLimitReached) */
#[Exclude]
final class MaxLinesLimitReached extends \RuntimeException
{
}
