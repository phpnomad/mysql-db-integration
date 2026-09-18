<?php

namespace PHPNomad\MySql\Integration\Strategies;

use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy as CoreQueryStrategy;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\MySql\Integration\Interfaces\CanBuildWithDatabaseStrategy;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\Utils\Helpers\Arr;
use PHPNomad\Utils\Helpers\Str;

class QueryStrategy implements CoreQueryStrategy
{
    public function __construct(
        protected DatabaseStrategy $db,
        protected TableSchemaService $tableSchemaService,
        protected ClauseBuilder    $clauseBuilder
    ) {
    }

    /** @return array<array-key, mixed> */
    public function query(QueryBuilder $builder): array
    {
        try {
            $query = $builder instanceof CanBuildWithDatabaseStrategy
                ? $builder->buildWithDatabaseStrategy($this->db)
                : $builder->build();
            /** @var array<array-key, mixed> $result */
            $result = $this->db->query($query);

        } catch (QueryBuilderException $e) {
            throw new DatastoreErrorException('Get results failed. Invalid query: ' . $e->getMessage(), 500, $e);
        }

        if (empty($result)) {
            throw new RecordNotFoundException();
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    public function insert(Table $table, array $data): array
    {
        $columns = Arr::process($data)
            ->keys()
            ->setSeparator(',')
            ->toString();

        $placeholders = Arr::process($data)
            ->map(fn () => '?s')
            ->setSeparator(',')
            ->toString();

        $query = $this->db->parse(
            "INSERT INTO ?n ($columns) VALUES ($placeholders)",
            $table->getName(),
            ...Arr::values($data)
        );

        $this->db->query($query);
        return $this->resolveInsertIdentity($table, $data);
    }

    /**
     * @param Table $table
     * @param array<string, mixed> $data
     * @return array<string, int>
     * @throws DatastoreErrorException
     */
    protected function resolveInsertIdentity(Table $table, array $data)
    {
        /** @var array<string, int> $identity */
        $identity = [];
        $primaryColumns = $this->tableSchemaService->getPrimaryColumnsForTable($table);

        foreach ($primaryColumns as $column) {
            $name = $column->getName();

            if (array_key_exists($name, $data)) {
                /** @var int $identityValue */
                $identityValue = $data[$name];
                $identity[$name] = $identityValue;
                continue;
            }

            if (Arr::hasValues($column->getAttributes(), 'AUTO_INCREMENT')) {
                /** @var array<int, array<string, mixed>>|false $result */
                $result = $this->db->query("SELECT LAST_INSERT_ID()");

                if (!$result) {
                    throw new DatastoreErrorException('Failed to fetch LAST_INSERT_ID()');
                }

                /** @var int|string $insertId */
                $insertId = Arr::get($result[0], 'LAST_INSERT_ID()');
                $identity[$name] = (int) $insertId;
            } else {
                throw new DatastoreErrorException("Missing identity field '$name' and it is not auto-increment.");
            }
        }

        return $identity;
    }


    /** @inheritDoc */
    public function delete(Table $table, array $ids): void
    {
        // `andWhere` is used throughout rather than `where` so compound-key
        // deletes AND the conditions together. `where` appends clauses with
        // no logical operator between them, which yields invalid SQL as soon
        // as there is more than one key.
        $this->clauseBuilder->reset()->useTable($table);
        foreach ($ids as $key => $value) {
            $this->clauseBuilder->andWhere($key, '=', $value);
        }

        $whereClause = $this->clauseBuilder instanceof CanBuildWithDatabaseStrategy
            ? $this->clauseBuilder->buildWithDatabaseStrategy($this->db)
            : $this->clauseBuilder->build();

        $query = $this->db->parse(
            "DELETE ?n FROM ?n AS ?n WHERE $whereClause",
            $table->getAlias(),
            $table->getName(),
            $table->getAlias()
        );

        $this->db->query($query);
    }

    /**
     * @param array<string, int> $ids
     * @param array<string, mixed> $data
     */
    public function update(Table $table, array $ids, array $data): void
    {
        // Build the SET clause
        $setClause = Arr::process($data)
            ->each(fn ($v, $k) => '?n = ?s')
            ->setSeparator(', ')
            ->toString();

        // Build WHERE clause
        $this->clauseBuilder->reset()->useTable($table);
        foreach ($ids as $key => $value) {
            $this->clauseBuilder->andWhere($key, '=', $value);
        }

        $whereClause = $this->clauseBuilder instanceof CanBuildWithDatabaseStrategy
            ? $this->clauseBuilder->buildWithDatabaseStrategy($this->db)
            : $this->clauseBuilder->build();

        // Flatten $data into [col1, val1, col2, val2, ...] manually
        $setBindings = [];
        foreach ($data as $key => $val) {
            $setBindings[] = $key;
            $setBindings[] = $val;
        }

        $query = $this->db->parse(
            "UPDATE ?n AS ?n SET $setClause WHERE $whereClause",
            $table->getName(),
            $table->getAlias(),
            ...$setBindings
        );

        // MySQL returns 0 affected rows for a legitimate no-op update
        // (matched row, values unchanged). That is not a "record not found"
        // condition — the row exists; the supplied values were already what
        // was stored. Callers that need existence checks should perform them
        // before calling update(), not rely on the affected-rows count.
        $this->db->query($query);
    }


    /** @inheritDoc */
    public function estimatedCount(Table $table): int
    {
        $query = $this->db->parse("SELECT COUNT(*) FROM ?n", $table->getName());

        try {
            /** @var array<int, array<string, mixed>> $result */
            $result = $this->db->query($query);
            /** @var int|string $count */
            $count = Arr::get($result[0], 'COUNT(*)');
            return (int) $count;
        } catch (\Exception $e) {
            throw new DatastoreErrorException('Count query failed: ' . $e->getMessage(), 500, $e);
        }
    }
}
