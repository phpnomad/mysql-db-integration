<?php

namespace PHPNomad\MySql\Integration\Tests\Integration;

use PDO;
use PDOException;
use PHPNomad\Cache\Interfaces\CachePolicy;
use PHPNomad\Cache\Interfaces\CacheStrategy;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Di\Container;
use PHPNomad\Events\Interfaces\EventStrategy;
use PHPNomad\Loader\Bootstrapper;
use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\MySqlInitializer;
use PHPNomad\MySql\Integration\Strategies\PdoDatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\FormattingHostServices;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\InsertIdentifierTable;
use PHPNomad\MySql\Integration\Tests\TestCase;

/** Production boot, literal identifiers, and independently visible ordinary inserts. */
final class InsertIdentifierDriverContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestIncomplete('Live insert column identifier formatting is pending.');
    }

    /** @dataProvider literalColumns */
    public function testLiteralColumnNamesPreserveTheirValues(string $column, bool $noBackslashes): void
    {
        $this->withDatabase($noBackslashes, static function (QueryStrategy $strategy, DatabaseStrategy $backend, PDO $observer, InsertIdentifierTable $table) use ($column): void {
            $value = "a\\b'c ?n";
            self::assertSame(['id' => 7], $strategy->insert($table, ['id' => 7, $column => $value]));
            $rows = $observer->query('SELECT id, `' . str_replace('`', '``', $column) . '` AS value FROM `' . $table->getName() . '`');
            self::assertNotFalse($rows);
            self::assertSame([['id' => '7', 'value' => $value]], $rows->fetchAll());
        });
    }

    /** @dataProvider quotingModes */
    public function testSyntaxShapedKeyCannotBecomeAnInsertStatement(bool $noBackslashes): void
    {
        $this->withDatabase($noBackslashes, static function (QueryStrategy $strategy, DatabaseStrategy $backend, PDO $observer, InsertIdentifierTable $table): void {
            $key = 'id) VALUES (99) -- ';
            $unsafe = $backend->parse('INSERT INTO ?n (' . $key . ') VALUES (?s)', $table->getName(), 'ignored');
            $backend->query($unsafe);
            $witness = $observer->query('SELECT id FROM `' . $table->getName() . '`');
            self::assertNotFalse($witness);
            self::assertSame([['id' => '99']], $witness->fetchAll(), 'The unquoted key must demonstrate a real write hazard.');
            self::assertSame(1, $observer->exec('DELETE FROM `' . $table->getName() . '` WHERE id = 99'));
            $caught = null;
            try {
                $strategy->insert($table, [$key => 'ignored']);
            } catch (DatastoreErrorException $failure) {
                $caught = $failure;
            }
            self::assertInstanceOf(DatastoreErrorException::class, $caught);
            self::assertInstanceOf(PDOException::class, $caught->getPrevious());
            $driverDetails = $caught->getPrevious()->errorInfo;
            self::assertIsArray($driverDetails);
            self::assertArrayHasKey(1, $driverDetails);
            self::assertSame(1054, $driverDetails[1]);
            $rows = $observer->query('SELECT id FROM `' . $table->getName() . '`');
            self::assertNotFalse($rows);
            self::assertSame([], $rows->fetchAll(), 'A syntax-shaped key must not insert a row.');
        });
    }

    /** @dataProvider quotingModes */
    public function testEmptyInsertStillCreatesOneDefaultRow(bool $noBackslashes): void
    {
        $this->withDatabase($noBackslashes, static function (QueryStrategy $strategy, DatabaseStrategy $backend, PDO $observer, InsertIdentifierTable $table): void {
            self::assertSame(['id' => 1], $strategy->insert($table, []));
            $rows = $observer->query('SELECT id, label FROM `' . $table->getName() . '`');
            self::assertNotFalse($rows);
            self::assertSame([['id' => '1', 'label' => null]], $rows->fetchAll());
        });
    }

    /** @param callable(QueryStrategy, DatabaseStrategy, PDO, InsertIdentifierTable): void $assertion */
    private function withDatabase(bool $noBackslashes, callable $assertion): void
    {
        $primary = $this->connection();
        $observer = $this->connection();
        $primary->exec("SET SESSION sql_mode = '" . ($noBackslashes ? 'NO_BACKSLASH_ESCAPES' : '') . "'");
        $table = new InsertIdentifierTable('nomad_insert_ids_' . bin2hex(random_bytes(6)));
        $observer->exec('CREATE TABLE `' . $table->getName() . '` (
            id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(64), `order` VARCHAR(64),
            `tick``label` VARCHAR(64), `?s` VARCHAR(64), `with.dot` VARCHAR(64)
        ) ENGINE=InnoDB');
        try {
            $container = new Container();
            $connection = PdoConnection::fromPdo($primary);
            $container->bindSingletonFromFactory(PdoConnection::class, static fn (): PdoConnection => $connection);
            $container->bindSingleton(PdoDatabaseStrategy::class, DatabaseStrategy::class);
            $host = new FormattingHostServices();
            foreach ([CachePolicy::class, CacheStrategy::class, EventStrategy::class] as $contract) {
                $container->bindSingletonFromFactory($contract, static fn (): FormattingHostServices => $host);
            }
            (new Bootstrapper($container, new MySqlInitializer()))->load();
            $assertion($container->get(QueryStrategy::class), $container->get(DatabaseStrategy::class), $observer, $table);
        } finally {
            $observer->exec('DROP TABLE `' . $table->getName() . '`');
        }
    }

    private function connection(): PDO
    {
        $dsn = getenv('TEST_MYSQL_DSN');
        if (!$dsn) {
            $this->markTestSkipped('An explicit isolated MySQL test DSN is required.');
        }
        return new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => true,
            PDO::ATTR_PERSISTENT => false,
        ]);
    }

    /** @return array<string, array{string, bool}> */
    public static function literalColumns(): array
    {
        $cases = [];
        foreach (['label', 'order', 'tick`label', '?s', 'with.dot'] as $column) {
            $cases[$column . ' backslashes'] = [$column, false];
            $cases[$column . ' no backslashes'] = [$column, true];
        }
        return $cases;
    }

    /** @return array<string, array{bool}> */
    public static function quotingModes(): array
    {
        return ['backslashes' => [false], 'no backslashes' => [true]];
    }
}
