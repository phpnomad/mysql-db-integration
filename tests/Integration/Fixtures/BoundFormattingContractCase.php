<?php

namespace PHPNomad\MySql\Integration\Tests\Integration\Fixtures;

use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Di\Container;
use PHPNomad\MySql\Integration\Facades\Database;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionProperty;

/** Real builders and container, with verified parser boundaries and no database I/O. */
abstract class BoundFormattingContractCase extends TestCase
{
    private ReflectionProperty $facadeInstance;
    private mixed $previousFacade;
    protected DatabaseStrategy&MockObject $globalDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->facadeInstance = new ReflectionProperty(Database::class, 'instance');
        $this->facadeInstance->setAccessible(true);
        $this->previousFacade = $this->facadeInstance->getValue();
        $this->facadeInstance->setValue(null, new Database());
        $this->globalDatabase = $this->createMock(DatabaseStrategy::class);
        $this->globalDatabase->expects(self::never())->method('query');
        $container = new Container();
        $container->bindSingletonFromFactory(DatabaseStrategy::class, fn(): DatabaseStrategy => $this->globalDatabase);
        Database::instance()->setContainer($container);
    }

    protected function tearDown(): void
    {
        $this->facadeInstance->setValue(null, $this->previousFacade);
        parent::tearDown();
    }

    protected function table(): Table
    {
        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn('scores');
        $table->method('getAlias')->willReturn('s');
        $table->method('getColumns')->willReturn([
            new Column('id', 'BIGINT', null, 'PRIMARY KEY'), new Column('score', 'BIGINT'),
        ]);
        $table->method('getIndices')->willReturn([]);
        return $table;
    }
}
