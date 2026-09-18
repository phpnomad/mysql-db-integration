<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Contracts;

use Error;
use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\MySql\Integration\Builders\MySqlClauseBuilder;
use PHPNomad\MySql\Integration\Tests\Unit\Fixtures\BoundFormattingContractCase;
use RuntimeException;
use Throwable;

/** Public predicate intake must preserve the complete requested condition. */
final class PredicateValidationContractTest extends BoundFormattingContractCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestIncomplete('Explicit predicate validation implementation is pending.');
    }

    /**
     * @dataProvider invalidConditions
     * @param string|list<string> $field
     */
    public function testRejectedConditionsDoNotChangeExistingClausesOrBindings(string $entry, string|array $field, string $operator): void
    {
        $builder = (new MySqlClauseBuilder())->useTable($this->table())->where('id', '=', 7);
        $this->globalDatabase->expects(self::once())->method('parse')
            ->with('s.id = ?s AND s.score = ?s', 7, 12)->willReturn('VALID');
        $failure = null;
        try {
            $builder->$entry($field, $operator, 999);
        } catch (QueryBuilderException $caught) {
            $failure = $caught;
        }
        self::assertInstanceOf(QueryBuilderException::class, $failure);
        self::assertSame('VALID', $builder->andWhere('score', '=', 12)->build());
    }

    /** @dataProvider invalidGroups */
    public function testRejectedGroupsDoNotLeaveAConnectorOrChildBehind(string $entry, string $logic): void
    {
        $builder = (new MySqlClauseBuilder())->useTable($this->table())->where('id', '=', 7);
        $child = $this->createMock(ClauseBuilder::class);
        $child->expects(self::never())->method('build');
        $this->globalDatabase->expects(self::once())->method('parse')
            ->with('s.id = ?s AND s.score = ?s', 7, 12)->willReturn('VALID');
        $failure = null;
        try {
            $builder->$entry($logic, $child);
        } catch (QueryBuilderException $caught) {
            $failure = $caught;
        }
        self::assertInstanceOf(QueryBuilderException::class, $failure);
        self::assertSame('VALID', $builder->andWhere('score', '=', 12)->build());
    }

    /** @dataProvider validGroups */
    public function testGroupLogicKeepsTheDocumentedCaseInsensitiveVocabulary(string $entry, string $logic): void
    {
        $builder = (new MySqlClauseBuilder())->useTable($this->table());
        $left = $this->createMock(ClauseBuilder::class);
        $right = $this->createMock(ClauseBuilder::class);
        $left->expects(self::once())->method('build')->willReturn('s.id IS NULL');
        $right->expects(self::once())->method('build')->willReturn('s.score IS NULL');
        $this->globalDatabase->expects(self::never())->method('parse');
        self::assertSame($builder, $builder->$entry($logic, $left, $right));
        self::assertSame('(s.id IS NULL ' . strtoupper($logic) . ' s.score IS NULL)', $builder->build());
    }

    /** @dataProvider descriptorFailures */
    public function testDescriptorFailuresEscapeUnchangedAndLeaveNoPartialCondition(string $entry, string $kind): void
    {
        $failure = $kind === 'error' ? new Error('Descriptor failed') : new RuntimeException('Descriptor failed');
        $table = $this->createMock(Table::class);
        $table->method('getColumns')->willThrowException($failure);
        $builder = (new MySqlClauseBuilder())->useTable($table);
        $caught = null;
        try {
            $builder->$entry('id', '=', 999);
        } catch (Throwable $actual) {
            $caught = $actual;
        }
        self::assertSame($failure, $caught);
        $this->globalDatabase->expects(self::once())->method('parse')->with('s.id = ?s', 7)->willReturn('VALID');
        self::assertSame('VALID', $builder->useTable($this->table())->where('id', '=', 7)->build());
    }

    /** @dataProvider validConditions */
    public function testKnownConditionsKeepTheirFieldHookAndOperatorNormalization(string $operator, string $suffix, bool $hasValue): void
    {
        $builder = new class extends MySqlClauseBuilder {
            protected function prependField(string $field, ?Table $table = null): string
            {
                return 'FIELD(' . parent::prependField($field, $table) . ')';
            }
        };
        $builder->useTable($this->table());
        if ($hasValue) {
            $this->globalDatabase->expects(self::once())->method('parse')
                ->with('FIELD(s.id) ' . $suffix, 7)->willReturn('VALID');
            self::assertSame($builder, $builder->where('id', $operator, 7));
            self::assertSame('VALID', $builder->build());
        } else {
            $this->globalDatabase->expects(self::never())->method('parse');
            self::assertSame($builder, $builder->where('id', $operator));
            self::assertSame('FIELD(s.id) ' . $suffix, $builder->build());
        }
    }

    /** @return array<string, array{string, string|list<string>, string}> */
    public static function invalidConditions(): array
    {
        $cases = [];
        $invalid = [
            'unknown operator' => ['id', 'UNKNOWN'],
            'empty operator' => ['id', ''],
            'unknown scalar' => ['missing', '='],
            'empty fields' => [[], '='],
            'all unknown fields' => [['missing'], '='],
            'unknown first' => [['missing', 'id'], '='],
            'unknown last' => [['id', 'missing'], '='],
        ];
        foreach (['where', 'andWhere', 'orWhere'] as $entry) {
            foreach ($invalid as $name => [$field, $operator]) {
                $cases[$entry . ' ' . $name] = [$entry, $field, $operator];
            }
        }
        return $cases;
    }

    /** @return array<string, array{string, string}> */
    public static function invalidGroups(): array
    {
        $cases = [];
        foreach (['group', 'andGroup', 'orGroup'] as $entry) {
            foreach (['', 'XOR', 'OR 1=1 OR'] as $logic) {
                $cases[$entry . ' ' . $logic] = [$entry, $logic];
            }
        }
        return $cases;
    }

    /** @return array<string, array{string, string}> */
    public static function validGroups(): array
    {
        $cases = [];
        foreach (['group', 'andGroup', 'orGroup'] as $entry) {
            foreach (['and', 'oR'] as $logic) {
                $cases[$entry . ' ' . $logic] = [$entry, $logic];
            }
        }
        return $cases;
    }

    /** @return array<string, array{string, string}> */
    public static function descriptorFailures(): array
    {
        $cases = [];
        foreach (['where', 'andWhere', 'orWhere'] as $entry) {
            foreach (['exception', 'error'] as $kind) {
                $cases[$entry . ' ' . $kind] = [$entry, $kind];
            }
        }
        return $cases;
    }

    /** @return array<string, array{string, string, bool}> */
    public static function validConditions(): array
    {
        return [
            'equals' => ['=', '= ?s', true],
            'case-insensitive like' => ['like', 'LIKE ?s', true],
            'case-insensitive in' => ['in', 'IN (?a)', true],
            'null without values' => ['is null', 'IS NULL ', false],
        ];
    }
}
