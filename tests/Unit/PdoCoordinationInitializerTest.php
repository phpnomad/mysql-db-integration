<?php

namespace PHPNomad\MySql\Integration\Tests\Unit;

use Mockery;
use PHPNomad\Cache\Interfaces\CachePolicy;
use PHPNomad\Cache\Interfaces\CacheStrategy;
use PHPNomad\Database\Interfaces\CoordinatedQueryStrategy as CoreCoordinatedQueryStrategy;
use PHPNomad\Database\Interfaces\OperationDatabaseProviderFactory;
use PHPNomad\Database\Interfaces\QueryStrategy as CoreQueryStrategy;
use PHPNomad\Di\Container;
use PHPNomad\Events\Interfaces\EventStrategy;
use PHPNomad\Loader\Bootstrapper;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Interfaces\CoordinatedDatabaseStrategy;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Interfaces\ProvidesOperationBuilders;
use PHPNomad\MySql\Integration\MySqlInitializer;
use PHPNomad\MySql\Integration\PdoCoordinationInitializer;
use PHPNomad\MySql\Integration\Tests\TestCase;

final class PdoCoordinationInitializerTest extends TestCase
{
    public function testProductionBindingsShareBothCoordinatorInstances(): void
    {
        $container = new Container();
        $container->bindSingletonFromFactory(PdoConnection::class, static fn (): PdoConnection => new PdoConnection());
        $container->bindSingletonFromFactory(LoggerStrategy::class, static fn () => Mockery::mock(LoggerStrategy::class));
        $container->bindSingletonFromFactory(CachePolicy::class, static fn () => Mockery::mock(CachePolicy::class));
        $container->bindSingletonFromFactory(CacheStrategy::class, static fn () => Mockery::mock(CacheStrategy::class));
        $container->bindSingletonFromFactory(EventStrategy::class, static fn () => Mockery::mock(EventStrategy::class));

        (new Bootstrapper(
            $container,
            new MySqlInitializer(),
            new PdoCoordinationInitializer()
        ))->load();

        self::assertSame(
            $container->get(DatabaseStrategy::class),
            $container->get(CoordinatedDatabaseStrategy::class)
        );
        self::assertSame(
            $container->get(CoreQueryStrategy::class),
            $container->get(CoreCoordinatedQueryStrategy::class)
        );
        self::assertSame(
            $container->get(CoreCoordinatedQueryStrategy::class),
            $container->get(ProvidesOperationBuilders::class)
        );
        self::assertInstanceOf(
            OperationDatabaseProviderFactory::class,
            $container->get(OperationDatabaseProviderFactory::class)
        );
    }
}
