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
    public ?PDOException $faultCause = null;
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
        return $this->failAcknowledgement();
    }

    public function rollBack(): bool
    {
        $this->rollbackCalls++;
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

    private function failAcknowledgement(): bool
    {
        $this->faultInfo = ['HY000', 2013, 'Connection acknowledgement fault'];
        $this->faultCause = new PDOException('Connection acknowledgement fault');
        $this->faultCause->errorInfo = $this->faultInfo;
        if ($this->throwFault) {
            throw $this->faultCause;
        }
        return false;
    }
}
