<?php

namespace PHPNomad\MySql\Integration\Interfaces;

use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy;

/** @internal Builder access for the exact active coordinated query handle. */
interface ProvidesOperationBuilders
{
    /** @return array{QueryBuilder, ClauseBuilder} */
    public function createOperationBuilders(QueryStrategy $queryStrategy): array;
}
