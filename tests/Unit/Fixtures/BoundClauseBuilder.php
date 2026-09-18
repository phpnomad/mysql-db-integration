<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Fixtures;

use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\MySql\Integration\Interfaces\CanBuildWithDatabaseStrategy;

/** Custom extension boundary independent of the built-in concrete builder. */
interface BoundClauseBuilder extends ClauseBuilder, CanBuildWithDatabaseStrategy
{
}
