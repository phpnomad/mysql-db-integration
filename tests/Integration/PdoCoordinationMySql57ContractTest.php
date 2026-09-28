<?php

namespace PHPNomad\MySql\Integration\Tests\Integration;

use PHPNomad\Database\Exceptions\UnsupportedCoordinationException;
use PHPNomad\Database\Exceptions\CoordinatedOperationReportingFailedException;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\OwnedPdoCoordinationContractCase;
use RuntimeException;

/** @group mysql57 */
final class PdoCoordinationMySql57ContractTest extends OwnedPdoCoordinationContractCase
{
    public function testMySql57IsRefusedBeforeTheCallbackWithoutChangingStorageOrOwnership(): void
    {
        $this->requireMySql57();
        $calls = 0;

        try {
            $this->coordinate(static function () use (&$calls): void {
                $calls++;
            });
            self::fail('MySQL 5.7 must be refused before coordination starts.');
        } catch (UnsupportedCoordinationException $failure) {
            self::assertSame('This database version is unsupported for coordination.', $failure->getMessage());
        }

        self::assertSame(0, $calls);
        self::assertSame([], $this->visibleEffects());
        self::assertFalse($this->primary->inTransaction());
        $this->assertFailureLog('validation', 'unchanged', false, UnsupportedCoordinationException::class);
        self::assertSame([['id' => '42']], $this->strategy->query('SELECT 42 AS id'));
    }

    public function testMySql57ReportingFailureRetainsTheVersionRefusalAndReleasesOwnership(): void
    {
        $this->requireMySql57();
        $reporting = new RuntimeException('Exact MySQL 5.7 reporting failure.');
        $this->logger->transportFailure = $reporting;
        $this->logger->throwBeforeWrite = true;
        $calls = 0;

        try {
            $this->coordinate(static function () use (&$calls): void {
                $calls++;
            });
            self::fail('A reporting failure must retain the version refusal.');
        } catch (CoordinatedOperationReportingFailedException $failure) {
            self::assertInstanceOf(UnsupportedCoordinationException::class, $failure->getOperationFailure());
            self::assertSame($reporting, $failure->getReportingFailure());
            self::assertSame($reporting, $failure->getPrevious());
        }

        self::assertSame(0, $calls);
        self::assertSame([], $this->visibleEffects());
        self::assertFalse($this->primary->inTransaction());
        self::assertSame(1, $this->logger->writeAttempts);
        self::assertSame([], $this->logger->entries);
        self::assertSame([['id' => '42']], $this->strategy->query('SELECT 42 AS id'));
    }

    private function requireMySql57(): void
    {
        $version = $this->primary->query('SELECT VERSION()');
        self::assertNotFalse($version);
        if (!str_starts_with((string) $version->fetchColumn(), '5.7.')) {
            $this->markTestSkipped('This contract requires MySQL 5.7.');
        }
    }
}
