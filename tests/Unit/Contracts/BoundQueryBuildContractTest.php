<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Contracts;

use Error;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\MySql\Integration\Builders\MySqlClauseBuilder;
use PHPNomad\MySql\Integration\Builders\QueryBuilder;
use PHPNomad\MySql\Integration\Facades\Database;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\Unit\Fixtures\BoundFormattingContractCase;
use PHPNomad\MySql\Integration\Tests\Unit\Fixtures\BoundClauseBuilder;
use RuntimeException;
use Throwable;

final class BoundQueryBuildContractTest extends BoundFormattingContractCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testTheWhereTreeUsesTheSuppliedBackend(): void
    {
        $bound = $this->createMock(DatabaseStrategy::class);
        $bound->expects(self::once())->method('parse')->with('s.id = ?s', 7)->willReturn('s.id = BOUND');
        $bound->expects(self::never())->method('query');
        $this->globalDatabase->expects(self::never())->method('parse');
        $table = $this->table();
        $leaf = (new MySqlClauseBuilder())->useTable($table)->where('id', '=', 7);
        $group = (new MySqlClauseBuilder())->useTable($table)->group('AND', $leaf);
        $query = (new QueryBuilder())->from($table)->select('*')->where($group);

        self::assertSame('SELECT * FROM scores AS s WHERE (s.id = BOUND)', $query->buildWithDatabaseStrategy($bound));
    }

    public function testSubclassPreparedTokensUseTheSuppliedBackend(): void
    {
        $bound = $this->createMock(DatabaseStrategy::class);
        $bound->expects(self::once())->method('parse')->with('SELECT * FROM scores AS s ORDER BY ?s', 'custom')->willReturn('BOUND');
        $bound->expects(self::never())->method('query');
        $this->globalDatabase->expects(self::never())->method('parse');
        $query = (new PreparedTokenQueryBuilder())->from($this->table())->select('*')->withPreparedOrder('custom');

        self::assertSame('BOUND', $query->buildWithDatabaseStrategy($bound));
    }

    public function testOrdinaryBuildRestoresItsFacadeAfterBoundBuild(): void
    {
        $bound = $this->createMock(DatabaseStrategy::class);
        $bound->expects(self::once())->method('parse')->with('SELECT * FROM scores AS s ORDER BY ?s', 'bound')->willReturn('BOUND');
        $bound->expects(self::never())->method('query');
        $this->globalDatabase->expects(self::once())->method('parse')->with('SELECT * FROM scores AS s ORDER BY ?s', 'ordinary')->willReturn('GLOBAL');
        $table = $this->table();
        $query = (new PreparedTokenQueryBuilder())->from($table)->select('*')->withPreparedOrder('bound');

        self::assertSame('BOUND', $query->buildWithDatabaseStrategy($bound));
        self::assertSame('GLOBAL', $query->from($table)->select('*')->withPreparedOrder('ordinary')->build());
    }

    /** @dataProvider failures */
    public function testOriginalFailureEscapesAndTheBindingIsRestored(string $kind): void
    {
        $failure = $kind === 'error' ? new Error('Parser failed') : new RuntimeException('Parser failed');
        $bound = $this->createMock(DatabaseStrategy::class);
        $bound->expects(self::once())->method('parse')->with('SELECT * FROM scores AS s ORDER BY ?s', 'bound')->willThrowException($failure);
        $bound->expects(self::never())->method('query');
        $this->globalDatabase->expects(self::once())->method('parse')->with('SELECT * FROM scores AS s ORDER BY ?s', 'ordinary')->willReturn('GLOBAL');
        $table = $this->table();
        $query = (new PreparedTokenQueryBuilder())->from($table)->select('*')->withPreparedOrder('bound');
        $caught = null;
        try {
            $query->buildWithDatabaseStrategy($bound);
        } catch (Throwable $actual) {
            $caught = $actual;
        }

        self::assertSame($failure, $caught);
        self::assertSame('GLOBAL', $query->reset()->from($table)->select('*')->withPreparedOrder('ordinary')->build());
    }

    public function testBuildAndFieldOverridesRemainActive(): void
    {
        $bound = $this->createMock(DatabaseStrategy::class);
        $bound->expects(self::never())->method('parse');
        $bound->expects(self::never())->method('query');
        $this->globalDatabase->expects(self::never())->method('parse');
        $query = new class extends QueryBuilder {
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

        self::assertSame('BUILD(SELECT FIELD(s.id) FROM scores AS s)', $query->from($this->table())->select('id')->buildWithDatabaseStrategy($bound));
        self::assertSame(1, $query->buildCalls);
    }

    public function testResourceIndependentCustomWhereBuildersRemainCompatible(): void
    {
        $bound = $this->createMock(DatabaseStrategy::class);
        $bound->expects(self::never())->method('parse');
        $bound->expects(self::never())->method('query');
        $this->globalDatabase->expects(self::never())->method('parse');
        $table = $this->table();
        $where = $this->createMock(ClauseBuilder::class);
        $where->expects(self::once())->method('useTable')->with($table)->willReturnSelf();
        $where->expects(self::once())->method('build')->willReturn('s.score IS NULL');
        $query = (new QueryBuilder())->from($table)->select('*')->where($where);

        self::assertSame('SELECT * FROM scores AS s WHERE s.score IS NULL', $query->buildWithDatabaseStrategy($bound));
    }

    public function testACapableCustomWhereBuilderReceivesTheSuppliedBackend(): void
    {
        $bound = $this->createMock(DatabaseStrategy::class);
        $bound->expects(self::never())->method('parse');
        $bound->expects(self::never())->method('query');
        $this->globalDatabase->expects(self::never())->method('parse');
        $table = $this->table();
        $where = $this->createMock(BoundClauseBuilder::class);
        $where->expects(self::once())->method('useTable')->with($table)->willReturnSelf();
        $where->expects(self::never())->method('build');
        $where->expects(self::once())->method('buildWithDatabaseStrategy')->with(self::identicalTo($bound))->willReturn('CUSTOM');
        $query = (new QueryBuilder())->from($table)->select('*')->where($where);

        self::assertSame('SELECT * FROM scores AS s WHERE CUSTOM', $query->buildWithDatabaseStrategy($bound));
    }

    /** @dataProvider nestedOutcomes */
    public function testReentrantBoundBuildRestoresTheOuterBackend(string $outcome): void
    {
        $failure = match ($outcome) {
            'exception' => new RuntimeException('Inner parser failed'),
            'error' => new Error('Inner parser failed'),
            default => null,
        };
        $table = $this->table();
        $builder = new PreparedTokenQueryBuilder();
        $sql = 'SELECT * FROM scores AS s ORDER BY ?s';
        $inner = $this->createMock(DatabaseStrategy::class);
        $inner->expects(self::never())->method('query');
        if ($failure === null) {
            $inner->expects(self::once())->method('parse')->with($sql, 'inner')->willReturn('INNER');
        } else {
            $inner->expects(self::once())->method('parse')->with($sql, 'inner')->willThrowException($failure);
        }
        $outer = $this->createMock(DatabaseStrategy::class);
        $outer->expects(self::never())->method('query');
        $calls = [];
        $outer->expects(self::exactly(2))->method('parse')->willReturnCallback(
            static function (string $query, mixed ...$values) use ($builder, $table, $inner, $failure, &$calls): string {
                $calls[] = [$query, $values];
                if (count($calls) === 2) {
                    return 'OUTER RESUMED';
                }
                $caught = null;
                $result = null;
                try {
                    $result = $builder->reset()->from($table)->select('*')->withPreparedOrder('inner')->buildWithDatabaseStrategy($inner);
                } catch (Throwable $actual) {
                    $caught = $actual;
                }
                self::assertSame($failure, $caught);
                self::assertSame($failure === null ? 'INNER' : null, $result);
                self::assertSame('OUTER RESUMED', $builder->reset()->from($table)->select('*')->withPreparedOrder('resumed')->build());
                return 'OUTER';
            }
        );
        $this->globalDatabase->expects(self::once())->method('parse')->with($sql, 'ordinary')->willReturn('GLOBAL');

        self::assertSame('OUTER', $builder->from($table)->select('*')->withPreparedOrder('outer')->buildWithDatabaseStrategy($outer));
        self::assertSame([[$sql, ['outer']], [$sql, ['resumed']]], $calls);
        self::assertSame('GLOBAL', $builder->from($table)->select('*')->withPreparedOrder('ordinary')->build());
    }

    public function testTheGlobalBindingStaysIntactDuringTheBoundParse(): void
    {
        $this->globalDatabase->expects(self::once())->method('parse')->with('PROBE', 8)->willReturn('GLOBAL');
        $bound = $this->createMock(DatabaseStrategy::class);
        $bound->expects(self::never())->method('query');
        $bound->expects(self::once())->method('parse')->with('SELECT * FROM scores AS s ORDER BY ?s', 'bound')->willReturnCallback(
            static function (): string {
                self::assertSame('GLOBAL', Database::parse('PROBE', 8));
                return 'BOUND';
            }
        );
        $query = (new PreparedTokenQueryBuilder())->from($this->table())->select('*')->withPreparedOrder('bound');

        self::assertSame('BOUND', $query->buildWithDatabaseStrategy($bound));
    }

    public function testAnotherBuilderDoesNotInheritTheActiveBinding(): void
    {
        $this->globalDatabase->expects(self::once())->method('parse')->with('SELECT * FROM scores AS s ORDER BY ?s', 'ordinary')->willReturn('GLOBAL');
        $ordinary = (new PreparedTokenQueryBuilder())->from($this->table())->select('*')->withPreparedOrder('ordinary');
        $bound = $this->createMock(DatabaseStrategy::class);
        $bound->expects(self::never())->method('query');
        $bound->expects(self::once())->method('parse')->with('SELECT * FROM scores AS s ORDER BY ?s', 'bound')->willReturnCallback(
            static function () use ($ordinary): string {
                self::assertSame('GLOBAL', $ordinary->build());
                return 'BOUND';
            }
        );
        $query = (new PreparedTokenQueryBuilder())->from($this->table())->select('*')->withPreparedOrder('bound');

        self::assertSame('BOUND', $query->buildWithDatabaseStrategy($bound));
    }

    /** @return array<string, array{string}> */
    public static function failures(): array
    {
        return ['exception' => ['exception'], 'error' => ['error']];
    }

    /** @return array<string, array{string}> */
    public static function nestedOutcomes(): array
    {
        return ['success' => ['success'], 'exception' => ['exception'], 'error' => ['error']];
    }
}

/** Exercises the existing protected prepared-token extension point. */
final class PreparedTokenQueryBuilder extends QueryBuilder
{
    public function withPreparedOrder(string $value): self
    {
        $this->orderBy = ['ORDER BY', ['type' => '?s', 'value' => $value]];
        return $this;
    }
}
