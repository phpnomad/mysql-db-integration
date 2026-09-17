<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Builders;

use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\MySql\Integration\Builders\QueryBuilder;
use PHPNomad\MySql\Integration\Tests\TestCase;

final class QueryBuilderSourceInternalsTest extends TestCase
{
    public function testCapturedSourceKeepsTheTableObject(): void
    {
        $table = $this->table('scores', 's');

        self::assertSame($table, (new InspectableQueryBuilder())->captureSource($table)['table']);
    }

    public function testCapturedSourceKeepsTheSqlTableName(): void
    {
        $table = $this->table('scores', 's');

        self::assertSame('scores', (new InspectableQueryBuilder())->captureSource($table)['name']);
    }

    public function testCapturedSourceKeepsTheSqlTableAlias(): void
    {
        $table = $this->table('scores', 's');

        self::assertSame('s', (new InspectableQueryBuilder())->captureSource($table)['alias']);
    }

    public function testSourceValidationRejectsAChangedTableName(): void
    {
        $name = 'scores';
        $alias = 's';
        $table = $this->mutableTable($name, $alias);
        $builder = new InspectableQueryBuilder();
        $source = $builder->captureSource($table);
        $name = 'different_scores';

        $this->expectException(QueryBuilderException::class);
        $builder->validateSource($source);
    }

    public function testSourceValidationRejectsAChangedTableAlias(): void
    {
        $name = 'scores';
        $alias = 's';
        $table = $this->mutableTable($name, $alias);
        $builder = new InspectableQueryBuilder();
        $source = $builder->captureSource($table);
        $alias = 'different_alias';

        $this->expectException(QueryBuilderException::class);
        $builder->validateSource($source);
    }

    public function testLeftJoinUsesThePrependFieldExtensionPointForTheJoinedField(): void
    {
        $builder = (new JoinHookQueryBuilder())->from($this->table('scores', 's'));
        $builder->leftJoin($this->table('programs', 'p'), 'programId', 'id');

        self::assertSame(1, $builder->getJoinedFieldHookCalls());
    }

    public function testRightJoinUsesThePrependFieldExtensionPointForTheJoinedField(): void
    {
        $builder = (new JoinHookQueryBuilder())->from($this->table('scores', 's'));
        $builder->rightJoin($this->table('programs', 'p'), 'programId', 'id');

        self::assertSame(1, $builder->getJoinedFieldHookCalls());
    }

    private function table(string $name, string $alias): Table
    {
        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn($name);
        $table->method('getAlias')->willReturn($alias);

        return $table;
    }

    private function mutableTable(string &$name, string &$alias): Table
    {
        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturnCallback(static function () use (&$name): string {
            return $name;
        });
        $table->method('getAlias')->willReturnCallback(static function () use (&$alias): string {
            return $alias;
        });

        return $table;
    }
}

final class InspectableQueryBuilder extends QueryBuilder
{
    /** @return array{table: Table, name: string, alias: string} */
    public function captureSource(Table $table): array
    {
        return $this->captureQuerySource($table);
    }

    /** @param array{table: Table, name: string, alias: string} $source */
    public function validateSource(array $source): void
    {
        $this->assertQuerySourceIsUnchanged($source);
    }
}

final class JoinHookQueryBuilder extends QueryBuilder
{
    private int $joinedFieldHookCalls = 0;

    protected function prependField(string $field, ?Table $table = null): string
    {
        if ($table !== null) {
            $this->joinedFieldHookCalls++;
        }

        return parent::prependField($field, $table);
    }

    public function getJoinedFieldHookCalls(): int
    {
        return $this->joinedFieldHookCalls;
    }
}
