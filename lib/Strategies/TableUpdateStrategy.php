<?php

namespace PHPNomad\MySql\Integration\Strategies;

use PHPNomad\Database\Exceptions\TableUpdateFailedException;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Interfaces\TableColumnRetirementStrategy as CoreTableColumnRetirementStrategy;
use PHPNomad\Database\Interfaces\TableUpdateStrategy as CoreTableUpdateStrategy;
use PHPNomad\Utils\Helpers\Arr;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;

class TableUpdateStrategy implements CoreTableUpdateStrategy, CoreTableColumnRetirementStrategy
{
    protected DatabaseStrategy $db;

    public function __construct(DatabaseStrategy $db)
    {
        $this->db = $db;
    }

    /**
     * Bring a table's columns in line with its definition, without removing
     * anything.
     *
     * Adds missing columns and modifies changed ones. It does NOT drop a column
     * the definition no longer declares, because this runs unattended and a
     * deploy is not always ahead of its database: a rollback, a canary, or two
     * services sharing one database all mean the running code can be missing a
     * column the database legitimately holds. Dropping it there destroys data.
     *
     * Use {@see syncColumnsAllowingDrops()} when removal is the intent.
     *
     * @param Table $table
     * @return void
     * @throws TableUpdateFailedException
     */
    public function syncColumns(Table $table): void
    {
        try {
            $query = $this->buildSyncColumnsQuery($table, false);

            if (!$query) {
                return;
            }

            $this->db->query($query);
        } catch (\Exception $e) {
            throw new TableUpdateFailedException($e);
        }
    }

    public function columnExists(Table $table, string $columnName): bool
    {
        $this->assertValidColumnName($columnName);

        try {
            return $this->findCurrentColumnName($table->getName(), $columnName) !== null;
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new TableUpdateFailedException($e);
        }
    }

    public function retireColumns(Table $table, string ...$columnNames): void
    {
        if ($columnNames === []) {
            throw new \InvalidArgumentException('At least one column must be named for retirement.');
        }

        foreach ($columnNames as $columnName) {
            $this->assertValidColumnName($columnName);
        }

        try {
            $targets = [];
            foreach ($columnNames as $columnName) {
                $currentName = $this->findCurrentColumnName($table->getName(), $columnName);
                if ($currentName !== null) {
                    $targets[$currentName] = $currentName;
                }
            }

            foreach ($table->getColumns() as $column) {
                foreach ($columnNames as $columnName) {
                    if ($this->identifiersEqual($column->getName(), $columnName)) {
                        throw new \InvalidArgumentException('A declared column cannot be retired.');
                    }
                }
            }

            if ($targets === []) {
                return;
            }

            $this->assertNoColumnDependencies($table->getName(), $targets);

            $drops = array_map(
                fn(string $columnName): string => 'DROP COLUMN ' . $this->db->parse('?n', $columnName),
                array_values($targets)
            );
            $query = $this->db->parse('ALTER TABLE ?n ', $table->getName()) . implode(', ', $drops);
            $this->db->query($query);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new TableUpdateFailedException($e);
        }
    }

    private function assertValidColumnName(string $columnName): void
    {
        if ($columnName === '' || str_contains($columnName, "\0")) {
            throw new \InvalidArgumentException('Column names must be non-empty and cannot contain NUL.');
        }
    }

    private function findCurrentColumnName(string $tableName, string $columnName): ?string
    {
        $rows = $this->metadataRows($this->db->query($this->db->parse(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s AND COLUMN_NAME = ?s',
            $tableName,
            $columnName
        )));

        foreach ($rows as $row) {
            $persistedName = $row['COLUMN_NAME'] ?? null;
            if (!is_string($persistedName)) {
                throw new \UnexpectedValueException('Column metadata did not contain a valid name.');
            }

            return $persistedName;
        }

        return null;
    }

    private function identifiersEqual(string $left, string $right): bool
    {
        $rows = $this->metadataRows($this->db->query($this->db->parse(
            'SELECT candidate = ?s AS identifiers_equal FROM ('
            . 'SELECT COLUMN_NAME AS candidate FROM INFORMATION_SCHEMA.COLUMNS WHERE 1 = 0 '
            . 'UNION ALL SELECT ?s) AS identifier_semantics',
            $right,
            $left
        )));
        $value = $rows[0]['identifiers_equal'] ?? null;

        if ($value !== 0 && $value !== 1 && $value !== '0' && $value !== '1') {
            throw new \UnexpectedValueException('Failed to compare column identifiers.');
        }

        return (string) $value === '1';
    }

    /** @param array<string, string> $targets persisted name => persisted name */
    private function assertNoColumnDependencies(string $tableName, array $targets): void
    {
        $statistics = $this->metadataRows($this->db->query($this->db->parse(
            'SELECT INDEX_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s',
            $tableName
        )));

        foreach ($statistics as $statistic) {
            if (!array_key_exists('COLUMN_NAME', $statistic)) {
                throw new \UnexpectedValueException('Index metadata did not contain a column identity.');
            }
            $columnName = $statistic['COLUMN_NAME'] ?? null;
            if ($columnName !== null && !is_string($columnName)) {
                throw new \UnexpectedValueException('Index metadata contained a malformed column identity.');
            }
            if (is_string($columnName) && isset($targets[$columnName])) {
                throw new \InvalidArgumentException('An indexed column cannot be retired implicitly.');
            }
            if ($columnName === null) {
                throw new \InvalidArgumentException('An unresolved functional index prevents column retirement.');
            }
        }

        $foreignKeys = $this->metadataRows($this->db->query($this->db->parse(
            'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME '
            . 'FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() '
            . 'AND (TABLE_NAME = ?s OR (REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ?s))',
            $tableName,
            $tableName
        )));

        foreach ($foreignKeys as $foreignKey) {
            $localColumn = $foreignKey['COLUMN_NAME'] ?? null;
            $referencedColumn = $foreignKey['REFERENCED_COLUMN_NAME'] ?? null;
            if (!is_string($localColumn)
                || ($referencedColumn !== null && !is_string($referencedColumn))) {
                throw new \UnexpectedValueException('Foreign-key metadata contained a malformed column identity.');
            }
            if ((is_string($localColumn) && isset($targets[$localColumn]))
                || (is_string($referencedColumn) && isset($targets[$referencedColumn]))) {
                throw new \InvalidArgumentException('A foreign-key column cannot be retired implicitly.');
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function metadataRows($result): array
    {
        if (!is_array($result)) {
            throw new \UnexpectedValueException('Metadata query did not return rows.');
        }

        foreach ($result as $row) {
            if (!is_array($row)) {
                throw new \UnexpectedValueException('Metadata query returned a malformed row.');
            }
        }

        return array_values($result);
    }

    /**
     * Attributes that declare a key rather than describe the column.
     *
     * These are valid when a column is first added, and invalid on MODIFY: the
     * key already exists, so MySQL rejects the statement ("Multiple primary key
     * defined"). Keys are owned by the table's index definitions.
     */
    protected const KEY_ATTRIBUTES = ['PRIMARY KEY', 'UNIQUE KEY', 'UNIQUE'];

    /**
     * Sync columns and drop any the definition no longer declares.
     *
     * Destructive, and deliberately not the default. Only call this when the
     * caller knows the definition is authoritative for the database it is
     * pointed at, which an unattended deploy cannot know.
     *
     * @throws TableUpdateFailedException
     */
    public function syncColumnsAllowingDrops(Table $table): void
    {
        try {
            $query = $this->buildSyncColumnsQuery($table, true);

            if (!$query) {
                return;
            }

            $this->db->query($query);
        } catch (\Exception $e) {
            throw new TableUpdateFailedException($e);
        }
    }

    protected function convertColumnToSql(Column $column, bool $includeKeys = true): string
    {
        // Get the column name and type
        $columnName = $column->getName();
        $columnType = $column->getType();

        // Handle type arguments (e.g., VARCHAR(255))
        $typeArgs = $column->getTypeArgs();
        if (!empty($typeArgs)) {
            $columnType .= '(' . implode(',', $typeArgs) . ')';
        }

        $columnAttributes = $column->getAttributes();
        if (!$includeKeys) {
            $columnAttributes = Arr::filter(
                $columnAttributes,
                static fn($attribute): bool => !in_array(
                    strtoupper(trim((string) $attribute)),
                    static::KEY_ATTRIBUTES,
                    true
                )
            );
        }

        // Handle attributes (e.g., NOT NULL, DEFAULT 'value')
        $attributes = implode(' ', $columnAttributes);

        return rtrim("`{$columnName}` {$columnType} {$attributes}");
    }

    protected function getCurrentColumns(string $tableName): array
    {
        $query = $this->db->parse(
            "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s",
            $tableName
        );

        $results = $this->db->query($query);

        $columns = [];
        foreach ($results as $row) {
            $columns[$row['COLUMN_NAME']] = $row;
        }

        return $columns;
    }

    /**
     * Integer types whose declared display width MySQL no longer stores.
     *
     * Since 8.0.17 the server drops the width from integer types, so a schema
     * declaring INT(11) reads back as plain `int`. Comparing the two literally
     * marks every integer column in the database as changed.
     */
    protected const WIDTHLESS_INTEGER_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'];

    protected function needsColumnModification(array $currentColumnData, Column $newColumn): bool
    {
        $currentType = $this->normalizeType((string) $currentColumnData['COLUMN_TYPE']);
        $newType = $this->normalizeType($this->declaredType($newColumn));
        $currentNullable = strtoupper(trim((string) $currentColumnData['IS_NULLABLE'])) === 'YES';
        // Attributes are free strings and NOT NULL often travels with company
        // ("NOT NULL DEFAULT 'human'"), so containment, not equality, decides.
        $newNullable = true;
        foreach ($newColumn->getAttributes() as $attribute) {
            if (str_contains(strtoupper((string) $attribute), 'NOT NULL')) {
                $newNullable = false;
                break;
            }
        }

        // Compare for equality, not containment. `bigint` contains `int`, so a
        // containment check reported a genuine int-to-bigint widening as already
        // satisfied and skipped it.
        return $currentType !== $newType || $currentNullable !== $newNullable;
    }

    /** The type as written in the schema, including any arguments. */
    protected function declaredType(Column $column): string
    {
        $type = $column->getType();
        $typeArgs = $column->getTypeArgs();

        if (!empty($typeArgs)) {
            $type .= '(' . implode(',', $typeArgs) . ')';
        }

        return $type;
    }

    /**
     * Reduce a type to the form MySQL actually stores, so a schema declaration
     * and what the server reports back can be compared for equality.
     */
    protected function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));

        // Strip the display width from integers, which the server discards.
        // Anything else keeps its arguments, since VARCHAR(100) and VARCHAR(255)
        // are genuinely different types.
        if (preg_match('/^(\w+)\s*\(\s*\d+\s*\)$/', $type, $matches) === 1
            && in_array($matches[1], static::WIDTHLESS_INTEGER_TYPES, true)) {
            return $matches[1];
        }

        return $type;
    }

    /**
     * Builds the SQL query to synchronize columns for the specified table.
     *
     * @param Table $table
     * @return string|null
     */
    /**
     * @param bool $allowDrops When false, a column the schema no longer declares
     *   is left in place rather than dropped.
     */
    protected function buildSyncColumnsQuery(Table $table, bool $allowDrops = true): ?string
    {
        $currentColumns = $this->getCurrentColumns($table->getName());
        $newColumns = $table->getColumns();
        $queries = [];

        // Add or modify columns
        foreach ($newColumns as $newColumn) {
            $columnName = $newColumn->getName();
            if (!array_key_exists($columnName, $currentColumns)) {
                // Column does not exist, add it. A key clause is correct here
                // because there is no existing key to collide with.
                $queries[] = "ADD COLUMN " . $this->convertColumnToSql($newColumn);
            } else {
                // Column exists, check if it needs to be modified. The key clause
                // is dropped: the key already exists, and re-declaring it makes
                // MySQL reject the whole statement.
                if ($this->needsColumnModification($currentColumns[$columnName], $newColumn)) {
                    $queries[] = "MODIFY COLUMN " . $this->convertColumnToSql($newColumn, false);
                }
            }
        }

        if ($allowDrops) {
            $newColumnNames = Arr::pluck($newColumns, 'name');

            // Drop columns that no longer exist in the new definition
            foreach ($currentColumns as $currentColumnName => $currentColumnData) {
                if (!in_array($currentColumnName, $newColumnNames)) {
                    $queries[] = "DROP COLUMN `{$currentColumnName}`";
                }
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
