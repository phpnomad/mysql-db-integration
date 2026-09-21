<?php

namespace PHPNomad\MySql\Integration\Tests\Integration;

use PDO;
use PHPNomad\Database\Interfaces\TableExistsStrategy;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Strategies\PdoDatabaseStrategy;
use PHPNomad\MySql\Integration\Strategies\TableExistsStrategy as MySqlTableExistsStrategy;
use PHPNomad\MySql\Integration\Tests\TestCase;

final class TableExistsStrategyContractTest extends TestCase
{
    private const TABLE_NAMES = [
        'nomad_table_exists_array_contract',
        'nomad_table_exists_literal%name',
        'nomad_table_exists_literal_name',
        'nomad_table_exists_percentXwildcard',
        'nomad_table_exists_underXwildcard',
    ];

    private PDO $pdo;
    private TableExistsStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $dsn = getenv('TEST_MYSQL_DSN');
        if (!$dsn) {
            $this->markTestSkipped('An explicit isolated MySQL test DSN is required.');
        }
        $this->pdo = new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => true,
            PDO::ATTR_PERSISTENT => false,
        ]);
        $this->dropFixtureTables();
        foreach (self::TABLE_NAMES as $tableName) {
            $this->pdo->exec("CREATE TABLE `{$tableName}` (id INT PRIMARY KEY) ENGINE=InnoDB");
        }
        $this->strategy = new MySqlTableExistsStrategy(
            new PdoDatabaseStrategy(PdoConnection::fromPdo($this->pdo))
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropFixtureTables();
        }
        parent::tearDown();
    }

    public function testItReadsTheDatabaseStrategyArrayResult(): void
    {
        self::assertTrue($this->strategy->exists('nomad_table_exists_array_contract'));
        self::assertFalse($this->strategy->exists('nomad_table_exists_array_contract_missing'));
    }

    public function testItTreatsLikeWildcardsAsLiteralTableNameCharacters(): void
    {
        self::assertTrue($this->strategy->exists('nomad_table_exists_literal%name'));
        self::assertTrue($this->strategy->exists('nomad_table_exists_literal_name'));
        self::assertFalse($this->strategy->exists('nomad_table_exists_percent%wildcard'));
        self::assertFalse($this->strategy->exists('nomad_table_exists_under_wildcard'));
        self::assertFalse($this->strategy->exists('nomad_table_exists_literal_name_missing'));
    }

    public function testItReportsQueryFailureAsAbsence(): void
    {
        $strategy = new MySqlTableExistsStrategy($this->databaseStrategyReturning(
            new DatastoreErrorException('Metadata is unavailable.')
        ));

        self::assertFalse($strategy->exists('nomad_table_exists_array_contract'));
    }

    public function testItRejectsMalformedMetadataResults(): void
    {
        $strategy = new MySqlTableExistsStrategy($this->databaseStrategyReturning(1));

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('The table metadata query must return an array.');

        $strategy->exists('nomad_table_exists_array_contract');
    }

    public function testItRejectsMalformedMetadataRows(): void
    {
        $strategy = new MySqlTableExistsStrategy($this->databaseStrategyReturning([
            ['unexpected_column' => 'nomad_table_exists_array_contract'],
        ]));

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('The table metadata query returned an invalid row.');

        $strategy->exists('nomad_table_exists_array_contract');
    }

    /**
     * @param mixed $result
     */
    private function databaseStrategyReturning($result): DatabaseStrategy
    {
        return new class ($result) implements DatabaseStrategy {
            /** @var mixed */
            private $result;

            /** @param mixed $result */
            public function __construct($result)
            {
                $this->result = $result;
            }

            public function parse(string $query, ...$args): string
            {
                return $query;
            }

            public function query(string $query)
            {
                if ($this->result instanceof DatastoreErrorException) {
                    throw $this->result;
                }

                return $this->result;
            }
        };
    }

    private function dropFixtureTables(): void
    {
        foreach (self::TABLE_NAMES as $tableName) {
            $this->pdo->exec("DROP TABLE IF EXISTS `{$tableName}`");
        }
    }
}
