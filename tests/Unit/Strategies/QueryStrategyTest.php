<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Strategies;

use Mockery;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Strategies\QueryStrategy;
use PHPNomad\MySql\Integration\Tests\TestCase;

/**
 * @covers \PHPNomad\MySql\Integration\Strategies\QueryStrategy
 */
class QueryStrategyTest extends TestCase
{
    /**
     * DELETE with compound keys must AND the conditions together, not OR-chain
     * or replace them. The ClauseBuilder's `where()` appends a condition with
     * no logical operator between clauses — calling it multiple times emits
     * SQL like "a = 1 b = 2" (invalid / unintended). `andWhere()` inserts AND
     * between clauses, which is what compound-key deletes require.
     */
    public function test_delete_ands_compound_key_conditions(): void
    {
        $table = $this->makeTable('posts', 'p');

        $clauseBuilder = Mockery::mock(ClauseBuilder::class);
        $clauseBuilder->shouldReceive('reset')->once()->andReturnSelf();
        $clauseBuilder->shouldReceive('useTable')->with($table)->once()->andReturnSelf();
        $clauseBuilder->shouldReceive('andWhere')
            ->with('post_id', '=', 1)
            ->once()
            ->andReturnSelf();
        $clauseBuilder->shouldReceive('andWhere')
            ->with('tag_id', '=', 7)
            ->once()
            ->andReturnSelf();
        $clauseBuilder->shouldNotReceive('where');
        $clauseBuilder->shouldReceive('build')
            ->once()
            ->andReturn('p.post_id = 1 AND p.tag_id = 7');

        $db = Mockery::mock(DatabaseStrategy::class);
        $db->shouldReceive('parse')->andReturnUsing(
            fn (string $q) => $q,
        );
        $db->shouldReceive('query')->once();

        $strategy = new QueryStrategy(
            $db,
            Mockery::mock(TableSchemaService::class),
            $clauseBuilder,
        );

        $strategy->delete($table, ['post_id' => 1, 'tag_id' => 7]);
    }

    /**
     * A MySQL UPDATE that matches rows but changes no values returns
     * `$affected_rows === 0`. That is not a "record not found" condition —
     * the record exists; the values were already what was passed in. Throwing
     * {@see \PHPNomad\Datastore\Exceptions\RecordNotFoundException} in that
     * case punishes callers who legitimately submit identical values and
     * makes idempotent writes impossible.
     *
     * The guard belongs on the caller side where the intent ("I know the row
     * exists, change these fields") is clearer than a raw MySQL affected-rows
     * count can express.
     */
    public function test_update_does_not_throw_when_mysql_reports_zero_affected_rows(): void
    {
        $table = $this->makeTable('posts', 'p');

        $clauseBuilder = Mockery::mock(ClauseBuilder::class);
        $clauseBuilder->shouldReceive('reset')->once()->andReturnSelf();
        $clauseBuilder->shouldReceive('useTable')->with($table)->once()->andReturnSelf();
        $clauseBuilder->shouldReceive('andWhere')->andReturnSelf();
        $clauseBuilder->shouldReceive('build')->andReturn('p.id = 1');

        $db = Mockery::mock(DatabaseStrategy::class);
        $db->shouldReceive('parse')->andReturnUsing(fn (string $q) => $q);
        // Simulate a no-op UPDATE: row matched, nothing changed → 0 affected.
        $db->shouldReceive('query')->once()->andReturn(0);

        $strategy = new QueryStrategy(
            $db,
            Mockery::mock(TableSchemaService::class),
            $clauseBuilder,
        );

        $strategy->update($table, ['id' => 1], ['title' => 'unchanged']);
        $this->addToAssertionCount(1);
    }

    protected function makeTable(string $name, string $alias): Table
    {
        $table = Mockery::mock(Table::class);
        $table->shouldReceive('getName')->andReturn($name);
        $table->shouldReceive('getAlias')->andReturn($alias);
        return $table;
    }
}
