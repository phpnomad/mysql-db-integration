<?php

namespace PHPNomad\MySql\Integration\Tests\Integration;

use Closure;
use PDO;
use PDOException;
use PHPNomad\Database\Exceptions\CoordinatedOperationCleanupFailedException;
use PHPNomad\Database\Exceptions\CoordinatedOperationConflictException;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Strategies\PdoCoordinatedDatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\InactiveCoordinationRollbackPdo;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\InactiveQueryRollbackPdo;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\OwnedPdoCoordinationContractCase;
use RuntimeException;
use Throwable;

/** Ownership loss cannot turn already committed effects into retryable failures. */
final class PdoCoordinationOwnershipContractTest extends OwnedPdoCoordinationContractCase
{
    /** @dataProvider inactiveCoordinationBoundaries */
    public function testInactiveOwnedCoordinationStatementFailureRemainsRetryEligible(string $boundary, int $mode): void
    {
        $pdo = $this->connect(InactiveCoordinationRollbackPdo::class);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, $mode);
        $pdo->arm($boundary, $this->effects->getName());
        $this->usePrimary($pdo);
        $calls = 0;
        $caught = null;
        try {
            $this->coordinate(static function () use (&$calls): void { $calls++; });
        } catch (Throwable $failure) {
            $caught = $failure;
        }
        self::assertInstanceOf(CoordinatedOperationConflictException::class, $caught);
        $cause = $caught->getPrevious();
        self::assertInstanceOf(PDOException::class, $cause);
        self::assertSame(['40001', 1213, 'Injected coordination failure after whole rollback'], $cause->errorInfo);
        if ($mode === PDO::ERRMODE_EXCEPTION) {
            self::assertSame($pdo->faultCause, $cause);
        }
        self::assertSame(12, $pdo->visibleBeforeAbort, 'The real transaction must contain a write before the injected abort.');
        self::assertTrue($pdo->inactiveAtFailure, 'The driver must already be inactive before operation-owner cleanup.');
        self::assertSame(1, $pdo->injectedFailures);
        self::assertSame(0, $calls, 'Coordination failure must not invoke or replay the callback.');
        self::assertFalse($pdo->inTransaction());
        self::assertSame([], $this->visibleEffects());
        $this->assertFailureLog('coordination', 'rolled_back', true, PDOException::class, '40001', 1213);
        self::assertSame([['id' => '42']], $this->strategy->query('SELECT 42 AS id'));
    }

    /** @dataProvider inactiveCoordinationBoundaries */
    public function testInternalFailureEnteredWithoutOwnershipCannotProveRollback(string $boundary, int $mode): void
    {
        $pdo = $this->connect(InactiveCoordinationRollbackPdo::class);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, $mode);
        $pdo->requireOwnedEntry = false;
        $hookCalls = 0;
        $this->useParticipantValidationHook($pdo, function () use (&$hookCalls, $pdo, $boundary): void {
            $hookCalls++;
            $this->primary->exec('INSERT INTO `' . $this->effects->getName() . '` VALUES (1, 99)');
            $this->primary->commit();
            $pdo->arm($boundary, $this->effects->getName());
        });
        $calls = 0;
        $caught = null;
        try {
            $this->coordinate(static function () use (&$calls): void { $calls++; });
        } catch (Throwable $failure) {
            $caught = $failure;
        }
        self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $caught);
        self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $caught);
        $original = $caught->getOperationFailure();
        self::assertInstanceOf(DatastoreErrorException::class, $caught->getPrevious());
        self::assertSame(1, $hookCalls, 'Participant validation must create the committed ownership-loss hazard.');
        self::assertSame(0, $calls);
        self::assertFalse($pdo->inTransaction());
        self::assertSame([['id' => '1', 'score' => '99']], $this->visibleEffects());
        self::assertLessThanOrEqual(1, $pdo->injectedFailures);
        if ($pdo->injectedFailures === 1) {
            self::assertTrue($pdo->inactiveAtFailure);
            self::assertInstanceOf(PDOException::class, $original);
            self::assertSame(['40001', 1213, 'Injected coordination failure without owned entry'], $original->errorInfo);
            if ($mode === PDO::ERRMODE_EXCEPTION) {
                self::assertSame($pdo->faultCause, $original);
            }
        } else {
            // A stricter adapter may refuse before issuing the unowned statement.
            self::assertInstanceOf(DatastoreErrorException::class, $original);
            self::assertNull($pdo->faultCause);
        }
        $this->assertFailureLog('rollback', 'unknown', false, DatastoreErrorException::class, priorFailure: [
            'phase' => 'coordination', 'causeClass' => get_class($original),
            'sqlState' => $pdo->injectedFailures === 1 ? '40001' : null,
            'driverCode' => $pdo->injectedFailures === 1 ? 1213 : null,
        ]);
    }

    /** @dataProvider inactiveCoordinationBoundaries */
    public function testPriorCoordinationEvidenceCannotAuthorizeTheSameFailureAfterALaterCommit(string $boundary, int $mode): void
    {
        $pdo = $this->connect(InactiveCoordinationRollbackPdo::class);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, $mode);
        $pdo->arm($boundary, $this->effects->getName());
        $this->usePrimary($pdo);
        $firstCalls = 0;
        $original = null;
        try {
            $this->coordinate(static function () use (&$firstCalls): void { $firstCalls++; });
        } catch (CoordinatedOperationConflictException $failure) {
            $original = $failure->getPrevious();
        }
        self::assertInstanceOf(PDOException::class, $original);
        self::assertSame(['40001', 1213, 'Injected coordination failure after whole rollback'], $original->errorInfo);
        if ($mode === PDO::ERRMODE_EXCEPTION) {
            self::assertSame($pdo->faultCause, $original);
        }
        self::assertSame(12, $pdo->visibleBeforeAbort);
        self::assertTrue($pdo->inactiveAtFailure);
        self::assertSame(0, $firstCalls);
        self::assertSame([], $this->visibleEffects());
        $this->assertFailureLog('coordination', 'rolled_back', true, PDOException::class, '40001', 1213);
        $this->logger->entries = [];
        $hookCalls = 0;
        $this->useParticipantValidationHook($pdo, function () use ($original, &$hookCalls): void {
            $hookCalls++;
            $this->primary->exec('INSERT INTO `' . $this->effects->getName() . '` VALUES (1, 99)');
            $this->primary->commit();
            throw $original;
        });
        $secondCalls = 0;
        $caught = null;
        try {
            $this->coordinate(static function () use (&$secondCalls): void { $secondCalls++; });
        } catch (Throwable $failure) {
            $caught = $failure;
        }
        self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $caught);
        self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $caught);
        self::assertSame($original, $caught->getOperationFailure());
        self::assertInstanceOf(DatastoreErrorException::class, $caught->getPrevious());
        self::assertSame(1, $hookCalls);
        self::assertSame(0, $secondCalls);
        self::assertSame(1, $pdo->injectedFailures);
        self::assertFalse($pdo->inTransaction());
        self::assertSame([['id' => '1', 'score' => '99']], $this->visibleEffects());
        $this->assertFailureLog('rollback', 'unknown', false, DatastoreErrorException::class, priorFailure: [
            'phase' => 'coordination', 'causeClass' => PDOException::class, 'sqlState' => '40001', 'driverCode' => 1213,
        ]);
    }

    /** @dataProvider allOwnershipLoss */
    public function testCoordinationPhaseAloneCannotAuthorizeADeadlockShapedValidationFailure(string $action): void
    {
        $original = $this->deadlockShapedFailure();
        $hookCalls = 0;
        $this->useParticipantValidationHook($this->primary, function () use ($action, $original, &$hookCalls): void {
            $hookCalls++;
            $this->primary->exec('INSERT INTO `' . $this->effects->getName() . '` VALUES (1, 12)');
            $this->endOwnership($action);
            throw $original;
        });
        $calls = 0;
        $caught = null;
        try {
            $this->coordinate(static function () use (&$calls): void { $calls++; });
        } catch (Throwable $failure) {
            $caught = $failure;
        }
        self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $caught);
        self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $caught);
        self::assertSame($original, $caught->getOperationFailure());
        self::assertInstanceOf(DatastoreErrorException::class, $caught->getPrevious());
        self::assertSame(1, $hookCalls);
        self::assertSame(0, $calls);
        self::assertFalse($this->primary->inTransaction());
        self::assertSame($action === 'rollback' ? [] : [['id' => '1', 'score' => '12']], $this->visibleEffects());
        $this->assertFailureLog('rollback', 'unknown', false, DatastoreErrorException::class, priorFailure: [
            'phase' => 'coordination', 'causeClass' => PDOException::class, 'sqlState' => '40001', 'driverCode' => 1213,
        ]);
    }

    /** @dataProvider inactiveQueryOutcomes */
    public function testInactiveRollbackEvidenceMustComeFromTheExactSuppliedQueryFailure(int $mode, bool $replace): void
    {
        $pdo = $this->connect(InactiveQueryRollbackPdo::class);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, $mode);
        $this->usePrimary($pdo);
        $replacement = $this->deadlockShapedFailure();
        $observed = null;
        $inactiveAtFailure = false;
        $calls = 0;
        $caught = null;
        /** @var callable(DatabaseStrategy): void $operation */
        $operation = function (DatabaseStrategy $backend) use ($pdo, $replace, $replacement, &$observed, &$calls, &$inactiveAtFailure): void {
            $calls++;
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            $pdo->faultQuery = 'SELECT 123 AS injected_rollback';
            try {
                $backend->query($pdo->faultQuery);
            } catch (DatastoreErrorException $failure) {
                $observed = $failure;
                $inactiveAtFailure = !$pdo->inTransaction();
                throw $replace ? $replacement : $failure;
            }
        };
        try {
            $this->coordinate($operation);
        } catch (Throwable $failure) {
            $caught = $failure;
        }
        self::assertInstanceOf(DatastoreErrorException::class, $observed);
        self::assertTrue($inactiveAtFailure, 'The driver must already be inactive before operation-owner cleanup.');
        self::assertInstanceOf(PDOException::class, $observed->getPrevious());
        self::assertSame(['40001', 1213, 'Injected query failure after whole rollback'], $observed->getPrevious()->errorInfo);
        if ($mode === PDO::ERRMODE_EXCEPTION) {
            self::assertSame($pdo->faultCause, $observed->getPrevious());
        }
        if ($replace) {
            self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $caught);
            self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $caught);
            self::assertSame($replacement, $caught->getOperationFailure());
            self::assertInstanceOf(DatastoreErrorException::class, $caught->getPrevious());
            $this->assertFailureLog('rollback', 'unknown', false, DatastoreErrorException::class, priorFailure: [
                'phase' => 'callback', 'causeClass' => PDOException::class, 'sqlState' => '40001', 'driverCode' => 1213,
            ]);
        } else {
            self::assertInstanceOf(CoordinatedOperationConflictException::class, $caught);
            self::assertSame($observed, $caught->getPrevious());
            $this->assertFailureLog('callback', 'rolled_back', true, DatastoreErrorException::class, '40001', 1213);
        }
        self::assertSame(1, $calls);
        self::assertSame(1, $pdo->injectedFailures);
        self::assertFalse($pdo->inTransaction());
        self::assertSame([], $this->visibleEffects());
        self::assertSame([['id' => '42']], $this->strategy->query('SELECT 42 AS id'));
    }

    /** @dataProvider queryFailureModes */
    public function testPriorAttemptEvidenceCannotAuthorizeTheSameFailureAfterALaterCommit(int $mode): void
    {
        $pdo = $this->connect(InactiveQueryRollbackPdo::class);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, $mode);
        $this->usePrimary($pdo);
        $observed = null;
        $inactiveAtFailure = false;
        try {
            $this->coordinate(function (DatabaseStrategy $backend) use ($pdo, &$inactiveAtFailure): void {
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
                $pdo->faultQuery = 'SELECT 123 AS injected_rollback';
                try {
                    $backend->query($pdo->faultQuery);
                } catch (DatastoreErrorException $failure) {
                    $inactiveAtFailure = !$pdo->inTransaction();
                    throw $failure;
                }
            });
        } catch (CoordinatedOperationConflictException $failure) {
            $observed = $failure->getPrevious();
        }
        self::assertInstanceOf(DatastoreErrorException::class, $observed);
        self::assertTrue($inactiveAtFailure, 'The first attempt must report its query failure after actual rollback.');
        self::assertSame([], $this->visibleEffects());
        $this->assertFailureLog('callback', 'rolled_back', true, DatastoreErrorException::class, '40001', 1213);
        $this->logger->entries = [];
        $calls = 0;
        $caught = null;
        /** @var callable(DatabaseStrategy): void $operation */
        $operation = function (DatabaseStrategy $backend) use ($observed, &$calls): void {
            $calls++;
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 99)', $this->effects->getName()));
            $this->primary->commit();
            throw $observed;
        };
        try {
            $this->coordinate($operation);
        } catch (Throwable $failure) {
            $caught = $failure;
        }
        self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $caught);
        self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $caught);
        self::assertSame($observed, $caught->getOperationFailure());
        self::assertInstanceOf(DatastoreErrorException::class, $caught->getPrevious());
        self::assertSame(1, $calls);
        self::assertSame(1, $pdo->injectedFailures);
        self::assertFalse($pdo->inTransaction());
        self::assertSame([['id' => '1', 'score' => '99']], $this->visibleEffects());
        $this->assertFailureLog('rollback', 'unknown', false, DatastoreErrorException::class, priorFailure: [
            'phase' => 'callback', 'causeClass' => DatastoreErrorException::class, 'sqlState' => '40001', 'driverCode' => 1213,
        ]);
    }

    /** @dataProvider ordinaryQueryOutcomes */
    public function testOrdinaryQueriesRemainAvailableBeforeAndAfterTheOwnedAttempt(bool $callbackFails): void
    {
        self::assertSame([['id' => '41']], $this->strategy->query('SELECT 41 AS id'));
        $original = new RuntimeException('Callback failed');
        $caught = null;
        $result = null;
        try {
            $result = $this->coordinate(function (DatabaseStrategy $backend) use ($callbackFails, $original): string {
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
                if ($callbackFails) {
                    throw $original;
                }
                return 'committed';
            });
        } catch (Throwable $failure) {
            $caught = $failure;
        }
        self::assertSame($callbackFails ? $original : null, $caught);
        self::assertSame($callbackFails ? null : 'committed', $result);
        self::assertSame([['id' => '42']], $this->strategy->query('SELECT 42 AS id'));
        self::assertFalse($this->primary->inTransaction());
        self::assertSame($callbackFails ? [] : [['id' => '1', 'score' => '12']], $this->visibleEffects());
        if ($callbackFails) {
            $this->assertFailureLog('callback', 'rolled_back', false, RuntimeException::class);
        } else {
            self::assertSame([], $this->logger->entries);
        }
    }

    /** @dataProvider allOwnershipLoss */
    public function testASuppliedBackendStatementThatEndsOwnershipCannotReturnToTheCallback(string $action): void
    {
        $continued = false;
        $calls = 0;
        /** @var callable(DatabaseStrategy): void $operation */
        $operation = function (DatabaseStrategy $backend) use ($action, &$continued, &$calls): void {
            $calls++;
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            $backend->query(match ($action) {
                'commit' => 'COMMIT',
                'rollback' => 'ROLLBACK',
                default => $backend->parse('ALTER TABLE ?n COMMENT = ?s', $this->effects->getName(), 'ownership boundary'),
            });
            $continued = true;
            throw $this->deadlockShapedFailure();
        };
        try {
            $this->coordinate($operation);
            self::fail('A query that ends the owned transaction must report an uncertain operation.');
        } catch (DatastoreErrorException $failure) {
            self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $failure);
            self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $failure);
            self::assertInstanceOf(DatastoreErrorException::class, $failure->getOperationFailure());
            $this->assertUnknownCleanupLog($failure);
        }
        self::assertFalse($continued, 'The ownership-ending statement must not return normally.');
        self::assertSame(1, $calls);
        self::assertFalse($this->primary->inTransaction());
        self::assertSame($action === 'rollback' ? [] : [['id' => '1', 'score' => '12']], $this->visibleEffects());
    }

    /** @dataProvider allOwnershipLoss */
    public function testALaterBackendQueryCannotWriteAfterOwnershipWasLost(string $action): void
    {
        $continued = false;
        $calls = 0;
        /** @var callable(DatabaseStrategy): void $operation */
        $operation = function (DatabaseStrategy $backend) use ($action, &$continued, &$calls): void {
            $calls++;
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            $this->endOwnership($action);
            $backend->query($backend->parse('INSERT INTO ?n VALUES (2, 99)', $this->effects->getName()));
            $continued = true;
            throw $this->deadlockShapedFailure();
        };
        try {
            $this->coordinate($operation);
            self::fail('The supplied backend must refuse a query outside its owned transaction.');
        } catch (DatastoreErrorException $failure) {
            self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $failure);
            self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $failure);
            self::assertInstanceOf(DatastoreErrorException::class, $failure->getOperationFailure());
            $this->assertUnknownCleanupLog($failure);
        }
        self::assertFalse($continued);
        self::assertSame(1, $calls);
        self::assertFalse($this->primary->inTransaction());
        self::assertSame(
            $action === 'rollback' ? [] : [['id' => '1', 'score' => '12']],
            $this->visibleEffects(),
            'The second write must never reach a replacement autocommit operation.'
        );
    }

    /** @dataProvider allOwnershipLoss */
    public function testADeadlockShapedCallbackFailureDoesNotProveAnInactiveAttemptRolledBack(string $action): void
    {
        $original = $this->deadlockShapedFailure();
        $calls = 0;
        /** @var callable(DatabaseStrategy): void $operation */
        $operation = function (DatabaseStrategy $backend) use ($action, $original, &$calls): void {
            $calls++;
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            $this->endOwnership($action);
            throw $original;
        };
        try {
            $this->coordinate($operation);
            self::fail('Error numbers alone cannot establish whole-attempt rollback.');
        } catch (DatastoreErrorException $failure) {
            self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $failure);
            self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $failure);
            self::assertSame($original, $failure->getOperationFailure());
            self::assertInstanceOf(DatastoreErrorException::class, $failure->getPrevious());
            $this->assertFailureLog('rollback', 'unknown', false, DatastoreErrorException::class, priorFailure: [
                'phase' => 'callback', 'causeClass' => PDOException::class, 'sqlState' => '40001', 'driverCode' => 1213,
            ]);
        }
        self::assertSame(1, $calls);
        self::assertFalse($this->primary->inTransaction());
        self::assertSame($action === 'rollback' ? [] : [['id' => '1', 'score' => '12']], $this->visibleEffects());
    }

    private function useParticipantValidationHook(PDO $pdo, Closure $hook): void
    {
        $this->primary = $pdo;
        $this->strategy = new ParticipantValidationHookStrategy(
            PdoConnection::fromPdo($pdo),
            $this->logger,
            $hook
        );
    }

    private function endOwnership(string $action): void
    {
        if ($action === 'rollback') {
            $this->primary->rollBack();
        } elseif ($action === 'implicit commit') {
            $this->primary->exec('ALTER TABLE `' . $this->effects->getName() . '` COMMENT = \'ownership boundary\'');
        } else {
            $this->primary->commit();
        }
    }

    private function deadlockShapedFailure(): PDOException
    {
        $failure = new PDOException('Deadlock-shaped callback failure');
        $failure->errorInfo = ['40001', 1213, 'Deadlock-shaped callback failure'];
        return $failure;
    }

    private function assertUnknownCleanupLog(CoordinatedOperationCleanupFailedException $failure): void
    {
        self::assertInstanceOf(DatastoreErrorException::class, $failure->getPrevious());
        $this->assertFailureLog('rollback', 'unknown', false, DatastoreErrorException::class, priorFailure: [
            'phase' => 'callback', 'causeClass' => get_class($failure->getOperationFailure()),
            'sqlState' => null, 'driverCode' => null,
        ]);
    }

    /** @return array<string, array{string}> */
    public static function allOwnershipLoss(): array
    {
        return ['commit' => ['commit'], 'implicit commit' => ['implicit commit'], 'rollback' => ['rollback']];
    }

    /** @return array<string, array{bool}> */
    public static function ordinaryQueryOutcomes(): array
    {
        return ['commit' => [false], 'callback failure' => [true]];
    }

    /** @return array<string, array{int, bool}> */
    public static function inactiveQueryOutcomes(): array
    {
        return [
            'exception exact' => [PDO::ERRMODE_EXCEPTION, false],
            'exception replaced' => [PDO::ERRMODE_EXCEPTION, true],
            'silent exact' => [PDO::ERRMODE_SILENT, false],
            'silent replaced' => [PDO::ERRMODE_SILENT, true],
        ];
    }

    /** @return array<string, array{int}> */
    public static function queryFailureModes(): array
    {
        return ['exception' => [PDO::ERRMODE_EXCEPTION], 'silent' => [PDO::ERRMODE_SILENT]];
    }

    /** @return array<string, array{string, int}> */
    public static function inactiveCoordinationBoundaries(): array
    {
        return [
            'query exception' => ['query', PDO::ERRMODE_EXCEPTION],
            'query silent' => ['query', PDO::ERRMODE_SILENT],
            'prepare exception' => ['prepare', PDO::ERRMODE_EXCEPTION],
            'prepare silent' => ['prepare', PDO::ERRMODE_SILENT],
            'execute exception' => ['execute', PDO::ERRMODE_EXCEPTION],
            'execute silent' => ['execute', PDO::ERRMODE_SILENT],
        ];
    }
}

final class ParticipantValidationHookStrategy extends PdoCoordinatedDatabaseStrategy
{
    public function __construct(
        PdoConnection $connection,
        LoggerStrategy $logger,
        private Closure $beforeValidation
    ) {
        parent::__construct($connection, $logger);
    }

    /**
     * @param non-empty-list<array{table: \PHPNomad\Database\Interfaces\Table, name: string}> $definitions
     * @param non-empty-list<string> $coordinationIdentity
     */
    protected function validateParticipants(
        PDO $pdo,
        string $schema,
        array $definitions,
        array $coordinationIdentity
    ): void {
        ($this->beforeValidation)();
        parent::validateParticipants($pdo, $schema, $definitions, $coordinationIdentity);
    }
}
