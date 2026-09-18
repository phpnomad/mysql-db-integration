<?php

namespace PHPNomad\MySql\Integration\Tests\Integration\Fixtures;

use PDO;
use PDOException;
use PDOStatement;

/** Observes actual driver attempts and errors without replacing persistence. */
final class QueryRecordingPdo extends PDO
{
    public int $queryCalls = 0;
    public ?PDOException $lastQueryFailure = null;
    /** @var array<array-key, mixed> */
    public array $lastQueryErrorInfo = [];
    /** @var list<mixed> */
    public array $queryModes = [];
    public mixed $handlerAtLastQuery = null;

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->queryCalls++;
        $this->queryModes[] = $this->getAttribute(PDO::ATTR_ERRMODE);
        // PHP has no read-only getter. Restore the slot before driver IO.
        $this->handlerAtLastQuery = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        try {
            $statement = parent::query($query, $fetchMode, ...$fetchModeArgs);
            $this->lastQueryErrorInfo = $this->errorInfo();
            return $statement;
        } catch (PDOException $failure) {
            $this->lastQueryFailure = $failure;
            $this->lastQueryErrorInfo = $this->errorInfo();
            throw $failure;
        }
    }
}
