<?php

namespace PHPNomad\MySql\Integration\Tests\Integration;

use Error;
use PDOException;
use PHPNomad\Database\Exceptions\CoordinatedOperationCleanupFailedException;
use PHPNomad\Database\Exceptions\CoordinatedOperationOutcomeUnknownException;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\OutcomeFaultPdo;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\OwnedPdoCoordinationContractCase;
use RuntimeException;

/** Real persistence with faults at the driver's commit/rollback acknowledgement. */
final class PdoCoordinationOutcomeContractTest extends OwnedPdoCoordinationContractCase
{
    /** @dataProvider commitFaults */
    public function testCommitFailureDistinguishesConfirmedRollbackFromUncertainCommit(bool $afterCommit, bool $throws): void
    {
        $pdo = $this->connect(OutcomeFaultPdo::class);
        $pdo->faultAt = 'commit';
        $pdo->afterOperation = $afterCommit;
        $pdo->throwFault = $throws;
        $this->usePrimary($pdo);
        $calls = 0;
        try {
            $this->coordinate(function (DatabaseStrategy $backend) use (&$calls): string {
                $calls++;
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
                return 'must not report success';
            });
            self::fail('A failed commit acknowledgement must not return success.');
        } catch (DatastoreErrorException $failure) {
            if ($afterCommit) {
                self::assertInstanceOf(CoordinatedOperationOutcomeUnknownException::class, $failure);
            } else {
                self::assertNotInstanceOf(CoordinatedOperationOutcomeUnknownException::class, $failure);
            }
            $cause = $failure->getPrevious();
            self::assertInstanceOf(PDOException::class, $cause);
            self::assertSame(['HY000', 2013, 'Connection acknowledgement fault'], $cause->errorInfo);
            if ($throws) {
                self::assertSame($pdo->faultCause, $cause);
            }
        }

        self::assertSame(1, $calls);
        self::assertSame(1, $pdo->commitCalls);
        self::assertSame($afterCommit ? 0 : 1, $pdo->rollbackCalls);
        self::assertFalse($pdo->inTransaction());
        self::assertSame($afterCommit ? [['id' => '1', 'score' => '12']] : [], $this->visibleEffects());
        $this->assertFailureLog('commit', $afterCommit ? 'unknown' : 'rolled_back', false, PDOException::class, 'HY000', 2013);
    }

    /** @dataProvider throwableRollbackFaults */
    public function testRollbackFailureNeverMasqueradesAsTheOriginalCallbackFailure(
        bool $afterRollback,
        bool $throws,
        bool $loggerFails,
        bool $operationIsError
    ): void {
        $pdo = $this->connect(OutcomeFaultPdo::class);
        $pdo->faultAt = 'rollback';
        $pdo->afterOperation = $afterRollback;
        $pdo->throwFault = $throws;
        $this->usePrimary($pdo);
        $this->logger->throwOnWrite = $loggerFails;
        $original = $operationIsError ? new Error('Original callback failure') : new RuntimeException('Original callback failure');
        $calls = 0;
        /** @var callable(DatabaseStrategy): void $operation */
        $operation = function (DatabaseStrategy $backend) use (&$calls, $original): void {
            $calls++;
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            throw $original;
        };
        try {
            try {
                $this->coordinate($operation);
                self::fail('An unconfirmed rollback must report an unknown outcome.');
            } catch (CoordinatedOperationCleanupFailedException $failure) {
                self::assertSame($original, $failure->getOperationFailure());
                $cause = $failure->getPrevious();
                self::assertInstanceOf(PDOException::class, $cause);
                self::assertSame(['HY000', 2013, 'Connection acknowledgement fault'], $cause->errorInfo);
                if ($throws) {
                    self::assertSame($pdo->faultCause, $cause);
                }
            }

            self::assertSame(1, $calls);
            self::assertSame(0, $pdo->commitCalls);
            self::assertSame(1, $pdo->rollbackCalls);
            self::assertSame(!$afterRollback, $pdo->inTransaction());
            self::assertSame([], $this->visibleEffects());
            $this->assertFailureLog('rollback', 'unknown', false, PDOException::class, 'HY000', 2013, priorFailure: [
                'phase' => 'callback', 'causeClass' => get_class($original), 'sqlState' => null, 'driverCode' => null,
            ]);
        } finally {
            $pdo->faultAt = null;
        }
    }

    /** @dataProvider lostOwnership */
    public function testLosingTransactionOwnershipInsideTheCallbackCannotProduceAConfirmedSuccess(string $action): void
    {
        $calls = 0;
        try {
            $this->coordinate(function (DatabaseStrategy $backend) use (&$calls, $action): string {
                $calls++;
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
                if ($action === 'rollback') {
                    $this->primary->rollBack();
                } elseif ($action === 'implicit commit') {
                    $this->primary->exec('ALTER TABLE `' . $this->effects->getName() . '` COMMENT = \'ownership proof\'');
                } else {
                    $this->primary->commit();
                }
                return 'must not claim owned commit';
            });
            self::fail('Losing the owned transaction must not report success.');
        } catch (CoordinatedOperationOutcomeUnknownException $failure) {
            self::assertInstanceOf(DatastoreErrorException::class, $failure->getPrevious());
        }

        self::assertSame(1, $calls);
        self::assertFalse($this->primary->inTransaction());
        self::assertSame($action === 'rollback' ? [] : [['id' => '1', 'score' => '12']], $this->visibleEffects());
        $this->assertFailureLog('commit', 'unknown', false, DatastoreErrorException::class);
    }

    /** @dataProvider combinedAcknowledgementFaults */
    public function testFailedCommitFollowedByUnconfirmedRollbackHasAnUnknownOutcome(
        bool $commitThrows,
        bool $rollbackThrows,
        bool $afterRollback,
        bool $loggerFails
    ): void {
        $pdo = $this->connect(OutcomeFaultPdo::class);
        $pdo->faultAt = 'commit';
        $pdo->throwFault = $commitThrows;
        $pdo->rollbackThrowsAfterCommitFault = $rollbackThrows;
        $pdo->rollbackAfterOperationAfterCommitFault = $afterRollback;
        $this->usePrimary($pdo);
        $this->logger->throwOnWrite = $loggerFails;
        $calls = 0;
        try {
            try {
                $this->coordinate(function (DatabaseStrategy $backend) use (&$calls): string {
                    $calls++;
                    $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
                    return 'must not report success';
                });
                self::fail('An unconfirmed cleanup rollback leaves the commit outcome unknown.');
            } catch (CoordinatedOperationCleanupFailedException $failure) {
                $operationCause = $failure->getOperationFailure();
                self::assertInstanceOf(PDOException::class, $operationCause);
                self::assertSame(['HY000', 2013, 'Connection acknowledgement fault'], $operationCause->errorInfo);
                if ($commitThrows) {
                    self::assertSame($pdo->commitFaultCause, $operationCause);
                }
                $cause = $failure->getPrevious();
                self::assertInstanceOf(PDOException::class, $cause);
                self::assertSame(['HY000', 2006, 'Rollback acknowledgement fault'], $cause->errorInfo);
                if ($rollbackThrows) {
                    self::assertSame($pdo->faultCause, $cause);
                }
            }
            self::assertSame(1, $calls);
            self::assertSame(1, $pdo->commitCalls);
            self::assertSame(1, $pdo->rollbackCalls);
            self::assertSame(!$afterRollback, $pdo->inTransaction());
            self::assertSame([], $this->visibleEffects());
            $this->assertFailureLog('rollback', 'unknown', false, PDOException::class, 'HY000', 2006, priorFailure: [
                'phase' => 'commit', 'causeClass' => PDOException::class, 'sqlState' => 'HY000', 'driverCode' => 2013,
            ]);
        } finally {
            $pdo->faultAt = null;
        }
    }

    /** @dataProvider rollbackFaults */
    public function testCoordinationFailureRemainsInspectableWhenCleanupAlsoFails(bool $afterRollback, bool $throws, bool $loggerFails): void
    {
        $pdo = $this->connect(OutcomeFaultPdo::class);
        $pdo->faultAt = 'rollback';
        $pdo->afterOperation = $afterRollback;
        $pdo->throwFault = $throws;
        $this->usePrimary($pdo);
        $this->logger->throwOnWrite = $loggerFails;
        $calls = 0;
        try {
            try {
                $this->strategy->coordinate($this->parents, ['tenantId' => 1, 'id' => 999], [$this->parents, $this->effects],
                    function () use (&$calls): void { $calls++; }
                );
                self::fail('Missing coordination state with unconfirmed cleanup is an unknown outcome.');
            } catch (CoordinatedOperationCleanupFailedException $failure) {
                self::assertInstanceOf(RecordNotFoundException::class, $failure->getOperationFailure());
                $cleanup = $failure->getPrevious();
                self::assertInstanceOf(PDOException::class, $cleanup);
                self::assertSame(['HY000', 2013, 'Connection acknowledgement fault'], $cleanup->errorInfo);
                if ($throws) {
                    self::assertSame($pdo->faultCause, $cleanup);
                }
            }
            self::assertSame(0, $calls);
            self::assertSame(0, $pdo->commitCalls);
            self::assertSame(1, $pdo->rollbackCalls);
            self::assertSame(!$afterRollback, $pdo->inTransaction());
            self::assertSame([], $this->visibleEffects());
            $this->assertFailureLog('rollback', 'unknown', false, PDOException::class, 'HY000', 2013, priorFailure: [
                'phase' => 'coordination', 'causeClass' => RecordNotFoundException::class, 'sqlState' => null, 'driverCode' => null,
            ]);
        } finally {
            $pdo->faultAt = null;
        }
    }

    /** @dataProvider throwableRollbackFaults */
    public function testCoordinationCleanupRetainsTheExactFailureFromTheOwnedResource(
        bool $afterRollback,
        bool $throws,
        bool $loggerFails,
        bool $operationIsError
    ): void {
        $pdo = $this->connect(OutcomeFaultPdo::class);
        $original = $operationIsError ? new Error('Coordination resource failure') : new RecordNotFoundException('Coordination resource failure');
        $pdo->coordinationFailure = $original;
        $pdo->faultAt = 'rollback';
        $pdo->afterOperation = $afterRollback;
        $pdo->throwFault = $throws;
        $this->usePrimary($pdo);
        $this->logger->throwOnWrite = $loggerFails;
        $calls = 0;
        try {
            try {
                $this->coordinate(function () use (&$calls): void { $calls++; });
                self::fail('An owned-resource failure with unconfirmed cleanup must retain both causes.');
            } catch (CoordinatedOperationCleanupFailedException $failure) {
                self::assertSame($original, $failure->getOperationFailure());
                $cleanup = $failure->getPrevious();
                self::assertInstanceOf(PDOException::class, $cleanup);
                self::assertSame(['HY000', 2013, 'Connection acknowledgement fault'], $cleanup->errorInfo);
                if ($throws) {
                    self::assertSame($pdo->faultCause, $cleanup);
                }
            }
            self::assertSame(1, $pdo->coordinationFaultCalls);
            self::assertNull($pdo->coordinationFailure);
            self::assertSame(0, $calls);
            self::assertSame(0, $pdo->commitCalls);
            self::assertSame(1, $pdo->rollbackCalls);
            self::assertSame(!$afterRollback, $pdo->inTransaction());
            self::assertSame([], $this->visibleEffects());
            $this->assertFailureLog('rollback', 'unknown', false, PDOException::class, 'HY000', 2013, priorFailure: [
                'phase' => 'coordination', 'causeClass' => get_class($original), 'sqlState' => null, 'driverCode' => null,
            ]);
        } finally {
            $pdo->coordinationFailure = null;
            $pdo->faultAt = null;
        }
    }

    /** @return array<string, array{bool, bool, bool, bool}> */
    public static function combinedAcknowledgementFaults(): array
    {
        $cases = [];
        foreach ([false, true] as $commitThrows) {
            foreach ([false, true] as $rollbackThrows) {
                foreach ([false, true] as $afterRollback) {
                    $name = 'commit ' . ($commitThrows ? 'throw' : 'false') . ', rollback ' .
                        ($rollbackThrows ? 'throw' : 'false') . ($afterRollback ? ' after' : ' before');
                    $cases[$name] = [$commitThrows, $rollbackThrows, $afterRollback, false];
                    $cases[$name . ' logger failure'] = [$commitThrows, $rollbackThrows, $afterRollback, true];
                }
            }
        }
        return $cases;
    }

    /** @return array<string, array{string}> */
    public static function lostOwnership(): array
    {
        return ['commit' => ['commit'], 'rollback' => ['rollback'], 'implicit commit' => ['implicit commit']];
    }

    /** @return array<string, array{bool, bool}> */
    public static function commitFaults(): array
    {
        return [
            'throw before commit' => [false, true], 'false before commit' => [false, false],
            'throw after commit' => [true, true], 'false after commit' => [true, false],
        ];
    }

    /** @return array<string, array{bool, bool, bool}> */
    public static function rollbackFaults(): array
    {
        return [
            'throw before rollback' => [false, true, false], 'false before rollback' => [false, false, false],
            'throw after rollback' => [true, true, false], 'false after rollback' => [true, false, false],
            'throw before rollback, logger failure' => [false, true, true], 'false before rollback, logger failure' => [false, false, true],
            'throw after rollback, logger failure' => [true, true, true], 'false after rollback, logger failure' => [true, false, true],
        ];
    }

    /** @return array<string, array{bool, bool, bool, bool}> */
    public static function throwableRollbackFaults(): array
    {
        $cases = [];
        foreach (self::rollbackFaults() as $name => $faults) {
            $cases[$name . ', exception'] = [...$faults, false];
            $cases[$name . ', error'] = [...$faults, true];
        }
        return $cases;
    }
}
