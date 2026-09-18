<?php

namespace PHPNomad\MySql\Integration\Tests\Integration;

use PDOException;
use PHPNomad\Database\Exceptions\CoordinatedOperationOutcomeUnknownException;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
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

    /** @dataProvider rollbackFaults */
    public function testRollbackFailureNeverMasqueradesAsTheOriginalCallbackFailure(bool $afterRollback, bool $throws): void
    {
        $pdo = $this->connect(OutcomeFaultPdo::class);
        $pdo->faultAt = 'rollback';
        $pdo->afterOperation = $afterRollback;
        $pdo->throwFault = $throws;
        $this->usePrimary($pdo);
        $original = new RuntimeException('Original callback failure');
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
            } catch (CoordinatedOperationOutcomeUnknownException $failure) {
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
            $this->assertFailureLog('rollback', 'unknown', false, PDOException::class, 'HY000', 2013);
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

    /** @return array<string, array{bool, bool}> */
    public static function rollbackFaults(): array
    {
        return [
            'throw before rollback' => [false, true], 'false before rollback' => [false, false],
            'throw after rollback' => [true, true], 'false after rollback' => [true, false],
        ];
    }
}
