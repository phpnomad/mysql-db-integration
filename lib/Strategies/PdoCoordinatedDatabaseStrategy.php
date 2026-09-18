<?php

namespace PHPNomad\MySql\Integration\Strategies;

use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Interfaces\CoordinatedDatabaseStrategy;

/**
 * Optional coordination on one explicitly opted-in PDO connection.
 * Supported grants must be final before that connection opens, including when
 * the application injects an existing PDO. Grants, roles, partial revokes, and
 * privilege tables must remain stable. Administrative changes invalidate this
 * capability until the application creates a fresh PDO and strategy.
 * Historical privilege changes cannot be detected from current grant metadata.
 * Refusal covers unsupported conditions observable on an eligible connection.
 */
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
