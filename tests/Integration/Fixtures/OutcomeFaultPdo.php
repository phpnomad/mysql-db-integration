<?php

namespace PHPNomad\MySql\Integration\Tests\Integration\Fixtures;

use PDO;
use PDOException;

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
    /** @var array{string, int, string}|null */
    private ?array $faultInfo = null;

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
