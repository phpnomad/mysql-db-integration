<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Contracts;

use Error;
use PHPNomad\Cache\Interfaces\CachePolicy;
use PHPNomad\Cache\Interfaces\CacheStrategy;
use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Events\Interfaces\EventStrategy;
use PHPNomad\MySql\Integration\Builders\MySqlClauseBuilder;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Strategies\QueryStrategy;
use PHPNomad\MySql\Integration\Tests\Fixtures\InsertIdentifierTable;
use PHPNomad\MySql\Integration\Tests\Unit\Fixtures\BoundFormattingContractCase;
use RuntimeException;
use Throwable;

/** Distinguishes the backend's identifier path from ordinary value formatting. */
final class InsertIdentifierContractTest extends BoundFormattingContractCase
{
    /** @dataProvider columnNames */
    public function testEveryColumnUsesTheInjectedIdentifierFormatter(string $column): void
    {
        $table = new InsertIdentifierTable('insert_contract');
        $backend = $this->createMock(DatabaseStrategy::class);
        $this->globalDatabase->expects(self::never())->method('parse');
        $identifiers = [];
        $values = [];
        $backend->method('parse')->willReturnCallback(static function (string $sql, mixed ...$arguments) use (&$identifiers, &$values): string {
            $offset = 0;
            $result = preg_replace_callback('/\?[nsp]/', static function (array $match) use (&$offset, $arguments, &$identifiers, &$values): string {
                $value = $arguments[$offset++];
                if ($match[0] === '?p') {
                    self::assertIsString($value);
                    return $value;
                }
                if ($match[0] === '?n') {
                    self::assertIsString($value);
                    $identifiers[] = $value;
                    return 'I<' . $value . '>';
                }
                $values[] = $value;
                return 'V<' . json_encode($value, JSON_THROW_ON_ERROR) . '>';
            }, $sql);
            self::assertIsString($result);
            return $result;
        });
        $query = null;
        $backend->expects(self::once())->method('query')->willReturnCallback(static function (string $sql) use (&$query): int {
            $query = $sql;
            return 1;
        });
        $value = "a\\b'c ?n";
        self::assertSame(['id' => 7], $this->strategy($backend)->insert($table, ['id' => 7, $column => $value]));
        self::assertEqualsCanonicalizing(['insert_contract', 'id', $column], $identifiers);
        self::assertSame([7, $value], $values);
        self::assertIsString($query);
        self::assertStringContainsString('I<insert_contract>', $query);
        self::assertStringContainsString('I<id>', $query);
        self::assertStringContainsString('I<' . $column . '>', $query);
        self::assertStringContainsString('V<7>', $query);
        self::assertStringContainsString('V<' . json_encode($value, JSON_THROW_ON_ERROR) . '>', $query);
        self::assertLessThan(
            strpos($query, 'I<' . $column . '>'),
            strpos($query, 'I<id>'),
            'Executed column order must preserve its association with the values.'
        );
        self::assertLessThan(
            strpos($query, 'V<' . json_encode($value, JSON_THROW_ON_ERROR) . '>'),
            strpos($query, 'V<7>'),
            'Executed values must retain the same order as their columns.'
        );
    }

    /** @dataProvider failureKinds */
    public function testFormattingFailurePropagatesBeforeExecution(string $kind): void
    {
        $original = $kind === 'error' ? new Error('Identifier unavailable') : new RuntimeException('Identifier unavailable');
        $backend = $this->createMock(DatabaseStrategy::class);
        $backend->method('parse')->willReturnCallback(static function (string $sql, mixed ...$arguments) use ($original): string {
            $offset = 0;
            $result = preg_replace_callback('/\?[nsp]/', static function (array $match) use (&$offset, $arguments, $original): string {
                $value = $arguments[$offset++];
                if ($match[0] === '?n' && $value === 'order') {
                    throw $original;
                }
                if ($match[0] === '?p') {
                    self::assertIsString($value);
                    return $value;
                }
                return json_encode($value, JSON_THROW_ON_ERROR);
            }, $sql);
            self::assertIsString($result);
            return $result;
        });
        $queryCount = 0;
        $backend->method('query')->willReturnCallback(static function (string $sql) use (&$queryCount): int {
            $queryCount++;
            return 1;
        });
        $caught = null;
        try {
            $this->strategy($backend)->insert(new InsertIdentifierTable('insert_contract'), ['id' => 7, 'order' => 'value']);
        } catch (Throwable $failure) {
            // The injected failure is the expected assertion subject, not a recovered operation.
            $caught = $failure;
        }
        self::assertSame($original, $caught);
        self::assertSame(0, $queryCount, 'A column-formatting failure must occur before database execution.');
    }

    public function testEmptyDataStillCreatesADefaultRowAndResolvesItsIdentity(): void
    {
        $backend = $this->createMock(DatabaseStrategy::class);
        $backend->expects(self::once())->method('parse')
            ->with('INSERT INTO ?n () VALUES ()', 'insert_contract')->willReturn('DEFAULT INSERT');
        $queries = [];
        $backend->expects(self::exactly(2))->method('query')->willReturnCallback(static function (string $query) use (&$queries): int|array {
            $queries[] = $query;
            return $query === 'DEFAULT INSERT' ? 1 : [['LAST_INSERT_ID()' => '17']];
        });
        self::assertSame(['id' => 17], $this->strategy($backend)->insert(new InsertIdentifierTable('insert_contract'), []));
        self::assertSame(['DEFAULT INSERT', 'SELECT LAST_INSERT_ID()'], $queries);
    }

    public function testInsertPreservesTheIdentityResolverExtension(): void
    {
        $timeline = [];
        $backend = $this->createMock(DatabaseStrategy::class);
        $backend->method('parse')->willReturn('FORMATTED INSERT');
        $backend->expects(self::once())->method('query')->with('FORMATTED INSERT')->willReturnCallback(static function () use (&$timeline): int {
            $timeline[] = 'insert';
            return 1;
        });
        $schema = $this->createMock(TableSchemaService::class);
        $strategy = new class ($backend, $schema, new MySqlClauseBuilder()) extends QueryStrategy {
            /** @var list<array{Table, array<string, mixed>}> */
            public array $identityCalls = [];
            /** @var list<string> */
            public array $timeline = [];

            /** @param array<string, mixed> $data
             * @return array<string, int>
             */
            protected function resolveInsertIdentity(Table $table, array $data): array
            {
                $this->timeline[] = 'identity';
                $this->identityCalls[] = [$table, $data];
                return ['customIdentity' => 91];
            }
        };
        $strategy->timeline = &$timeline;
        $table = new InsertIdentifierTable('insert_contract');
        $data = ['id' => 7, 'order' => 'value'];
        self::assertSame(['customIdentity' => 91], $strategy->insert($table, $data));
        self::assertSame([[$table, $data]], $strategy->identityCalls);
        self::assertSame(['insert', 'identity'], $timeline);
    }

    private function strategy(DatabaseStrategy $backend): QueryStrategy
    {
        $cache = $this->createMock(CacheStrategy::class);
        $cache->method('exists')->willReturn(false);
        $policy = $this->createMock(CachePolicy::class);
        $policy->method('getCacheKey')->willReturn('insert_contract');
        $policy->method('getTtl')->willReturn(null);
        $events = $this->createMock(EventStrategy::class);
        $schema = new TableSchemaService(new CacheableService($events, $cache, $policy));
        return new QueryStrategy($backend, $schema, new MySqlClauseBuilder());
    }

    /** @return array<string, array{string}> */
    public static function columnNames(): array
    {
        return [
            'plain' => ['label'], 'reserved' => ['order'], 'backtick' => ['tick`label'],
            'placeholder' => ['?s'], 'dot' => ['with.dot'], 'syntax' => ['id) VALUES (99) -- '],
        ];
    }

    /** @return array<string, array{string}> */
    public static function failureKinds(): array
    {
        return ['exception' => ['exception'], 'error' => ['error']];
    }
}
