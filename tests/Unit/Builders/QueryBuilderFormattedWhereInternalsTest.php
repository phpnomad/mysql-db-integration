<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Builders;

use PHPNomad\MySql\Integration\Builders\MySqlClauseBuilder;
use PHPNomad\MySql\Integration\Builders\QueryBuilder;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\Unit\Fixtures\BoundFormattingContractCase;

final class QueryBuilderFormattedWhereInternalsTest extends BoundFormattingContractCase
{
    public function testPreparedArgumentsStayOrderedAroundAnOpaqueWhereClause(): void
    {
        $literal = 'literal ?s ?n ?i ?a ?u ?p';
        $formattedWhere = "s.score = 'literal ?s ?n ?i ?a ?u ?p'";
        $calls = [];
        $database = $this->createMock(DatabaseStrategy::class);
        $database->expects(self::never())->method('query');
        $database->expects(self::exactly(2))->method('parse')->willReturnCallback(
            static function (string $sql, mixed ...$arguments) use (&$calls, $formattedWhere): string {
                $calls[] = [$sql, $arguments];

                return count($calls) === 1 ? $formattedWhere : 'BOUND';
            }
        );
        $this->globalDatabase->expects(self::never())->method('parse');
        $table = $this->table();
        $where = (new MySqlClauseBuilder())->useTable($table)->where('score', '=', $literal);
        $query = (new PreparedWhereOrderingQueryBuilder())
            ->from($table)
            ->select('*')
            ->withPreparedExtensions('before ?s', 'after ?n', 5)
            ->where($where);

        self::assertSame('BOUND', $query->buildWithDatabaseStrategy($database));
        self::assertSame([
            ['s.score = ?s', [$literal]],
            [
                'SELECT * FROM scores AS s JOIN_HINT ?s WHERE ?p ORDER BY ?n LIMIT ?i',
                ['before ?s', $formattedWhere, 'after ?n', 5],
            ],
        ], $calls);
    }

    public function testWhereClauseStaysInlineWhenNoOuterPreparationIsNeeded(): void
    {
        $literal = 'literal ?s ?n ?i ?a ?u ?p';
        $formattedWhere = "s.score = 'literal ?s ?n ?i ?a ?u ?p'";
        $database = $this->createMock(DatabaseStrategy::class);
        $database->expects(self::never())->method('query');
        $database->expects(self::once())->method('parse')
            ->with('s.score = ?s', $literal)->willReturn($formattedWhere);
        $this->globalDatabase->expects(self::never())->method('parse');
        $table = $this->table();
        $where = (new MySqlClauseBuilder())->useTable($table)->where('score', '=', $literal);
        $query = (new QueryBuilder())->from($table)->select('*')->where($where);

        self::assertSame(
            'SELECT * FROM scores AS s WHERE ' . $formattedWhere,
            $query->buildWithDatabaseStrategy($database)
        );
    }
}

/** Exercises prepared descriptors on both sides of the WHERE extension point. */
final class PreparedWhereOrderingQueryBuilder extends QueryBuilder
{
    public function withPreparedExtensions(string $before, string $after, int $limit): self
    {
        $this->join = ['JOIN_HINT', ['type' => '?s', 'value' => $before]];
        $this->orderBy = ['ORDER BY', ['type' => '?n', 'value' => $after]];
        $this->limit = ['LIMIT', ['type' => '?i', 'value' => $limit]];

        return $this;
    }
}
