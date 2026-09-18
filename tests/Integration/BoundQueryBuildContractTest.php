<?php

namespace PHPNomad\MySql\Integration\Tests\Integration;

use Error;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\MySql\Integration\Builders\MySqlClauseBuilder;
use PHPNomad\MySql\Integration\Builders\QueryBuilder;
use PHPNomad\MySql\Integration\Facades\Database;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\BoundFormattingContractCase;
use RuntimeException;
use Throwable;

final class BoundQueryBuildContractTest extends BoundFormattingContractCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestIncomplete('Operation-bound query formatting implementation is pending.');
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
