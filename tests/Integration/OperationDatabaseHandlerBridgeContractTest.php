<?php

namespace PHPNomad\MySql\Integration\Tests\Integration;

use PDO;
use PHPNomad\Cache\Exceptions\CachedItemNotFoundException;
use PHPNomad\Cache\Interfaces\CachePolicy;
use PHPNomad\Cache\Interfaces\CacheStrategy;
use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Factories\Index;
use PHPNomad\Database\Interfaces\CoordinatedQueryStrategy;
use PHPNomad\Database\Interfaces\DatabaseHandler;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Models\CoordinatedOperationResult;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Services\OperationDatabaseHandlerBridge;
use PHPNomad\Di\Container;
use PHPNomad\Events\Interfaces\Event;
use PHPNomad\Events\Interfaces\EventStrategy;
use PHPNomad\Loader\Bootstrapper;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Facades\Database;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\MySqlInitializer;
use PHPNomad\MySql\Integration\PdoCoordinationInitializer;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\RecordingLogger;
use PHPNomad\MySql\Integration\Tests\TestCase;
use ReflectionProperty;
use RuntimeException;

final class OperationDatabaseHandlerBridgeContractTest extends TestCase
{
    private PDO $pdo;
    private PDO $observer;
    private Container $container;
    private BridgeHostServices $host;
    private BridgeTable $parentTable;
    private BridgeTable $childTable;
    private DatabaseServiceProvider $provider;
    private CoordinatedQueryStrategy $coordinator;
    private OperationDatabaseHandlerBridge $bridge;
    private ReflectionProperty $facadeInstance;
    private mixed $previousFacade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->connection();
        $this->observer = $this->connection();
        $this->pdo->exec('DROP TABLE IF EXISTS nomad_query_coordination_child');
        $this->pdo->exec('DROP TABLE IF EXISTS nomad_query_coordination_parent');
        $this->pdo->exec(
            'CREATE TABLE nomad_query_coordination_parent ('
            . 'id INT NOT NULL PRIMARY KEY, value INT NOT NULL'
            . ') ENGINE=InnoDB'
        );
        $this->pdo->exec(
            'CREATE TABLE nomad_query_coordination_child ('
            . 'id INT NOT NULL PRIMARY KEY, value INT NOT NULL'
            . ') ENGINE=InnoDB'
        );
        $this->pdo->exec('INSERT INTO nomad_query_coordination_parent VALUES (1, 10)');
        $this->pdo->exec('INSERT INTO nomad_query_coordination_child VALUES (1, 20)');

        $this->host = new BridgeHostServices();
        $this->container = new Container();
        $connection = PdoConnection::fromPdo($this->pdo);
        $logger = new RecordingLogger();
        $this->container->bindSingletonFromFactory(PdoConnection::class, static fn (): PdoConnection => $connection);
        $this->container->bindSingletonFromFactory(LoggerStrategy::class, static fn (): LoggerStrategy => $logger);
        foreach ([CachePolicy::class, CacheStrategy::class, EventStrategy::class] as $contract) {
            $host = $this->host;
            $this->container->bindSingletonFromFactory($contract, static fn (): BridgeHostServices => $host);
        }
        (new Bootstrapper(
            $this->container,
            new MySqlInitializer(),
            new PdoCoordinationInitializer()
        ))->load();

        $this->provider = $this->container->get(DatabaseServiceProvider::class);
        $this->coordinator = $this->container->get(CoordinatedQueryStrategy::class);
        $this->bridge = $this->container->get(OperationDatabaseHandlerBridge::class);
        $this->parentTable = new BridgeTable('nomad_query_coordination_parent', 'parent');
        $this->childTable = new BridgeTable('nomad_query_coordination_child', 'child');

        $poison = $this->createMock(DatabaseStrategy::class);
        $poison->expects(self::never())->method('parse');
        $poison->expects(self::never())->method('query');
        $poisonContainer = new Container();
        $poisonContainer->bindSingletonFromFactory(DatabaseStrategy::class, static fn (): DatabaseStrategy => $poison);
        $this->facadeInstance = new ReflectionProperty(Database::class, 'instance');
        $this->facadeInstance->setAccessible(true);
        $this->previousFacade = $this->facadeInstance->getValue();
        $this->facadeInstance->setValue(null, new Database());
        Database::instance()->setContainer($poisonContainer);
    }

    protected function tearDown(): void
    {
        if (isset($this->facadeInstance)) {
            $this->facadeInstance->setValue(null, $this->previousFacade);
        }
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS nomad_query_coordination_child');
            $this->pdo->exec('DROP TABLE IF EXISTS nomad_query_coordination_parent');
        }
        parent::tearDown();
    }

    public function testCommitUsesFreshIndependentBuildersAndPublishesAfterCommit(): void
    {
        $parent = new BridgeDatabaseHandler($this->provider, $this->parentTable);
        $child = new BridgeDatabaseHandler($this->provider, $this->childTable);
        $this->observer->exec('UPDATE nomad_query_coordination_child SET value = 24 WHERE id = 1');
        $this->host->set($this->host->getCacheKey(['table' => $this->childTable->getName(), 'id' => 1]), 999, null);
        $this->host->resetObservations();
        $callbackCount = 0;

        $result = $this->bridge->coordinate(
            $this->coordinator,
            $this->parentTable,
            ['id' => 1],
            [$this->parentTable, $this->childTable],
            ['parent' => $parent, 'child' => $child],
            function (array $handlers, QueryStrategy $queryStrategy) use (&$callbackCount, $parent, $child): string {
                $callbackCount++;
                /** @var BridgeDatabaseHandler $localParent */
                $localParent = $handlers['parent'];
                /** @var BridgeDatabaseHandler $localChild */
                $localChild = $handlers['child'];
                self::assertNotSame($parent, $localParent);
                self::assertNotSame($child, $localChild);
                self::assertSame($queryStrategy, $localParent->getDatabaseServiceProvider()->queryStrategy);
                self::assertSame($queryStrategy, $localChild->getDatabaseServiceProvider()->queryStrategy);
                self::assertNotSame(
                    $parent->getDatabaseServiceProvider()->queryBuilder,
                    $localParent->getDatabaseServiceProvider()->queryBuilder
                );
                self::assertNotSame(
                    $localParent->getDatabaseServiceProvider()->queryBuilder,
                    $localChild->getDatabaseServiceProvider()->queryBuilder
                );
                self::assertNotSame(
                    $localParent->getDatabaseServiceProvider()->clauseBuilder,
                    $localChild->getDatabaseServiceProvider()->clauseBuilder
                );
                self::assertSame(24, $localChild->cachedValue(1));
                $localParent->updateValue(1, 11);
                $localChild->updateValue(1, 25);
                self::assertSame(11, $localParent->value(1));
                self::assertSame(25, $localChild->value(1));
                self::assertSame([], $this->host->deletedKeys);
                self::assertSame([], $this->host->broadcastEvents);

                return 'committed';
            }
        );

        self::assertInstanceOf(CoordinatedOperationResult::class, $result);
        self::assertSame('committed', $result->getValue());
        self::assertSame([], $result->getPublicationFailures());
        self::assertSame(1, $callbackCount);
        self::assertSame(11, $this->storedValue('nomad_query_coordination_parent'));
        self::assertSame(25, $this->storedValue('nomad_query_coordination_child'));
        self::assertCount(2, $this->host->deletedKeys);
        self::assertCount(2, $this->host->broadcastEvents);
    }

    public function testMultipleWritesAndReadAfterWriteRollBackWithoutReplayOrPublication(): void
    {
        $parent = new BridgeDatabaseHandler($this->provider, $this->parentTable);
        $child = new BridgeDatabaseHandler($this->provider, $this->childTable);
        $callbackCount = 0;

        try {
            $this->bridge->coordinate(
                $this->coordinator,
                $this->parentTable,
                ['id' => 1],
                [$this->parentTable, $this->childTable],
                [$parent, $child],
                function (array $handlers) use (&$callbackCount): void {
                    $callbackCount++;
                    /** @var BridgeDatabaseHandler $localParent */
                    $localParent = $handlers[0];
                    /** @var BridgeDatabaseHandler $localChild */
                    $localChild = $handlers[1];
                    $localParent->updateValue(1, 31);
                    $localChild->updateValue(1, 41);
                    $localChild->insertValue(2, 42);
                    self::assertSame(31, $localParent->value(1));
                    self::assertSame(41, $localChild->value(1));
                    self::assertSame(42, $localChild->value(2));
                    self::assertSame([], $this->host->deletedKeys);
                    self::assertSame([], $this->host->broadcastEvents);
                    throw new RuntimeException('roll back all writes');
                }
            );
            self::fail('The callback failure must propagate.');
        } catch (RuntimeException $failure) {
            self::assertSame('roll back all writes', $failure->getMessage());
        }

        self::assertSame(1, $callbackCount);
        self::assertSame(10, $this->storedValue('nomad_query_coordination_parent'));
        self::assertSame(20, $this->storedValue('nomad_query_coordination_child'));
        self::assertSame(0, $this->storedCount('nomad_query_coordination_child', 2));
        self::assertSame([], $this->host->deletedKeys);
        self::assertSame([], $this->host->broadcastEvents);
    }

    public function testUndeclaredAndExpiredHandlesRefuseBeforeExecution(): void
    {
        $handler = new BridgeDatabaseHandler($this->provider, $this->parentTable);
        $undeclared = new BridgeTable('nomad_query_coordination_child', 'child');
        $callbackCount = 0;

        try {
            $this->bridge->coordinate(
                $this->coordinator,
                $this->parentTable,
                ['id' => 1],
                [$this->parentTable],
                [$handler],
                function (array $handlers, QueryStrategy $queryStrategy) use (&$callbackCount, $undeclared): void {
                    $callbackCount++;
                    /** @var BridgeDatabaseHandler $localHandler */
                    $localHandler = $handlers[0];
                    $localHandler->updateValue(1, 99);
                    $queryStrategy->estimatedCount($undeclared);
                }
            );
            self::fail('An undeclared table must be rejected.');
        } catch (\InvalidArgumentException $expected) {
        }

        self::assertSame(1, $callbackCount);
        self::assertSame(10, $this->storedValue('nomad_query_coordination_parent'));

        $escaped = null;
        $this->bridge->coordinate(
            $this->coordinator,
            $this->parentTable,
            ['id' => 1],
            [$this->parentTable],
            [$handler],
            static function (array $handlers, QueryStrategy $queryStrategy) use (&$escaped): void {
                $escaped = $queryStrategy;
            }
        );

        $this->expectException(\PHPNomad\Database\Exceptions\InactiveDatabaseOperationException::class);
        $escaped->estimatedCount($this->parentTable);
    }

    private function connection(): PDO
    {
        $dsn = getenv('TEST_MYSQL_COORDINATION_DSN');
        if (!$dsn) {
            $this->markTestSkipped('An explicit isolated MySQL coordination DSN is required.');
        }

        return new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => true,
            PDO::ATTR_PERSISTENT => false,
        ]);
    }

    private function storedValue(string $table): int
    {
        $statement = $this->observer->query("SELECT value FROM `$table` WHERE id = 1");
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    private function storedCount(string $table, int $id): int
    {
        $statement = $this->observer->query("SELECT COUNT(*) FROM `$table` WHERE id = $id");
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }
}

final class BridgeDatabaseHandler implements DatabaseHandler
{
    private DatabaseServiceProvider $provider;
    private Table $table;

    public function __construct(DatabaseServiceProvider $provider, Table $table)
    {
        $this->provider = $provider;
        $this->table = $table;
    }

    public function getDatabaseTable(): Table
    {
        return $this->table;
    }

    public function getDatabaseServiceProvider(): DatabaseServiceProvider
    {
        return $this->provider;
    }

    public function cloneForOperation(DatabaseServiceProvider $serviceProvider): DatabaseHandler
    {
        $clone = clone $this;
        $clone->provider = $serviceProvider;

        return $clone;
    }

    public function value(int $id): int
    {
        $clause = (clone $this->provider->clauseBuilder)
            ->reset()
            ->useTable($this->table)
            ->where('id', '=', $id);
        $builder = $this->provider->queryBuilder
            ->reset()
            ->from($this->table)
            ->select('*')
            ->where($clause);
        $rows = $this->provider->queryStrategy->query($builder);

        return (int) $rows[0]['value'];
    }

    public function cachedValue(int $id): int
    {
        return (int) $this->provider->cacheableService->getWithCache(
            'read',
            ['table' => $this->table->getName(), 'id' => $id],
            fn (): int => $this->value($id)
        );
    }

    public function updateValue(int $id, int $value): void
    {
        $this->provider->queryStrategy->update($this->table, ['id' => $id], ['value' => $value]);
        $this->provider->cacheableService->delete(['table' => $this->table->getName(), 'id' => $id]);
        $this->provider->eventStrategy->broadcast(new BridgeRecordChanged($this->table->getName(), $id));
    }

    public function insertValue(int $id, int $value): void
    {
        $this->provider->queryStrategy->insert($this->table, ['id' => $id, 'value' => $value]);
        $this->provider->cacheableService->delete(['table' => $this->table->getName(), 'id' => $id]);
        $this->provider->eventStrategy->broadcast(new BridgeRecordChanged($this->table->getName(), $id));
    }
}

final class BridgeTable implements Table
{
    private string $name;
    private string $alias;

    public function __construct(string $name, string $alias)
    {
        $this->name = $name;
        $this->alias = $alias;
    }

    public function getName(): string
    {
        return $this->name;
    }
    public function getAlias(): string
    {
        return $this->alias;
    }
    public function getTableVersion(): string
    {
        return '1';
    }
    /** @return list<Column> */
    public function getColumns(): array
    {
        return [new Column('id', 'INT', null, 'PRIMARY KEY'), new Column('value', 'INT')];
    }
    /** @return list<Index> */
    public function getIndices(): array
    {
        return [];
    }
    public function getCharset(): ?string
    {
        return 'utf8mb4';
    }
    public function getCollation(): ?string
    {
        return 'utf8mb4_bin';
    }
    /** @return non-empty-list<string> */
    public function getFieldsForIdentity(): array
    {
        return ['id'];
    }
    public function getUnprefixedName(): string
    {
        return $this->name;
    }
    public function getSingularUnprefixedName(): string
    {
        return $this->name;
    }
}

final class BridgeRecordChanged implements Event
{
    public string $table;
    public int $id;

    public function __construct(string $table, int $id)
    {
        $this->table = $table;
        $this->id = $id;
    }

    public static function getId(): string
    {
        return 'mysql.operation.record.changed';
    }
}

final class BridgeHostServices implements CachePolicy, CacheStrategy, EventStrategy
{
    /** @var array<string, mixed> */
    private array $cache = [];
    /** @var list<string> */
    public array $deletedKeys = [];
    /** @var list<Event> */
    public array $broadcastEvents = [];

    public function resetObservations(): void
    {
        $this->deletedKeys = [];
        $this->broadcastEvents = [];
    }

    public function get(string $key): mixed
    {
        if (!$this->exists($key)) {
            throw new CachedItemNotFoundException();
        }

        return $this->cache[$key];
    }

    public function set(string $key, mixed $value, ?int $ttl): void
    {
        $this->cache[$key] = $value;
    }
    public function delete(string $key): void
    {
        $this->deletedKeys[] = $key;
        unset($this->cache[$key]);
    }
    public function clear(): void
    {
        $this->cache = [];
    }
    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->cache);
    }
    /** @param array<array-key, mixed> $context */
    public function getCacheKey(array $context): string
    {
        return serialize($context);
    }
    /** @param array<array-key, mixed> $context */
    public function shouldCache(string $operation, array $context = []): bool
    {
        return true;
    }
    /** @param array<array-key, mixed> $context */
    public function getTtl(array $context = []): ?int
    {
        return null;
    }
    /** @param array<array-key, mixed> $context */
    public function shouldInvalidate(string $operation, array $context = []): bool
    {
        return true;
    }
    public function broadcast(Event $event): void
    {
        $this->broadcastEvents[] = $event;
    }
    public function attach(string $event, callable $action, ?int $priority = null): void
    {
    }
    public function detach(string $event, callable $action, ?int $priority = null): void
    {
    }
}
