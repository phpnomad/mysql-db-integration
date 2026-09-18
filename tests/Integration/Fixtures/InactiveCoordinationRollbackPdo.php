<?php

namespace PHPNomad\MySql\Integration\Tests\Integration\Fixtures;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/** Injects an inactive failure at one real owned driver boundary. */
final class InactiveCoordinationRollbackPdo extends PDO
{
    public ?string $faultBoundary = null;
    public string $witnessTable;
    public ?PDOException $faultCause = null;
    public int $injectedFailures = 0;
    public ?int $visibleBeforeAbort = null;
    public bool $inactiveAtFailure = false;

    public function arm(string $boundary, string $witnessTable): void
    {
        $this->faultBoundary = $boundary;
        $this->witnessTable = $witnessTable;
        if (!$this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [InactiveCoordinationRollbackStatement::class, [$this]])) {
            throw new RuntimeException('The fixture requires its owned statement class.');
        }
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($this->failOwnedBoundary('query')) {
            return false;
        }
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    /** @param array<array-key, mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->failOwnedBoundary('prepare')) {
            return false;
        }
        return parent::prepare($query, $options);
    }

    /** Returns true only for the silent-mode injected failure. */
    public function failOwnedBoundary(string $boundary): bool
    {
        if ($this->faultBoundary !== $boundary || !$this->inTransaction()) {
            return false;
        }
        $this->faultBoundary = null;
        $table = '`' . str_replace('`', '``', $this->witnessTable) . '`';
        if (parent::exec('INSERT INTO ' . $table . ' VALUES (1, 12)') !== 1) {
            throw new RuntimeException('The fixture must create a rollback witness.');
        }
        $statement = parent::query('SELECT score FROM ' . $table . ' WHERE id = 1');
        if ($statement === false) {
            throw new RuntimeException('The fixture must observe its rollback witness.');
        }
        $this->visibleBeforeAbort = (int) $statement->fetchColumn();
        if (!parent::rollBack()) {
            throw new RuntimeException('The fixture must physically roll back before injecting the failure.');
        }
        $this->inactiveAtFailure = !$this->inTransaction();
        $this->injectedFailures++;
        $this->faultCause = new PDOException('Injected coordination failure after whole rollback');
        $this->faultCause->errorInfo = ['40001', 1213, 'Injected coordination failure after whole rollback'];
        if ($this->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_SILENT) {
            return true;
        }
        throw $this->faultCause;
    }

    /** @return array<array-key, mixed> */
    public function errorInfo(): array
    {
        return $this->faultCause?->errorInfo ?? parent::errorInfo();
    }
}
