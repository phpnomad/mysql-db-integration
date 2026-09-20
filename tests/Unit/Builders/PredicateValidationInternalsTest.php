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
        [$exitCode, $output] = $this->runFixture('NullableFieldStringOverrideCompatibilityFixture.php');

        self::assertSame(0, $exitCode, $output);
        self::assertSame('NULLABLE_OVERRIDE_LOADED', $output);
    }

    public function testNullableFieldStringOverrideCannotSilentlyDropACondition(): void
    {
        [$exitCode, $output] = $this->runFixture('NullFieldStringOverrideRejectionFixture.php');

        self::assertSame(0, $exitCode, $output);
        self::assertSame('NULL_OVERRIDE_REJECTED', $output);
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

    /**
     * @dataProvider valuedConditionValues
     * @param list<mixed> $values
     */
    public function testValuedConditionValuesRetainEveryPosition(string $operator, array $values): void
    {
        self::assertSame($values, (new PredicateValidationProbe())->conditionValues($operator, $values));
    }

    /** @dataProvider nullOperators */
    public function testNullOperatorsNormalizeTheirCompatibilityNull(string $operator): void
    {
        $probe = new PredicateValidationProbe();

        self::assertSame([], $probe->conditionValues($operator, []));
        self::assertSame([], $probe->conditionValues($operator, [null]));
    }

    /**
     * @dataProvider invalidConditionValues
     * @param list<mixed> $values
     */
    public function testInvalidConditionValueCountsAreRejected(string $operator, array $values): void
    {
        $this->expectException(QueryBuilderException::class);

        (new PredicateValidationProbe())->conditionValues($operator, $values);
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

    /** @return array<string, array{string, list<mixed>}> */
    public static function valuedConditionValues(): array
    {
        return [
            'scalar null' => ['=', [null]],
            'range lower null' => ['BETWEEN', [null, 20]],
            'range upper null' => ['NOT BETWEEN', [20, null]],
            'variadic list null' => ['IN', [10, null, 20]],
            'array list null' => ['NOT IN', [[10, null, 20]]],
            'compound tuples' => ['IN', [['id' => 1, 'score' => 10], ['id' => 2, 'score' => null]]],
            'explicit empty list' => ['IN', [[]]],
        ];
    }

    /** @return array<string, array{string}> */
    public static function nullOperators(): array
    {
        return ['is null' => ['IS NULL'], 'is not null' => ['IS NOT NULL']];
    }

    /** @return array<string, array{string, list<mixed>}> */
    public static function invalidConditionValues(): array
    {
        return [
            'scalar missing' => ['=', []],
            'scalar extra' => ['LIKE', [10, 20]],
            'range missing' => ['BETWEEN', [10]],
            'range extra' => ['NOT BETWEEN', [10, 20, 30]],
            'in empty' => ['IN', []],
            'not in empty' => ['NOT IN', []],
            'is null valued' => ['IS NULL', [10]],
            'is not null extra null' => ['IS NOT NULL', [null, null]],
        ];
    }

    private function probe(): PredicateValidationProbe
    {
        $table = $this->createMock(Table::class);
        $table->method('getAlias')->willReturn('s');
        $table->method('getColumns')->willReturn([new Column('id', 'BIGINT')]);

        return (new PredicateValidationProbe())->useTable($table);
    }

    /** @return array{int, string} */
    private function runFixture(string $fixture): array
    {
        $command = escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg(dirname(__DIR__) . '/Fixtures/' . $fixture)
            . ' 2>&1';
        $lines = [];
        $exitCode = 0;
        exec($command, $lines, $exitCode);

        return [$exitCode, implode("\n", $lines)];
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

    /**
     * @param list<mixed> $values
     * @return list<mixed>
     */
    public function conditionValues(string $operator, array $values): array
    {
        return $this->normalizeConditionValues($operator, $values);
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
