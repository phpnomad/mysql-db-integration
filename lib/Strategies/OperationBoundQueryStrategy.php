<?php

namespace PHPNomad\MySql\Integration\Strategies;

use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\Database\Exceptions\UnsupportedCoordinationException;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\MySql\Integration\Interfaces\CanBuildWithDatabaseStrategy;
use PHPNomad\Utils\Helpers\Arr;

/** Query operations fixed to the backend owned by one coordinated attempt. */
final class OperationBoundQueryStrategy extends QueryStrategy
{
    /** @return array<array-key, mixed> */
    public function query(QueryBuilder $builder): array
    {
        if (!$builder instanceof CanBuildWithDatabaseStrategy) {
            throw new UnsupportedCoordinationException('Coordinated queries need a resource-bound query builder.');
        }

        try {
            $query = $builder->buildWithDatabaseStrategy($this->db);
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
     * Insert through the owned backend without starting or ending a transaction.
     *
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    public function insert(Table $table, array $data): array
    {
        $columns = Arr::process($data)
            ->keys()
            ->map(fn (string $column): string => $this->db->parse('?n', $column))
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
}
