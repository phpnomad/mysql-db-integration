<?php

namespace PHPNomad\MySql\Integration\Tests\Integration\Fixtures;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/** Injects acknowledgement faults around real transaction operations. */
final class OutcomeFaultPdo extends PDO
{
    public ?string $faultAt = null;
    public bool $afterOperation = false;
    public bool $throwFault = true;
    public ?bool $rollbackThrowsAfterCommitFault = null;
    public bool $rollbackAfterOperationAfterCommitFault = false;
    public ?PDOException $faultCause = null;
    public ?PDOException $commitFaultCause = null;
    public int $commitCalls = 0;
    public int $rollbackCalls = 0;
    public ?Throwable $coordinationFailure = null;
    public int $coordinationFaultCalls = 0;
    /** @var array{string, int, string}|null */
    private ?array $faultInfo = null;

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->failOwnedStatement();
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        $this->failOwnedStatement();
        return parent::exec($statement);
    }

    /** @param array<array-key, mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->failOwnedStatement();
        return parent::prepare($query, $options);
    }

    /** Fail once at the first statement boundary after transaction ownership begins. */
    private function failOwnedStatement(): void
    {
        if ($this->coordinationFailure !== null && $this->inTransaction()) {
            $failure = $this->coordinationFailure;
            $this->coordinationFailure = null;
            $this->coordinationFaultCalls++;
            throw $failure;
        }
    }

    public function commit(): bool
    {
        $this->commitCalls++;
        if ($this->faultAt !== 'commit') {
            return parent::commit();
        }
        if ($this->afterOperation) {
            parent::commit();
        }
        try {
            return $this->failAcknowledgement();
        } finally {
            $this->commitFaultCause = $this->faultCause;
        }
    }

    public function rollBack(): bool
    {
        $this->rollbackCalls++;
        if ($this->faultAt === 'commit' && $this->rollbackThrowsAfterCommitFault !== null) {
            if ($this->rollbackAfterOperationAfterCommitFault) {
                parent::rollBack();
            }
            return $this->failAcknowledgement('Rollback acknowledgement fault', 2006, $this->rollbackThrowsAfterCommitFault);
        }
        if ($this->faultAt !== 'rollback') {
            return parent::rollBack();
        }
        if ($this->afterOperation) {
            parent::rollBack();
        }
        return $this->failAcknowledgement();
    }

    /** @return array<array-key, mixed> */
    public function errorInfo(): array
    {
        return $this->faultInfo ?? parent::errorInfo();
    }

    private function failAcknowledgement(string $message = 'Connection acknowledgement fault', int $code = 2013, ?bool $throws = null): bool
    {
        $this->faultInfo = ['HY000', $code, $message];
        $this->faultCause = new PDOException($message);
        $this->faultCause->errorInfo = $this->faultInfo;
        if ($throws ?? $this->throwFault) {
            throw $this->faultCause;
        }
        return false;
    }
}
