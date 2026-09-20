<?php

namespace PHPNomad\MySql\Integration\Tests\Integration;

use PDO;
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
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\MySqlInitializer;
use PHPNomad\MySql\Integration\Strategies\PdoDatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\FormattingHostServices;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\FormattingTable;
use PHPNomad\MySql\Integration\Tests\TestCase;

/** Observes rows through production query wiring and literal SQL controls. */
final class PredicateArgumentsDriverContractTest extends TestCase
{
    private PDO $pdo;
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        $dsn = getenv('TEST_MYSQL_DSN');
        if (!$dsn) {
            $this->markTestSkipped('An isolated MySQL test DSN is required.');
        }
        $this->pdo = new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => true,
        ]);
        $this->pdo->exec('CREATE TEMPORARY TABLE nomad_bound_formatting (id INT PRIMARY KEY, score INT, label VARCHAR(64))');
        $this->pdo->exec("INSERT INTO nomad_bound_formatting VALUES (1,30,'first'), (50,70,'target'), (60,NULL,'null')");
        $this->container = new Container();
        $connection = PdoConnection::fromPdo($this->pdo);
        $this->container->bindSingletonFromFactory(PdoConnection::class, static fn (): PdoConnection => $connection);
        $this->container->bindSingleton(PdoDatabaseStrategy::class, DatabaseStrategy::class);
        $host = new FormattingHostServices();
        foreach ([CachePolicy::class, CacheStrategy::class, EventStrategy::class] as $contract) {
            $this->container->bindSingletonFromFactory($contract, static fn (): FormattingHostServices => $host);
        }
        (new Bootstrapper($this->container, new MySqlInitializer()))->load();
    }

    protected function tearDown(): void
    {
        unset($this->container, $this->pdo);
        parent::tearDown();
    }

    /**
     * @dataProvider validConditions
     * @param list<mixed> $values
     */
    public function testEachOperandKeepsItsPositionBeforeALaterPredicate(string $entry, string $operator, array $values, string $literal): void
    {
        $table = new FormattingTable();
        $clause = (clone $this->container->get(ClauseBuilder::class))->useTable($table);
        $prefix = '';
        if ($entry === 'andWhere') {
            $clause->where('id', '>', 0);
            $prefix = 'id > 0 AND ';
        } elseif ($entry === 'orWhere') {
            $clause->where('id', '=', -1);
            $prefix = 'id = -1 OR ';
        }
        $clause->$entry('score', $operator, ...$values)->orWhere('id', '=', 50);
        $query = $this->container->get(QueryBuilder::class)->from($table)->select('*')->where($clause)->orderBy('id', 'ASC');
        $control = $this->pdo->query('SELECT * FROM nomad_bound_formatting WHERE ' . $prefix . $literal . ' OR id = 50 ORDER BY id');
        self::assertNotFalse($control);
        $expected = $control->fetchAll();
        self::assertNotEmpty($expected);
        self::assertSame($expected, $this->container->get(QueryStrategy::class)->query($query));
    }

    /**
     * @dataProvider invalidConditions
     * @param list<mixed> $values
     */
    public function testInvalidArityIsRefusedWithoutChangingExistingPredicates(string $entry, string $operator, array $values): void
    {
        $table = new FormattingTable();
        $clause = (clone $this->container->get(ClauseBuilder::class))->useTable($table)->where('id', '=', 50);
        $failure = null;
        try {
            $clause->$entry('score', $operator, ...$values);
        } catch (QueryBuilderException $caught) {
            $failure = $caught;
        }
        self::assertInstanceOf(QueryBuilderException::class, $failure);
        $clause->andWhere('score', '=', 70);
        $query = $this->container->get(QueryBuilder::class)->from($table)->select('*')->where($clause);
        self::assertSame([['id' => '50', 'score' => '70', 'label' => 'target']], $this->container->get(QueryStrategy::class)->query($query));
        $count = $this->pdo->query('SELECT COUNT(*) FROM nomad_bound_formatting');
        self::assertNotFalse($count);
        self::assertSame('3', (string) $count->fetchColumn());
    }

    public function testCompoundIdentityTuplesRetainAllColumnsAndValues(): void
    {
        $table = new FormattingTable();
        $clause = (clone $this->container->get(ClauseBuilder::class))->useTable($table)
            ->where(['id', 'score'], 'IN', ['id' => 1, 'score' => 30], ['id' => 50, 'score' => 70]);
        $query = $this->container->get(QueryBuilder::class)->from($table)->select('*')->where($clause)->orderBy('id', 'ASC');
        self::assertSame([
            ['id' => '1', 'score' => '30', 'label' => 'first'],
            ['id' => '50', 'score' => '70', 'label' => 'target'],
        ], $this->container->get(QueryStrategy::class)->query($query));
    }

    /** @dataProvider writes */
    public function testInvalidWriteIdentityIsReportedAsADatastoreFailure(string $operation): void
    {
        $failure = null;
        try {
            $strategy = $this->container->get(QueryStrategy::class);
            if ($operation === 'update') {
                $strategy->update(new FormattingTable(), ['missing' => 50], ['score' => 99]);
            } else {
                $strategy->delete(new FormattingTable(), ['missing' => 50]);
            }
        } catch (\Throwable $caught) {
            $failure = $caught;
        }
        self::assertInstanceOf(\PHPNomad\Datastore\Exceptions\DatastoreErrorException::class, $failure);
        self::assertInstanceOf(QueryBuilderException::class, $failure->getPrevious());
        $control = $this->pdo->query('SELECT id, score FROM nomad_bound_formatting ORDER BY id');
        self::assertNotFalse($control);
        self::assertSame([['id' => '1', 'score' => '30'], ['id' => '50', 'score' => '70'], ['id' => '60', 'score' => null]], $control->fetchAll());
    }

    /** @dataProvider literalIdentityTokens */
    public function testFormattedWriteIdentitiesRemainLiteralData(string $operation, string $token): void
    {
        $literal = 'literal ' . $token;
        $statement = $this->pdo->prepare('UPDATE nomad_bound_formatting SET label = ? WHERE id = 50');
        self::assertNotFalse($statement);
        $statement->execute([$literal]);
        $strategy = $this->container->get(QueryStrategy::class);
        self::assertInstanceOf(\PHPNomad\MySql\Integration\Strategies\QueryStrategy::class, $strategy);
        if ($operation === 'update') {
            $strategy->update(new FormattingTable(), ['label' => $literal], ['score' => 99, 'label' => 'updated ' . $token]);
            $control = $this->pdo->query('SELECT id, score, label FROM nomad_bound_formatting WHERE id = 50');
            self::assertNotFalse($control);
            self::assertSame([['id' => '50', 'score' => '99', 'label' => 'updated ' . $token]], $control->fetchAll());
        } else {
            $strategy->delete(new FormattingTable(), ['label' => $literal]);
            $control = $this->pdo->query('SELECT id FROM nomad_bound_formatting ORDER BY id');
            self::assertNotFalse($control);
            self::assertSame(['1', '60'], $control->fetchAll(PDO::FETCH_COLUMN));
        }
        $untouched = $this->pdo->query('SELECT score FROM nomad_bound_formatting WHERE id = 1');
        self::assertNotFalse($untouched);
        self::assertSame('30', (string) $untouched->fetchColumn());
    }

    /** @return array<string, array{string}> */
    public static function writes(): array
    {
        return ['update' => ['update'], 'delete' => ['delete']];
    }

    /** @return array<string, array{string, string}> */
    public static function literalIdentityTokens(): array
    {
        $cases = [];
        foreach (['update', 'delete'] as $operation) {
            foreach (['?s', '?n', '?i', '?a', '?u', '?p'] as $token) {
                $cases[$operation . ' ' . $token] = [$operation, $token];
            }
        }
        return $cases;
    }

    /** @return array<string, array{string, string, list<mixed>, string}> */
    public static function validConditions(): array
    {
        $profiles = [
            ['BETWEEN', [null, 20], 'score BETWEEN NULL AND 20'],
            ['BETWEEN', [20, null], 'score BETWEEN 20 AND NULL'],
            ['BETWEEN', [null, null], 'score BETWEEN NULL AND NULL'],
            ['BETWEEN', [20, 40], 'score BETWEEN 20 AND 40'],
            ['NOT BETWEEN', [null, 20], 'score NOT BETWEEN NULL AND 20'],
            ['NOT BETWEEN', [20, null], 'score NOT BETWEEN 20 AND NULL'],
            ['NOT BETWEEN', [null, null], 'score NOT BETWEEN NULL AND NULL'],
            ['NOT BETWEEN', [20, 40], 'score NOT BETWEEN 20 AND 40'],
            ['IN', [null, 30], 'score IN (NULL, 30)'],
            ['NOT IN', [30, null], 'score NOT IN (30, NULL)'],
            ['IN', [null], 'score IN (NULL)'],
            ['IN', [[null, 30]], 'score IN (NULL, 30)'],
            ['NOT IN', [[30, null]], 'score NOT IN (30, NULL)'],
            ['IN', [[]], 'score IN (NULL)'],
            ['NOT IN', [[]], 'score NOT IN (NULL)'],
            ['IS NULL', [], 'score IS NULL'],
            ['IS NOT NULL', [], 'score IS NOT NULL'],
            ['IS NULL', [null], 'score IS NULL'],
            ['IS NOT NULL', [null], 'score IS NOT NULL'],
        ];
        foreach (['=', '<>', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'] as $operator) {
            $profiles[] = [$operator, [null], 'score ' . $operator . ' NULL'];
            $profiles[] = [$operator, [30], 'score ' . $operator . ' 30'];
        }
        $cases = [];
        foreach (['where', 'andWhere', 'orWhere'] as $entry) {
            foreach ($profiles as $index => [$operator, $values, $literal]) {
                $cases[$entry . ' ' . $index . ' ' . $operator] = [$entry, $operator, $values, $literal];
            }
        }
        return $cases;
    }

    /** @return array<string, array{string, string, list<mixed>}> */
    public static function invalidConditions(): array
    {
        $profiles = [];
        foreach (['IN', 'NOT IN'] as $operator) {
            $profiles[] = [$operator, []];
        }
        foreach (['BETWEEN', 'NOT BETWEEN'] as $operator) {
            foreach ([[], [20], [20, 40, 999]] as $values) {
                $profiles[] = [$operator, $values];
            }
        }
        foreach (['=', '<>', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'] as $operator) {
            $profiles[] = [$operator, []];
            $profiles[] = [$operator, [30, 999]];
        }
        foreach (['IS NULL', 'IS NOT NULL'] as $operator) {
            $profiles[] = [$operator, [999]];
            $profiles[] = [$operator, [null, null]];
        }
        $cases = [];
        foreach (['where', 'andWhere', 'orWhere'] as $entry) {
            foreach ($profiles as $index => [$operator, $values]) {
                $cases[$entry . ' ' . $index . ' ' . $operator] = [$entry, $operator, $values];
            }
        }
        return $cases;
    }
}
