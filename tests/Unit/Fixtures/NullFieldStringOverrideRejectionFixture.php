<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Fixtures;

use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\MySql\Integration\Builders\MySqlClauseBuilder;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

final class NullFieldStringOverrideRejectionFixture extends MySqlClauseBuilder
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

try {
    (new NullFieldStringOverrideRejectionFixture())->condition('id', '=', [7]);
} catch (QueryBuilderException) {
    fwrite(STDOUT, "NULL_OVERRIDE_REJECTED\n");
    exit(0);
}

fwrite(STDERR, "NULL_OVERRIDE_WAS_SILENT\n");
exit(1);
