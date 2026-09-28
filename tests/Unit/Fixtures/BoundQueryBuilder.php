<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Fixtures;

use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\HasQueryTables;
use PHPNomad\MySql\Integration\Interfaces\CanBuildWithDatabaseStrategy;

/** Custom extension boundary independent of the built-in concrete builder. */
interface BoundQueryBuilder extends QueryBuilder, HasQueryTables, CanBuildWithDatabaseStrategy
{
}
