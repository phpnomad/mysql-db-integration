<?php

namespace PHPNomad\MySql\Integration\Strategies;

use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use PHPNomad\Database\Exceptions\CoordinatedOperationCleanupFailedException;
use PHPNomad\Database\Exceptions\CoordinatedOperationConflictException;
use PHPNomad\Database\Exceptions\CoordinatedOperationOutcomeUnknownException;
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
 */
class PdoCoordinatedDatabaseStrategy extends PdoDatabaseStrategy implements CoordinatedDatabaseStrategy
{
    protected LoggerStrategy $logger;

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
            foreach ($participants as $participant) {
                if ($participant instanceof Table) {
                    $tableNames[] = $participant->getName();
                }
            }

            $definitions = $this->validateRequest($coordinationTable, $identity, $participants);
            $pdo = $this->connection->pdo();
            $schema = $this->validateSession($pdo);
            $this->validateTriggerVisibility($pdo, $schema, $tableNames);
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

        try {
            $this->guardCoordinationRecord($pdo, $coordinationTable->getName(), $definitions[0]['identity'], $identity);
            $this->validateParticipants($pdo, $schema, $definitions);
        } catch (Throwable $failure) {
            $this->abortAttempt($pdo, 'coordination', $tableNames, $failure);
        }

        try {
            $result = $operation($this);
        } catch (Throwable $failure) {
            $this->abortAttempt($pdo, 'callback', $tableNames, $failure);
        }

        if (!$pdo->inTransaction()) {
            $failure = new DatastoreErrorException('The coordinated operation lost transaction ownership.');
            $this->reportFailure('commit', $tableNames, 'unknown', false, $failure);
            throw new CoordinatedOperationOutcomeUnknownException(
                'The coordinated database operation outcome is unknown.',
                0,
                $failure
            );
        }

        try {
            if (!$pdo->commit()) {
                throw $this->driverFailure($pdo, 'The coordinated database commit was not acknowledged.');
            }
        } catch (Throwable $failure) {
            $this->handleCommitFailure($pdo, $tableNames, $failure);
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $identity
     * @param array<array-key, mixed> $participants
     * @return non-empty-list<array{table: Table, name: string, identity: non-empty-list<string>}>
     */
    protected function validateRequest(Table $coordinationTable, array $identity, array $participants): array
    {
        if ($participants === [] || !array_is_list($participants)) {
            throw new InvalidArgumentException('Participants must be a nonempty list of tables.');
        }

        $definitions = [];
        foreach ($participants as $participant) {
            if (!$participant instanceof Table) {
                throw new InvalidArgumentException('Every participant must be a table descriptor.');
            }

            $name = $participant->getName();
            if (!$this->isValidIdentifier($name)) {
                throw new InvalidArgumentException('Every participant must have a valid table name.');
            }

            $validatedFields = $this->validateIdentityFields($participant->getFieldsForIdentity());

            $definitions[] = ['table' => $participant, 'name' => $name, 'identity' => $validatedFields];
        }

        $coordinationName = $coordinationTable->getName();
        $coordinationIndex = null;
        foreach ($definitions as $index => $definition) {
            if ($definition['name'] === $coordinationName) {
                $coordinationIndex = $index;
                break;
            }
        }
        if ($coordinationIndex === null) {
            throw new InvalidArgumentException('The coordination table must be a participant.');
        }
        $coordinationDefinition = $definitions[$coordinationIndex];

        $coordinationFields = $this->validateIdentityFields($coordinationTable->getFieldsForIdentity());
        if ($coordinationFields !== $coordinationDefinition['identity']) {
            throw new InvalidArgumentException('The coordination table must describe one complete primary identity.');
        }

        $identityKeys = array_keys($identity);
        if (
            count($identityKeys) !== count($coordinationFields)
            || array_diff($coordinationFields, $identityKeys) !== []
            || array_diff($identityKeys, $coordinationFields) !== []
        ) {
            throw new InvalidArgumentException('The coordination identity must contain every primary field exactly once.');
        }
        foreach ($coordinationFields as $field) {
            if (!is_int($identity[$field]) && !is_string($identity[$field])) {
                throw new InvalidArgumentException('Coordination identity values must be integers or strings.');
            }
        }

        $ordered = [$coordinationDefinition];
        array_splice($definitions, $coordinationIndex, 1);
        array_push($ordered, ...$definitions);

        return $ordered;
    }

    /**
     * @param array<array-key, mixed> $fields
     * @return non-empty-list<string>
     */
    protected function validateIdentityFields(array $fields): array
    {
        if ($fields === [] || !array_is_list($fields)) {
            throw new InvalidArgumentException('Every participant must describe a nonempty primary identity.');
        }

        $validated = [];
        foreach ($fields as $field) {
            if (!is_string($field) || !$this->isValidIdentifier($field) || in_array($field, $validated, true)) {
                throw new InvalidArgumentException('Every participant identity must contain unique valid field names.');
            }
            $validated[] = $field;
        }

        return $validated;
    }

    protected function validateSession(PDO $pdo): string
    {
        if ($pdo->inTransaction()) {
            throw new UnsupportedCoordinationException('Coordination cannot join an ambient transaction.');
        }
        if ($pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_WARNING) {
            throw new UnsupportedCoordinationException('PDO warning mode is unsupported for coordination.');
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
     * @param non-empty-list<array{table: Table, name: string, identity: non-empty-list<string>}> $definitions
     */
    protected function validateParticipants(PDO $pdo, string $schema, array $definitions): void
    {
        foreach ($definitions as $definition) {
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

            $primaryStatement = $this->prepareStatement(
                $pdo,
                "SELECT COLUMN_NAME FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = 'PRIMARY'
                    ORDER BY SEQ_IN_INDEX"
            );
            $this->executeStatement($primaryStatement, [$schema, $definition['name']]);
            $primaryFields = $primaryStatement->fetchAll(PDO::FETCH_COLUMN);
            if ($primaryFields !== $definition['identity']) {
                throw new InvalidArgumentException('A participant descriptor does not match its complete primary key.');
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
            if ($this->isDeadlockFailure($failure)) {
                $this->reportFailure($phase, $tables, 'rolled_back', true, $failure);
                throw new CoordinatedOperationConflictException(
                    'The coordinated database operation conflicted.',
                    0,
                    $failure
                );
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
        $this->reportFailure($phase, $tables, 'rolled_back', $retryable, $failure);
        if ($retryable) {
            throw new CoordinatedOperationConflictException(
                'The coordinated database operation conflicted.',
                0,
                $failure
            );
        }

        throw $failure;
    }

    /** @param list<string> $tables */
    protected function handleCommitFailure(PDO $pdo, array $tables, Throwable $failure): never
    {
        if (!$pdo->inTransaction()) {
            $this->reportFailure('commit', $tables, 'unknown', false, $failure);
            throw new CoordinatedOperationOutcomeUnknownException(
                'The coordinated database operation outcome is unknown.',
                0,
                $failure
            );
        }

        try {
            if (!$pdo->rollBack()) {
                throw $this->driverFailure($pdo, 'The coordinated database rollback was not acknowledged.');
            }
        } catch (Throwable $cleanup) {
            $this->throwCleanupFailure($tables, 'commit', $failure, $cleanup);
        }

        $this->reportFailure('commit', $tables, 'rolled_back', false, $failure);
        throw new DatastoreErrorException('The coordinated database commit failed.', 0, $failure);
    }

    /** @param list<string> $tables */
    protected function throwCleanupFailure(
        array $tables,
        string $operationPhase,
        Throwable $operationFailure,
        Throwable $cleanupFailure
    ): never {
        $this->reportFailure(
            'rollback',
            $tables,
            'unknown',
            false,
            $cleanupFailure,
            $this->failureDetails($operationPhase, $operationFailure)
        );
        throw new CoordinatedOperationCleanupFailedException($operationFailure, $cleanupFailure);
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
        Throwable $failure,
        ?array $priorFailure = null
    ): void {
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
        } catch (Throwable) {
            // The database outcome and its retained causes take precedence over a broken log transport.
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

    protected function queryStatement(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw $this->driverFailure($pdo, 'A coordinated database query failed.');
        }

        return $statement;
    }

    protected function prepareStatement(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->prepare($sql);
        if ($statement === false) {
            throw $this->driverFailure($pdo, 'A coordinated database statement could not be prepared.');
        }

        return $statement;
    }

    /** @param list<int|string> $values */
    protected function executeStatement(PDOStatement $statement, array $values): void
    {
        if (!$statement->execute($values)) {
            throw $this->driverFailure($statement, 'A coordinated database statement failed.');
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
