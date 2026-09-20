<?php

declare(strict_types=1);

namespace PHPNomad\MySql\Integration\Tests\Integration;

use PDO;
use PDOException;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Strategies\PdoDatabaseStrategy;
use PHPNomad\MySql\Integration\Strategies\TableUpdateStrategy;
use PHPNomad\MySql\Integration\Tests\TestCase;

/** Persisted-schema contract against an explicitly assigned MySQL database. */
final class RealMySqlTableColumnRetirementContractTest extends TestCase
{
    public const TABLE = 'nomad_column_retirement_contract';
    private const CHILD_TABLE = 'nomad_column_retirement_child';

    private PDO $pdo;
    private TableUpdateStrategy $strategy;
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

        $this->strategy = new TableUpdateStrategy(
            new PdoDatabaseStrategy(PdoConnection::fromPdo($this->pdo))
        );
        $this->shadowSchema = 'nomad_retirement_shadow_' . getmypid();
        $this->resetFixtures();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . self::CHILD_TABLE);
            $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
            $this->pdo->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($this->shadowSchema));
        }

        parent::tearDown();
    }

    public function testSyncIsAdditiveAndColumnExistenceIsScopedToTheActiveSchema(): void
    {
        $this->markTestIncomplete('Remove this marker when implementing the accepted retirement contract.');

        $this->pdo->exec('CREATE DATABASE ' . $this->quoteIdentifier($this->shadowSchema));
        $this->pdo->exec(
            'CREATE TABLE ' . $this->quoteIdentifier($this->shadowSchema) . '.' . self::TABLE
            . ' (crossSchemaOnly INT NULL) ENGINE=InnoDB'
        );

        self::assertTrue($this->strategy->columnExists($this->table(), 'legacyValue'));
        self::assertFalse($this->strategy->columnExists($this->table(), 'crossSchemaOnly'));

        $this->strategy->syncColumns($this->table());

        self::assertSame(
            ['id', 'legacyValue', 'unrelatedUnknown', 'legacy value', 'odd`name', 'modernValue'],
            $this->columns()
        );
        self::assertSame(
            [['id' => '1', 'legacyValue' => '41', 'unrelatedUnknown' => 'keep']],
            $this->pdo->query(
                'SELECT id, legacyValue, unrelatedUnknown FROM ' . self::TABLE
            )->fetchAll()
        );
    }

    public function testRetirementPersistsOnlyNamedDropsAndIsIdempotent(): void
    {
        $this->markTestIncomplete('Remove this marker when implementing the accepted retirement contract.');

        $this->strategy->retireColumns($this->table(), 'legacyValue', 'legacy value', 'odd`name');
        $this->strategy->retireColumns($this->table(), 'legacyValue', 'legacy value', 'odd`name');

        self::assertSame(['id', 'unrelatedUnknown'], $this->columns());
        self::assertSame(
            [['id' => '1', 'unrelatedUnknown' => 'keep']],
            $this->pdo->query('SELECT id, unrelatedUnknown FROM ' . self::TABLE)->fetchAll()
        );
    }

    public function testWholeBatchPreflightRejectsADeclaredCaseVariantBeforeDdl(): void
    {
        $this->markTestIncomplete('Remove this marker when implementing the accepted retirement contract.');

        try {
            $this->strategy->retireColumns($this->table(), 'legacyValue', 'ID');
            self::fail('A case-variant declared column must reject the whole batch.');
        } catch (\InvalidArgumentException $expected) {
            self::assertSame(
                ['id', 'legacyValue', 'unrelatedUnknown', 'legacy value', 'odd`name'],
                $this->columns()
            );
        }
    }

    public function testIndexedAndInboundForeignKeyColumnsAreRefusedWithoutSchemaChanges(): void
    {
        $this->markTestIncomplete('Remove this marker when implementing the accepted retirement contract.');

        $this->pdo->exec('ALTER TABLE ' . self::TABLE . ' ADD UNIQUE INDEX legacy_value_unique (legacyValue)');
        $this->pdo->exec(
            'CREATE TABLE ' . self::CHILD_TABLE
            . ' (id INT PRIMARY KEY, parentLegacyValue INT NULL, '
            . 'CONSTRAINT retirement_parent_fk FOREIGN KEY (parentLegacyValue) REFERENCES '
            . self::TABLE . ' (legacyValue)) ENGINE=InnoDB'
        );

        try {
            $this->strategy->retireColumns($this->table(), 'legacyValue');
            self::fail('Retirement must not remove an index or inbound foreign key implicitly.');
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
        $this->pdo->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($this->shadowSchema));
        $this->pdo->exec(
            'CREATE TABLE ' . self::TABLE . ' ('
            . 'id INT PRIMARY KEY, legacyValue INT NULL, unrelatedUnknown VARCHAR(32) NULL, '
            . '`legacy value` INT NULL, `odd``name` INT NULL) ENGINE=InnoDB'
        );
        $this->pdo->exec(
            "INSERT INTO " . self::TABLE
            . " (id, legacyValue, unrelatedUnknown, `legacy value`, `odd``name`) VALUES (1, 41, 'keep', 42, 43)"
        );
    }

    /** @return list<string> */
    private function columns(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $statement->execute([self::TABLE]);

        return array_column($statement->fetchAll(), 'COLUMN_NAME');
    }

    private function table(): Table
    {
        return new class implements Table {
            public function getName(): string { return RealMySqlTableColumnRetirementContractTest::TABLE; }
            public function getAlias(): string { return 'retirement'; }
            public function getTableVersion(): string { return '1'; }
            public function getColumns(): array
            {
                return [new Column('id', 'INT', null, 'PRIMARY KEY'), new Column('modernValue', 'INT')];
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
