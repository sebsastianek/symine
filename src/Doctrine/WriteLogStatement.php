<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** @internal see WriteLogMiddleware */
#[Exclude]
final class WriteLogStatement extends AbstractStatementMiddleware
{
    public function __construct(Statement $statement, private readonly WriteLog $log, private readonly string $sql)
    {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        $result = parent::execute();
        $this->log->statement($this->sql);

        return $result;
    }
}
