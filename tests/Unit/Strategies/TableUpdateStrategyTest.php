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
    private function buildQueryAllowingDrops(array $currentColumns, array $schemaColumns): ?string
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

        return $build->invoke($strategy, $table, true);
    }

    /**
     * @param array<string, array<string, string|null>> $currentColumns Keyed by column name.
     * @param Column[] $schemaColumns
     */
    private function buildAdditiveQuery(array $currentColumns, array $schemaColumns): ?string
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

        return $build->invoke($strategy, $table, false);
    }

    /**
     * @param array<string, string|null> $currentColumnData
     */
    private function needsColumnModification(array $currentColumnData, Column $newColumn): bool
    {
        $strategy = new TableUpdateStrategy(Mockery::mock(DatabaseStrategy::class));
        $needsModification = new ReflectionMethod($strategy, 'needsColumnModification');
        $needsModification->setAccessible(true);

        return $needsModification->invoke($strategy, $currentColumnData, $newColumn);
    }

    /**
     * MySQL 8.0.17 removed display widths from integer types, so the schema's
     * INT(11) reads back as plain `int`. Treating that as a difference makes
     * every integer column in the database look perpetually out of date, and
     * migrate then rewrites tables it has no reason to touch.
     */
    public function test_integer_display_width_is_not_a_difference(): void
    {
        $query = $this->buildQueryAllowingDrops(
            ['views' => ['COLUMN_TYPE' => 'int', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => '']],
            [new Column('views', 'INT', [11])]
        );

        $this->assertNull($query, 'An int column matching INT(11) needs no change.');
    }

    public function test_bigint_display_width_is_not_a_difference(): void
    {
        $query = $this->buildQueryAllowingDrops(
            ['size' => ['COLUMN_TYPE' => 'bigint', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => '']],
            [new Column('size', 'BIGINT', [20])]
        );

        $this->assertNull($query);
    }

    public function test_nullable_declaration_modifies_a_not_null_live_column(): void
    {
        $query = $this->buildQueryAllowingDrops(
            ['fleetId' => ['COLUMN_TYPE' => 'bigint', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => '']],
            [new Column('fleetId', 'BIGINT')]
        );

        $this->assertNotNull($query);
        $this->assertStringContainsString('MODIFY COLUMN `fleetId` BIGINT', $query);
    }

    public function test_not_null_declaration_modifies_a_nullable_live_column(): void
    {
        $query = $this->buildQueryAllowingDrops(
            ['fleetId' => ['COLUMN_TYPE' => 'bigint', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => '']],
            [new Column('fleetId', 'BIGINT', null, ' not null ')]
        );

        $this->assertNotNull($query);
        $this->assertStringContainsString('MODIFY COLUMN `fleetId` BIGINT', $query);
    }

    public function test_not_null_traveling_with_a_default_still_reads_as_not_null(): void
    {
        // Attributes are free strings; "NOT NULL DEFAULT 'human'" is one
        // attribute. Equality matching read it as nullable and emitted a
        // MODIFY that dropped the constraint.
        $query = $this->buildQueryAllowingDrops(
            ['actorType' => ['COLUMN_TYPE' => 'varchar(20)', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => 'human', 'EXTRA' => '']],
            [new Column('actorType', 'VARCHAR', [20], "NOT NULL DEFAULT 'human'")]
        );

        $this->assertNull($query);
    }

    public function test_matching_type_and_nullability_produces_no_query(): void
    {
        $query = $this->buildQueryAllowingDrops(
            ['fleetId' => ['COLUMN_TYPE' => 'bigint', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => '']],
            [new Column('fleetId', 'BIGINT', null, 'NOT NULL')]
        );

        $this->assertNull($query);
    }

    /**
     * Keep this assertion on the comparison result itself. It fails if the
     * nullability comparison is removed while INFORMATION_SCHEMA still fetches
     * IS_NULLABLE.
     */
    public function test_nullability_only_drift_requires_modification(): void
    {
        $needsModification = $this->needsColumnModification(
            ['COLUMN_TYPE' => 'bigint', 'IS_NULLABLE' => 'NO'],
            new Column('fleetId', 'BIGINT')
        );

        $this->assertTrue($needsModification);
    }

    /**
     * The reverse of the width problem. Comparing with str_contains means
     * `bigint` contains `int`, so a genuine widening from int to bigint was
     * read as already satisfied and silently skipped.
     */
    public function test_widening_an_int_to_bigint_is_still_detected(): void
    {
        $query = $this->buildQueryAllowingDrops(
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
        $query = $this->buildQueryAllowingDrops(
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
        $query = $this->buildQueryAllowingDrops(
            [],
            [(new PrimaryKeyFactory())->toColumn()]
        );

        $this->assertNotNull($query);
        $this->assertStringContainsString('ADD COLUMN `id` BIGINT', $query);
        $this->assertStringContainsString('PRIMARY KEY', $query);
    }

    public function test_a_genuinely_new_column_is_added(): void
    {
        $query = $this->buildQueryAllowingDrops(
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

    /** Only the explicit drop-allowing path removes a column. */
    public function test_a_removed_column_is_dropped_when_drops_are_allowed(): void
    {
        $query = $this->buildQueryAllowingDrops(
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
        $query = $this->buildQueryAllowingDrops(
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
        $query = $this->buildQueryAllowingDrops(
            [
                'id' => ['COLUMN_TYPE' => 'bigint', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => 'auto_increment'],
                'title' => ['COLUMN_TYPE' => 'varchar(255)', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
                'views' => ['COLUMN_TYPE' => 'int', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
            ],
            [
                new Column('id', 'BIGINT', null, 'AUTO_INCREMENT', 'PRIMARY KEY', 'NOT NULL'),
                new Column('title', 'VARCHAR', [255], 'NOT NULL'),
                new Column('views', 'INT', [11]),
            ]
        );

        $this->assertNull($query);
    }

    // --- additive-only synchronisation ---

    /**
     * The reason this mode exists. A container startup script has to bring the
     * schema forward before serving traffic, but a deploy is not always ahead of
     * the database: a rollback, a canary, or two services sharing one database
     * all mean the code can be missing a column the database legitimately holds.
     * Dropping it there destroys data, so dropping must stay a deliberate act.
     */
    public function test_additive_mode_never_drops_a_column_the_schema_does_not_declare(): void
    {
        $query = $this->buildAdditiveQuery(
            [
                'title' => ['COLUMN_TYPE' => 'varchar(255)', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
                'writtenByNewerCode' => ['COLUMN_TYPE' => 'varchar(255)', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
            ],
            [new Column('title', 'VARCHAR', [255], 'NOT NULL')]
        );

        $this->assertNull($query, 'An unknown column is left alone, so there is nothing to do.');
    }

    public function test_additive_mode_still_adds_a_missing_column(): void
    {
        $query = $this->buildAdditiveQuery(
            ['title' => ['COLUMN_TYPE' => 'varchar(255)', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => '']],
            [
                new Column('title', 'VARCHAR', [255], 'NOT NULL'),
                new Column('trustScore', 'INT', [11], 'NOT NULL', 'DEFAULT 500'),
            ]
        );

        $this->assertNotNull($query);
        $this->assertStringContainsString('ADD COLUMN `trustScore` INT(11)', $query);
        $this->assertStringNotContainsString('DROP COLUMN', $query);
    }

    public function test_additive_mode_still_widens_a_changed_column(): void
    {
        $query = $this->buildAdditiveQuery(
            ['title' => ['COLUMN_TYPE' => 'varchar(100)', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => '']],
            [new Column('title', 'VARCHAR', [255], 'NOT NULL')]
        );

        $this->assertNotNull($query);
        $this->assertStringContainsString('MODIFY COLUMN `title` VARCHAR(255)', $query);
    }

    public function test_additive_mode_adds_and_keeps_an_unknown_column_in_the_same_table(): void
    {
        $query = $this->buildAdditiveQuery(
            [
                'title' => ['COLUMN_TYPE' => 'varchar(255)', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
                'writtenByNewerCode' => ['COLUMN_TYPE' => 'text', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
            ],
            [
                new Column('title', 'VARCHAR', [255], 'NOT NULL'),
                new Column('reviewedAt', 'DATETIME'),
            ]
        );

        $this->assertNotNull($query);
        $this->assertStringContainsString('ADD COLUMN `reviewedAt` DATETIME', $query);
        $this->assertStringNotContainsString('writtenByNewerCode', $query);
    }

    /**
     * The default must not drop. This is the guard: syncColumns runs unattended
     * from a container startup script, and a deploy is not always ahead of its
     * database.
     */
    public function test_the_default_does_not_drop_a_removed_column(): void
    {
        $query = $this->buildAdditiveQuery(
            [
                'title' => ['COLUMN_TYPE' => 'varchar(255)', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
                'legacy' => ['COLUMN_TYPE' => 'varchar(255)', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
            ],
            [new Column('title', 'VARCHAR', [255], 'NOT NULL')]
        );

        $this->assertNull($query, 'The default leaves an undeclared column alone.');
    }
}
