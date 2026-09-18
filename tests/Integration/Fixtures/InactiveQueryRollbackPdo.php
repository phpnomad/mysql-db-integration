<?php

namespace PHPNomad\MySql\Integration\Tests\Integration\Fixtures;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/** Injects a query failure after real rollback, not a second real deadlock. */
final class InactiveQueryRollbackPdo extends PDO
{
    public ?string $faultQuery = null;
    public ?PDOException $faultCause = null;
    public int $injectedFailures = 0;

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($this->faultQuery === $query) {
            $this->faultQuery = null;
            if (!parent::rollBack()) {
                throw new RuntimeException('The fixture must physically roll back before injecting its query failure.');
            }
            $this->injectedFailures++;
            $this->faultCause = new PDOException('Injected query failure after whole rollback');
            $this->faultCause->errorInfo = ['40001', 1213, 'Injected query failure after whole rollback'];
            if ($this->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_SILENT) {
                return false;
            }
            throw $this->faultCause;
        }
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    /** @return array<array-key, mixed> */
    public function errorInfo(): array
    {
        return $this->faultCause?->errorInfo ?? parent::errorInfo();
    }
}
