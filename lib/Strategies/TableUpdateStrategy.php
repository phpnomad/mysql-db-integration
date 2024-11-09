<?php

namespace PHPNomad\MySql\Integration\Strategies;

use PHPNomad\Database\Exceptions\TableUpdateFailedException;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Interfaces\TableUpdateStrategy as CoreTableUpdateStrategy;
use PHPNomad\Utils\Helpers\Arr;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;

class TableUpdateStrategy implements CoreTableUpdateStrategy
{
    protected DatabaseStrategy $db;

    public function __construct(DatabaseStrategy $db)
    {
        $this->db = $db;
    }

    /**
     * @param Table $table
     * @return void
     * @throws TableUpdateFailedException
     */
    public function syncColumns(Table $table): void
    {
        try {
            $query = $this->buildSyncColumnsQuery($table);

            if (!$query) {
                return;
            }

            $this->db->query($query);
        } catch (\Exception $e) {
            throw new TableUpdateFailedException($e);
        }
    }

    protected function convertColumnToSql(Column $column): string
    {
        // Get the column name and type
        $columnName = $column->getName();
        $columnType = $column->getType();

        // Handle type arguments (e.g., VARCHAR(255))
        $typeArgs = $column->getTypeArgs();
        if (!empty($typeArgs)) {
            $columnType .= '(' . implode(',', $typeArgs) . ')';
        }

        // Handle attributes (e.g., NOT NULL, DEFAULT 'value')
        $attributes = implode(' ', $column->getAttributes());

        return "`{$columnName}` {$columnType} {$attributes}";
    }

    protected function getCurrentColumns(string $tableName): array
    {
        $query = $this->db->parse(
            "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_NAME = ?s",
            $tableName
        );

        $results = $this->db->query($query);

        $columns = [];
        foreach ($results as $row) {
            $columns[$row['COLUMN_NAME']] = $row;
        }

        return $columns;
    }

    protected function needsColumnModification(array $currentColumnData, Column $newColumn): bool
    {
        // Check if the column type and type arguments match
        $currentType = $currentColumnData['COLUMN_TYPE'];
        $newType = $newColumn->getType();

        // Append type arguments to the new type if they exist
        $typeArgs = $newColumn->getTypeArgs();
        if (!empty($typeArgs)) {
            $newType .= '(' . implode(',', $typeArgs) . ')';
        }

        // Check if the types are different
        if (!str_contains(strtolower($currentType), strtolower($newType))) {
            return true;
        }

        return false;
    }

    /**
     * Builds the SQL query to synchronize columns for the specified table.
     *
     * @param Table $table
     * @return string|null
     */
    protected function buildSyncColumnsQuery(Table $table): ?string
    {
        $currentColumns = $this->getCurrentColumns($table->getName());
        $newColumns = $table->getColumns();
        $queries = [];

        // Add or modify columns
        foreach ($newColumns as $newColumn) {
            $columnName = $newColumn->getName();
            if (!array_key_exists($columnName, $currentColumns)) {
                // Column does not exist, add it
                $queries[] = "ADD COLUMN " . $this->convertColumnToSql($newColumn);
            } else {
                // Column exists, check if it needs to be modified
                if ($this->needsColumnModification($currentColumns[$columnName], $newColumn)) {
                    $queries[] = "MODIFY COLUMN " . $this->convertColumnToSql($newColumn);
                }
            }
        }

        $newColumnNames = Arr::pluck($newColumns, 'name');

        // Drop columns that no longer exist in the new definition
        foreach ($currentColumns as $currentColumnName => $currentColumnData) {
            if (!in_array($currentColumnName, $newColumnNames)) {
                $queries[] = "DROP COLUMN `{$currentColumnName}`";
            }
        }

        $args = Arr::process($queries)
            ->whereNotEmpty()
            ->setSeparator(",\n ")
            ->toString();

        if (empty($args)) {
            return null;
        }

        return $this->db->parse(
            "ALTER TABLE ?n $args",
            $table->getName()
        );
    }
}
