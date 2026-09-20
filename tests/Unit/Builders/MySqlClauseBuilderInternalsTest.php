<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Builders;

use Closure;
use Error;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\MySql\Integration\Builders\MySqlClauseBuilder;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\TestCase;
use PHPNomad\MySql\Integration\Tests\Unit\Fixtures\BoundClauseBuilder;
use RuntimeException;
use Throwable;

final class MySqlClauseBuilderInternalsTest extends TestCase
{
    public function testBoundBuildActivatesItsBackendAndClearsItAfterSuccess(): void
    {
        $database = $this->createMock(DatabaseStrategy::class);
        $builder = new InspectableMySqlClauseBuilder();
        $builder->onBuild = static function () use ($builder, $database): string {
            self::assertSame($database, $builder->activeDatabaseStrategy());

            return 'BOUND';
        };

        self::assertSame('BOUND', $builder->buildWithDatabaseStrategy($database));
        self::assertNull($builder->activeDatabaseStrategy());
    }

    public function testSuccessfulNestedBuildRestoresTheOuterBackend(): void
    {
        $outer = $this->createMock(DatabaseStrategy::class);
        $inner = $this->createMock(DatabaseStrategy::class);
        $builder = new InspectableMySqlClauseBuilder();
        $builds = 0;
        $builder->onBuild = static function () use ($builder, $outer, $inner, &$builds): string {
            $builds++;
            if ($builds === 2) {
                self::assertSame($inner, $builder->activeDatabaseStrategy());

                return 'INNER';
            }

            self::assertSame($outer, $builder->activeDatabaseStrategy());
            self::assertSame('INNER', $builder->buildWithDatabaseStrategy($inner));
            self::assertSame($outer, $builder->activeDatabaseStrategy());

            return 'OUTER';
        };

        self::assertSame('OUTER', $builder->buildWithDatabaseStrategy($outer));
        self::assertNull($builder->activeDatabaseStrategy());
    }

    /** @dataProvider failures */
    public function testBoundBuildPropagatesTheOriginalFailure(string $kind): void
    {
        $failure = $kind === 'error' ? new Error('Build failed') : new RuntimeException('Build failed');
        $database = $this->createMock(DatabaseStrategy::class);
        $builder = new InspectableMySqlClauseBuilder();
        $builder->onBuild = static function () use ($failure): string {
            throw $failure;
        };
        $caught = null;
        try {
            $builder->buildWithDatabaseStrategy($database);
        } catch (Throwable $actual) {
            $caught = $actual;
        }

        self::assertSame($failure, $caught);
        self::assertNull($builder->activeDatabaseStrategy());
    }

    /** @dataProvider failures */
    public function testFailedNestedBuildPropagatesTheFailureAndRestoresTheOuterBackend(string $kind): void
    {
        $failure = $kind === 'error' ? new Error('Nested build failed') : new RuntimeException('Nested build failed');
        $outer = $this->createMock(DatabaseStrategy::class);
        $inner = $this->createMock(DatabaseStrategy::class);
        $builder = new InspectableMySqlClauseBuilder();
        $builds = 0;
        $builder->onBuild = static function () use ($builder, $outer, $inner, $failure, &$builds): string {
            $builds++;
            if ($builds === 2) {
                self::assertSame($inner, $builder->activeDatabaseStrategy());
                throw $failure;
            }

            self::assertSame($outer, $builder->activeDatabaseStrategy());
            try {
                $builder->buildWithDatabaseStrategy($inner);
                self::fail('The nested failure must escape.');
            } catch (Throwable $actual) {
                self::assertSame($failure, $actual);
            }
            self::assertSame($outer, $builder->activeDatabaseStrategy());

            return 'OUTER';
        };

        self::assertSame('OUTER', $builder->buildWithDatabaseStrategy($outer));
        self::assertNull($builder->activeDatabaseStrategy());
    }

    public function testActiveBackendIsPassedToACapableChild(): void
    {
        $database = $this->createMock(DatabaseStrategy::class);
        $child = $this->createMock(BoundClauseBuilder::class);
        $child->expects(self::never())->method('build');
        $child->expects(self::once())->method('buildWithDatabaseStrategy')
            ->with(self::identicalTo($database))->willReturn('CAPABLE');
        $builder = new InspectableMySqlClauseBuilder();
        $builder->onBuild = static fn (): string => $builder->buildChild($child);

        self::assertSame('CAPABLE', $builder->buildWithDatabaseStrategy($database));
    }

    public function testCapableChildReceivesTheInnermostActiveBackend(): void
    {
        $outer = $this->createMock(DatabaseStrategy::class);
        $inner = $this->createMock(DatabaseStrategy::class);
        $child = $this->createMock(BoundClauseBuilder::class);
        $child->expects(self::never())->method('build');
        $child->expects(self::once())->method('buildWithDatabaseStrategy')
            ->with(self::identicalTo($inner))->willReturn('INNER CHILD');
        $builder = new InspectableMySqlClauseBuilder();
        $builds = 0;
        $builder->onBuild = static function () use ($builder, $inner, $child, &$builds): string {
            $builds++;

            return $builds === 1
                ? $builder->buildWithDatabaseStrategy($inner)
                : $builder->buildChild($child);
        };

        self::assertSame('INNER CHILD', $builder->buildWithDatabaseStrategy($outer));
    }

    public function testPlainChildKeepsItsBuildContractDuringABoundBuild(): void
    {
        $database = $this->createMock(DatabaseStrategy::class);
        $child = $this->createMock(ClauseBuilder::class);
        $child->expects(self::once())->method('build')->willReturn('PLAIN');
        $builder = new InspectableMySqlClauseBuilder();
        $builder->onBuild = static fn (): string => $builder->buildChild($child);

        self::assertSame('PLAIN', $builder->buildWithDatabaseStrategy($database));
    }

    public function testCapableChildKeepsItsOrdinaryBuildContractWithoutAnActiveBackend(): void
    {
        $child = $this->createMock(BoundClauseBuilder::class);
        $child->expects(self::once())->method('build')->willReturn('ORDINARY');
        $child->expects(self::never())->method('buildWithDatabaseStrategy');
        $builder = new InspectableMySqlClauseBuilder();

        self::assertSame('ORDINARY', $builder->buildChild($child));
    }

    /** @return array<string, array{string}> */
    public static function failures(): array
    {
        return ['exception' => ['exception'], 'error' => ['error']];
    }
}

final class InspectableMySqlClauseBuilder extends MySqlClauseBuilder
{
    public ?Closure $onBuild = null;

    public function build(): string
    {
        if ($this->onBuild !== null) {
            return ($this->onBuild)();
        }

        return parent::build();
    }

    public function activeDatabaseStrategy(): ?DatabaseStrategy
    {
        return $this->getActiveDatabaseStrategy();
    }

    public function buildChild(ClauseBuilder $clause): string
    {
        return $this->buildClause($clause);
    }
}
