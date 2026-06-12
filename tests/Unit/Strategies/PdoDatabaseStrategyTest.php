<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Strategies;

use PDO;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Strategies\PdoDatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\TestCase;

/**
 * Pins the SafeMySQL-compatible placeholder language the query builders
 * depend on (?n ?s ?i ?a ?u ?p plus the row-tuple argument preprocessing),
 * now backed by PDO. Runs against a real MySQL when reachable
 * (TEST_MYSQL_DSN/USER/PASS, defaulting to 127.0.0.1:3308) and skips
 * otherwise.
 */
class PdoDatabaseStrategyTest extends TestCase
{
    private function makeStrategy(): PdoDatabaseStrategy
    {
        $dsn = getenv('TEST_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3308;charset=utf8mb4';
        $user = getenv('TEST_MYSQL_USER') ?: 'root';
        $pass = getenv('TEST_MYSQL_PASS') ?: 'root';

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => true,
            ]);
        } catch (\PDOException $e) {
            $this->markTestSkipped('No MySQL server reachable for PDO strategy tests: ' . $e->getMessage());
        }

        $pdo->exec('CREATE DATABASE IF NOT EXISTS phpnomad_pdo_test');
        $pdo->exec('USE phpnomad_pdo_test');

        return new PdoDatabaseStrategy(PdoConnection::fromPdo($pdo));
    }

    /**
     * @dataProvider placeholderProvider
     */
    public function testParse(string $query, array $args, string $expected): void
    {
        $this->assertSame($expected, $this->makeStrategy()->parse($query, ...$args));
    }

    public static function placeholderProvider(): array
    {
        return [
            'identifier' => ["SELECT * FROM ?n", ['users'], "SELECT * FROM `users`"],
            'identifier with embedded backtick' => ["SELECT ?n", ['we`ird'], "SELECT `we``ird`"],
            'string' => ["WHERE name = ?s", ['Alex'], "WHERE name = 'Alex'"],
            'string null' => ["SET ref = ?s", [null], "SET ref = NULL"],
            'string escapes quotes' => ["WHERE name = ?s", ["O'Neil"], "WHERE name = 'O\\'Neil'"],
            'integer' => ["LIMIT ?i", ['25'], "LIMIT 25"],
            'integer null' => ["SET count = ?i", [null], "SET count = NULL"],
            'integer truncates float' => ["LIMIT ?i", [3.9], "LIMIT 4"],
            'in list' => ["WHERE id IN(?a)", [[1, 'two', null]], "WHERE id IN('1','two',NULL)"],
            'in list from scalar wraps' => ["WHERE id IN(?a)", [7], "WHERE id IN('7')"],
            'set clause' => ["SET ?u", [[]], "SET "],
            'raw' => ["ORDER BY ?p", ['created DESC'], "ORDER BY created DESC"],
            'list of rows becomes tuples' => [
                "VALUES ?a",
                [[['a' => 1, 'b' => 'x'], ['a' => 2, 'b' => null]]],
                "VALUES (1, 'x'), (2, NULL)",
            ],
            'associative array becomes single tuple' => [
                "VALUES ?a",
                [['a' => 1, 'b' => 'x']],
                "VALUES (1, 'x')",
            ],
            'multiple placeholders consume in order' => [
                "SELECT ?n FROM ?n WHERE id = ?i AND name = ?s",
                ['col', 'tbl', 5, 'x'],
                "SELECT `col` FROM `tbl` WHERE id = 5 AND name = 'x'",
            ],
        ];
    }

    public function testParseSetClauseWithPairs(): void
    {
        $result = $this->makeStrategy()->parse("UPDATE t SET ?u", ['name' => 'Alex', 'age' => null]);

        $this->assertSame("UPDATE t SET `name`='Alex', `age`=NULL", $result);
    }

    public function testQueryReturnsRowsForSelect(): void
    {
        $strategy = $this->makeStrategy();
        $strategy->query('CREATE TEMPORARY TABLE t (id INTEGER, name TEXT)');
        $strategy->query("INSERT INTO t VALUES (1, 'a'), (2, 'b')");

        $rows = $strategy->query('SELECT * FROM t ORDER BY id');

        // Stringified fetches pin parity with the mysqli-based backend.
        $this->assertSame([['id' => '1', 'name' => 'a'], ['id' => '2', 'name' => 'b']], $rows);
    }

    public function testQueryReturnsAffectedRowCountForWrites(): void
    {
        $strategy = $this->makeStrategy();
        $strategy->query('CREATE TEMPORARY TABLE t (id INTEGER)');
        $strategy->query('INSERT INTO t VALUES (1), (2), (3)');

        $affected = $strategy->query('DELETE FROM t WHERE id > 1');

        $this->assertSame(2, $affected);
    }

    public function testQueryFailureThrowsStableMessage(): void
    {
        $this->expectException(DatastoreErrorException::class);
        $this->expectExceptionMessage('Failed to execute query.');

        $this->makeStrategy()->query('SELECT * FROM missing_table');
    }
}
