<?php

namespace PHPNomad\MySql\Integration\Tests\Integration;

use PDO;
use PDOException;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Strategies\PdoDatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\QueryRecordingPdo;
use PHPNomad\MySql\Integration\Tests\TestCase;

/** Real driver contract. Full application binding proof is a separate gate. */
final class PdoQueryFailureContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestIncomplete('PDO query failure implementation assignment is pending.');
    }

    /** @dataProvider driverModes */
    public function testQueryFailurePreservesDriverDetailsWithoutExposingSql(int $mode): void
    {
        $pdo = $this->connection($mode);
        $pdo->exec('CREATE TEMPORARY TABLE nomad_failure_contract (id INT PRIMARY KEY, secret VARCHAR(100))');
        $pdo->exec("INSERT INTO nomad_failure_contract VALUES (1, 'first')");
        $strategy = new PdoDatabaseStrategy(PdoConnection::fromPdo($pdo));

        try {
            $strategy->query("INSERT INTO nomad_failure_contract VALUES (1, 'sensitive-contract-value')");
            self::fail('The duplicate write must fail in both driver modes.');
        } catch (DatastoreErrorException $failure) {
            self::assertSame('Failed to execute query.', $failure->getMessage());
            self::assertSame(500, $failure->getCode());
            $cause = $failure->getPrevious();
            self::assertInstanceOf(PDOException::class, $cause);
            self::assertIsArray($cause->errorInfo);
            self::assertSame('23000', $cause->errorInfo[0]);
            self::assertSame(1062, $cause->errorInfo[1]);
            self::assertNotEmpty($cause->errorInfo[2]);
            self::assertSame($pdo->lastQueryErrorInfo, $cause->errorInfo);
            if ($mode === PDO::ERRMODE_EXCEPTION) {
                self::assertSame($pdo->lastQueryFailure, $cause);
            }
        }

        self::assertSame(1, $pdo->queryCalls);
        self::assertSame([['id' => '1', 'secret' => 'first']], $strategy->query('SELECT * FROM nomad_failure_contract'));
        self::assertSame(2, $pdo->queryCalls);
        self::assertSame([$mode, $mode], $pdo->queryModes);
        self::assertSame($mode, $pdo->getAttribute(PDO::ATTR_ERRMODE));
    }

    /** @dataProvider driverModes */
    public function testQueryFailureCannotLeakSqlIntoThePublicMessage(int $mode): void
    {
        $pdo = $this->connection($mode);
        $strategy = new PdoDatabaseStrategy(PdoConnection::fromPdo($pdo));

        try {
            $strategy->query('sensitive_contract_identifier SELECT');
            self::fail('The invalid SQL must fail.');
        } catch (DatastoreErrorException $failure) {
            self::assertSame('Failed to execute query.', $failure->getMessage());
            self::assertSame(500, $failure->getCode());
            $cause = $failure->getPrevious();
            self::assertInstanceOf(PDOException::class, $cause);
            self::assertIsArray($cause->errorInfo);
            self::assertSame('42000', $cause->errorInfo[0]);
            self::assertSame(1064, $cause->errorInfo[1]);
            self::assertStringContainsString('sensitive_contract_identifier', $cause->errorInfo[2]);
            self::assertSame($pdo->lastQueryErrorInfo, $cause->errorInfo);
            if ($mode === PDO::ERRMODE_EXCEPTION) {
                self::assertSame($pdo->lastQueryFailure, $cause);
            }
        }

        self::assertSame(1, $pdo->queryCalls);
        self::assertSame([$mode], $pdo->queryModes);
        self::assertSame($mode, $pdo->getAttribute(PDO::ATTR_ERRMODE));
    }

    /** @dataProvider driverModes */
    public function testSuccessfulQueriesAndZeroAffectedRowsRemainValid(int $mode): void
    {
        $pdo = $this->connection($mode);
        $strategy = new PdoDatabaseStrategy(PdoConnection::fromPdo($pdo));
        self::assertSame(0, $strategy->query('CREATE TEMPORARY TABLE nomad_success_contract (id INT PRIMARY KEY, score INT)'));
        self::assertSame(1, $pdo->queryCalls);

        self::assertSame(1, $strategy->query('INSERT INTO nomad_success_contract VALUES (1, 10)'));
        self::assertSame(2, $pdo->queryCalls);
        self::assertSame(0, $strategy->query('UPDATE nomad_success_contract SET score = 10 WHERE id = 1'));
        self::assertSame(3, $pdo->queryCalls);
        self::assertSame(0, $strategy->query('DELETE FROM nomad_success_contract WHERE id = 2'));
        self::assertSame(4, $pdo->queryCalls);
        self::assertSame([['id' => '1', 'score' => '10']], $strategy->query('SELECT * FROM nomad_success_contract'));
        self::assertSame(5, $pdo->queryCalls);
        self::assertSame([], $strategy->query('SELECT * FROM nomad_success_contract WHERE id = 2'));
        self::assertSame(6, $pdo->queryCalls);
        self::assertSame(array_fill(0, 6, $mode), $pdo->queryModes);
        self::assertSame($mode, $pdo->getAttribute(PDO::ATTR_ERRMODE));
    }

    /** @return array<string, array{int}> */
    public static function driverModes(): array
    {
        return ['exception mode' => [PDO::ERRMODE_EXCEPTION], 'silent mode' => [PDO::ERRMODE_SILENT]];
    }

    public function testWarningModePreservesHostDiagnosticsAndNormalizesTheFalseResult(): void
    {
        $pdo = $this->connection(PDO::ERRMODE_WARNING);
        $strategy = new PdoDatabaseStrategy(PdoConnection::fromPdo($pdo));
        $warnings = [];
        $hostHandler = static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = [$severity, $message];
            return true;
        };
        set_error_handler($hostHandler);
        try {
            try {
                $strategy->query('sensitive_contract_identifier SELECT');
                self::fail('Warning mode must normalize the false result after the host receives its warning.');
            } catch (DatastoreErrorException $failure) {
                self::assertSame('Failed to execute query.', $failure->getMessage());
                self::assertSame(500, $failure->getCode());
                $cause = $failure->getPrevious();
                self::assertInstanceOf(PDOException::class, $cause);
                self::assertSame($pdo->lastQueryErrorInfo, $cause->errorInfo);
                self::assertSame(['42000', 1064], array_slice($pdo->lastQueryErrorInfo, 0, 2));
            }
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $warnings);
        self::assertSame(E_WARNING, $warnings[0][0]);
        self::assertStringContainsString('sensitive_contract_identifier', $warnings[0][1]);
        self::assertSame(1, $pdo->queryCalls);
        self::assertSame($hostHandler, $pdo->handlerAtLastQuery);
        self::assertSame([PDO::ERRMODE_WARNING], $pdo->queryModes);
        self::assertSame(PDO::ERRMODE_WARNING, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        self::assertSame([['value' => '1']], $strategy->query('SELECT 1 AS value'));
        self::assertSame(2, $pdo->queryCalls);
        self::assertSame([PDO::ERRMODE_WARNING, PDO::ERRMODE_WARNING], $pdo->queryModes);
    }

    public function testWarningModeRetainsAHostHandlerThatThrows(): void
    {
        $pdo = $this->connection(PDO::ERRMODE_WARNING);
        $strategy = new PdoDatabaseStrategy(PdoConnection::fromPdo($pdo));
        $hostFailure = new \ErrorException('Host warning handler sentinel');
        $caught = null;
        $hostHandler = static function () use ($hostFailure): void {
            throw $hostFailure;
        };
        set_error_handler($hostHandler);
        try {
            try {
                $this->queryThroughThrowingHostHandler($strategy);
            } catch (\ErrorException $failure) {
                $caught = $failure;
            }
        } finally {
            restore_error_handler();
        }

        self::assertSame($hostFailure, $caught);
        self::assertSame(1, $pdo->queryCalls);
        self::assertSame($hostHandler, $pdo->handlerAtLastQuery);
        self::assertSame([PDO::ERRMODE_WARNING], $pdo->queryModes);
        self::assertSame(PDO::ERRMODE_WARNING, $pdo->getAttribute(PDO::ATTR_ERRMODE));
    }

    /** @throws \ErrorException The installed host warning handler throws this exception. */
    private function queryThroughThrowingHostHandler(PdoDatabaseStrategy $strategy): void
    {
        $strategy->query('sensitive_contract_identifier SELECT');
    }

    private function connection(int $mode): QueryRecordingPdo
    {
        $dsn = getenv('TEST_MYSQL_COORDINATION_DSN');
        if (!$dsn) {
            $this->markTestSkipped('TEST_MYSQL_COORDINATION_DSN must name an explicitly owned schema.');
        }

        try {
            return new QueryRecordingPdo(
                $dsn,
                getenv('TEST_MYSQL_USER') ?: 'root',
                getenv('TEST_MYSQL_PASS') ?: 'root',
                [
                    PDO::ATTR_ERRMODE => $mode,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_STRINGIFY_FETCHES => true,
                    PDO::ATTR_PERSISTENT => false,
                ]
            );
        } catch (PDOException $failure) {
            $this->markTestSkipped('The explicitly configured coordination test database is unavailable.');
        }
    }
}
