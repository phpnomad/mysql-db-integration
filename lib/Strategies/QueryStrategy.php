<?php

namespace PHPNomad\MySql\Integration\Strategies;

use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\Database\Exceptions\RecordNotFoundException;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy as CoreQueryStrategy;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\Utils\Helpers\Arr;

class QueryStrategy implements CoreQueryStrategy
{
    public function __construct(
        protected DatabaseStrategy $db,
        protected ClauseBuilder $clauseBuilder
    ) {}

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
        // Process $data to build columns and placeholders
        $columns = Arr::process($data)
            ->keys()
            ->setSeparator(',')
            ->toString();

        $placeholders = Arr::process($data)
            ->map(fn() => '?s')
            ->setSeparator(',')
            ->toString();

        $query = $this->db->parse("INSERT INTO ?n ($columns) VALUES ($placeholders)", $table->getName(), ...Arr::values($data));

        $this->db->query($query);
        $id = $this->db->query("SELECT LAST_INSERT_ID()");

        return ['id' => $id];
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
        $setClause = Arr::process($data)
            ->map(fn($value, $key) => "?n = ?s")
            ->setSeparator(', ')
            ->toString();

        $this->clauseBuilder->reset()->useTable($table);
        foreach ($ids as $key => $value) {
            $this->clauseBuilder->where($key, '=', $value);
        }

        $whereClause = $this->clauseBuilder->build();

        $query = $this->db->parse(
            "UPDATE ?n SET $setClause WHERE $whereClause",
            $table->getName(),
            ...Arr::merge(Arr::values($data), Arr::values($ids))
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