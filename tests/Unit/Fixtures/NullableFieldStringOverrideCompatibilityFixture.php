<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Fixtures;

use PHPNomad\MySql\Integration\Builders\MySqlClauseBuilder;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

final class NullableFieldStringOverrideCompatibilityFixture extends MySqlClauseBuilder
{
    protected function getFieldString($field): ?string
    {
        return parent::getFieldString($field);
    }
}

new NullableFieldStringOverrideCompatibilityFixture();
fwrite(STDOUT, "NULLABLE_OVERRIDE_LOADED\n");
