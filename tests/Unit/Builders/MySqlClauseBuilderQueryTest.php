<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Builders;

use Mockery;
use PDO;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Di\Container;
use PHPNomad\MySql\Integration\Builders\MySqlClauseBuilder;
use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Facades\Database;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Strategies\PdoDatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\TestCase;

/**
 * Runs grouped conditions against a real MySQL when reachable
 * (TEST_MYSQL_DSN/USER/PASS, defaulting to 127.0.0.1:3308) and skips
 * otherwise.
 *
 * @covers \PHPNomad\MySql\Integration\Builders\MySqlClauseBuilder
 */
class MySqlClauseBuilderQueryTest extends TestCase
{
    private PdoDatabaseStrategy $database;
    private Table $table;

    protected function setUp(): void
    {
        parent::setUp();

        $dsn = getenv('TEST_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3308;charset=utf8mb4';

        try {
            $pdo = new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: 'root', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => true,
            ]);
        } catch (\PDOException $e) {
            $this->markTestSkipped('No MySQL server reachable for clause builder query tests: ' . $e->getMessage());
        }

        $pdo->exec('CREATE DATABASE IF NOT EXISTS phpnomad_pdo_test');
        $pdo->exec('USE phpnomad_pdo_test');
        $pdo->exec('DROP TABLE IF EXISTS clause_rows');
        $pdo->exec('CREATE TABLE clause_rows (id INT PRIMARY KEY, status VARCHAR(64), name VARCHAR(64))');
        $pdo->exec(
            "INSERT INTO clause_rows VALUES (1, '__NOMADIC_SUBQUERY__2', 'b'), (2, 'other', 'b'), (3, 'other', 'c')"
        );

        $this->database = new PdoDatabaseStrategy(PdoConnection::fromPdo($pdo));
        $container = new Container();
        $container->bindSingletonFromFactory(DatabaseStrategy::class, fn () => $this->database);
        Database::instance()->setContainer($container);

        $table = Mockery::mock(Table::class);
        $table->shouldReceive('getAlias')->andReturn('cr');
        $table->shouldReceive('getColumns')->andReturn([
            new Column('id', 'INT'),
            new Column('status', 'VARCHAR'),
            new Column('name', 'VARCHAR'),
        ]);
        $this->table = $table;
    }

    private function builder(): MySqlClauseBuilder
    {
        return (new MySqlClauseBuilder())->useTable($this->table);
    }

    private function ids(string $where): array
    {
        $rows = $this->database->query("SELECT cr.id FROM clause_rows AS cr WHERE {$where} ORDER BY cr.id");

        return array_map(fn ($row) => (int) $row['id'], $rows);
    }

    public function test_marker_text_value_matches_only_the_row_holding_that_text(): void
    {
        $where = $this->builder()
            ->andGroup('AND', $this->builder()->where('status', '=', '__NOMADIC_SUBQUERY__2'))
            ->andGroup('AND', $this->builder()->where('name', '=', 'b'))
            ->build();

        $this->assertSame([1], $this->ids($where));
    }

    public function test_eleven_groups_match_the_expected_rows(): void
    {
        $builder = $this->builder();

        foreach (range(1, 11) as $id) {
            $builder->orGroup('AND', $this->builder()->where('id', '=', $id));
        }

        $this->assertSame([1, 2, 3], $this->ids($builder->build()));
    }
}
