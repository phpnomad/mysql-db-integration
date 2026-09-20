<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Strategies;

use PDO;
use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Strategies\PdoDatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\TestCase;

final class PdoArgumentCountValidationTest extends TestCase
{
    /**
     * @dataProvider mismatchedArgumentCounts
     * @param list<mixed> $arguments
     */
    public function testMismatchedArgumentCountsFailBeforeFormatting(
        string $query,
        array $arguments,
        string $message
    ): void {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('quote');
        $strategy = new PdoDatabaseStrategy(PdoConnection::fromPdo($pdo));

        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessage($message);

        $strategy->parse($query, ...$arguments);
    }

    public function testNullAndPlaceholderTextInsideArgumentsRemainValues(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())
            ->method('quote')
            ->with('literal ?s')
            ->willReturn("'literal ?s'");
        $strategy = new PdoDatabaseStrategy(PdoConnection::fromPdo($pdo));

        self::assertSame(
            "SELECT NULL, 'literal ?s'",
            $strategy->parse('SELECT ?s, ?s', null, 'literal ?s')
        );
    }

    /** @return array<string, array{string, list<mixed>, string}> */
    public static function mismatchedArgumentCounts(): array
    {
        return [
            'missing argument' => [
                'SELECT ?s, ?i',
                ['value'],
                'Query placeholder count 2 does not match argument count 1.',
            ],
            'extra argument' => [
                'SELECT ?s',
                ['value', 2],
                'Query placeholder count 1 does not match argument count 2.',
            ],
            'argument without placeholder' => [
                'SELECT 1',
                [1],
                'Query placeholder count 0 does not match argument count 1.',
            ],
        ];
    }
}
