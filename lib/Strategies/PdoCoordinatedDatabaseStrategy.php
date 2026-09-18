<?php

namespace PHPNomad\MySql\Integration\Strategies;

use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Interfaces\CoordinatedDatabaseStrategy;

/** Architecture stub for optional coordination on one PDO connection. */
class PdoCoordinatedDatabaseStrategy extends PdoDatabaseStrategy implements CoordinatedDatabaseStrategy
{
    public function __construct(PdoConnection $connection, LoggerStrategy $logger)
    {
        parent::__construct($connection);
    }

    /** @inheritDoc */
    public function coordinate(
        Table $coordinationTable,
        array $identity,
        array $participants,
        callable $operation
    ) {
        return null;
    }
}
