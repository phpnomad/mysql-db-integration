<?php

namespace PHPNomad\MySql\Integration\Services;

use PHPNomad\Database\Interfaces\DatabaseHandler;
use PHPNomad\Database\Interfaces\OperationDatabaseProviderFactory;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Services\OperationCacheableService;
use PHPNomad\Database\Services\OperationEventStrategy;
use PHPNomad\MySql\Integration\Interfaces\ProvidesOperationBuilders;

/** Creates fresh MySQL builders for one operation-bound handler. */
final class MySqlOperationDatabaseProviderFactory implements OperationDatabaseProviderFactory
{
    private ProvidesOperationBuilders $coordinator;

    public function __construct(ProvidesOperationBuilders $coordinator)
    {
        $this->coordinator = $coordinator;
    }

    public function create(
        DatabaseHandler $handler,
        QueryStrategy $queryStrategy,
        OperationCacheableService $cache,
        OperationEventStrategy $events
    ): DatabaseServiceProvider {
        [$queryBuilder, $clauseBuilder] = $this->coordinator->createOperationBuilders($queryStrategy);

        return $handler->getDatabaseServiceProvider()->forOperation(
            $queryStrategy,
            $queryBuilder,
            $clauseBuilder,
            $cache,
            $events
        );
    }
}
