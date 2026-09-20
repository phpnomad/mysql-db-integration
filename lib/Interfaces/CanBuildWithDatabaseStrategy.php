<?php

namespace PHPNomad\MySql\Integration\Interfaces;

/** Optional one-shot value formatting on the caller's database resource. */
interface CanBuildWithDatabaseStrategy
{
    /**
     * Build through the existing public build method using this backend.
     * Supporting nested builders use the same backend. The call leaves no
     * backend binding behind, whether it returns or throws. Original failures
     * propagate unchanged. Ordinary zero-argument build behavior stays the same.
     * Nested custom builders must implement this interface or remain resource-independent.
     * No global facade binding changes and no query execution occur here.
     */
    public function buildWithDatabaseStrategy(DatabaseStrategy $database): string;
}
