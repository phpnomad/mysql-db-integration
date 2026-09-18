<?php

namespace PHPNomad\MySql\Integration\Tests\Integration;

use PDO;
use PDOException;
use PHPNomad\Cache\Interfaces\CachePolicy;
use PHPNomad\Cache\Interfaces\CacheStrategy;
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

/** Production library boot and PDO/MySQL path with distinct session quoting. */
final class BoundFormattingDriverContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestIncomplete('Operation-bound formatting driver proof is pending.');
    }

    /** @dataProvider operations */
    public function testFormattingAndExecutionShareTheBoundSession(string $method, bool $boundUsesBackslashes): void
    {
        $global = $this->connection();
        $bound = $this->connection();
        $global->exec("SET SESSION sql_mode = '" . ($boundUsesBackslashes ? 'NO_BACKSLASH_ESCAPES' : '') . "'");
        $bound->exec("SET SESSION sql_mode = '" . ($boundUsesBackslashes ? '' : 'NO_BACKSLASH_ESCAPES') . "'");
        $value = "a\\b'c";
        self::assertNotSame($global->quote($value), $bound->quote($value), 'The two live sessions must format this value differently.');
        $bound->exec('CREATE TEMPORARY TABLE nomad_bound_formatting (id INT PRIMARY KEY, score INT, label VARCHAR(64)) ENGINE=InnoDB');
        $seed = $bound->prepare('INSERT INTO nomad_bound_formatting VALUES (?, ?, ?), (?, ?, ?)');
        self::assertNotFalse($seed);
        $seed->execute([7, 10, $value, 8, 20, 'other']);
        $globalContainer = $this->boot($global);
        $boundContainer = $this->boot($bound);
        $facade = new ReflectionProperty(Database::class, 'instance');
        $facade->setAccessible(true);
        $previous = $facade->getValue();
        $facade->setValue(null, new Database());
        Database::instance()->setContainer($globalContainer);
        $global->quoteCalls = $global->queryCalls = $bound->quoteCalls = $bound->queryCalls = 0;
        try {
            $strategy = $boundContainer->get(QueryStrategy::class);
            $table = new FormattingTable();
            if ($method === 'query') {
                $leaf = (clone $boundContainer->get(ClauseBuilder::class))->useTable($table)->where('label', '=', $value);
                $middle = (clone $boundContainer->get(ClauseBuilder::class))->useTable($table)->group('AND', $leaf);
                $root = (clone $boundContainer->get(ClauseBuilder::class))->useTable($table)->group('AND', $middle);
                $query = $boundContainer->get(QueryBuilder::class)->from($table)->select('*')->where($root);
                self::assertSame([['id' => '7', 'score' => '10', 'label' => $value]], $strategy->query($query));
            } elseif ($method === 'update') {
                $strategy->update($table, ['id' => 7], ['score' => 12, 'label' => $value . ' updated']);
            } else {
                $strategy->delete($table, ['id' => 7]);
            }
            self::assertSame(0, $global->quoteCalls);
            self::assertSame(0, $global->queryCalls);
            self::assertSame($method === 'update' ? 3 : 1, $bound->quoteCalls);
            self::assertSame(1, $bound->queryCalls);
            $rows = $bound->query('SELECT * FROM nomad_bound_formatting ORDER BY id');
            self::assertNotFalse($rows);
            $expected = $method === 'delete' ? [] : [[
                'id' => '7', 'score' => $method === 'update' ? '12' : '10',
                'label' => $method === 'update' ? $value . ' updated' : $value,
            ]];
            $expected[] = ['id' => '8', 'score' => '20', 'label' => 'other'];
            self::assertSame($expected, $rows->fetchAll());
        } finally {
            $facade->setValue(null, $previous);
        }
    }

    private function boot(FormattingPdo $pdo): Container
    {
        $container = new Container();
        $connection = PdoConnection::fromPdo($pdo);
        $container->bindSingletonFromFactory(PdoConnection::class, static fn(): PdoConnection => $connection);
        $container->bindSingleton(PdoDatabaseStrategy::class, DatabaseStrategy::class);
        $services = new FormattingHostServices();
        foreach ([CachePolicy::class, CacheStrategy::class, EventStrategy::class] as $contract) {
            $container->bindSingletonFromFactory($contract, static fn(): FormattingHostServices => $services);
        }
        (new Bootstrapper($container, new MySqlInitializer()))->load();
        return $container;
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

    /** @return array<string, array{string, bool}> */
    public static function operations(): array
    {
        $cases = [];
        foreach (['query', 'update', 'delete'] as $method) {
            $cases[$method . ' backslashes'] = [$method, true];
            $cases[$method . ' no backslashes'] = [$method, false];
        }
        return $cases;
    }
}
