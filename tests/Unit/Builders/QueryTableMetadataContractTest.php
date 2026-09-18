<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Builders;

use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\Database\Interfaces\HasQueryTables;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\MySql\Integration\Builders\QueryBuilder;
use PHPNomad\MySql\Integration\Tests\TestCase;

final class QueryTableMetadataContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testFieldContextAloneDoesNotCreateAQuerySource(): void
    {
        $builder = new QueryBuilder();
        self::assertInstanceOf(HasQueryTables::class, $builder);
        self::assertSame([], $builder->getReferencedTables());
        $builder->useTable($this->table('scores', 's'));
        self::assertSame([], $builder->getReferencedTables());
    }

    public function testRootAndBothJoinDirectionsMatchBuiltSqlWithoutConsumingMetadata(): void
    {
        $builder = $this->joinedBuilder();
        $expected = [['scores', 's'], ['programs', 'p'], ['scores', 'other']];
        self::assertSame($expected, $this->sources($builder));
        self::assertSame($expected, $this->sources($builder));
        self::assertSame(
            'SELECT * FROM scores AS s LEFT JOIN programs AS p ON s.programId = p.id RIGHT JOIN scores AS other ON s.id = other.id',
            $builder->build()
        );
        self::assertSame([], $builder->getReferencedTables());
    }

    public function testReplacingFromChangesOnlyTheRootSource(): void
    {
        $builder = $this->joinedBuilder();
        $builder->from($this->table('new_scores', 'n'));

        self::assertSame([['new_scores', 'n'], ['programs', 'p'], ['scores', 'other']], $this->sources($builder));
        self::assertStringContainsString('FROM new_scores AS n LEFT JOIN programs AS p', $builder->build());
    }

    public function testUseTableCannotReplaceAnExistingFromSource(): void
    {
        $builder = (new QueryBuilder())->from($this->table('scores', 's'))->select('*');
        $builder->useTable($this->table('field_context', 'f'));

        self::assertSame([['scores', 's']], $this->sources($builder));
        self::assertSame('SELECT * FROM scores AS s', $builder->build());
    }

    public function testResetRemovesEverySourceAndDoesNotLeakIntoReuse(): void
    {
        $builder = $this->joinedBuilder();
        self::assertSame($builder, $builder->reset());
        self::assertSame([], $builder->getReferencedTables());
        $builder->from($this->table('new_scores', 'n'))->select('*');

        self::assertSame([['new_scores', 'n']], $this->sources($builder));
        self::assertSame('SELECT * FROM new_scores AS n', $builder->build());
    }

    public function testResettingJoinsPreservesTheRootAndRemovesBothJoinDirections(): void
    {
        $builder = $this->joinedBuilder();
        self::assertSame($builder, $builder->resetClauses('join'));

        self::assertSame([['scores', 's']], $this->sources($builder));
        self::assertSame('SELECT * FROM scores AS s', $builder->build());
    }

    public function testMissingFromReportsNoSourcesButRestoringFromRetainsExistingJoins(): void
    {
        $builder = $this->joinedBuilder()->resetClauses('from');
        self::assertSame([], $builder->getReferencedTables());
        $builder->from($this->table('restored', 'r'));

        self::assertSame([['restored', 'r'], ['programs', 'p'], ['scores', 'other']], $this->sources($builder));
        self::assertStringContainsString('FROM restored AS r LEFT JOIN programs AS p', $builder->build());
    }

    public function testMixedClauseResetKeepsMetadataInStepWithSql(): void
    {
        $builder = $this->joinedBuilder()->resetClauses('select', 'join', 'orderBy');
        self::assertSame([['scores', 's']], $this->sources($builder));
        $builder->select('*');
        self::assertSame('SELECT * FROM scores AS s', $builder->build());
    }

    public function testNonSourceAndUnknownClauseResetsDoNotLoseSources(): void
    {
        $builder = $this->joinedBuilder();
        $builder->resetClauses('limit', 'offset', 'orderBy', 'groupBy', 'unknown_clause', 'referencedTables');

        self::assertSame([['scores', 's'], ['programs', 'p'], ['scores', 'other']], $this->sources($builder));
        self::assertStringContainsString('RIGHT JOIN scores AS other', $builder->build());
    }

    public function testFailedBuildClearsSourcesThroughTheExistingResetPath(): void
    {
        $builder = (new QueryBuilder())->from($this->table('scores', 's'));
        try {
            $builder->build();
            self::fail('A missing select must fail.');
        } catch (QueryBuilderException $failure) {
            self::assertSame('Missing select field', $failure->getMessage());
        }

        self::assertSame([], $builder->getReferencedTables());
    }

    /** @dataProvider buildOutcomes */
    public function testReuseAfterBuildDoesNotResurrectOldRootOrJoins(bool $failFirstBuild): void
    {
        $builder = $this->joinedBuilder();
        if ($failFirstBuild) {
            $builder->resetClauses('select');
            try {
                $builder->build();
                self::fail('A missing select must fail.');
            } catch (QueryBuilderException $failure) {
                self::assertSame('Missing select field', $failure->getMessage());
            }
        } else {
            $builder->build();
        }
        $builder->from($this->table('fresh_root', 'f'))->select('*')
            ->leftJoin($this->table('fresh_join', 'j'), 'id', 'id');

        self::assertSame([['fresh_root', 'f'], ['fresh_join', 'j']], $this->sources($builder));
        self::assertSame('SELECT * FROM fresh_root AS f LEFT JOIN fresh_join AS j ON f.id = j.id', $builder->build());
        self::assertSame([], $builder->getReferencedTables());
    }

    /** @return array<string, array{bool}> */
    public static function buildOutcomes(): array
    {
        return ['successful build' => [false], 'failed build' => [true]];
    }

    public function testClonedBuildersHaveIndependentSourceState(): void
    {
        $original = $this->joinedBuilder();
        $copy = clone $original;
        $copy->reset()->from($this->table('copy', 'c'))->select('*');

        self::assertSame([['scores', 's'], ['programs', 'p'], ['scores', 'other']], $this->sources($original));
        self::assertSame([['copy', 'c']], $this->sources($copy));
        $original->build();
        self::assertSame([['copy', 'c']], $this->sources($copy));
    }

    /** @dataProvider descriptorMutations */
    public function testChangedDescriptorsAreRejectedBeforeBuild(string $source, string $field): void
    {
        $name = 'captured_table';
        $alias = 'captured_alias';
        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturnCallback(static function () use (&$name): string {
            return $name;
        });
        $table->method('getAlias')->willReturnCallback(static function () use (&$alias): string {
            return $alias;
        });
        $builder = new QueryBuilder();
        if ($source === 'root') {
            $builder->from($table)->select('*');
        } else {
            $builder->from($this->table('scores', 's'))->select('*');
            $method = $source === 'left' ? 'leftJoin' : 'rightJoin';
            $builder->$method($table, 'id', 'id');
        }

        if ($field === 'name') {
            $name = 'different_table';
        } else {
            $alias = 'different_alias';
        }

        $this->expectException(QueryBuilderException::class);
        $builder->getReferencedTables();
    }

    /** @return array<string, array{string, string}> */
    public static function descriptorMutations(): array
    {
        return [
            'root name' => ['root', 'name'], 'root alias' => ['root', 'alias'],
            'left name' => ['left', 'name'], 'left alias' => ['left', 'alias'],
            'right name' => ['right', 'name'], 'right alias' => ['right', 'alias'],
        ];
    }

    private function joinedBuilder(): QueryBuilder
    {
        return (new QueryBuilder())->from($this->table('scores', 's'))->select('*')
            ->leftJoin($this->table('programs', 'p'), 'programId', 'id')
            ->rightJoin($this->table('scores', 'other'), 'id', 'id');
    }

    /** @return list<array{string, string}> */
    private function sources(QueryBuilder $builder): array
    {
        return array_map(static fn (Table $table): array => [$table->getName(), $table->getAlias()], $builder->getReferencedTables());
    }

    private function table(string $name, string $alias): Table
    {
        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn($name);
        $table->method('getAlias')->willReturn($alias);
        return $table;
    }
}
