<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/** Reports every statement sent to the database to the WriteLog. */
final class WriteLogMiddleware implements Middleware
{
    public function __construct(private readonly WriteLog $log)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new WriteLogDriver($driver, $this->log);
    }
}
