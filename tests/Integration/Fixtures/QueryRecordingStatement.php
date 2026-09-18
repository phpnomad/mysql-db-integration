<?php

namespace PHPNomad\MySql\Integration\Tests\Integration\Fixtures;

use PDOStatement;

/** Observes explicit statement execution while retaining native persistence. */
final class QueryRecordingStatement extends PDOStatement
{
    protected function __construct(private QueryRecordingPdo $connection)
    {
    }

    /** @param array<array-key, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        $this->connection->statementExecuteCalls++;
        return parent::execute($params);
    }
}
