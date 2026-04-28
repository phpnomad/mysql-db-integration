<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Strategies;

use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Strategies\QueryStrategy;
use PHPUnit\Framework\TestCase;

class QueryStrategyTest extends TestCase
{
    public function testInsertResolvesLastInsertIdInsideTransaction(): void
    {
        $db = new InsertTransactionDatabaseStrategyStub();

        $tableSchemaService = $this->createMock(TableSchemaService::class);
        $tableSchemaService->expects($this->once())
            ->method('getPrimaryColumnsForTable')
            ->willReturn([new Column('id', 'INT', null, 'AUTO_INCREMENT')]);

        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn('test_records');

        $strategy = new QueryStrategy(
            $db,
            $tableSchemaService,
            $this->createMock(ClauseBuilder::class)
        );

        $identity = $strategy->insert($table, ['name' => 'Example']);

        $this->assertSame(['id' => 123], $identity);
        $this->assertSame([
            'START TRANSACTION',
            'INSERT INTO test_records (name) VALUES ("Example")',
            'SELECT LAST_INSERT_ID()',
            'COMMIT',
        ], $db->queries);
        $this->assertTrue($db->lastInsertIdQueriedInTransaction);
    }

    public function testQueryUsesTransactionBackedReadAfterInsert(): void
    {
        $db = new InsertTransactionDatabaseStrategyStub();

        $tableSchemaService = $this->createMock(TableSchemaService::class);
        $tableSchemaService->expects($this->once())
            ->method('getPrimaryColumnsForTable')
            ->willReturn([new Column('id', 'INT', null, 'AUTO_INCREMENT')]);

        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn('test_records');

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->expects($this->once())
            ->method('build')
            ->willReturn('SELECT * FROM test_records WHERE id = 123');

        $strategy = new QueryStrategy(
            $db,
            $tableSchemaService,
            $this->createMock(ClauseBuilder::class)
        );

        $strategy->insert($table, ['name' => 'Example']);

        $this->assertSame([['id' => 123, 'name' => 'Example']], $strategy->query($queryBuilder));
        $this->assertSame([
            'START TRANSACTION',
            'INSERT INTO test_records (name) VALUES ("Example")',
            'SELECT LAST_INSERT_ID()',
            'COMMIT',
            'START TRANSACTION',
            'SELECT * FROM test_records WHERE id = 123',
            'COMMIT',
        ], $db->queries);
    }
}

class InsertTransactionDatabaseStrategyStub implements DatabaseStrategy
{
    public array $queries = [];
    public bool $lastInsertIdQueriedInTransaction = false;
    private bool $inTransaction = false;

    public function parse(string $query, ...$args): string
    {
        return 'INSERT INTO test_records (name) VALUES ("Example")';
    }

    public function query(string $query)
    {
        $this->queries[] = $query;

        if ($query === 'START TRANSACTION') {
            $this->inTransaction = true;

            return 1;
        }

        if ($query === 'COMMIT' || $query === 'ROLLBACK') {
            $this->inTransaction = false;

            return 1;
        }

        if ($query === 'SELECT LAST_INSERT_ID()') {
            $this->lastInsertIdQueriedInTransaction = $this->inTransaction;

            return [['LAST_INSERT_ID()' => 123]];
        }

        if (str_starts_with($query, 'SELECT *')) {
            return $this->inTransaction ? [['id' => 123, 'name' => 'Example']] : [];
        }

        return 1;
    }
}
