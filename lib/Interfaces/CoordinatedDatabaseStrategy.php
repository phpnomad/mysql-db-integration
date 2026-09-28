<?php

namespace PHPNomad\MySql\Integration\Interfaces;

use PHPNomad\Database\Interfaces\Table;

/** Optional backend coordination used by the integration's query strategy. */
interface CoordinatedDatabaseStrategy extends DatabaseStrategy
{
    /**
     * Own one coordinated operation on this database resource without retries.
     *
     * Within the adapter's documented deployment preconditions, observable
     * unsupported participants or ambient operations fail before the callback
     * or any write. All participating writes commit together. The callback
     * must use only the supplied backend and declared tables, perform no
     * external effects, and leave transaction and schema control to the owner.
     * Supplied queries must refuse observable ownership loss before execution
     * and detect it before returning. This is not a sandbox for arbitrary SQL.
     * Error numbers alone do not establish that an inactive attempt rolled back.
     * This seam does not expose application datastore operations.
     *
     * @template TResult
     * @param non-empty-array<string, int|string> $identity
     * @param non-empty-list<Table> $participants
     * @param callable(DatabaseStrategy): TResult $operation
     * @return TResult
     * @throws \InvalidArgumentException Invalid identity or participant input.
     * @throws \PHPNomad\Database\Exceptions\UnsupportedCoordinationException
     * @throws \PHPNomad\Database\Exceptions\CoordinatedOperationConflictException Retry-eligible only after whole-attempt rollback.
     * @throws \PHPNomad\Database\Exceptions\CoordinatedOperationOutcomeUnknownException Uncertain outcome, not automatically retryable.
     * @throws \PHPNomad\Database\Exceptions\CoordinatedOperationCleanupFailedException Unconfirmed rollback after an earlier failure, retaining both original causes.
     * @throws \PHPNomad\Database\Exceptions\CoordinatedOperationReportingFailedException Reporting transport failure, retaining the classified operation failure. Retry classification comes only from the retained operation failure.
     * @throws \PHPNomad\Datastore\Exceptions\RecordNotFoundException
     * @throws \PHPNomad\Datastore\Exceptions\DatastoreErrorException Ordinary query failure, or failed commit followed by confirmed rollback.
     * @throws \Throwable Original callback failure after confirmed rollback.
     */
    public function coordinate(
        Table $coordinationTable,
        array $identity,
        array $participants,
        callable $operation
    );
}
