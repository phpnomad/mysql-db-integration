<?php

namespace PHPNomad\MySql\Integration\Interfaces;

use PHPNomad\Database\Interfaces\Table;

/** Optional backend coordination used by the integration's query strategy. */
interface CoordinatedDatabaseStrategy extends DatabaseStrategy
{
    /**
     * Own one coordinated operation on this database resource without retries.
     *
     * Unsupported participants or ambient operations fail before the callback
     * or any write. All participating writes commit together. The callback
     * must use only the supplied backend and perform no external effects.
     * This seam does not expose application datastore operations.
     *
     * @template TResult
     * @param non-empty-array<string, int|string> $identity
     * @param non-empty-list<Table> $participants
     * @param callable(DatabaseStrategy): TResult $operation
     * @return TResult
     * @throws \InvalidArgumentException Invalid identity or participant input.
     * @throws \PHPNomad\Database\Exceptions\UnsupportedCoordinationException
     * @throws \PHPNomad\Database\Exceptions\CoordinatedOperationConflictException
     * @throws \PHPNomad\Database\Exceptions\CoordinatedOperationOutcomeUnknownException
     * @throws \PHPNomad\Datastore\Exceptions\RecordNotFoundException
     * @throws \Throwable Original callback failure after confirmed rollback.
     */
    public function coordinate(
        Table $coordinationTable,
        array $identity,
        array $participants,
        callable $operation
    );
}
