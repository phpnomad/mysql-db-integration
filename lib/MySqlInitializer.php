<?php

namespace PHPNomad\MySql\Integration;

use PHPNomad\Database\Interfaces\CanConvertDatabaseStringToDateTime;
use PHPNomad\Database\Interfaces\CanConvertToDatabaseDateString;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\QueryBuilder as QueryBuilderInterface;
use PHPNomad\Database\Interfaces\QueryStrategy as CoreQueryStrategy;
use PHPNomad\Database\Interfaces\TableCreateStrategy as CoreTableCreateStrategy;
use PHPNomad\Database\Interfaces\TableDeleteStrategy as CoreTableDeleteStrategy;
use PHPNomad\Database\Interfaces\TableExistsStrategy as CoreTableExistsStrategy;
use PHPNomad\Database\Interfaces\TableUpdateStrategy as CoreTableUpdateStrategy;
use PHPNomad\Loader\Interfaces\HasClassDefinitions;
use PHPNomad\MySql\Integration\Adapters\DatabaseDateAdapter;
use PHPNomad\MySql\Integration\Builders\MySqlClauseBuilder;
use PHPNomad\MySql\Integration\Builders\QueryBuilder;
use PHPNomad\MySql\Integration\Strategies\QueryStrategy;
use PHPNomad\MySql\Integration\Strategies\TableCreateStrategy;
use PHPNomad\MySql\Integration\Strategies\TableDeleteStrategy;
use PHPNomad\MySql\Integration\Strategies\TableExistsStrategy;
use PHPNomad\MySql\Integration\Strategies\TableUpdateStrategy;

class MySqlInitializer implements HasClassDefinitions
{
    public function getClassDefinitions(): array
    {
        return [
            QueryBuilder::class => QueryBuilderInterface::class,
            MySqlClauseBuilder::class => ClauseBuilder::class,
            TableCreateStrategy::class => CoreTableCreateStrategy::class,
            TableDeleteStrategy::class => CoreTableDeleteStrategy::class,
            TableExistsStrategy::class => CoreTableExistsStrategy::class,
            TableUpdateStrategy::class => CoreTableUpdateStrategy::class,
            QueryStrategy::class => CoreQueryStrategy::class,
            DatabaseDateAdapter::class => [
                CanConvertDatabaseStringToDateTime::class,
                CanConvertToDatabaseDateString::class
            ]
        ];
    }
}