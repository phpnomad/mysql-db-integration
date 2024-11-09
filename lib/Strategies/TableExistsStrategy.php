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
            $query = $this->db->parse("SHOW TABLES LIKE ?s", $tableName);
            return $this->db->query($query)->fetchColumn() === $tableName;

        } catch (DatastoreErrorException $e) {
            return false;
        }
    }
}
