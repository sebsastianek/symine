<?php

declare(strict_types=1);

namespace App\Service\Wiki;

/** ActiveRecord::StaleObjectError / Redmine::WikiFormatting::StaleSectionError equivalent */
final class StaleWikiContentException extends \RuntimeException
{
}
