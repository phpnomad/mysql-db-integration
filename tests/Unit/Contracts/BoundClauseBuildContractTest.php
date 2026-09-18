<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Contracts;

use Error;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\MySql\Integration\Builders\MySqlClauseBuilder;
use PHPNomad\MySql\Integration\Facades\Database;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\Unit\Fixtures\BoundFormattingContractCase;
use PHPNomad\MySql\Integration\Tests\Unit\Fixtures\BoundClauseBuilder;
use RuntimeException;
use Throwable;

final class BoundClauseBuildContractTest extends BoundFormattingContractCase
{
    public function testBoundBuildUsesItsBackendAndOrdinaryBuildKeepsTheFacade(): void
    {
        $bound = $this->createMock(DatabaseStrategy::class);
        $bound->expects(self::once())->method('parse')->with('s.id = ?s', 7)->willReturn('BOUND');
        $bound->expects(self::never())->method('query');
        $this->globalDatabase->expects(self::once())->method('parse')->with('s.id = ?s', 9)->willReturn('GLOBAL');
        $builder = (new MySqlClauseBuilder())->useTable($this->table());

        self::assertSame('BOUND', $builder->where('id', '=', 7)->buildWithDatabaseStrategy($bound));
        self::assertSame('GLOBAL', $builder->where('id', '=', 9)->build());
    }

    public function testNestedGroupsUseTheSameBackendAtEveryDepth(): void
    {
        $bound = $this->createMock(DatabaseStrategy::class);
        $bound->expects(self::never())->method('query');
        $calls = [];
        $bound->expects(self::exactly(3))->method('parse')->willReturnCallback(
            static function (string $query, mixed ...$values) use (&$calls): string {
                $calls[] = $values;
                return str_replace('?s', 'BOUND' . count($calls), $query);
            }
        );
        $this->globalDatabase->expects(self::never())->method('parse');
        $table = $this->table();
        $leaf = (new MySqlClauseBuilder())->useTable($table)->where('id', '=', 1);
        $middle = (new MySqlClauseBuilder())->useTable($table)->where('id', '=', 2)->andGroup('OR', $leaf);
        $root = (new MySqlClauseBuilder())->useTable($table)->where('id', '=', 3)->andGroup('AND', $middle);

        self::assertSame('s.id = BOUND3 AND (s.id = BOUND2 AND (s.id = BOUND1))', $root->buildWithDatabaseStrategy($bound));
        self::assertSame([[1], [2], [3]], $calls);
    }

    /** @dataProvider failures */
    public function testOriginalParserFailureEscapesAndTheBindingIsRestored(string $kind): void
    {
        $failure = $kind === 'error' ? new Error('Parser failed') : new RuntimeException('Parser failed');
        $bound = $this->createMock(DatabaseStrategy::class);
        $bound->expects(self::once())->method('parse')->with('s.id = ?s', 7)->willThrowException($failure);
        $bound->expects(self::never())->method('query');
        $this->globalDatabase->expects(self::once())->method('parse')->with('s.id = ?s', 9)->willReturn('GLOBAL');
        $builder = (new MySqlClauseBuilder())->useTable($this->table())->where('id', '=', 7);
        $caught = null;
        try {
            $builder->buildWithDatabaseStrategy($bound);
        } catch (Throwable $actual) {
            $caught = $actual;
        }

        self::assertSame($failure, $caught);
        self::assertSame('GLOBAL', $builder->reset()->where('id', '=', 9)->build());
    }

    public function testTheExistingBuildAndFieldHooksRemainActive(): void
    {
        $bound = $this->createMock(DatabaseStrategy::class);
        $bound->expects(self::once())->method('parse')->with('FIELD(s.id) = ?s', 7)->willReturn('BOUND');
        $bound->expects(self::never())->method('query');
        $this->globalDatabase->expects(self::never())->method('parse');
        $builder = new class extends MySqlClauseBuilder {
            public int $buildCalls = 0;
            public function build(): string
            {
                $this->buildCalls++;
                return 'BUILD(' . parent::build() . ')';
            }
            protected function prependField(string $field, ?Table $table = null): string
            {
                return 'FIELD(' . parent::prependField($field, $table) . ')';
            }
        };

        self::assertSame('BUILD(BOUND)', $builder->useTable($this->table())->where('id', '=', 7)->buildWithDatabaseStrategy($bound));
        self::assertSame(1, $builder->buildCalls);
    }

    public function testAResourceIndependentCustomChildKeepsItsExistingBuildContract(): void
    {
        $bound = $this->createMock(DatabaseStrategy::class);
        $bound->expects(self::never())->method('parse');
        $bound->expects(self::never())->method('query');
        $this->globalDatabase->expects(self::never())->method('parse');
        $child = $this->createMock(ClauseBuilder::class);
        $child->expects(self::once())->method('build')->willReturn('s.score IS NULL');
        $builder = (new MySqlClauseBuilder())->useTable($this->table())->group('AND', $child);

        self::assertSame('(s.score IS NULL)', $builder->buildWithDatabaseStrategy($bound));
    }

    public function testACapableCustomChildReceivesTheSuppliedBackend(): void
    {
        $bound = $this->createMock(DatabaseStrategy::class);
        $bound->expects(self::never())->method('parse');
        $bound->expects(self::never())->method('query');
        $this->globalDatabase->expects(self::never())->method('parse');
        $child = $this->createMock(BoundClauseBuilder::class);
        $child->expects(self::never())->method('build');
        $child->expects(self::once())->method('buildWithDatabaseStrategy')->with(self::identicalTo($bound))->willReturn('CUSTOM');
        $builder = (new MySqlClauseBuilder())->useTable($this->table())->group('AND', $child);

        self::assertSame('(CUSTOM)', $builder->buildWithDatabaseStrategy($bound));
    }

    /** @dataProvider nestedOutcomes */
    public function testReentrantBoundBuildRestoresTheOuterBackend(string $outcome): void
    {
        $failure = match ($outcome) {
            'exception' => new RuntimeException('Inner parser failed'),
            'error' => new Error('Inner parser failed'),
            default => null,
        };
        $builder = (new MySqlClauseBuilder())->useTable($this->table());
        $inner = $this->createMock(DatabaseStrategy::class);
        $inner->expects(self::never())->method('query');
        if ($failure === null) {
            $inner->expects(self::once())->method('parse')->with('s.id = ?s', 2)->willReturn('INNER');
        } else {
            $inner->expects(self::once())->method('parse')->with('s.id = ?s', 2)->willThrowException($failure);
        }
        $outer = $this->createMock(DatabaseStrategy::class);
        $outer->expects(self::never())->method('query');
        $calls = [];
        $outer->expects(self::exactly(2))->method('parse')->willReturnCallback(
            static function (string $sql, mixed ...$values) use ($builder, $inner, $failure, &$calls): string {
                $calls[] = [$sql, $values];
                if (count($calls) === 2) {
                    return 'OUTER RESUMED';
                }
                $caught = null;
                $result = null;
                try {
                    $result = $builder->reset()->where('id', '=', 2)->buildWithDatabaseStrategy($inner);
                } catch (Throwable $actual) {
                    $caught = $actual;
                }
                self::assertSame($failure, $caught);
                self::assertSame($failure === null ? 'INNER' : null, $result);
                self::assertSame('OUTER RESUMED', $builder->reset()->where('id', '=', 3)->build());
                return 'OUTER';
            }
        );
        $this->globalDatabase->expects(self::once())->method('parse')->with('s.id = ?s', 4)->willReturn('GLOBAL');

        self::assertSame('OUTER', $builder->where('id', '=', 1)->buildWithDatabaseStrategy($outer));
        self::assertSame([['s.id = ?s', [1]], ['s.id = ?s', [3]]], $calls);
        self::assertSame('GLOBAL', $builder->where('id', '=', 4)->build());
    }

    /** @dataProvider nestedOutcomes */
    public function testEveryNestedBuilderRestoresItsOwnBinding(string $outcome): void
    {
        $failure = match ($outcome) {
            'exception' => new RuntimeException('Nested parser failed'),
            'error' => new Error('Nested parser failed'),
            default => null,
        };
        $bound = $this->createMock(DatabaseStrategy::class);
        $bound->expects(self::never())->method('query');
        if ($failure !== null) {
            $bound->expects(self::once())->method('parse')->with('s.id = ?s', 1)->willThrowException($failure);
        } else {
            $bound->expects(self::exactly(3))->method('parse')->willReturnCallback(
                static fn(string $query): string => str_replace('?s', 'BOUND', $query)
            );
        }
        $this->globalDatabase->expects(self::exactly(3))->method('parse')->with('s.id = ?s', 9)->willReturn('GLOBAL');
        $table = $this->table();
        $leaf = (new MySqlClauseBuilder())->useTable($table)->where('id', '=', 1);
        $middle = (new MySqlClauseBuilder())->useTable($table)->where('id', '=', 2)->andGroup('AND', $leaf);
        $root = (new MySqlClauseBuilder())->useTable($table)->where('id', '=', 3)->andGroup('AND', $middle);
        $caught = null;
        try {
            self::assertSame('s.id = BOUND AND (s.id = BOUND AND (s.id = BOUND))', $root->buildWithDatabaseStrategy($bound));
        } catch (Throwable $actual) {
            $caught = $actual;
        }

        self::assertSame($failure, $caught);
        foreach ([$root, $middle, $leaf] as $builder) {
            self::assertSame('GLOBAL', $builder->reset()->where('id', '=', 9)->build());
        }
    }

    public function testTheGlobalBindingStaysIntactDuringTheBoundParse(): void
    {
        $this->globalDatabase->expects(self::once())->method('parse')->with('PROBE', 8)->willReturn('GLOBAL');
        $bound = $this->createMock(DatabaseStrategy::class);
        $bound->expects(self::never())->method('query');
        $bound->expects(self::once())->method('parse')->with('s.id = ?s', 7)->willReturnCallback(
            static function (): string {
                self::assertSame('GLOBAL', Database::parse('PROBE', 8));
                return 'BOUND';
            }
        );
        $builder = (new MySqlClauseBuilder())->useTable($this->table())->where('id', '=', 7);

        self::assertSame('BOUND', $builder->buildWithDatabaseStrategy($bound));
    }

    public function testAnotherBuilderDoesNotInheritTheActiveBinding(): void
    {
        $this->globalDatabase->expects(self::once())->method('parse')->with('s.id = ?s', 8)->willReturn('GLOBAL');
        $ordinary = (new MySqlClauseBuilder())->useTable($this->table())->where('id', '=', 8);
        $bound = $this->createMock(DatabaseStrategy::class);
        $bound->expects(self::never())->method('query');
        $bound->expects(self::once())->method('parse')->with('s.id = ?s', 7)->willReturnCallback(
            static function () use ($ordinary): string {
                self::assertSame('GLOBAL', $ordinary->build());
                return 'BOUND';
            }
        );
        $builder = (new MySqlClauseBuilder())->useTable($this->table())->where('id', '=', 7);

        self::assertSame('BOUND', $builder->buildWithDatabaseStrategy($bound));
    }

    /** @return array<string, array{string}> */
    public static function nestedOutcomes(): array
    {
        return ['success' => ['success'], 'exception' => ['exception'], 'error' => ['error']];
    }

    /** @return array<string, array{string}> */
    public static function failures(): array
    {
        return ['exception' => ['exception'], 'error' => ['error']];
    }
}
