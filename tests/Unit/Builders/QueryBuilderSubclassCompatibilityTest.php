<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Builders;

use PHPNomad\MySql\Integration\Tests\TestCase;

final class QueryBuilderSubclassCompatibilityTest extends TestCase
{
    /** @dataProvider propertyCollisionFixtures */
    public function testConsumerMetadataPropertyNameDoesNotCollideWithParentState(
        string $fixture,
        string $successMarker
    ): void {
        $command = escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg(dirname(__DIR__) . '/Fixtures/' . $fixture)
            . ' 2>&1';
        $lines = [];
        $exitCode = 0;
        exec($command, $lines, $exitCode);
        $output = implode("\n", $lines);

        self::assertSame(0, $exitCode, $output);
        self::assertSame($successMarker, $output);
    }

    /** @return array<string, array{string, string}> */
    public static function propertyCollisionFixtures(): array
    {
        return [
            'root source property' => [
                'RootQuerySourceSubclassCompatibilityFixture.php',
                'ROOT_QUERY_SOURCE_SUBCLASS_COMPATIBLE',
            ],
            'joined sources property' => [
                'JoinedQuerySourcesSubclassCompatibilityFixture.php',
                'JOINED_QUERY_SOURCES_SUBCLASS_COMPATIBLE',
            ],
        ];
    }
}
