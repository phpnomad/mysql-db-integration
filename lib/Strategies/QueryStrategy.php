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
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\Utils\Helpers\Arr;
use PHPNomad\Utils\Helpers\Str;

class QueryStrategy implements CoreQueryStrategy
{
    public function __construct(
        protected DatabaseStrategy $db,
        protected TableSchemaService $tableSchemaService,
        protected ClauseBuilder    $clauseBuilder
    )
    {
    }

    /** @inheritDoc */
    public function query(QueryBuilder $builder): array
    {
        try {
            $result = $this->db->query($builder->build());
        } catch (QueryBuilderException $e) {
            throw new DatastoreErrorException('Get results failed. Invalid query: ' . $e->getMessage(), 500, $e);
        }

        if (empty($result)) {
            throw new RecordNotFoundException();
        }

        return $result;
    }

    /** @inheritDoc */
    public function insert(Table $table, array $data): array
    {
        $columns = Arr::process($data)
            ->keys()
            ->setSeparator(',')
            ->toString();

        $placeholders = Arr::process($data)
            ->map(fn() => '?s')
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
     * @param array $data
     * @return array
     * @throws DatastoreErrorException
     */
    protected function resolveInsertIdentity(Table $table, array $data): array
    {
        $identity = [];
        $primaryColumns = $this->tableSchemaService->getPrimaryColumnsForTable($table);

        foreach ($primaryColumns as $column) {
            $name = $column->getName();

            if (array_key_exists($name, $data)) {
                $identity[$name] = $data[$name];
                continue;
            }

            if (Arr::hasValues($column->getAttributes(), 'AUTO_INCREMENT')) {
                $result = $this->db->query("SELECT LAST_INSERT_ID()");
                $identity[$name] = is_array($result) ? Arr::first($result) : $result;
            } else {
                throw new DatastoreErrorException("Missing identity field '$name' and it is not auto-increment.");
            }
        }

        return $identity;
    }


    /** @inheritDoc */
    public function delete(Table $table, array $ids): void
    {
        // Use ClauseBuilder to construct the WHERE clause
        $this->clauseBuilder->reset()->useTable($table);
        foreach ($ids as $key => $value) {
            $this->clauseBuilder->where($key, '=', $value);
        }

        $whereClause = $this->clauseBuilder->build();

        $query = $this->db->parse(
            "DELETE FROM ?n WHERE $whereClause",
            $table->getName()
        );

        $this->db->query($query);
    }

    /** @inheritDoc */
    public function update(Table $table, array $ids, array $data): void
    {
        // Build the SET clause
        $setClause = Arr::process($data)
            ->each(fn($v, $k) => '?n = ?s')
            ->setSeparator(', ')
            ->toString();

        // Build WHERE clause
        $this->clauseBuilder->reset()->useTable($table);
        foreach ($ids as $key => $value) {
            $this->clauseBuilder->andWhere($key, '=', $value);
        }
        
        $whereClause = $this->clauseBuilder->build();

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
        

        $result = $this->db->query($query);

        if ($result === 0) {
            throw new RecordNotFoundException('The update failed because no record exists with the specified IDs.');
        }
    }


    /** @inheritDoc */
    public function estimatedCount(Table $table): int
    {
        $query = $this->db->parse("SELECT COUNT(*) FROM ?n", $table->getName());

        try {
            $result = $this->db->query($query);
            return (int)Arr::get($result[0], 'COUNT(*)', 0);
        } catch (\Exception $e) {
            throw new DatastoreErrorException('Count query failed: ' . $e->getMessage(), 500, $e);
        }
    }
}
