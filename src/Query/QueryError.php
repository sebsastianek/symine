<?php

declare(strict_types=1);

namespace App\Query;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
/** Query::QueryError - invalid query definition (unknown operator, association, ...) */
#[Exclude]
class QueryError extends \RuntimeException
{
}
