<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Strategies;

use PDO;
use PDOException;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Strategies\PdoCoordinatedDatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\RecordingLogger;
use PHPNomad\MySql\Integration\Tests\TestCase;
use RuntimeException;
use Throwable;

final class PdoCoordinatedDatabaseStrategyTest extends TestCase
{
    private GrantVisibilityProbe $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new GrantVisibilityProbe(new PdoConnection(), new RecordingLogger());
    }

    public function testAnExactTableTriggerGrantProvesVisibility(): void
    {
        self::assertTrue($this->strategy->grantCovers(
            'GRANT TRIGGER ON `owned_schema`.`effects` TO `worker`@`%`',
            'owned_schema',
            'effects'
        ));
    }

    public function testAnExactSchemaAllPrivilegesGrantProvesVisibility(): void
    {
        self::assertTrue($this->strategy->grantCovers(
            'GRANT ALL PRIVILEGES ON `owned_schema`.* TO `worker`@`%`',
            'owned_schema',
            'effects'
        ));
    }

    public function testAGlobalTriggerGrantProvesVisibility(): void
    {
        self::assertTrue($this->strategy->grantCovers(
            'GRANT SELECT, INSERT, TRIGGER ON *.* TO `worker`@`%`',
            'owned_schema',
            'effects'
        ));
    }

    public function testAWildcardSchemaGrantDoesNotProveExactVisibility(): void
    {
        self::assertFalse($this->strategy->grantCovers(
            'GRANT TRIGGER ON `owned_schema%`.* TO `worker`@`%`',
            'owned_schema',
            'effects'
        ));
    }

    public function testAnUnrelatedPrivilegeDoesNotProveTriggerVisibility(): void
    {
        self::assertFalse($this->strategy->grantCovers(
            'GRANT SELECT, INSERT ON `owned_schema`.`effects` TO `worker`@`%`',
            'owned_schema',
            'effects'
        ));
    }

    public function testAStatementOutsideAnOwnedAttemptDoesNotRequireATransaction(): void
    {
        $pdo = new OwnershipStatePdo();

        self::assertFalse($this->strategy->enterStatement($pdo));
    }

    public function testAStatementOnAnotherResourceDoesNotEnterTheOwnedAttempt(): void
    {
        $owned = new OwnershipStatePdo();
        $owned->active = true;
        $other = new OwnershipStatePdo();
        $this->strategy->openAttempt($owned);

        self::assertFalse($this->strategy->enterStatement($other));
    }

    public function testAnOwnedStatementMustEnterWithTheTransactionActive(): void
    {
        $pdo = new OwnershipStatePdo();
        $this->strategy->openAttempt($pdo);

        $this->expectException(DatastoreErrorException::class);
        $this->strategy->enterStatement($pdo);
    }

    public function testClosingAnAttemptReleasesItsStatementOwnershipGuard(): void
    {
        $pdo = new OwnershipStatePdo();
        $pdo->active = true;
        $this->strategy->openAttempt($pdo);
        $this->strategy->closeAttempt();
        $pdo->active = false;

        self::assertFalse($this->strategy->enterStatement($pdo));
    }

    public function testAnOwnedStatementMustReturnWithTheTransactionActive(): void
    {
        $pdo = new OwnershipStatePdo();
        $pdo->active = true;
        $this->strategy->openAttempt($pdo);
        $enteredOwned = $this->strategy->enterStatement($pdo);
        $pdo->active = false;

        $this->expectException(DatastoreErrorException::class);
        $this->strategy->retainOwnership($pdo, $enteredOwned);
    }

    public function testOnlyTheExactObservedInactiveDriverFailureProvesTheAbort(): void
    {
        $pdo = new OwnershipStatePdo();
        $pdo->active = true;
        $this->strategy->openAttempt($pdo);
        $this->strategy->enterStatement($pdo);
        $pdo->active = false;
        $observed = $this->deadlockFailure();
        $this->strategy->observeFailure($pdo, $observed);

        self::assertTrue($this->strategy->isInactiveAbortEvidence($observed));
        self::assertFalse($this->strategy->isInactiveAbortEvidence($this->deadlockFailure()));
    }

    public function testAFailureOnAnotherResourceCannotProveTheAbort(): void
    {
        $owned = new OwnershipStatePdo();
        $owned->active = true;
        $other = new OwnershipStatePdo();
        $this->strategy->openAttempt($owned);
        $observed = $this->deadlockFailure();
        $this->strategy->observeFailure($other, $observed);

        self::assertFalse($this->strategy->isInactiveAbortEvidence($observed));
    }

    public function testADriverFailureThatLeavesTheTransactionActiveCannotProveTheAbort(): void
    {
        $pdo = new OwnershipStatePdo();
        $pdo->active = true;
        $this->strategy->openAttempt($pdo);
        $observed = $this->deadlockFailure();
        $this->strategy->observeFailure($pdo, $observed);

        self::assertFalse($this->strategy->isInactiveAbortEvidence($observed));
    }

    public function testANonDriverFailureCannotProveTheAbort(): void
    {
        $pdo = new OwnershipStatePdo();
        $pdo->active = true;
        $this->strategy->openAttempt($pdo);
        $pdo->active = false;
        $observed = new RuntimeException('Application failure');
        $this->strategy->observeFailure($pdo, $observed);

        self::assertFalse($this->strategy->isInactiveAbortEvidence($observed));
    }

    public function testInactiveAbortEvidenceDoesNotSurviveTheAttempt(): void
    {
        $pdo = new OwnershipStatePdo();
        $pdo->active = true;
        $this->strategy->openAttempt($pdo);
        $observed = $this->deadlockFailure();
        $pdo->active = false;
        $this->strategy->observeFailure($pdo, $observed);
        $this->strategy->closeAttempt();
        $pdo->active = true;
        $this->strategy->openAttempt($pdo);

        self::assertFalse($this->strategy->isInactiveAbortEvidence($observed));
    }

    private function deadlockFailure(): PDOException
    {
        $failure = new PDOException('Deadlock');
        $failure->errorInfo = ['40001', 1213, 'Deadlock'];

        return $failure;
    }
}

final class GrantVisibilityProbe extends PdoCoordinatedDatabaseStrategy
{
    public function grantCovers(string $grant, string $schema, string $table): bool
    {
        return $this->grantProvesTriggerVisibility($grant, $schema, $table);
    }

    public function openAttempt(PDO $pdo): void
    {
        $this->openOwnedAttempt($pdo);
    }

    public function closeAttempt(): void
    {
        $this->closeOwnedAttempt();
    }

    public function enterStatement(PDO $pdo): bool
    {
        return $this->enterOwnedStatement($pdo);
    }

    public function retainOwnership(PDO $pdo, bool $enteredOwned): void
    {
        $this->requireRetainedOwnership($pdo, $enteredOwned);
    }

    public function observeFailure(PDO $pdo, Throwable $failure): void
    {
        $this->observeInactiveDriverFailure($pdo, $failure);
    }

    public function isInactiveAbortEvidence(Throwable $failure): bool
    {
        return $this->hasInactiveAbortEvidence($failure);
    }
}

final class OwnershipStatePdo extends PDO
{
    public bool $active = false;

    public function __construct()
    {
    }

    public function inTransaction(): bool
    {
        return $this->active;
    }
}
