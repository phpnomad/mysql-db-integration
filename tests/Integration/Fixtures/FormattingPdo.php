<?php

namespace PHPNomad\MySql\Integration\Tests\Integration\Fixtures;

use PDO;
use PDOStatement;

/** Observes real driver formatting and execution without replacing either. */
final class FormattingPdo extends PDO
{
    public int $quoteCalls = 0;
    public int $queryCalls = 0;

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        $this->quoteCalls++;
        return parent::quote($string, $type);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->queryCalls++;
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}
