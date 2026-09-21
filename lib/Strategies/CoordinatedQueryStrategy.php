<?php

namespace PHPNomad\MySql\Integration\Strategies;

use PHPNomad\Database\Interfaces\CoordinatedQueryStrategy as CoreCoordinatedQueryStrategy;
use PHPNomad\Database\Interfaces\QueryStrategy as CoreQueryStrategy;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Exceptions\UnsupportedCoordinationException;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Database\Strategies\OperationQueryStrategy;
use PHPNomad\MySql\Integration\Builders\MySqlClauseBuilder;
use PHPNomad\MySql\Integration\Builders\QueryBuilder;
use PHPNomad\MySql\Integration\Interfaces\CoordinatedDatabaseStrategy;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Interfaces\ProvidesOperationBuilders;

/** MySQL query coordination over one backend-owned database operation. */
final class CoordinatedQueryStrategy extends QueryStrategy implements CoreCoordinatedQueryStrategy, ProvidesOperationBuilders
{
    private CoordinatedDatabaseStrategy $coordinatedDatabaseStrategy;

    private ?OperationQueryStrategy $activeQueryStrategy = null;

    private ?DatabaseStrategy $activeDatabaseStrategy = null;

    public function __construct(
        CoordinatedDatabaseStrategy $db,
        TableSchemaService $tableSchemaService,
        \PHPNomad\Database\Interfaces\ClauseBuilder $clauseBuilder
    ) {
        parent::__construct($db, $tableSchemaService, $clauseBuilder);
        $this->coordinatedDatabaseStrategy = $db;
    }

    /**
     * @template TResult
     * @param non-empty-array<string, int|string> $identity
     * @param non-empty-list<Table> $participants
     * @param callable(CoreQueryStrategy): TResult $operation
     * @return TResult
     */
    public function coordinate(
        Table $coordinationTable,
        array $identity,
        array $participants,
        callable $operation
    ) {
        return $this->coordinatedDatabaseStrategy->coordinate(
            $coordinationTable,
            $identity,
            $participants,
            function (DatabaseStrategy $database) use ($participants, $operation) {
                $delegate = new OperationBoundQueryStrategy(
                    $database,
                    $this->tableSchemaService,
                    new MySqlClauseBuilder()
                );
                $queryStrategy = new OperationQueryStrategy($delegate, $participants);
                $this->activeQueryStrategy = $queryStrategy;
                $this->activeDatabaseStrategy = $database;

                try {
                    return $operation($queryStrategy);
                } finally {
                    $this->activeQueryStrategy = null;
                    $this->activeDatabaseStrategy = null;
                    $queryStrategy->close();
                }
            }
        );
    }

    /** @return array{\PHPNomad\Database\Interfaces\QueryBuilder, \PHPNomad\Database\Interfaces\ClauseBuilder} */
    public function createOperationBuilders(CoreQueryStrategy $queryStrategy): array
    {
        if (
            $queryStrategy !== $this->activeQueryStrategy
            || $this->activeDatabaseStrategy === null
        ) {
            throw new UnsupportedCoordinationException(
                'Operation builders require the exact active coordinated query strategy.'
            );
        }

        return [new QueryBuilder(), new MySqlClauseBuilder()];
    }
}
