<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** @internal see WriteLogMiddleware */
#[Exclude]
final class WriteLogConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $connection, private readonly WriteLog $log)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new WriteLogStatement(parent::prepare($sql), $this->log, $sql);
    }

    public function query(string $sql): Result
    {
        $result = parent::query($sql);
        $this->log->statement($sql);

        return $result;
    }

    public function exec(string $sql): int|string
    {
        $result = parent::exec($sql);
        $this->log->statement($sql);
        if (0 === stripos(ltrim($sql), 'ROLLBACK')) {
            // what was read inside the transaction (or after the savepoint) may no longer be true
            $this->log->everything();
        }

        return $result;
    }

    public function rollBack(): void
    {
        parent::rollBack();
        $this->log->everything();
    }
}
