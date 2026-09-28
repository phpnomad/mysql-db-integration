<?php

namespace PHPNomad\MySql\Integration\Tests\Integration\Fixtures;

use PDOStatement;

/** Keeps real prepared execution except at the explicitly armed fault boundary. */
final class InactiveCoordinationRollbackStatement extends PDOStatement
{
    protected function __construct(private InactiveCoordinationRollbackPdo $owner)
    {
    }

    /** @param array<array-key, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        if ($this->owner->failOwnedBoundary('execute')) {
            return false;
        }
        return parent::execute($params);
    }

    /** @return array<array-key, mixed> */
    public function errorInfo(): array
    {
        return $this->owner->faultCause?->errorInfo ?? parent::errorInfo();
    }
}
