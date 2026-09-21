<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Strategies;

use Mockery;
use PHPNomad\Database\Exceptions\InactiveDatabaseOperationException;
use PHPNomad\Database\Exceptions\UnsupportedCoordinationException;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy as CoreQueryStrategy;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\MySql\Integration\Builders\MySqlClauseBuilder;
use PHPNomad\MySql\Integration\Builders\QueryBuilder;
use PHPNomad\MySql\Integration\Interfaces\CoordinatedDatabaseStrategy;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Strategies\CoordinatedQueryStrategy;
use PHPNomad\MySql\Integration\Tests\TestCase;
use PHPNomad\MySql\Integration\Tests\Unit\Fixtures\BoundQueryBuilder;

final class CoordinatedQueryStrategyTest extends TestCase
{
    public function testItUsesTheAttemptBackendOnceAndPermanentlyClosesTheHandle(): void
    {
        $backend = Mockery::mock(CoordinatedDatabaseStrategy::class);
        $operationBackend = Mockery::mock(DatabaseStrategy::class);
        $table = Mockery::mock(Table::class);
        $table->shouldReceive('getName')->andReturn('records');
        $builder = Mockery::mock(BoundQueryBuilder::class);
        $builder->shouldReceive('getReferencedTables')->once()->andReturn([$table]);
        $builder->shouldReceive('buildWithDatabaseStrategy')
            ->once()
            ->with($operationBackend)
            ->andReturn('SELECT operation_resource');
        $operationBackend->shouldReceive('query')
            ->once()
            ->with('SELECT operation_resource')
            ->andReturn([['value' => 17]]);

        $callbackCount = 0;
        $escaped = null;
        $strategy = new CoordinatedQueryStrategy(
            $backend,
            Mockery::mock(TableSchemaService::class),
            Mockery::mock(ClauseBuilder::class)
        );
        $backend->shouldReceive('coordinate')
            ->once()
            ->with($table, ['id' => 1], [$table], Mockery::type('callable'))
            ->andReturnUsing(static function ($coordinationTable, $identity, $participants, $operation) use ($operationBackend) {
                return $operation($operationBackend);
            });

        $result = $strategy->coordinate(
            $table,
            ['id' => 1],
            [$table],
            function (CoreQueryStrategy $operation) use (&$callbackCount, &$escaped, $strategy, $builder): array {
                $callbackCount++;
                $escaped = $operation;
                [$firstQuery, $firstClause] = $strategy->createOperationBuilders($operation);
                [$secondQuery, $secondClause] = $strategy->createOperationBuilders($operation);
                self::assertInstanceOf(QueryBuilder::class, $firstQuery);
                self::assertInstanceOf(MySqlClauseBuilder::class, $firstClause);
                self::assertNotSame($firstQuery, $secondQuery);
                self::assertNotSame($firstClause, $secondClause);

                return $operation->query($builder);
            }
        );

        self::assertSame(1, $callbackCount);
        self::assertSame([['value' => 17]], $result);
        self::assertInstanceOf(CoreQueryStrategy::class, $escaped);

        try {
            $strategy->createOperationBuilders($escaped);
            self::fail('Inactive handles must not mint operation builders.');
        } catch (UnsupportedCoordinationException $expected) {
        }

        $this->expectException(InactiveDatabaseOperationException::class);
        $escaped->estimatedCount($table);
    }

    public function testAnotherHandleCannotMintBuildersDuringTheAttempt(): void
    {
        $backend = Mockery::mock(CoordinatedDatabaseStrategy::class);
        $operationBackend = Mockery::mock(DatabaseStrategy::class);
        $table = Mockery::mock(Table::class);
        $table->shouldReceive('getName')->andReturn('records');
        $strategy = new CoordinatedQueryStrategy(
            $backend,
            Mockery::mock(TableSchemaService::class),
            Mockery::mock(ClauseBuilder::class)
        );
        $backend->shouldReceive('coordinate')->once()->andReturnUsing(
            static fn ($coordinationTable, $identity, $participants, $operation) => $operation($operationBackend)
        );

        $this->expectException(UnsupportedCoordinationException::class);
        $strategy->coordinate($table, ['id' => 1], [$table], function () use ($strategy): void {
            $strategy->createOperationBuilders(Mockery::mock(CoreQueryStrategy::class));
        });
    }
}
