<?php

namespace PHPNomad\MySql\Integration\Tests\Integration;

use PDO;
use PDOException;
use PHPNomad\Cache\Interfaces\CachePolicy;
use PHPNomad\Cache\Interfaces\CacheStrategy;
use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Di\Container;
use PHPNomad\Events\Interfaces\EventStrategy;
use PHPNomad\Loader\Bootstrapper;
use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Facades\Database;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\MySqlInitializer;
use PHPNomad\MySql\Integration\Strategies\PdoDatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\FormattingHostServices;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\FormattingPdo;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\FormattingTable;
use PHPNomad\MySql\Integration\Tests\TestCase;
use ReflectionProperty;

/** Library boot and actual row effects expose silently broadened predicates. */
final class PredicateValidationDriverContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestIncomplete('Explicit predicate validation driver proof is pending.');
    }

    /** @dataProvider invalidIdentities */
    public function testInvalidFieldsPreventExecutionAndPreserveBothRows(string $operation, string $position): void
    {
        $this->withFixture(function (FormattingPdo $pdo, Container $container): void {
            $hazard = $pdo->query('SELECT id FROM nomad_bound_formatting WHERE score = 10 ORDER BY id');
            self::assertNotFalse($hazard);
            self::assertSame([['id' => '7'], ['id' => '8']], $hazard->fetchAll());
        }, function (FormattingPdo $pdo, Container $container) use ($operation, $position): void {
            $ids = match ($position) {
                'first' => ['missing' => 7, 'score' => 10],
                'last' => ['score' => 10, 'missing' => 7],
                default => ['missing' => 7],
            };
            $pdo->queryCalls = 0;
            $failure = null;
            try {
                $this->execute($container, $operation, $ids);
            } catch (QueryBuilderException $caught) {
                $failure = $caught;
            }
            self::assertInstanceOf(QueryBuilderException::class, $failure);
            self::assertSame(0, $pdo->queryCalls);
            self::assertSame($this->originalRows(), $this->rows($pdo));
        });
    }

    public function testAnUnknownOperatorCannotTurnANarrowReadIntoABroadRead(): void
    {
        $this->withFixture(function (FormattingPdo $pdo, Container $container): void {
            $hazard = $pdo->query('SELECT id FROM nomad_bound_formatting WHERE score = 10 ORDER BY id');
            self::assertNotFalse($hazard);
            self::assertCount(2, $hazard->fetchAll());
        }, function (FormattingPdo $pdo, Container $container): void {
            $table = new FormattingTable();
            $clause = (clone $container->get(ClauseBuilder::class))->useTable($table)->where('score', '=', 10);
            $pdo->queryCalls = 0;
            $failure = null;
            try {
                $clause->andWhere('id', 'UNKNOWN', 7);
                $query = $container->get(QueryBuilder::class)->from($table)->select('*')->where($clause);
                $container->get(QueryStrategy::class)->query($query);
            } catch (QueryBuilderException $caught) {
                $failure = $caught;
            }
            self::assertInstanceOf(QueryBuilderException::class, $failure);
            self::assertSame(0, $pdo->queryCalls);
            self::assertSame($this->originalRows(), $this->rows($pdo));
        });
    }

    /** @dataProvider validOperations */
    public function testACompleteValidPredicateTargetsOnlyItsRequestedRow(string $operation): void
    {
        $this->withFixture(static function (): void {}, function (FormattingPdo $pdo, Container $container) use ($operation): void {
            $pdo->queryCalls = 0;
            $result = $this->execute($container, $operation, ['score' => 10, 'id' => 7]);
            if ($operation === 'query') {
                self::assertSame([$this->originalRows()[0]], $result);
            }
            self::assertSame(1, $pdo->queryCalls);
            $expected = $this->originalRows();
            if ($operation === 'update') {
                $expected[0]['score'] = '12';
            } elseif ($operation === 'delete') {
                array_shift($expected);
            }
            self::assertSame($expected, $this->rows($pdo));
        });
    }

    /**
     * @param array<string, int> $ids
     * @return array<array-key, mixed>|null
     */
    private function execute(Container $container, string $operation, array $ids): ?array
    {
        $table = new FormattingTable();
        $strategy = $container->get(QueryStrategy::class);
        if ($operation === 'update') {
            $strategy->update($table, $ids, ['score' => 12]);
            return null;
        }
        if ($operation === 'delete') {
            $strategy->delete($table, $ids);
            return null;
        }
        $clause = (clone $container->get(ClauseBuilder::class))->useTable($table);
        foreach ($ids as $field => $value) {
            $clause->andWhere($field, '=', $value);
        }
        $query = $container->get(QueryBuilder::class)->from($table)->select('*')->where($clause);
        return $strategy->query($query);
    }

    /**
     * @param callable(FormattingPdo, Container): void $establishHazard
     * @param callable(FormattingPdo, Container): void $assertOperation
     */
    private function withFixture(callable $establishHazard, callable $assertOperation): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TEMPORARY TABLE nomad_bound_formatting (id INT PRIMARY KEY, score INT, label VARCHAR(64)) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO nomad_bound_formatting VALUES (7, 10, 'first'), (8, 10, 'sibling')");
        $container = new Container();
        $connection = PdoConnection::fromPdo($pdo);
        $container->bindSingletonFromFactory(PdoConnection::class, static fn(): PdoConnection => $connection);
        $container->bindSingleton(PdoDatabaseStrategy::class, DatabaseStrategy::class);
        $services = new FormattingHostServices();
        foreach ([CachePolicy::class, CacheStrategy::class, EventStrategy::class] as $contract) {
            $container->bindSingletonFromFactory($contract, static fn(): FormattingHostServices => $services);
        }
        (new Bootstrapper($container, new MySqlInitializer()))->load();
        $facade = new ReflectionProperty(Database::class, 'instance');
        $facade->setAccessible(true);
        $previous = $facade->getValue();
        $facade->setValue(null, new Database());
        Database::instance()->setContainer($container);
        try {
            $establishHazard($pdo, $container);
            $assertOperation($pdo, $container);
        } finally {
            $facade->setValue(null, $previous);
        }
    }

    private function connection(): FormattingPdo
    {
        $dsn = getenv('TEST_MYSQL_DSN');
        if (!$dsn) {
            $this->markTestSkipped('An explicit isolated MySQL test DSN is required.');
        }
        try {
            return new FormattingPdo($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => true,
                PDO::ATTR_PERSISTENT => false,
            ]);
        } catch (PDOException $failure) {
            $this->markTestSkipped('The assigned MySQL resource is unavailable: ' . $failure->getCode());
        }
    }

    /** @return list<array{id:string, score:string, label:string}> */
    private function originalRows(): array
    {
        return [['id' => '7', 'score' => '10', 'label' => 'first'], ['id' => '8', 'score' => '10', 'label' => 'sibling']];
    }

    /** @return array<array-key, mixed> */
    private function rows(FormattingPdo $pdo): array
    {
        $statement = $pdo->query('SELECT * FROM nomad_bound_formatting ORDER BY id');
        self::assertNotFalse($statement);
        return $statement->fetchAll();
    }

    /** @return array<string, array{string, string}> */
    public static function invalidIdentities(): array
    {
        $cases = [];
        foreach (['query', 'update', 'delete'] as $operation) {
            foreach (['first', 'last', 'only'] as $position) {
                $cases[$operation . ' unknown ' . $position] = [$operation, $position];
            }
        }
        return $cases;
    }

    /** @return array<string, array{string}> */
    public static function validOperations(): array
    {
        return ['query' => ['query'], 'update' => ['update'], 'delete' => ['delete']];
    }
}
