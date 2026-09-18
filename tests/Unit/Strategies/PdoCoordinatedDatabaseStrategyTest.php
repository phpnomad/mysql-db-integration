<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Strategies;

use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Strategies\PdoCoordinatedDatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\RecordingLogger;
use PHPNomad\MySql\Integration\Tests\TestCase;

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
}

final class GrantVisibilityProbe extends PdoCoordinatedDatabaseStrategy
{
    public function grantCovers(string $grant, string $schema, string $table): bool
    {
        return $this->grantProvesTriggerVisibility($grant, $schema, $table);
    }
}
