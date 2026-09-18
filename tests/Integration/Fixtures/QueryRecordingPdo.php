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

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->queryCalls++;
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
