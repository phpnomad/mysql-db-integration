<?php

namespace PHPNomad\MySql\Integration\Tests\Fixtures;

use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Strategies\PdoDatabaseStrategy;

/**
 * Runs the real PdoDatabaseStrategy placeholder parser without a database
 * connection. Strings are quoted the way mysqli_real_escape_string does, so
 * builder tests can compare exact SQL without a MySQL server.
 */
class QuotingDatabaseStrategy extends PdoDatabaseStrategy
{
    public function __construct()
    {
        parent::__construct(new PdoConnection());
    }

    protected function escapeString(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return "'" . addcslashes((string) $value, "\\\0\n\r'\"\x1a") . "'";
    }
}
