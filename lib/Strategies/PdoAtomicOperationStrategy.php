<?php

namespace PHPNomad\MySql\Integration\Strategies;

use PHPNomad\Database\Interfaces\AtomicOperationStrategy;
use PHPNomad\MySql\Integration\Connections\PdoConnection;

class PdoAtomicOperationStrategy implements AtomicOperationStrategy
{
    public function __construct(protected PdoConnection $connection)
    {
    }

    /** @inheritDoc */
    public function atomic(callable $operation)
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $result = $operation();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}
