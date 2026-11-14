<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** @internal see WriteLogMiddleware */
#[Exclude]
final class WriteLogDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $driver, private readonly WriteLog $log)
    {
        parent::__construct($driver);
    }

    public function connect(#[\SensitiveParameter] array $params): Connection
    {
        return new WriteLogConnection(parent::connect($params), $this->log);
    }
}
