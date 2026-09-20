<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Contracts;

use PDO;
use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Strategies\PdoDatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\TestCase;

final class PdoArgumentCountContractTest extends TestCase
{
    /**
     * @dataProvider mismatches
     * @param list<mixed> $arguments
     */
    public function testMismatchedCountsFailBeforeFormattingAnyValue(string $template, array $arguments): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('quote');
        $strategy = new PdoDatabaseStrategy(PdoConnection::fromPdo($pdo));
        $this->expectException(QueryBuilderException::class);
        $strategy->parse($template, ...$arguments);
    }

    public function testExplicitNullIsAnArgumentAndInsertedTokensAreLiteralData(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('quote')->with('literal ?s')->willReturn("'literal ?s'");
        $strategy = new PdoDatabaseStrategy(PdoConnection::fromPdo($pdo));
        self::assertSame("SELECT NULL, 'literal ?s'", $strategy->parse('SELECT ?s, ?s', null, 'literal ?s'));
    }

    /** @return array<string, array{string, list<mixed>}> */
    public static function mismatches(): array
    {
        return [
            'missing one string' => ['SELECT ?s', []],
            'missing second string' => ['SELECT ?s, ?s', ['first']],
            'extra string' => ['SELECT ?s', ['first', 'second']],
            'argument without placeholder' => ['SELECT 1', [99]],
            'missing identifier' => ['SELECT * FROM ?n', []],
            'missing number' => ['SELECT ?i', []],
            'missing list' => ['SELECT 1 IN (?a)', []],
            'missing assignments' => ['UPDATE t SET ?u', []],
            'missing raw fragment' => ['SELECT ?p', []],
        ];
    }
}
