<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Contracts;

use Closure;
use Error;
use PHPNomad\MySql\Integration\Builders\MySqlClauseBuilder;
use PHPNomad\MySql\Integration\Builders\QueryBuilder;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\Unit\Fixtures\BoundFormattingContractCase;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Throwable;

/** Clones retain builder content, not another invocation's temporary resource. */
final class BoundBuilderCloneContractTest extends BoundFormattingContractCase
{
    /** @dataProvider outcomes */
    public function testClauseClonesOwnTheirBindingLifecycle(string $outcome, bool $nested): void
    {
        $this->assertCloneLifecycle(new CloneProbeClauseBuilder(), $outcome, $nested);
    }

    /** @dataProvider outcomes */
    public function testQueryClonesOwnTheirBindingLifecycle(string $outcome, bool $nested): void
    {
        $this->assertCloneLifecycle(new CloneProbeQueryBuilder(), $outcome, $nested);
    }

    private function assertCloneLifecycle(
        CloneProbeClauseBuilder|CloneProbeQueryBuilder $builder,
        string $outcome,
        bool $nested
    ): void {
        $failure = match ($outcome) {
            'exception' => new RuntimeException('Outer formatting failed'),
            'error' => new Error('Outer formatting failed'),
            default => null,
        };
        $table = $this->table();
        $sql = $builder instanceof CloneProbeQueryBuilder
            ? 'SELECT * FROM scores AS s ORDER BY ?s'
            : 's.id = ?s';
        $values = static fn (int $value): int|string => $builder instanceof CloneProbeQueryBuilder
            ? (string) $value : $value;
        $prepare = static function (CloneProbeClauseBuilder|CloneProbeQueryBuilder $subject, int $value) use ($table): void {
            if ($subject instanceof CloneProbeQueryBuilder) {
                $subject->reset()->from($table)->select('*')->withPreparedOrder((string) $value);
            } else {
                $subject->reset()->useTable($table)->where('id', '=', $value);
            }
        };
        $outer = $this->createMock(DatabaseStrategy::class);
        $inner = $this->createMock(DatabaseStrategy::class);
        $cloneBackend = $this->createMock(DatabaseStrategy::class);
        foreach ([$outer, $inner, $cloneBackend, $this->globalDatabase] as $backend) {
            $backend->expects(self::never())->method('query');
        }
        if ($failure === null) {
            $outer->expects(self::once())->method('parse')->with($sql, $values(7))->willReturn('OUTER');
        } else {
            $outer->expects(self::once())->method('parse')->with($sql, $values(7))->willThrowException($failure);
        }
        $inner->expects($nested ? self::once() : self::never())->method('parse')
            ->with($sql, $values(7))->willReturn('INNER');
        $cloneBackend->expects(self::once())->method('parse')->with($sql, $values(8))->willReturn('CLONE');
        $globalCalls = [];
        $this->globalDatabase->expects(self::exactly(4))->method('parse')->willReturnCallback(
            static function (string $query, mixed ...$arguments) use (&$globalCalls): string {
                $globalCalls[] = [$query, $arguments];
                return 'GLOBAL';
            }
        );
        $copy = null;
        $capture = static function (CloneProbeClauseBuilder|CloneProbeQueryBuilder $subject) use (
            &$copy, $cloneBackend, $prepare, $table
        ): void {
            $copy = clone $subject;
            self::assertNotSame($subject, $copy);
            if ($copy instanceof CloneProbeQueryBuilder) {
                self::assertSame([$table], $copy->getReferencedTables());
            }
            // No reset before this call: cloning must preserve pending content.
            self::assertSame('GLOBAL', $copy->build());
            $prepare($copy, 8);
            self::assertSame('CLONE', $copy->buildWithDatabaseStrategy($cloneBackend));
            $prepare($copy, 9);
            self::assertSame('GLOBAL', $copy->build());
        };
        $builder->beforeBuild = static function (CloneProbeClauseBuilder|CloneProbeQueryBuilder $subject) use (
            $nested, $capture, $inner, $prepare
        ): void {
            if ($nested) {
                $subject->beforeBuild = $capture;
                self::assertSame('INNER', $subject->buildWithDatabaseStrategy($inner));
                $prepare($subject, 7);
            } else {
                $capture($subject);
            }
        };
        $prepare($builder, 7);
        $caught = null;
        $result = null;
        try {
            $result = $builder->buildWithDatabaseStrategy($outer);
        } catch (AssertionFailedError $assertion) {
            throw $assertion;
        } catch (Throwable $actual) {
            $caught = $actual;
        }
        self::assertSame($failure, $caught);
        self::assertSame($failure === null ? 'OUTER' : null, $result);
        self::assertNotNull($copy);
        $prepare($copy, 10);
        self::assertSame('GLOBAL', $copy->build());
        $prepare($builder, 11);
        self::assertSame('GLOBAL', $builder->build());
        self::assertSame([
            [$sql, [$values(7)]], [$sql, [$values(9)]],
            [$sql, [$values(10)]], [$sql, [$values(11)]],
        ], $globalCalls);
    }

    /** @return array<string, array{string, bool}> */
    public static function outcomes(): array
    {
        return [
            'success' => ['success', false],
            'exception' => ['exception', false],
            'error' => ['error', false],
            'nested success' => ['success', true],
            'nested exception' => ['exception', true],
            'nested error' => ['error', true],
        ];
    }
}

/** Runs the clone observation before the production build consumes its state. */
final class CloneProbeClauseBuilder extends MySqlClauseBuilder
{
    /** @var Closure(self): void|null */
    public ?Closure $beforeBuild = null;

    public function build(): string
    {
        $hook = $this->beforeBuild;
        $this->beforeBuild = null;
        if ($hook !== null) {
            $hook($this);
        }
        return parent::build();
    }
}

/** Uses the existing prepared-token extension point to observe resource choice. */
final class CloneProbeQueryBuilder extends QueryBuilder
{
    /** @var Closure(self): void|null */
    public ?Closure $beforeBuild = null;

    public function build(): string
    {
        $hook = $this->beforeBuild;
        $this->beforeBuild = null;
        if ($hook !== null) {
            $hook($this);
        }
        return parent::build();
    }

    public function withPreparedOrder(string $value): self
    {
        $this->orderBy = ['ORDER BY', ['type' => '?s', 'value' => $value]];
        return $this;
    }
}
