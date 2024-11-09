<?php

namespace PHPNomad\MySql\Integration\Strategies;

use PHPNomad\Database\Exceptions\TableCreateFailedException;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Factories\Index;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Interfaces\TableCreateStrategy as CoreTableCreateStrategy;
use PHPNomad\Utils\Helpers\Arr;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;

class TableCreateStrategy implements CoreTableCreateStrategy
{
    protected DatabaseStrategy $db;

    public function __construct(DatabaseStrategy $db)
    {
        $this->db = $db;
    }

    /**
     * @param Table $table
     * @return void
     * @throws TableCreateFailedException
     */
    public function create(Table $table): void
    {
        try {
            $this->db->query($this->buildCreateQuery($table));
        } catch (\Exception $e) {
            throw new TableCreateFailedException($e);
        }
    }

    /**
     * Builds the SQL query string for creating a table.
     *
     * @param Table $table
     * @return string
     */
    protected function buildCreateQuery(Table $table): string
    {
        $args = Arr::process([
            $this->convertColumnsToSqlString($table),
            $this->convertIndicesToSqlString($table),
        ])
            ->whereNotEmpty()
            ->setSeparator(",\n ")
            ->toString();

        return $this->db->parse(
            "CREATE TABLE IF NOT EXISTS ?n ( $args ) CHARACTER SET ?s COLLATE ?s;",
            $table->getName(),
            $table->getCharset(),
            $table->getCollation()
        );
    }

    protected function convertColumnsToSqlString(Table $table): string
    {
        return Arr::process($table->getColumns())
            ->map(fn(Column $column) => $this->convertColumnToSchemaString($column))
            ->setSeparator(",\n ")
            ->toString();
    }

    protected function convertIndicesToSqlString(Table $table): string
    {
        return Arr::process($table->getIndices())
            ->map(fn(Index $index) => $this->convertIndexToSchemaString($index))
            ->setSeparator(",\n ")
            ->toString();
    }

    /**
     * Converts the specified column into a MySQL formatted string.
     *
     * @param Column $column
     * @return string
     */
    protected function convertColumnToSchemaString(Column $column): string
    {
        $type = $column->getType();
        if ($args = $column->getTypeArgs()) {
            $type .= '(' . implode(',', $args) . ')';
        }

        return Arr::process([
            $column->getName(),
            $type,
        ])
            ->merge($column->getAttributes())
            ->whereNotNull()
            ->setSeparator(' ')
            ->toString();
    }

    protected function convertIndexToSchemaString(Index $index): string
    {
        $pieces = [];

        if ($index->getType()) {
            $pieces[] = strtoupper($index->getType());
        }

        if ($index->getName()) {
            $pieces[] = $index->getName();
        }

        $pieces[] = "(" . implode(', ', $index->getColumns()) . ")";

        return implode(' ', Arr::merge($pieces, $index->getAttributes()));
    }
}