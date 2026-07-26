<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Strategies;

use Mockery;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Factories\Columns\PrimaryKeyFactory;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Strategies\TableUpdateStrategy;
use PHPNomad\MySql\Integration\Tests\TestCase;
use ReflectionMethod;

/**
 * @covers \PHPNomad\MySql\Integration\Strategies\TableUpdateStrategy
 */
class TableUpdateStrategyTest extends TestCase
{
    /**
     * @param array<string, array<string, string|null>> $currentColumns Keyed by column name.
     * @param Column[] $schemaColumns
     */
    private function buildQuery(array $currentColumns, array $schemaColumns): ?string
    {
        $rows = [];
        foreach ($currentColumns as $name => $row) {
            $rows[] = array_merge(['COLUMN_NAME' => $name], $row);
        }

        $db = Mockery::mock(DatabaseStrategy::class);
        $db->shouldReceive('parse')->andReturnUsing(fn (string $q) => $q);
        $db->shouldReceive('query')->andReturn($rows);

        $table = Mockery::mock(Table::class);
        $table->shouldReceive('getName')->andReturn('posts');
        $table->shouldReceive('getAlias')->andReturn('p');
        $table->shouldReceive('getColumns')->andReturn($schemaColumns);

        $strategy = new TableUpdateStrategy($db);
        $build = new ReflectionMethod($strategy, 'buildSyncColumnsQuery');
        $build->setAccessible(true);

        return $build->invoke($strategy, $table);
    }

    /**
     * MySQL 8.0.17 removed display widths from integer types, so the schema's
     * INT(11) reads back as plain `int`. Treating that as a difference makes
     * every integer column in the database look perpetually out of date, and
     * migrate then rewrites tables it has no reason to touch.
     */
    public function test_integer_display_width_is_not_a_difference(): void
    {
        $query = $this->buildQuery(
            ['views' => ['COLUMN_TYPE' => 'int', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => '']],
            [new Column('views', 'INT', [11])]
        );

        $this->assertNull($query, 'An int column matching INT(11) needs no change.');
    }

    public function test_bigint_display_width_is_not_a_difference(): void
    {
        $query = $this->buildQuery(
            ['size' => ['COLUMN_TYPE' => 'bigint', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => '']],
            [new Column('size', 'BIGINT', [20])]
        );

        $this->assertNull($query);
    }

    /**
     * The reverse of the width problem. Comparing with str_contains means
     * `bigint` contains `int`, so a genuine widening from int to bigint was
     * read as already satisfied and silently skipped.
     */
    public function test_widening_an_int_to_bigint_is_still_detected(): void
    {
        $query = $this->buildQuery(
            ['id' => ['COLUMN_TYPE' => 'int', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => '']],
            [new Column('id', 'BIGINT', null, 'NOT NULL')]
        );

        $this->assertNotNull($query, 'An int column must still be widened to BIGINT.');
        $this->assertStringContainsString('MODIFY COLUMN `id` BIGINT', $query);
    }

    /**
     * The bug that made migrate unusable. A primary key column whose type has
     * drifted needs modifying, but re-emitting PRIMARY KEY on MODIFY COLUMN
     * makes MySQL reject the statement with "Multiple primary key defined",
     * because the key already exists. Keys belong to index definitions, not to
     * a column modification.
     */
    public function test_modify_does_not_re_declare_the_primary_key(): void
    {
        $query = $this->buildQuery(
            ['id' => ['COLUMN_TYPE' => 'int', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => 'auto_increment']],
            [(new PrimaryKeyFactory())->toColumn()]
        );

        $this->assertNotNull($query);
        $this->assertStringContainsString('MODIFY COLUMN `id` BIGINT', $query);
        $this->assertStringNotContainsString('PRIMARY KEY', $query);
        $this->assertStringContainsString('AUTO_INCREMENT', $query, 'Auto increment is a column property and must stay.');
    }

    /**
     * Adding a brand new column is the one place the key clause is correct,
     * because there is no existing key to collide with.
     */
    public function test_adding_a_new_primary_key_column_keeps_the_key_clause(): void
    {
        $query = $this->buildQuery(
            [],
            [(new PrimaryKeyFactory())->toColumn()]
        );

        $this->assertNotNull($query);
        $this->assertStringContainsString('ADD COLUMN `id` BIGINT', $query);
        $this->assertStringContainsString('PRIMARY KEY', $query);
    }

    public function test_a_genuinely_new_column_is_added(): void
    {
        $query = $this->buildQuery(
            ['title' => ['COLUMN_TYPE' => 'varchar(255)', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => '']],
            [
                new Column('title', 'VARCHAR', [255], 'NOT NULL'),
                new Column('slug', 'VARCHAR', [255], 'NOT NULL'),
            ]
        );

        $this->assertNotNull($query);
        $this->assertStringContainsString('ADD COLUMN `slug` VARCHAR(255)', $query);
        $this->assertStringNotContainsString('`title`', $query, 'An unchanged column must be left alone.');
    }

    public function test_a_removed_column_is_dropped(): void
    {
        $query = $this->buildQuery(
            [
                'title' => ['COLUMN_TYPE' => 'varchar(255)', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
                'legacy' => ['COLUMN_TYPE' => 'varchar(255)', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
            ],
            [new Column('title', 'VARCHAR', [255], 'NOT NULL')]
        );

        $this->assertNotNull($query);
        $this->assertStringContainsString('DROP COLUMN `legacy`', $query);
    }

    public function test_a_varchar_length_change_is_detected(): void
    {
        $query = $this->buildQuery(
            ['title' => ['COLUMN_TYPE' => 'varchar(100)', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => '']],
            [new Column('title', 'VARCHAR', [255], 'NOT NULL')]
        );

        $this->assertNotNull($query);
        $this->assertStringContainsString('MODIFY COLUMN `title` VARCHAR(255)', $query);
    }

    /**
     * A table already matching its definition must produce no statement at all,
     * so migrate is a no-op rather than a rewrite of every table it inspects.
     */
    public function test_a_table_in_sync_produces_no_query(): void
    {
        $query = $this->buildQuery(
            [
                'id' => ['COLUMN_TYPE' => 'bigint', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => 'auto_increment'],
                'title' => ['COLUMN_TYPE' => 'varchar(255)', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
                'views' => ['COLUMN_TYPE' => 'int', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
            ],
            [
                (new PrimaryKeyFactory())->toColumn(),
                new Column('title', 'VARCHAR', [255], 'NOT NULL'),
                new Column('views', 'INT', [11]),
            ]
        );

        $this->assertNull($query);
    }
}
