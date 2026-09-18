<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Contracts;

use Error;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\QueryBuilder as QueryBuilderInterface;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\MySql\Integration\Builders\MySqlClauseBuilder;
use PHPNomad\MySql\Integration\Builders\QueryBuilder;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Strategies\QueryStrategy;
use PHPNomad\MySql\Integration\Tests\Unit\Fixtures\BoundFormattingContractCase;
use PHPNomad\MySql\Integration\Tests\Unit\Fixtures\BoundClauseBuilder;
use PHPNomad\MySql\Integration\Tests\Unit\Fixtures\BoundQueryBuilder;
use RuntimeException;
use Throwable;

final class BoundQueryStrategyContractTest extends BoundFormattingContractCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testQueryFormatsAndExecutesThroughTheInjectedBackend(): void
    {
        $backend = $this->createMock(DatabaseStrategy::class);
        $backend->expects(self::once())->method('parse')->with('s.id = ?s', 7)->willReturn('s.id = BOUND');
        $rows = [['id' => 7, 'score' => 12]];
        $backend->expects(self::once())->method('query')->with('SELECT * FROM scores AS s WHERE s.id = BOUND')->willReturn($rows);
        $this->globalDatabase->expects(self::never())->method('parse');
        $table = $this->table();
        $where = (new MySqlClauseBuilder())->useTable($table)->where('id', '=', 7);
        $builder = (new QueryBuilder())->from($table)->select('*')->where($where);

        self::assertSame($rows, $this->strategy($backend)->query($builder));
    }

    /** @dataProvider writes */
    public function testWritePredicatesAndStatementsUseTheInjectedBackend(string $method): void
    {
        $backend = $this->createMock(DatabaseStrategy::class);
        $calls = [];
        $backend->expects(self::exactly(2))->method('parse')->willReturnCallback(
            static function (string $query, mixed ...$values) use (&$calls): string {
                $calls[] = [$query, $values];
                return count($calls) === 1 ? 's.id = BOUND' : 'WRITE';
            }
        );
        $backend->expects(self::once())->method('query')->with('WRITE')->willReturn(0);
        $this->globalDatabase->expects(self::never())->method('parse');
        $strategy = $this->strategy($backend);
        $table = $this->table();
        if ($method === 'update') {
            $strategy->update($table, ['id' => 7], ['score' => 12]);
        } else {
            $strategy->delete($table, ['id' => 7]);
        }

        self::assertSame([
            ['s.id = ?s', [7]],
            $method === 'update'
                ? ['UPDATE ?n AS ?n SET ?n = ?s WHERE s.id = BOUND', ['scores', 's', 'score', 12]]
                : ['DELETE ?n FROM ?n AS ?n WHERE s.id = BOUND', ['s', 'scores', 's']],
        ], $calls);
    }

    public function testAResourceIndependentCustomQueryBuilderRemainsCompatible(): void
    {
        $backend = $this->createMock(DatabaseStrategy::class);
        $backend->expects(self::never())->method('parse');
        $backend->expects(self::once())->method('query')->with('SELECT 1')->willReturn([['value' => 1]]);
        $this->globalDatabase->expects(self::never())->method('parse');
        $builder = $this->createMock(QueryBuilderInterface::class);
        $builder->expects(self::once())->method('build')->willReturn('SELECT 1');

        self::assertSame([['value' => 1]], $this->strategy($backend)->query($builder));
    }

    public function testACapableCustomQueryBuilderReceivesTheExecutionBackend(): void
    {
        $backend = $this->createMock(DatabaseStrategy::class);
        $backend->expects(self::never())->method('parse');
        $backend->expects(self::once())->method('query')->with('CUSTOM')->willReturn([['value' => 1]]);
        $this->globalDatabase->expects(self::never())->method('parse');
        $builder = $this->createMock(BoundQueryBuilder::class);
        $builder->expects(self::never())->method('build');
        $builder->expects(self::once())->method('buildWithDatabaseStrategy')->with(self::identicalTo($backend))->willReturn('CUSTOM');

        self::assertSame([['value' => 1]], $this->strategy($backend)->query($builder));
    }

    /** @dataProvider customWriteBuilders */
    public function testCustomWriteBuildersUseTheirSupportedPublicContract(string $method, bool $capable): void
    {
        $backend = $this->createMock(DatabaseStrategy::class);
        if ($method === 'update') {
            $backend->expects(self::once())->method('parse')
                ->with('UPDATE ?n AS ?n SET ?n = ?s WHERE CUSTOM', 'scores', 's', 'score', 12)->willReturn('WRITE');
        } else {
            $backend->expects(self::once())->method('parse')
                ->with('DELETE ?n FROM ?n AS ?n WHERE CUSTOM', 's', 'scores', 's')->willReturn('WRITE');
        }
        $backend->expects(self::once())->method('query')->with('WRITE')->willReturn(0);
        $this->globalDatabase->expects(self::never())->method('parse');
        $table = $this->table();
        $builder = $this->createMock($capable ? BoundClauseBuilder::class : ClauseBuilder::class);
        $builder->expects(self::once())->method('reset')->willReturnSelf();
        $builder->expects(self::once())->method('useTable')->with($table)->willReturnSelf();
        $builder->expects(self::once())->method('andWhere')->with('id', '=', 7)->willReturnSelf();
        if ($capable) {
            $builder->expects(self::never())->method('build');
            $builder->expects(self::once())->method('buildWithDatabaseStrategy')->with(self::identicalTo($backend))->willReturn('CUSTOM');
        } else {
            $builder->expects(self::once())->method('build')->willReturn('CUSTOM');
        }
        $strategy = new QueryStrategy($backend, $this->createMock(TableSchemaService::class), $builder);

        if ($method === 'update') {
            $strategy->update($table, ['id' => 7], ['score' => 12]);
        } else {
            $strategy->delete($table, ['id' => 7]);
        }
    }

    /** @dataProvider failurePaths */
    public function testFormattingFailureReachesTheCallerWithoutExecutingAQuery(string $method, string $kind): void
    {
        $failure = $kind === 'error' ? new Error('Parser failed') : new RuntimeException('Parser failed');
        $backend = $this->createMock(DatabaseStrategy::class);
        $backend->expects(self::once())->method('parse')->with('s.id = ?s', 7)->willThrowException($failure);
        $backend->expects(self::never())->method('query');
        $this->globalDatabase->expects(self::never())->method('parse');
        $strategy = $this->strategy($backend);
        $table = $this->table();
        $caught = null;
        try {
            if ($method === 'query') {
                $where = (new MySqlClauseBuilder())->useTable($table)->where('id', '=', 7);
                $strategy->query((new QueryBuilder())->from($table)->select('*')->where($where));
            } elseif ($method === 'update') {
                $strategy->update($table, ['id' => 7], ['score' => 12]);
            } else {
                $strategy->delete($table, ['id' => 7]);
            }
        } catch (Throwable $actual) {
            $caught = $actual;
        }

        self::assertSame($failure, $caught);
    }

    private function strategy(DatabaseStrategy $backend): QueryStrategy
    {
        return new QueryStrategy($backend, $this->createMock(TableSchemaService::class), new MySqlClauseBuilder());
    }

    /** @return array<string, array{string}> */
    public static function writes(): array
    {
        return ['update' => ['update'], 'delete' => ['delete']];
    }

    /** @return array<string, array{string, bool}> */
    public static function customWriteBuilders(): array
    {
        return [
            'capable update' => ['update', true], 'plain update' => ['update', false],
            'capable delete' => ['delete', true], 'plain delete' => ['delete', false],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function failurePaths(): array
    {
        $cases = [];
        foreach (['query', 'update', 'delete'] as $method) {
            foreach (['exception', 'error'] as $kind) {
                $cases[$method . ' ' . $kind] = [$method, $kind];
            }
        }
        return $cases;
    }
}
