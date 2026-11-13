<?php

declare(strict_types=1);

namespace App\Query;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
/** Query::StatementInvalid - the generated SQL failed */
#[Exclude]
class StatementInvalid extends \RuntimeException
{
}
