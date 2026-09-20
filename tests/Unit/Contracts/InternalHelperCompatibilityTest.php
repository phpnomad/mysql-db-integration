<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Contracts;

use PHPNomad\MySql\Integration\Tests\TestCase;

final class InternalHelperCompatibilityTest extends TestCase
{
    /** @dataProvider helpers */
    public function testConsumerMethodDoesNotCollideWithInternalHelper(string $parent, string $method): void
    {
        $script = 'require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
            . 'class Consumer extends \\' . $parent . ' {'
            . 'protected function ' . $method . '(int $value): bool { return $value === 7; }'
            . 'public function consumerBehavior(): bool { return $this->' . $method . '(7); }'
            . '}'
            . '$consumer = (new ReflectionClass(Consumer::class))->newInstanceWithoutConstructor();'
            . 'if (!$consumer->consumerBehavior()) { exit(1); }'
            . 'echo "CONSUMER_HELPER_COMPATIBLE";';
        $lines = [];
        $exitCode = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $lines, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $lines));
        self::assertSame(['CONSUMER_HELPER_COMPATIBLE'], $lines);
    }

    /** @return array<string, array{string, string}> */
    public static function helpers(): array
    {
        return [
            'predicate normalization' => [
                'PHPNomad\\MySql\\Integration\\Builders\\MySqlClauseBuilder',
                'normalizeConditionValues',
            ],
            'placeholder count' => [
                'PHPNomad\\MySql\\Integration\\Strategies\\PdoDatabaseStrategy',
                'assertArgumentCountMatches',
            ],
        ];
    }
}
