<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Builders;

use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\MySql\Integration\Builders\MySqlClauseBuilder;
use PHPNomad\MySql\Integration\Tests\TestCase;

final class PredicateValidationInternalsTest extends TestCase
{
    public function testConditionRejectsAnUnknownOperator(): void
    {
        $this->expectException(QueryBuilderException::class);

        (new PredicateValidationProbe())->condition('id', 'UNKNOWN', [7]);
    }

    public function testConditionNormalizesAKnownOperator(): void
    {
        $probe = $this->probe();

        $probe->condition('id', 'like', [7]);

        self::assertSame(['s.id', 'LIKE', '?s'], $probe->clauseParts());
    }

    public function testFieldStringRejectsAnUnknownScalarField(): void
    {
        $this->expectException(QueryBuilderException::class);

        $this->probe()->fieldString('missing');
    }

    public function testExistingNullableFieldStringOverrideRemainsCompatible(): void
    {
        $table = $this->createMock(Table::class);
        $table->method('getAlias')->willReturn('s');
        $table->method('getColumns')->willReturn([new Column('id', 'BIGINT')]);
        $probe = (new NullableFieldStringOverrideClauseBuilder())->useTable($table);

        self::assertSame('s.id', $probe->fieldString('id'));
    }

    public function testNullableFieldStringOverrideCannotSilentlyDropACondition(): void
    {
        $this->expectException(QueryBuilderException::class);

        (new NullFieldStringOverrideClauseBuilder())->condition('id', '=', [7]);
    }

    public function testFieldStringRejectsAnEmptyFieldList(): void
    {
        $this->expectException(QueryBuilderException::class);

        $this->probe()->fieldString([]);
    }

    /**
     * @dataProvider listsWithAnUnknownMember
     * @param list<string> $fields
     */
    public function testFieldStringRejectsAnyUnknownListMember(array $fields): void
    {
        $this->expectException(QueryBuilderException::class);

        $this->probe()->fieldString($fields);
    }

    public function testRangePlaceholderRetainsBothBounds(): void
    {
        $probe = new PredicateValidationProbe();

        self::assertSame('?s AND ?s', $probe->placeholder('BETWEEN'));
        self::assertSame('?s AND ?s', $probe->placeholder('NOT BETWEEN'));
    }

    /** @dataProvider validGroupLogic */
    public function testGroupLogicNormalizesTheValidVocabulary(string $logic, string $expected): void
    {
        self::assertSame($expected, (new PredicateValidationProbe())->groupLogic($logic));
    }

    /** @dataProvider invalidGroupLogic */
    public function testGroupLogicRejectsAnythingOutsideTheValidVocabulary(string $logic): void
    {
        $this->expectException(QueryBuilderException::class);

        (new PredicateValidationProbe())->groupLogic($logic);
    }

    /** @return array<string, array{list<string>}> */
    public static function listsWithAnUnknownMember(): array
    {
        return [
            'unknown first' => [['missing', 'id']],
            'unknown last' => [['id', 'missing']],
            'all unknown' => [['missing']],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function validGroupLogic(): array
    {
        return ['and' => ['and', 'AND'], 'mixed-case or' => ['oR', 'OR']];
    }

    /** @return array<string, array{string}> */
    public static function invalidGroupLogic(): array
    {
        return ['empty' => [''], 'unknown' => ['XOR'], 'injection-shaped' => ['OR 1=1 OR']];
    }

    private function probe(): PredicateValidationProbe
    {
        $table = $this->createMock(Table::class);
        $table->method('getAlias')->willReturn('s');
        $table->method('getColumns')->willReturn([new Column('id', 'BIGINT')]);

        return (new PredicateValidationProbe())->useTable($table);
    }
}

final class PredicateValidationProbe extends MySqlClauseBuilder
{
    /** @param string|list<string> $field */
    public function fieldString(string|array $field): ?string
    {
        return $this->getFieldString($field);
    }

    /** @param list<mixed> $values */
    public function condition(string $field, string $operator, array $values): self
    {
        return $this->addCondition($field, $operator, $values);
    }

    public function placeholder(string $operator): string
    {
        return $this->generatePlaceholder('id', [7, 9], $operator);
    }

    public function groupLogic(string $logic): string
    {
        return $this->normalizeGroupLogic($logic);
    }

    /** @return list<mixed> */
    public function clauseParts(): array
    {
        return $this->clauses;
    }
}

final class NullableFieldStringOverrideClauseBuilder extends MySqlClauseBuilder
{
    protected function getFieldString($field): ?string
    {
        return parent::getFieldString($field);
    }

    public function fieldString(string $field): ?string
    {
        return $this->getFieldString($field);
    }
}

final class NullFieldStringOverrideClauseBuilder extends MySqlClauseBuilder
{
    protected function getFieldString($field): ?string
    {
        return null;
    }

    /** @param list<mixed> $values */
    public function condition(string $field, string $operator, array $values): self
    {
        return $this->addCondition($field, $operator, $values);
    }
}
