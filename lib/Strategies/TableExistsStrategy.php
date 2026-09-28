<?php

namespace PHPNomad\MySql\Integration\Strategies;

use PHPNomad\Database\Interfaces\TableExistsStrategy as CoreTableExistsStrategy;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;

class TableExistsStrategy implements CoreTableExistsStrategy
{
    protected DatabaseStrategy $db;

    public function __construct(DatabaseStrategy $db)
    {
        $this->db = $db;
    }

    /**
     * Returns true if the specified table exists.
     *
     * @param string $tableName
     * @return bool
     */
    public function exists(string $tableName): bool
    {
        try {
            $query = $this->db->parse(
                'SELECT TABLE_NAME AS table_name FROM information_schema.TABLES'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s',
                $tableName
            );
            $rows = $this->db->query($query);
        } catch (DatastoreErrorException $e) {
            return false;
        }

        if (!is_array($rows)) {
            throw new \UnexpectedValueException('The table metadata query must return an array.');
        }

        if ($rows === []) {
            return false;
        }

        $firstRow = reset($rows);
        if (count($rows) !== 1 || !is_array($firstRow) || !array_key_exists('table_name', $firstRow)
            || !is_string($firstRow['table_name'])) {
            throw new \UnexpectedValueException('The table metadata query returned an invalid row.');
        }

        return $firstRow['table_name'] === $tableName;
    }
}
