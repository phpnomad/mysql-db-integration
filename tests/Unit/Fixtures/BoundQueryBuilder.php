<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Fixtures;

use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\MySql\Integration\Interfaces\CanBuildWithDatabaseStrategy;

/** Custom extension boundary independent of the built-in concrete builder. */
interface BoundQueryBuilder extends QueryBuilder, CanBuildWithDatabaseStrategy
{
}
