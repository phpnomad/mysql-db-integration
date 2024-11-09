<?php

namespace PHPNomad\MySql\Integration\Strategies;

use PHPNomad\Database\Exceptions\TableDropFailedException;
use PHPNomad\Database\Interfaces\TableDeleteStrategy as CoreTableDeleteStrategy;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;

class TableDeleteStrategy implements CoreTableDeleteStrategy
{
    protected DatabaseStrategy $db;

    public function __construct(DatabaseStrategy $db)
    {
        $this->db = $db;
    }

    /**
     * Drops the specified table.
     *
     * @param string $tableName
     * @return void
     * @throws TableDropFailedException
     */
    public function delete(string $tableName): void
    {
        try {
            $query = $this->db->parse("DROP TABLE IF EXISTS ?n", $tableName);
            $this->db->query($query);
        } catch (\Exception $e) {
            throw new TableDropFailedException($e->getMessage(), $e->getCode(), $e);
        }
    }
}