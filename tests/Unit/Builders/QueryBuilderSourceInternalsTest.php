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
        $builder = (new JoinHookQueryBuilder())->from($this->table('scores', 's'))->select('*');
        $builder->leftJoin($this->table('programs', 'p'), 'programId', 'id');

        self::assertSame(
            'SELECT * FROM scores AS s LEFT JOIN programs AS p ON s.programId = JOINED_FIELD(p.id)',
            $builder->build()
        );
    }

    public function testRightJoinUsesThePrependFieldExtensionPointForTheJoinedField(): void
    {
        $builder = (new JoinHookQueryBuilder())->from($this->table('scores', 's'))->select('*');
        $builder->rightJoin($this->table('programs', 'p'), 'programId', 'id');

        self::assertSame(
            'SELECT * FROM scores AS s RIGHT JOIN programs AS p ON s.programId = JOINED_FIELD(p.id)',
            $builder->build()
        );
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
    protected function prependField(string $field, ?Table $table = null): string
    {
        if ($table !== null) {
            return 'JOINED_FIELD(' . parent::prependField($field, $table) . ')';
        }

        return parent::prependField($field, $table);
    }
}
