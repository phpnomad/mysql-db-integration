<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Strategies;

use Mockery;
use PHPNomad\Database\Exceptions\TableUpdateFailedException;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Interfaces\TableColumnRetirementStrategy as RetirementContract;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Strategies\TableUpdateStrategy;
use PHPNomad\MySql\Integration\Tests\TestCase;

/**
 * Acceptance contract for explicit named-column retirement.
 *
 * These tests intentionally precede the adapter implementation.
 * @covers \PHPNomad\MySql\Integration\Strategies\TableUpdateStrategy
 */
final class TableColumnRetirementStrategyTest extends TestCase
{
    public function testStrategyExposesTheOptionalCapability(): void
    {
        self::assertInstanceOf(
            RetirementContract::class,
            new TableUpdateStrategy(new RetirementRecordingDatabase())
        );
    }

    public function testColumnExistsUsesTheActiveSchemaMetadata(): void
    {
        $db = new RetirementRecordingDatabase(['legacyValue']);
        $strategy = new TableUpdateStrategy($db);

        self::assertTrue($strategy->columnExists($this->table(), 'legacyValue'));
        self::assertStringContainsString('TABLE_SCHEMA = DATABASE()', $db->queries[0]);
    }

    public function testMissingColumnIsReportedAsAbsent(): void
    {
        $strategy = new TableUpdateStrategy(new RetirementRecordingDatabase(['id']));

        self::assertFalse($strategy->columnExists($this->table(), 'legacyValue'));
    }

    public function testMetadataFailureIsNotClassifiedAsAbsence(): void
    {
        $db = new RetirementRecordingDatabase(['legacyValue']);
        $db->failMetadata = true;

        $this->expectException(TableUpdateFailedException::class);
        (new TableUpdateStrategy($db))->columnExists($this->table(), 'legacyValue');
    }

    /** @dataProvider malformedMetadataResults */
    public function testMalformedMetadataResultFailsClosedBeforeDdl($result): void
    {
        $db = new RetirementRecordingDatabase(['legacyValue']);
        $db->overrideMetadataResult = true;
        $db->metadataResult = $result;

        try {
            (new TableUpdateStrategy($db))->retireColumns($this->table(), 'legacyValue');
            self::fail('Malformed metadata must fail closed.');
        } catch (TableUpdateFailedException $expected) {
            self::assertSame([], $db->alterQueries());
        }
    }

    public function malformedMetadataResults(): array
    {
        return [
            'false' => [false],
            'null' => [null],
            'malformed row' => [[null]],
        ];
    }

    /** @dataProvider malformedMetadataResults */
    public function testMalformedIdentifierComparisonFailsClosedBeforeDdl($result): void
    {
        $db = new RetirementRecordingDatabase(['id', 'legacyValue']);
        $db->metadataOverrides['identifier'] = $result;

        try {
            (new TableUpdateStrategy($db))->retireColumns($this->table(), 'legacyValue');
            self::fail('Malformed identifier-comparison metadata must fail closed.');
        } catch (TableUpdateFailedException $expected) {
            self::assertSame([], $db->alterQueries());
        }
    }

    /** @dataProvider malformedMetadataResults */
    public function testMalformedStatisticsMetadataFailsClosedBeforeDdl($result): void
    {
        $db = new RetirementRecordingDatabase(['id', 'legacyValue']);
        $db->metadataOverrides['statistics'] = $result;

        try {
            (new TableUpdateStrategy($db))->retireColumns($this->table(), 'legacyValue');
            self::fail('Malformed index metadata must fail closed.');
        } catch (TableUpdateFailedException $expected) {
            self::assertSame([], $db->alterQueries());
        }
    }

    /** @dataProvider malformedMetadataResults */
    public function testMalformedForeignKeyMetadataFailsClosedBeforeDdl($result): void
    {
        $db = new RetirementRecordingDatabase(['id', 'legacyValue']);
        $db->metadataOverrides['foreign_key'] = $result;

        try {
            (new TableUpdateStrategy($db))->retireColumns($this->table(), 'legacyValue');
            self::fail('Malformed foreign-key metadata must fail closed.');
        } catch (TableUpdateFailedException $expected) {
            self::assertSame([], $db->alterQueries());
        }
    }

    public function testRetirementDropsOnlyTheNamedColumn(): void
    {
        $db = new RetirementRecordingDatabase(['id', 'legacyValue', 'unrelatedUnknown']);
        (new TableUpdateStrategy($db))->retireColumns($this->table(), 'legacyValue');

        self::assertCount(1, $db->alterQueries());
        self::assertStringContainsString('DROP COLUMN `legacyValue`', $db->alterQueries()[0]);
        self::assertStringNotContainsString('unrelatedUnknown', $db->alterQueries()[0]);
    }

    public function testAbsentNamedColumnIsAnIdempotentNoOp(): void
    {
        $db = new RetirementRecordingDatabase(['id', 'unrelatedUnknown']);
        $strategy = new TableUpdateStrategy($db);

        $strategy->retireColumns($this->table(), 'legacyValue');
        $strategy->retireColumns($this->table(), 'legacyValue');

        self::assertSame([], $db->alterQueries());
    }

    public function testEmptyRetirementRequestIsRejectedBeforeDdl(): void
    {
        $db = new RetirementRecordingDatabase(['legacyValue']);

        try {
            (new TableUpdateStrategy($db))->retireColumns($this->table());
            self::fail('An empty retirement request must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame([], $db->alterQueries());
        }
    }

    /** @dataProvider invalidColumnNames */
    public function testEmptyOrNulColumnNameIsRejectedBeforeDdl(string $name): void
    {
        $db = new RetirementRecordingDatabase(['legacyValue']);

        try {
            (new TableUpdateStrategy($db))->retireColumns($this->table(), 'legacyValue', $name);
            self::fail('The invalid name must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame([], $db->alterQueries());
        }
    }

    public function invalidColumnNames(): array
    {
        return [[''], ["legacy\0Value"]];
    }

    public function testCaseVariantOfDeclaredColumnIsRejectedBeforeDdl(): void
    {
        $db = new RetirementRecordingDatabase(['id']);

        try {
            (new TableUpdateStrategy($db))->retireColumns($this->table(), 'ID');
            self::fail('MySQL column identifiers are case-insensitive.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame([], $db->alterQueries());
        }
    }

    public function testOneDeclaredNameRejectsTheWholeBatchBeforeDdl(): void
    {
        $db = new RetirementRecordingDatabase(['id', 'legacyValue']);

        try {
            (new TableUpdateStrategy($db))->retireColumns($this->table(), 'legacyValue', 'ID');
            self::fail('A mixed valid/invalid batch must not partially execute.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame([], $db->alterQueries());
        }
    }

    public function testQuotedBackendIdentifiersAreAccepted(): void
    {
        $db = new RetirementRecordingDatabase(['id', 'legacy value', 'odd`name']);
        (new TableUpdateStrategy($db))->retireColumns($this->table(), 'legacy value', 'odd`name');

        self::assertStringContainsString('DROP COLUMN `legacy value`', $db->alterQueries()[0]);
        self::assertStringContainsString('DROP COLUMN `odd``name`', $db->alterQueries()[0]);
        self::assertStringStartsWith('ALTER TABLE `sample-table` ', $db->alterQueries()[0]);
    }

    public function testIndexedColumnIsRejectedWithoutDdl(): void
    {
        $db = new RetirementRecordingDatabase(['id', 'legacyValue']);
        $db->indexedColumns = ['legacyValue'];

        try {
            (new TableUpdateStrategy($db))->retireColumns($this->table(), 'legacyValue');
            self::fail('Index retirement is outside this capability.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame([], $db->alterQueries());
        }
    }

    public function testForeignKeyColumnIsRejectedWithoutDdl(): void
    {
        $db = new RetirementRecordingDatabase(['id', 'legacyValue']);
        $db->foreignKeyColumns = ['legacyValue'];

        try {
            (new TableUpdateStrategy($db))->retireColumns($this->table(), 'legacyValue');
            self::fail('Foreign-key retirement is outside this capability.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame([], $db->alterQueries());
        }
    }

    public function testDdlFailureIsWrapped(): void
    {
        $db = new RetirementRecordingDatabase(['id', 'legacyValue']);
        $db->failAlter = true;

        $this->expectException(TableUpdateFailedException::class);
        (new TableUpdateStrategy($db))->retireColumns($this->table(), 'legacyValue');
    }

    /** @param list<string> $declaredNames */
    private function table(array $declaredNames = ['id']): Table
    {
        $table = Mockery::mock(Table::class);
        $table->shouldReceive('getName')->andReturn('sample-table');
        $table->shouldReceive('getColumns')->andReturn(array_map(
            static fn (string $name): Column => new Column($name, 'BIGINT'),
            $declaredNames
        ));

        return $table;
    }
}

/** Query-boundary fixture: metadata in, recorded DDL out. */
final class RetirementRecordingDatabase implements DatabaseStrategy
{
    /** @var list<string> */
    public array $queries = [];
    /** @var list<string> */
    public array $columns;
    /** @var list<string> */
    public array $indexedColumns = [];
    /** @var list<string> */
    public array $foreignKeyColumns = [];
    public bool $failMetadata = false;
    public bool $failAlter = false;
    public bool $overrideMetadataResult = false;
    public $metadataResult;
    /** @var array<string, mixed> */
    public array $metadataOverrides = [];

    /** @param list<string> $columns */
    public function __construct(array $columns = [])
    {
        $this->columns = $columns;
    }

    public function parse(string $query, ...$args): string
    {
        foreach ($args as $arg) {
            $position = strpos($query, '?n');
            if ($position !== false) {
                $quoted = '`' . str_replace('`', '``', (string) $arg) . '`';
                $query = substr_replace($query, $quoted, $position, 2);
                continue;
            }

            $position = strpos($query, '?s');
            if ($position !== false) {
                $quoted = "'" . str_replace("'", "''", (string) $arg) . "'";
                $query = substr_replace($query, $quoted, $position, 2);
            }
        }

        return $query;
    }

    public function query(string $query)
    {
        $this->queries[] = $query;

        if (stripos($query, 'ALTER TABLE') !== false) {
            if ($this->failAlter) {
                throw new DatastoreErrorException('DDL failed');
            }
            return [];
        }
        if ($this->failMetadata) {
            throw new DatastoreErrorException('Metadata failed');
        }
        if (stripos($query, 'AS identifiers_equal') !== false
            && array_key_exists('identifier', $this->metadataOverrides)) {
            return $this->metadataOverrides['identifier'];
        }
        if (stripos($query, 'INFORMATION_SCHEMA.STATISTICS') !== false
            && array_key_exists('statistics', $this->metadataOverrides)) {
            return $this->metadataOverrides['statistics'];
        }
        if (stripos($query, 'INFORMATION_SCHEMA.KEY_COLUMN_USAGE') !== false
            && array_key_exists('foreign_key', $this->metadataOverrides)) {
            return $this->metadataOverrides['foreign_key'];
        }
        if ($this->overrideMetadataResult && stripos($query, 'INFORMATION_SCHEMA.') !== false) {
            return $this->metadataResult;
        }
        if (stripos($query, 'AS identifiers_equal') !== false) {
            if (preg_match(
                "/SELECT candidate = '((?:''|[^'])*)'.*UNION ALL SELECT '((?:''|[^'])*)'/is",
                $query,
                $matches
            ) !== 1) {
                throw new \RuntimeException('Identifier comparison query was not understood by the fixture.');
            }
            $right = str_replace("''", "'", $matches[1]);
            $left = str_replace("''", "'", $matches[2]);

            return [['identifiers_equal' => strcasecmp($left, $right) === 0 ? '1' : '0']];
        }
        if (stripos($query, 'INFORMATION_SCHEMA.COLUMNS') !== false) {
            $columns = $this->columns;
            if (preg_match("/AND COLUMN_NAME = '((?:''|[^'])*)'/i", $query, $matches) === 1) {
                $requestedName = str_replace("''", "'", $matches[1]);
                $columns = array_values(array_filter(
                    $columns,
                    static fn (string $name): bool => strcasecmp($name, $requestedName) === 0
                ));
            }

            return array_map(static fn (string $name): array => [
                'COLUMN_NAME' => $name,
                'COLUMN_TYPE' => 'bigint',
                'IS_NULLABLE' => 'YES',
                'COLUMN_DEFAULT' => null,
                'EXTRA' => '',
            ], $columns);
        }
        if (stripos($query, 'INFORMATION_SCHEMA.STATISTICS') !== false) {
            return array_map(static fn (string $name): array => ['COLUMN_NAME' => $name], $this->indexedColumns);
        }
        if (stripos($query, 'INFORMATION_SCHEMA.KEY_COLUMN_USAGE') !== false) {
            return array_map(static fn (string $name): array => ['COLUMN_NAME' => $name], $this->foreignKeyColumns);
        }

        return [];
    }

    /** @return list<string> */
    public function alterQueries(): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn (string $query): bool => stripos($query, 'ALTER TABLE') !== false
        ));
    }
}
