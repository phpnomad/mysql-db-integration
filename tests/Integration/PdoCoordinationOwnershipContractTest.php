<?php

namespace PHPNomad\MySql\Integration\Tests\Integration;

use PDOException;
use PHPNomad\Database\Exceptions\CoordinatedOperationCleanupFailedException;
use PHPNomad\Database\Exceptions\CoordinatedOperationConflictException;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\OwnedPdoCoordinationContractCase;

/** Ownership loss cannot turn already committed effects into retryable failures. */
final class PdoCoordinationOwnershipContractTest extends OwnedPdoCoordinationContractCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestIncomplete('Composed ownership-loss protection is pending.');
    }

    /** @dataProvider committedOwnershipLoss */
    public function testASuppliedBackendStatementThatEndsOwnershipCannotReturnToTheCallback(string $action): void
    {
        $continued = false;
        $calls = 0;
        /** @var callable(DatabaseStrategy): void $operation */
        $operation = function (DatabaseStrategy $backend) use ($action, &$continued, &$calls): void {
            $calls++;
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            $backend->query($action === 'commit' ? 'COMMIT' :
                $backend->parse('ALTER TABLE ?n COMMENT = ?s', $this->effects->getName(), 'ownership boundary'));
            $continued = true;
            throw $this->deadlockShapedFailure();
        };
        try {
            $this->coordinate($operation);
            self::fail('A query that ends the owned transaction must report an uncertain operation.');
        } catch (CoordinatedOperationCleanupFailedException $failure) {
            self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $failure);
            self::assertInstanceOf(DatastoreErrorException::class, $failure->getOperationFailure());
            $this->assertUnknownCleanupLog($failure);
        }
        self::assertFalse($continued, 'The ownership-ending statement must not return normally.');
        self::assertSame(1, $calls);
        self::assertFalse($this->primary->inTransaction());
        self::assertSame([['id' => '1', 'score' => '12']], $this->visibleEffects());
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
        } catch (CoordinatedOperationCleanupFailedException $failure) {
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
        } catch (CoordinatedOperationCleanupFailedException $failure) {
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
    public static function committedOwnershipLoss(): array
    {
        return ['commit' => ['commit'], 'implicit commit' => ['implicit commit']];
    }

    /** @return array<string, array{string}> */
    public static function allOwnershipLoss(): array
    {
        return self::committedOwnershipLoss() + ['rollback' => ['rollback']];
    }
}
