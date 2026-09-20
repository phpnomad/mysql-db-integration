<?php

declare(strict_types=1);

namespace PHPNomad\MySql\Integration\Tests\Integration;

use PDO;
use PDOException;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Interfaces\TableColumnRetirementStrategy as RetirementStrategy;
use PHPNomad\Database\Interfaces\TableUpdateStrategy as UpdateStrategy;
use PHPNomad\Di\Container;
use PHPNomad\Loader\Bootstrapper;
use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\MySqlInitializer;
use PHPNomad\MySql\Integration\Strategies\PdoDatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\TestCase;

/** Persisted-schema contract against an explicitly assigned MySQL database. */
final class RealMySqlTableColumnRetirementContractTest extends TestCase
{
    public const TABLE = 'nomad_column_retirement_contract';
    private const CHILD_TABLE = 'nomad_column_retirement_child';
    private const QUOTED_TABLE = 'nomad retirement odd`table';
    private const ACCENT_TABLE = 'nomad_identifier_accent_contract';

    private PDO $pdo;
    private Container $container;
    private RetirementStrategy $strategy;
    private string $shadowSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $dsn = getenv('TEST_MYSQL_DSN');

        if (!is_string($dsn) || $dsn === '') {
            $this->markTestSkipped('An explicit isolated MySQL test DSN is required.');
        }

        try {
            $this->pdo = new PDO(
                $dsn,
                getenv('TEST_MYSQL_USER') ?: 'root',
                getenv('TEST_MYSQL_PASS') ?: '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_STRINGIFY_FETCHES => true,
                ]
            );
        } catch (PDOException $failure) {
            $this->markTestSkipped('The assigned MySQL resource is unavailable: ' . $failure->getCode());
        }

        $connection = PdoConnection::fromPdo($this->pdo);
        $this->container = new Container();
        $this->container->bindSingletonFromFactory(
            PdoConnection::class,
            static fn(): PdoConnection => $connection
        );
        $this->container->bindSingleton(PdoDatabaseStrategy::class, DatabaseStrategy::class);
        (new Bootstrapper($this->container, new MySqlInitializer()))->load();
        $this->strategy = $this->container->get(RetirementStrategy::class);
        $this->shadowSchema = 'nomad_retirement_shadow_' . getmypid();
        $this->resetFixtures();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . self::CHILD_TABLE);
            $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->quoteIdentifier(self::QUOTED_TABLE));
            $this->pdo->exec('DROP TABLE IF EXISTS ' . self::ACCENT_TABLE);
            $this->pdo->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($this->shadowSchema));
        }

        parent::tearDown();
    }

    public function testSyncIsAdditiveAndColumnExistenceIsScopedToTheActiveSchema(): void
    {
        $this->pdo->exec('CREATE DATABASE ' . $this->quoteIdentifier($this->shadowSchema));
        $this->pdo->exec(
            'CREATE TABLE ' . $this->quoteIdentifier($this->shadowSchema) . '.' . self::TABLE
            . ' (crossSchemaOnly INT NULL) ENGINE=InnoDB'
        );

        self::assertTrue($this->strategy->columnExists($this->table(), 'legacyValue'));
        self::assertTrue($this->strategy->columnExists($this->table(), 'LEGACYVALUE'));
        self::assertTrue($this->strategy->columnExists($this->table(), 'LÉGACY值'));
        self::assertFalse($this->strategy->columnExists($this->table(), 'crossSchemaOnly'));

        $this->strategy->syncColumns($this->table());

        self::assertSame(
            ['id', 'legacyValue', 'unrelatedUnknown', 'legacy value', 'odd`name', 'select', 'legacy-name', 'légacy值', 'modernValue'],
            $this->columns()
        );
        self::assertSame(
            [['id' => '1', 'legacyValue' => '41', 'unrelatedUnknown' => 'keep']],
            $this->pdo->query(
                'SELECT id, legacyValue, unrelatedUnknown FROM ' . self::TABLE
            )->fetchAll()
        );
    }

    public function testBootstrapperResolvesOneStrategyForBaseAndRetirementContracts(): void
    {
        self::assertSame($this->strategy, $this->container->get(UpdateStrategy::class));
    }

    public function testRetirementDeduplicatesBackendEquivalentNamesAndIsIdempotent(): void
    {
        $retired = [
            'legacyValue',
            'LEGACYVALUE',
            'legacy value',
            'odd`name',
            'select',
            'legacy-name',
            'légacy值',
            'LÉGACY值',
        ];
        $this->strategy->retireColumns($this->table(), ...$retired);
        $this->strategy->retireColumns($this->table(), ...$retired);

        self::assertSame(['id', 'unrelatedUnknown'], $this->columns());
        self::assertSame(
            [['id' => '1', 'unrelatedUnknown' => 'keep']],
            $this->pdo->query('SELECT id, unrelatedUnknown FROM ' . self::TABLE)->fetchAll()
        );
    }

    public function testCaseVariantPresentAndAbsentNamesRetireOnlyThePresentIntersection(): void
    {
        $this->strategy->retireColumns($this->table(), 'LEGACYVALUE', 'missingLegacy');

        self::assertSame(
            ['id', 'unrelatedUnknown', 'legacy value', 'odd`name', 'select', 'legacy-name', 'légacy值'],
            $this->columns()
        );
        self::assertSame(
            [['id' => '1', 'unrelatedUnknown' => 'keep']],
            $this->pdo->query('SELECT id, unrelatedUnknown FROM ' . self::TABLE)->fetchAll()
        );
    }

    public function testMetadataResolutionIsCaseInsensitiveButAccentSensitive(): void
    {
        $this->pdo->exec(
            'CREATE TABLE ' . self::ACCENT_TABLE
            . ' (id INT PRIMARY KEY, legacy INT NULL, `légacy` INT NULL, unrelatedUnknown VARCHAR(32) NULL) '
            . 'ENGINE=InnoDB'
        );
        $this->pdo->exec(
            "INSERT INTO " . self::ACCENT_TABLE
            . " (id, legacy, `légacy`, unrelatedUnknown) VALUES (1, 41, 42, 'keep')"
        );
        $table = $this->table(['id', 'legacy'], self::ACCENT_TABLE);

        self::assertTrue($this->strategy->columnExists($table, 'LEGACY'));
        self::assertTrue($this->strategy->columnExists($table, 'LÉGACY'));
        $this->strategy->retireColumns($table, 'LÉGACY');

        self::assertSame(['id', 'legacy', 'unrelatedUnknown'], $this->columns(self::ACCENT_TABLE));
        self::assertSame(
            [['id' => '1', 'legacy' => '41', 'unrelatedUnknown' => 'keep']],
            $this->pdo->query(
                'SELECT id, legacy, unrelatedUnknown FROM ' . self::ACCENT_TABLE
            )->fetchAll()
        );
    }

    /** @dataProvider absentDeclaredCaseVariants */
    public function testAbsentDeclaredCaseVariantRejectsWholeBatchBeforeMutation(
        string $declaredName,
        string $requestedName
    ): void {
        $failure = null;
        try {
            $this->strategy->retireColumns(
                $this->table(['id', $declaredName]),
                'legacyValue',
                $requestedName
            );
        } catch (\InvalidArgumentException $expected) {
            $failure = $expected;
        }

        self::assertSame(
            ['id', 'legacyValue', 'unrelatedUnknown', 'legacy value', 'odd`name', 'select', 'legacy-name', 'légacy值'],
            $this->columns()
        );
        self::assertSame(
            [['id' => '1', 'legacyValue' => '41', 'unrelatedUnknown' => 'keep']],
            $this->pdo->query('SELECT id, legacyValue, unrelatedUnknown FROM ' . self::TABLE)->fetchAll()
        );
        self::assertInstanceOf(\InvalidArgumentException::class, $failure);
    }

    public function absentDeclaredCaseVariants(): array
    {
        return [
            'ASCII case variant' => ['modernValue', 'MODERNVALUE'],
            'Unicode case variant' => ['módernValue', 'MÓDERNVALUE'],
        ];
    }

    /** @dataProvider invalidBatchMembers */
    public function testInvalidBatchMemberPreventsAnyPersistedMutation(string $invalidName): void
    {
        $failure = null;
        try {
            $this->strategy->retireColumns($this->table(), 'legacyValue', $invalidName);
        } catch (\InvalidArgumentException $expected) {
            $failure = $expected;
        }

        self::assertSame(
            ['id', 'legacyValue', 'unrelatedUnknown', 'legacy value', 'odd`name', 'select', 'legacy-name', 'légacy值'],
            $this->columns()
        );
        self::assertSame(
            [['id' => '1', 'legacyValue' => '41', 'unrelatedUnknown' => 'keep']],
            $this->pdo->query('SELECT id, legacyValue, unrelatedUnknown FROM ' . self::TABLE)->fetchAll()
        );
        self::assertInstanceOf(\InvalidArgumentException::class, $failure);
    }

    public function invalidBatchMembers(): array
    {
        return [[''], ["legacy\0Value"]];
    }

    public function testRetirementQuotesThePersistedTableIdentifier(): void
    {
        $quotedTable = $this->quoteIdentifier(self::QUOTED_TABLE);
        $this->pdo->exec(
            'CREATE TABLE ' . $quotedTable
            . ' (id INT PRIMARY KEY, `legacy value` INT NULL, unrelatedUnknown VARCHAR(32) NULL) ENGINE=InnoDB'
        );
        $this->pdo->exec(
            "INSERT INTO " . $quotedTable . " (id, `legacy value`, unrelatedUnknown) VALUES (1, 42, 'keep')"
        );

        $this->strategy->retireColumns($this->table(['id'], self::QUOTED_TABLE), 'legacy value');

        self::assertSame(['id', 'unrelatedUnknown'], $this->columns(self::QUOTED_TABLE));
        self::assertSame(
            [['id' => '1', 'unrelatedUnknown' => 'keep']],
            $this->pdo->query('SELECT id, unrelatedUnknown FROM ' . $quotedTable)->fetchAll()
        );
    }

    public function testWholeBatchPreflightRejectsADeclaredCaseVariantBeforeDdl(): void
    {
        try {
            $this->strategy->retireColumns(
                $this->table(['id', 'unrelatedUnknown']),
                'legacyValue',
                'UNRELATEDUNKNOWN'
            );
            self::fail('A case-variant declared column must reject the whole batch.');
        } catch (\InvalidArgumentException $expected) {
            self::assertSame(
                ['id', 'legacyValue', 'unrelatedUnknown', 'legacy value', 'odd`name', 'select', 'legacy-name', 'légacy值'],
                $this->columns()
            );
        }
    }

    public function testWholeBatchPreflightUsesDatabaseCaseSemanticsForUnicodeNames(): void
    {
        try {
            $this->strategy->retireColumns(
                $this->table(['id', 'légacy值']),
                'legacyValue',
                'LÉGACY值'
            );
            self::fail('A Unicode case-variant declared column must reject the whole batch.');
        } catch (\InvalidArgumentException $expected) {
            self::assertSame(
                ['id', 'legacyValue', 'unrelatedUnknown', 'legacy value', 'odd`name', 'select', 'legacy-name', 'légacy值'],
                $this->columns()
            );
        }
    }

    public function testPlainIndexedColumnIsRefusedWithoutSchemaChanges(): void
    {
        $this->pdo->exec('ALTER TABLE ' . self::TABLE . ' ADD INDEX legacy_value_index (legacyValue)');

        try {
            $this->strategy->retireColumns($this->table(), 'legacyValue');
            self::fail('Retirement must not remove a plain index implicitly.');
        } catch (\InvalidArgumentException $expected) {
            self::assertContains('legacyValue', $this->columns());
            self::assertSame('legacy_value_index', $this->pdo->query(
                "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS "
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "' "
                . "AND COLUMN_NAME = 'legacyValue'"
            )->fetchColumn());
        }
    }

    public function testFunctionalIndexDependencyIsRefusedWhenStatisticsColumnNameIsNull(): void
    {
        $this->pdo->exec(
            'ALTER TABLE ' . self::TABLE . ' ADD INDEX legacy_value_expression ((legacyValue + 1))'
        );

        self::assertSame(null, $this->pdo->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "' "
            . "AND INDEX_NAME = 'legacy_value_expression'"
        )->fetchColumn());

        try {
            $this->strategy->retireColumns($this->table(), 'legacyValue');
            self::fail('Retirement must not overlook a functional-index dependency.');
        } catch (\InvalidArgumentException $expected) {
            self::assertContains('legacyValue', $this->columns());
            self::assertSame('legacy_value_expression', $this->pdo->query(
                "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS "
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "' "
                . "AND INDEX_NAME = 'legacy_value_expression'"
            )->fetchColumn());
        }
    }

    public function testInboundForeignKeyColumnIsRefusedWithoutSchemaChanges(): void
    {
        $this->pdo->exec('ALTER TABLE ' . self::TABLE . ' ADD UNIQUE INDEX legacy_value_unique (legacyValue)');
        $this->pdo->exec(
            'CREATE TABLE ' . self::CHILD_TABLE
            . ' (id INT PRIMARY KEY, parentLegacyValue INT NULL, '
            . 'CONSTRAINT retirement_parent_fk FOREIGN KEY (parentLegacyValue) REFERENCES '
            . self::TABLE . ' (legacyValue)) ENGINE=InnoDB'
        );

        try {
            $this->strategy->retireColumns($this->table(), 'legacyValue');
            self::fail('Retirement must not remove an inbound foreign key implicitly.');
        } catch (\InvalidArgumentException $expected) {
            self::assertContains('legacyValue', $this->columns());
            self::assertSame('retirement_parent_fk', $this->pdo->query(
                "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS "
                . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::CHILD_TABLE . "'"
            )->fetchColumn());
        }
    }

    private function resetFixtures(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::CHILD_TABLE);
        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->quoteIdentifier(self::QUOTED_TABLE));
        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::ACCENT_TABLE);
        $this->pdo->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($this->shadowSchema));
        $this->pdo->exec(
            'CREATE TABLE ' . self::TABLE . ' ('
            . 'id INT PRIMARY KEY, legacyValue INT NULL, unrelatedUnknown VARCHAR(32) NULL, '
            . '`legacy value` INT NULL, `odd``name` INT NULL, `select` INT NULL, '
            . '`legacy-name` INT NULL, `légacy值` INT NULL) ENGINE=InnoDB'
        );
        $this->pdo->exec(
            "INSERT INTO " . self::TABLE
            . " (id, legacyValue, unrelatedUnknown, `legacy value`, `odd``name`, `select`, `legacy-name`, `légacy值`) "
            . "VALUES (1, 41, 'keep', 42, 43, 44, 45, 46)"
        );
    }

    /** @return list<string> */
    private function columns(string $tableName = self::TABLE): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $statement->execute([$tableName]);

        return array_column($statement->fetchAll(), 'COLUMN_NAME');
    }

    /** @param list<string> $declaredNames */
    private function table(array $declaredNames = ['id', 'modernValue'], string $tableName = self::TABLE): Table
    {
        return new class ($declaredNames, $tableName) implements Table {
            public function __construct(private array $declaredNames, private string $tableName) {}
            public function getName(): string { return $this->tableName; }
            public function getAlias(): string { return 'retirement'; }
            public function getTableVersion(): string { return '1'; }
            public function getColumns(): array
            {
                return array_map(
                    static fn(string $name): Column => new Column($name, 'INT'),
                    $this->declaredNames
                );
            }
            public function getIndices(): array { return []; }
            public function getCharset(): ?string { return 'utf8mb4'; }
            public function getCollation(): ?string { return 'utf8mb4_unicode_ci'; }
            public function getFieldsForIdentity(): array { return ['id']; }
            public function getUnprefixedName(): string { return $this->getName(); }
            public function getSingularUnprefixedName(): string { return 'retirement'; }
        };
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
