<?php

namespace PHPNomad\MySql\Integration\Strategies;

use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use PHPNomad\Database\Exceptions\CoordinatedOperationCleanupFailedException;
use PHPNomad\Database\Exceptions\CoordinatedOperationConflictException;
use PHPNomad\Database\Exceptions\CoordinatedOperationOutcomeUnknownException;
use PHPNomad\Database\Exceptions\CoordinatedOperationReportingFailedException;
use PHPNomad\Database\Exceptions\UnsupportedCoordinationException;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Interfaces\CoordinatedDatabaseStrategy;
use Throwable;

/**
 * Optional coordination on one explicitly opted-in PDO connection.
 * Supported grants must be final before that connection opens, including when
 * the application injects an existing PDO. Grants, roles, partial revokes, and
 * privilege tables must remain stable. Administrative changes invalidate this
 * capability until the application creates a fresh PDO and strategy.
 * Historical privilege changes cannot be detected from current grant metadata.
 * Refusal covers unsupported conditions observable on an eligible connection.
 * Participant descriptors supply names only. Primary-key admission comes from
 * storage metadata and the coordination key is rechecked after its row guard.
 * Coordination requires MySQL 8 role and session metadata. Older servers are
 * refused before the adapter starts a transaction or invokes the callback.
 */
class PdoCoordinatedDatabaseStrategy extends PdoDatabaseStrategy implements CoordinatedDatabaseStrategy
{
    protected LoggerStrategy $logger;

    protected ?PDO $ownedAttemptPdo = null;

    protected ?Throwable $inactiveAbortEvidence = null;

    public function __construct(PdoConnection $connection, LoggerStrategy $logger)
    {
        parent::__construct($connection);
        $this->logger = $logger;
    }

    /** @inheritDoc */
    public function coordinate(
        Table $coordinationTable,
        array $identity,
        array $participants,
        callable $operation
    ) {
        $tableNames = [];

        try {
            $definitions = $this->validateRequest($coordinationTable, $identity, $participants, $tableNames);
            $pdo = $this->connection->pdo();
            $schema = $this->validateSession($pdo);
            $this->validateTriggerVisibility($pdo, $schema, $tableNames);
            $coordinationIdentity = $this->validateCompleteIdentity(
                $this->readPrimaryFields($pdo, $schema, $definitions[0]['name']),
                $identity
            );
        } catch (Throwable $failure) {
            $this->reportFailure('validation', $tableNames, 'unchanged', false, $failure);
            throw $failure;
        }

        try {
            if (!$pdo->beginTransaction()) {
                throw $this->driverFailure($pdo, 'Could not start the coordinated database operation.');
            }
        } catch (Throwable $failure) {
            $this->reportFailure('coordination', $tableNames, 'unchanged', false, $failure);
            throw $failure;
        }

        $this->openOwnedAttempt($pdo);

        try {
            try {
                $this->guardCoordinationRecord($pdo, $definitions[0]['name'], $coordinationIdentity, $identity);
                $this->validateParticipants($pdo, $schema, $definitions, $coordinationIdentity);
            } catch (Throwable $failure) {
                $this->abortAttempt($pdo, 'coordination', $tableNames, $failure);
            }

            try {
                $result = $operation($this);
            } catch (Throwable $failure) {
                $this->abortAttempt($pdo, 'callback', $tableNames, $failure);
            }

            if (!$pdo->inTransaction()) {
                $cause = new DatastoreErrorException('The coordinated operation lost transaction ownership.');
                $failure = new CoordinatedOperationOutcomeUnknownException(
                    'The coordinated database operation outcome is unknown.',
                    0,
                    $cause
                );
                $this->reportFailure('commit', $tableNames, 'unknown', false, $failure, $cause);
                throw $failure;
            }

            try {
                if (!$pdo->commit()) {
                    throw $this->driverFailure($pdo, 'The coordinated database commit was not acknowledged.');
                }
            } catch (Throwable $failure) {
                $this->handleCommitFailure($pdo, $tableNames, $failure);
            }

            return $result;
        } finally {
            $this->closeOwnedAttempt();
        }
    }

    /** @inheritDoc */
    public function query(string $query)
    {
        $pdo = $this->connection->pdo();
        $enteredOwned = $this->enterOwnedStatement($pdo);

        try {
            $result = parent::query($query);
        } catch (Throwable $failure) {
            $this->observeInactiveDriverFailure($pdo, $failure);
            throw $failure;
        }

        $this->requireRetainedOwnership($pdo, $enteredOwned);

        return $result;
    }

    /**
     * @param array<array-key, mixed> $identity
     * @param array<array-key, mixed> $participants
     * @param list<string> $tableNames
     * @return non-empty-list<array{table: Table, name: string}>
     */
    protected function validateRequest(
        Table $coordinationTable,
        array $identity,
        array $participants,
        array &$tableNames
    ): array
    {
        $definitionsByIndex = [];
        foreach ($participants as $index => $participant) {
            if ($participant instanceof Table) {
                $name = $participant->getName();
                $tableNames[] = $name;
                $definitionsByIndex[$index] = ['table' => $participant, 'name' => $name];
            }
        }

        if ($participants === [] || !array_is_list($participants)) {
            throw new InvalidArgumentException('Participants must be a nonempty list of tables.');
        }

        foreach ($participants as $index => $participant) {
            if (!$participant instanceof Table) {
                throw new InvalidArgumentException('Every participant must be a table descriptor.');
            }

            $name = $definitionsByIndex[$index]['name'];
            if (!$this->isValidIdentifier($name)) {
                throw new InvalidArgumentException('Every participant must have a valid table name.');
            }
        }

        $definitions = array_values($definitionsByIndex);

        $coordinationIndex = null;
        foreach ($definitions as $index => $definition) {
            if ($definition['table'] === $coordinationTable) {
                $coordinationIndex = $index;
                break;
            }
        }
        if ($coordinationIndex === null) {
            $coordinationName = $coordinationTable->getName();
            foreach ($definitions as $index => $definition) {
                if ($definition['name'] === $coordinationName) {
                    $coordinationIndex = $index;
                    break;
                }
            }
        }
        if ($coordinationIndex === null) {
            throw new InvalidArgumentException('The coordination table must be a participant.');
        }
        $identityKeys = array_keys($identity);
        if ($identityKeys === []) {
            throw new InvalidArgumentException('The coordination identity must be nonempty.');
        }
        foreach ($identityKeys as $field) {
            if (!is_string($field) || !$this->isValidIdentifier($field)) {
                throw new InvalidArgumentException('Coordination identity fields must be valid names.');
            }
            if (!is_int($identity[$field]) && !is_string($identity[$field])) {
                throw new InvalidArgumentException('Coordination identity values must be integers or strings.');
            }
        }

        $ordered = [$definitions[$coordinationIndex]];
        array_splice($definitions, $coordinationIndex, 1);
        array_push($ordered, ...$definitions);

        return $ordered;
    }

    /**
     * @param list<string> $primaryFields
     * @param array<array-key, mixed> $identity
     * @return non-empty-list<string>
     */
    protected function validateCompleteIdentity(array $primaryFields, array $identity): array
    {
        $identityFields = array_keys($identity);
        if ($primaryFields === []) {
            throw new UnsupportedCoordinationException('The coordination resource must have a primary key.');
        }
        if (
            count($identityFields) !== count($primaryFields)
            || array_diff($primaryFields, $identityFields) !== []
            || array_diff($identityFields, $primaryFields) !== []
        ) {
            throw new InvalidArgumentException('The coordination identity must contain every primary field exactly once.');
        }

        return $primaryFields;
    }

    protected function validateSession(PDO $pdo): string
    {
        if ($pdo->inTransaction()) {
            throw new UnsupportedCoordinationException('Coordination cannot join an ambient transaction.');
        }
        if ($pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_WARNING) {
            throw new UnsupportedCoordinationException('PDO warning mode is unsupported for coordination.');
        }

        $versionStatement = $this->queryStatement($pdo, 'SELECT VERSION()');
        $serverVersion = $versionStatement->fetchColumn();
        if (!is_string($serverVersion) || version_compare($serverVersion, '8.0.0', '<')) {
            throw new UnsupportedCoordinationException('This database version is unsupported for coordination.');
        }

        $statement = $this->queryStatement(
            $pdo,
            'SELECT @@autocommit AS autocommit, @@SESSION.transaction_isolation AS isolation,
                DATABASE() AS schemaName, CURRENT_ROLE() AS currentRole'
        );
        $state = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($state)) {
            throw new UnsupportedCoordinationException('The database session state could not be established.');
        }
        if ((string) ($state['autocommit'] ?? '') !== '1') {
            throw new UnsupportedCoordinationException('Disabled autocommit is unsupported for coordination.');
        }
        if (!in_array($state['isolation'] ?? null, ['READ-COMMITTED', 'REPEATABLE-READ'], true)) {
            throw new UnsupportedCoordinationException('The selected transaction isolation is unsupported.');
        }
        if (($state['currentRole'] ?? null) !== 'NONE') {
            throw new UnsupportedCoordinationException('Active database roles are unsupported for coordination.');
        }

        $schema = $state['schemaName'] ?? null;
        if (!is_string($schema) || $schema === '') {
            throw new UnsupportedCoordinationException('Coordination requires a selected database.');
        }

        return $schema;
    }

    /** @param list<string> $tables */
    protected function validateTriggerVisibility(PDO $pdo, string $schema, array $tables): void
    {
        $statement = $this->queryStatement($pdo, 'SHOW GRANTS FOR CURRENT_USER()');
        $grants = [];
        while (($grant = $statement->fetchColumn()) !== false) {
            if (!is_string($grant)) {
                throw new UnsupportedCoordinationException('The account grants could not be established.');
            }
            if (str_starts_with(strtoupper($grant), 'REVOKE ')) {
                throw new UnsupportedCoordinationException('Partial privilege revokes are unsupported for coordination.');
            }
            $grants[] = $grant;
        }

        foreach ($tables as $table) {
            $visible = false;
            foreach ($grants as $grant) {
                if ($this->grantProvesTriggerVisibility($grant, $schema, $table)) {
                    $visible = true;
                    break;
                }
            }
            if (!$visible) {
                throw new UnsupportedCoordinationException('Direct trigger visibility is required for every participant.');
            }
        }
    }

    protected function grantProvesTriggerVisibility(string $grant, string $schema, string $table): bool
    {
        if (!preg_match('/^GRANT (.+) ON (.+) TO /i', $grant, $matches)) {
            return false;
        }

        $privileges = array_map('trim', explode(',', strtoupper($matches[1])));
        if (!in_array('TRIGGER', $privileges, true) && !in_array('ALL PRIVILEGES', $privileges, true)) {
            return false;
        }

        $resource = trim($matches[2]);
        if ($resource === '*.*') {
            return true;
        }
        if (preg_match('/^`((?:``|[^`])*)`\.\*$/D', $resource, $scope)) {
            return $this->decodeIdentifier($scope[1]) === $schema;
        }
        if (preg_match('/^`((?:``|[^`])*)`\.`((?:``|[^`])*)`$/D', $resource, $scope)) {
            return $this->decodeIdentifier($scope[1]) === $schema
                && $this->decodeIdentifier($scope[2]) === $table;
        }

        return false;
    }

    /**
     * @param non-empty-list<string> $fields
     * @param array<string, int|string> $identity
     */
    protected function guardCoordinationRecord(PDO $pdo, string $table, array $fields, array $identity): void
    {
        $conditions = array_map(
            fn (string $field): string => $this->quoteIdentifier($field) . ' = ?',
            $fields
        );
        $statement = $this->prepareStatement(
            $pdo,
            'SELECT 1 FROM ' . $this->quoteIdentifier($table) . ' WHERE ' . implode(' AND ', $conditions) . ' FOR UPDATE'
        );
        $values = array_map(static fn (string $field): int|string => $identity[$field], $fields);
        $this->executeStatement($statement, $values);
        if ($statement->fetchColumn() === false) {
            throw new RecordNotFoundException('The coordination record does not exist.');
        }
    }

    /**
     * @param non-empty-list<array{table: Table, name: string}> $definitions
     * @param non-empty-list<string> $coordinationIdentity
     */
    protected function validateParticipants(
        PDO $pdo,
        string $schema,
        array $definitions,
        array $coordinationIdentity
    ): void
    {
        foreach ($definitions as $index => $definition) {
            $create = $this->readCreateDefinition($pdo, $definition['name']);
            if ($create['temporary'] || $create['view']) {
                throw new UnsupportedCoordinationException('Temporary tables and views are unsupported participants.');
            }

            $this->queryStatement(
                $pdo,
                'SELECT 1 FROM ' . $this->quoteIdentifier($definition['name']) . ' WHERE 1 = 0 FOR UPDATE'
            );

            $create = $this->readCreateDefinition($pdo, $definition['name']);
            if ($create['temporary'] || $create['view']) {
                throw new UnsupportedCoordinationException('Temporary tables and views are unsupported participants.');
            }

            $tableStatement = $this->prepareStatement(
                $pdo,
                'SELECT TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
            );
            $this->executeStatement($tableStatement, [$schema, $definition['name']]);
            $metadata = $tableStatement->fetch(PDO::FETCH_ASSOC);
            if (
                !is_array($metadata)
                || ($metadata['TABLE_TYPE'] ?? null) !== 'BASE TABLE'
                || strtoupper((string) ($metadata['ENGINE'] ?? '')) !== 'INNODB'
            ) {
                throw new UnsupportedCoordinationException('Every participant must be an InnoDB base table.');
            }

            $primaryFields = $this->readPrimaryFields($pdo, $schema, $definition['name']);
            if ($primaryFields === []) {
                throw new UnsupportedCoordinationException('Every participant must have a primary key.');
            }
            if ($index === 0 && $primaryFields !== $coordinationIdentity) {
                throw new InvalidArgumentException('The coordination identity must match the stable primary key.');
            }

            $triggerStatement = $this->prepareStatement(
                $pdo,
                'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?'
            );
            $this->executeStatement($triggerStatement, [$schema, $definition['name']]);
            if ($triggerStatement->fetchColumn() !== false) {
                throw new UnsupportedCoordinationException('Trigger-bearing tables are unsupported participants.');
            }
        }
    }

    /** @return list<string> */
    protected function readPrimaryFields(PDO $pdo, string $schema, string $table): array
    {
        $statement = $this->prepareStatement(
            $pdo,
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = 'PRIMARY'
                ORDER BY SEQ_IN_INDEX"
        );
        $this->executeStatement($statement, [$schema, $table]);
        $fields = $statement->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_filter($fields, 'is_string'));
    }

    /** @return array{temporary: bool, view: bool} */
    protected function readCreateDefinition(PDO $pdo, string $table): array
    {
        $statement = $this->queryStatement($pdo, 'SHOW CREATE TABLE ' . $this->quoteIdentifier($table));
        $definition = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($definition)) {
            throw new UnsupportedCoordinationException('A participant definition could not be established.');
        }

        $create = $definition['Create Table'] ?? '';

        return [
            'temporary' => is_string($create) && preg_match('/^CREATE\s+TEMPORARY\s+TABLE\b/i', $create) === 1,
            'view' => array_key_exists('Create View', $definition),
        ];
    }

    /** @param list<string> $tables */
    protected function abortAttempt(PDO $pdo, string $phase, array $tables, Throwable $failure): never
    {
        if (!$pdo->inTransaction()) {
            if ($this->hasInactiveAbortEvidence($failure) && $this->isDeadlockFailure($failure)) {
                $operationFailure = new CoordinatedOperationConflictException(
                    'The coordinated database operation conflicted.',
                    0,
                    $failure
                );
                $this->reportFailure($phase, $tables, 'rolled_back', true, $operationFailure, $failure);
                throw $operationFailure;
            }
            $cleanup = new DatastoreErrorException('Transaction ownership was lost before rollback.');
            $this->throwCleanupFailure($tables, $phase, $failure, $cleanup);
        }

        try {
            if (!$pdo->rollBack()) {
                throw $this->driverFailure($pdo, 'The coordinated database rollback was not acknowledged.');
            }
        } catch (Throwable $cleanup) {
            $this->throwCleanupFailure($tables, $phase, $failure, $cleanup);
        }

        $retryable = $this->isContentionFailure($failure);
        $operationFailure = $failure;
        if ($retryable) {
            $operationFailure = new CoordinatedOperationConflictException(
                'The coordinated database operation conflicted.',
                0,
                $failure
            );
        }
        $this->reportFailure($phase, $tables, 'rolled_back', $retryable, $operationFailure, $failure);

        throw $operationFailure;
    }

    /** @param list<string> $tables */
    protected function handleCommitFailure(PDO $pdo, array $tables, Throwable $failure): never
    {
        if (!$pdo->inTransaction()) {
            $operationFailure = new CoordinatedOperationOutcomeUnknownException(
                'The coordinated database operation outcome is unknown.',
                0,
                $failure
            );
            $this->reportFailure('commit', $tables, 'unknown', false, $operationFailure, $failure);
            throw $operationFailure;
        }

        try {
            if (!$pdo->rollBack()) {
                throw $this->driverFailure($pdo, 'The coordinated database rollback was not acknowledged.');
            }
        } catch (Throwable $cleanup) {
            $this->throwCleanupFailure($tables, 'commit', $failure, $cleanup);
        }

        $operationFailure = new DatastoreErrorException('The coordinated database commit failed.', 0, $failure);
        $this->reportFailure('commit', $tables, 'rolled_back', false, $operationFailure, $failure);
        throw $operationFailure;
    }

    /** @param list<string> $tables */
    protected function throwCleanupFailure(
        array $tables,
        string $operationPhase,
        Throwable $operationFailure,
        Throwable $cleanupFailure
    ): never {
        $failure = new CoordinatedOperationCleanupFailedException($operationFailure, $cleanupFailure);
        $this->reportFailure(
            'rollback',
            $tables,
            'unknown',
            false,
            $failure,
            $cleanupFailure,
            $this->failureDetails($operationPhase, $operationFailure)
        );
        throw $failure;
    }

    /**
     * @param list<string> $tables
     * @param array{phase: string, causeClass: class-string, sqlState: ?string, driverCode: ?int}|null $priorFailure
     */
    protected function reportFailure(
        string $phase,
        array $tables,
        string $outcome,
        bool $retryable,
        Throwable $operationFailure,
        ?Throwable $logFailure = null,
        ?array $priorFailure = null
    ): void {
        $failure = $logFailure ?? $operationFailure;
        $driver = $this->driverDetails($failure);
        $context = [
            'phase' => $phase,
            'tables' => $tables,
            'outcome' => $outcome,
            'retryable' => $retryable,
            'causeClass' => get_class($failure),
            'sqlState' => $driver['sqlState'],
            'driverCode' => $driver['driverCode'],
        ];
        if ($priorFailure !== null) {
            $context['priorFailure'] = $priorFailure;
        }

        try {
            $this->logger->error('Coordinated database operation failed.', $context);
        } catch (Throwable $reportingFailure) {
            throw new CoordinatedOperationReportingFailedException($operationFailure, $reportingFailure);
        }
    }

    /** @return array{phase: string, causeClass: class-string, sqlState: ?string, driverCode: ?int} */
    protected function failureDetails(string $phase, Throwable $failure): array
    {
        $driver = $this->driverDetails($failure);

        return [
            'phase' => $phase,
            'causeClass' => get_class($failure),
            'sqlState' => $driver['sqlState'],
            'driverCode' => $driver['driverCode'],
        ];
    }

    /** @return array{sqlState: ?string, driverCode: ?int} */
    protected function driverDetails(Throwable $failure): array
    {
        for ($node = $failure; $node !== null; $node = $node->getPrevious()) {
            if (!$node instanceof PDOException) {
                continue;
            }
            $errorInfo = $node->errorInfo ?? null;
            $sqlState = is_array($errorInfo) && is_string($errorInfo[0] ?? null) ? $errorInfo[0] : null;
            $code = is_array($errorInfo) ? ($errorInfo[1] ?? null) : null;

            return [
                'sqlState' => $sqlState,
                'driverCode' => is_int($code) || (is_string($code) && ctype_digit($code)) ? (int) $code : null,
            ];
        }

        return ['sqlState' => null, 'driverCode' => null];
    }

    protected function isContentionFailure(Throwable $failure): bool
    {
        $driver = $this->driverDetails($failure);

        return ($driver['sqlState'] === '40001' && $driver['driverCode'] === 1213)
            || ($driver['sqlState'] === 'HY000' && $driver['driverCode'] === 1205);
    }

    protected function isDeadlockFailure(Throwable $failure): bool
    {
        $driver = $this->driverDetails($failure);

        return $driver['sqlState'] === '40001' && $driver['driverCode'] === 1213;
    }

    protected function openOwnedAttempt(PDO $pdo): void
    {
        $this->ownedAttemptPdo = $pdo;
        $this->inactiveAbortEvidence = null;
    }

    protected function closeOwnedAttempt(): void
    {
        $this->ownedAttemptPdo = null;
    }

    protected function hasInactiveAbortEvidence(Throwable $failure): bool
    {
        return $this->inactiveAbortEvidence === $failure;
    }

    protected function enterOwnedStatement(PDO $pdo): bool
    {
        if ($this->ownedAttemptPdo !== $pdo) {
            return false;
        }
        if (!$pdo->inTransaction()) {
            throw new DatastoreErrorException('The coordinated operation lost transaction ownership before a database statement.');
        }

        return true;
    }

    protected function requireRetainedOwnership(PDO $pdo, bool $enteredOwned): void
    {
        if ($enteredOwned && !$pdo->inTransaction()) {
            throw new DatastoreErrorException('The coordinated operation lost transaction ownership during a database statement.');
        }
    }

    protected function observeInactiveDriverFailure(PDO $pdo, Throwable $failure): void
    {
        if ($this->ownedAttemptPdo === $pdo && !$pdo->inTransaction() && $this->isDeadlockFailure($failure)) {
            $this->inactiveAbortEvidence = $failure;
        }
    }

    protected function queryStatement(PDO $pdo, string $sql): PDOStatement
    {
        $enteredOwned = $this->enterOwnedStatement($pdo);
        try {
            $statement = $pdo->query($sql);
        } catch (Throwable $failure) {
            $this->observeInactiveDriverFailure($pdo, $failure);
            throw $failure;
        }
        if ($statement === false) {
            $failure = $this->driverFailure($pdo, 'A coordinated database query failed.');
            $this->observeInactiveDriverFailure($pdo, $failure);
            throw $failure;
        }
        $this->requireRetainedOwnership($pdo, $enteredOwned);

        return $statement;
    }

    protected function prepareStatement(PDO $pdo, string $sql): PDOStatement
    {
        $enteredOwned = $this->enterOwnedStatement($pdo);
        try {
            $statement = $pdo->prepare($sql);
        } catch (Throwable $failure) {
            $this->observeInactiveDriverFailure($pdo, $failure);
            throw $failure;
        }
        if ($statement === false) {
            $failure = $this->driverFailure($pdo, 'A coordinated database statement could not be prepared.');
            $this->observeInactiveDriverFailure($pdo, $failure);
            throw $failure;
        }
        $this->requireRetainedOwnership($pdo, $enteredOwned);

        return $statement;
    }

    /** @param list<int|string> $values */
    protected function executeStatement(PDOStatement $statement, array $values): void
    {
        $pdo = $this->ownedAttemptPdo;
        $enteredOwned = $pdo !== null && $this->enterOwnedStatement($pdo);
        try {
            $executed = $statement->execute($values);
        } catch (Throwable $failure) {
            if ($pdo !== null) {
                $this->observeInactiveDriverFailure($pdo, $failure);
            }
            throw $failure;
        }
        if (!$executed) {
            $failure = $this->driverFailure($statement, 'A coordinated database statement failed.');
            if ($pdo !== null) {
                $this->observeInactiveDriverFailure($pdo, $failure);
            }
            throw $failure;
        }
        if ($pdo !== null) {
            $this->requireRetainedOwnership($pdo, $enteredOwned);
        }
    }

    protected function driverFailure(PDO|PDOStatement $source, string $message): PDOException
    {
        $errorInfo = $source->errorInfo();
        $failure = new PDOException($message);
        $failure->errorInfo = $errorInfo;

        return $failure;
    }

    protected function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    protected function decodeIdentifier(string $identifier): string
    {
        return str_replace('``', '`', $identifier);
    }

    protected function isValidIdentifier(string $identifier): bool
    {
        return strlen($identifier) <= 64
            && preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/D', $identifier) === 1;
    }
}
