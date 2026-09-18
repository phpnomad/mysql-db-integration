<?php

namespace PHPNomad\MySql\Integration\Tests\Integration\Fixtures;

use PDO;
use PDOException;
use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Strategies\PdoCoordinatedDatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\TestCase;

/** Owns exact fixture tables in an explicitly assigned schema. */
abstract class OwnedPdoCoordinationContractCase extends TestCase
{
    protected PDO $primary;
    protected PDO $observer;
    protected RecordingLogger $logger;
    protected PdoCoordinatedDatabaseStrategy $strategy;
    protected CoordinationTable $parents;
    protected CoordinationTable $effects;
    /** @var list<string> */
    protected array $ownedTables = [];
    /** @var list<string> */
    protected array $ownedViews = [];
    /** @var list<string> */
    protected array $ownedAccounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->primary = $this->connect();
        $this->observer = $this->connect();
        $prefix = 'nomad_coord_' . bin2hex(random_bytes(6));
        $this->parents = new CoordinationTable($prefix . '_parents', ['tenantId', 'id']);
        $this->effects = new CoordinationTable($prefix . '_effects', ['id']);
        $this->createTable($this->parents->getName(), '(tenantId BIGINT NOT NULL, id BIGINT NOT NULL, PRIMARY KEY (tenantId, id))');
        $this->createTable($this->effects->getName(), '(id BIGINT PRIMARY KEY, score BIGINT NOT NULL)');
        $this->observer->exec('INSERT INTO `' . $this->parents->getName() . '` VALUES (1, 7), (2, 7)');
        $this->logger = new RecordingLogger();
        $this->usePrimary($this->primary);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->primary)) {
                if ($this->primary->inTransaction()) {
                    $this->primary->rollBack();
                }
                $this->primary->exec('SET autocommit = 1');
            }
            foreach (array_reverse($this->ownedViews) as $name) {
                $this->observer->exec('DROP VIEW `' . $name . '`');
            }
            foreach (array_reverse($this->ownedTables) as $name) {
                $this->observer->exec('DROP TABLE `' . $name . '`');
            }
            foreach (array_reverse($this->ownedAccounts) as $name) {
                $this->observer->exec('DROP USER ' . $this->observer->quote($name) . "@'%'");
            }
        } finally {
            try {
                parent::tearDown();
            } finally {
                unset($this->strategy, $this->primary, $this->observer);
            }
        }
    }

    protected function usePrimary(PDO $pdo): void
    {
        $this->primary = $pdo;
        $this->strategy = new PdoCoordinatedDatabaseStrategy(PdoConnection::fromPdo($pdo), $this->logger);
    }

    /**
     * @template TResult
     * @param callable(DatabaseStrategy): TResult $operation
     * @return TResult
     */
    protected function coordinate(callable $operation)
    {
        return $this->strategy->coordinate($this->parents, ['tenantId' => 1, 'id' => 7], [$this->parents, $this->effects], $operation);
    }

    /** @return array<array-key, mixed> */
    protected function visibleEffects(): array
    {
        $statement = $this->observer->query('SELECT id, score FROM `' . $this->effects->getName() . '` ORDER BY id');
        self::assertNotFalse($statement);
        return $statement->fetchAll();
    }

    protected function createTable(string $name, string $columns): void
    {
        $this->observer->exec('CREATE TABLE `' . $name . '` ' . $columns . ' ENGINE=InnoDB');
        $this->ownedTables[] = $name;
    }

    /**
     * @param list<string>|null $tables
     * @param array{phase:string, causeClass:class-string, sqlState:?string, driverCode:?int}|null $priorFailure
     */
    protected function assertFailureLog(
        string $phase,
        string $outcome,
        bool $retryable,
        string $causeClass,
        ?string $sqlState = null,
        ?int $driverCode = null,
        ?array $tables = null,
        ?array $priorFailure = null
    ): void {
        $expected = [[
            'level' => 'error',
            'message' => 'Coordinated database operation failed.',
            'context' => [
                'phase' => $phase,
                'tables' => $tables ?? [$this->parents->getName(), $this->effects->getName()],
                'outcome' => $outcome,
                'retryable' => $retryable,
                'causeClass' => $causeClass,
                'sqlState' => $sqlState,
                'driverCode' => $driverCode,
            ],
        ]];
        if ($priorFailure !== null) {
            ksort($priorFailure);
            $expected[0]['context']['priorFailure'] = $priorFailure;
        }
        self::assertCount(1, $this->logger->entries);
        $actual = $this->logger->entries;
        if (isset($actual[0]['context']['priorFailure']) && is_array($actual[0]['context']['priorFailure'])) {
            ksort($actual[0]['context']['priorFailure']);
        }
        ksort($expected[0]['context']);
        ksort($actual[0]['context']);
        self::assertSame($expected, $actual);
    }

    /**
     * @template TPdo of PDO
     * @param class-string<TPdo> $class
     * @return TPdo
     */
    protected function connect(string $class = PDO::class): PDO
    {
        $dsn = getenv('TEST_MYSQL_COORDINATION_DSN');
        if (!$dsn) {
            $this->markTestSkipped('TEST_MYSQL_COORDINATION_DSN must name an explicitly owned schema.');
        }
        try {
            return new $class($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: 'root', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => true,
                PDO::ATTR_PERSISTENT => false,
            ]);
        } catch (PDOException $failure) {
            $this->markTestSkipped('The explicitly configured coordination test database is unavailable.');
        }
    }
}
