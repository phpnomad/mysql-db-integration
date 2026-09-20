<?php

namespace PHPNomad\MySql\Integration\Builders;

use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\HasQueryTables;
use PHPNomad\Database\Interfaces\QueryBuilder as QueryBuilderInterface;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Traits\WithPrependedFields;
use PHPNomad\MySql\Integration\Facades\Database;
use PHPNomad\MySql\Integration\Interfaces\CanBuildWithDatabaseStrategy;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\Utils\Helpers\Arr;

class QueryBuilder implements QueryBuilderInterface, HasQueryTables, CanBuildWithDatabaseStrategy
{
    use WithPrependedFields;

    protected array $select = [];

    protected array $from = [];

    protected array $sql = [];

    private array $preparedValues = [];

    private array $prepare = [];

    protected array $raw = [];

    protected array $items = [];

    protected array $operands = [];

    protected array $limit = [];

    protected array $offset = [];

    protected array $orderBy = [];

    protected ?ClauseBuilder $clauseBuilder = null;
    protected array $groupBy = [];
    protected array $join = [];

    /** @var array{table: Table, name: string, alias: string}|null */
    private ?array $rootQuerySource = null;

    /** @var list<array{table: Table, name: string, alias: string}> */
    private array $joinedQuerySources = [];

    /** @var list<DatabaseStrategy> */
    private array $databaseStrategyStack = [];

    /** Copy builder content without inheriting a temporary backend binding. */
    public function __clone(): void
    {
        $this->databaseStrategyStack = [];
    }


    /** @inheritDoc */
    public function buildWithDatabaseStrategy(DatabaseStrategy $database): string
    {
        $this->databaseStrategyStack[] = $database;

        try {
            return $this->build();
        } finally {
            array_pop($this->databaseStrategyStack);
        }
    }

    /** @inheritDoc */
    public function getReferencedTables(): array
    {
        if (empty($this->from) || $this->rootQuerySource === null) {
            return [];
        }

        $sources = array_merge([$this->rootQuerySource], $this->joinedQuerySources);
        $tables = [];

        foreach ($sources as $source) {
            $this->assertQuerySourceIsUnchanged($source);
            $tables[] = $source['table'];
        }

        return $tables;
    }

    /** @inheritDoc */
    public function select(string $field, string ...$fields)
    {
        if (empty($this->select)) {
            $this->select = ['SELECT'];
        }

        if ('*' === $field) {
            $this->select[] = '*';
            return $this;
        }

        $this->select[] = Arr::process(Arr::merge([$field], $fields))
            ->each(fn (string $field) => $this->prependField($field))
            ->toString();

        return $this;
    }

    /** @inheritDoc */
    public function from(Table $table)
    {
        $this->useTable($table);
        $this->rootQuerySource = $this->captureQuerySource($table);
        $this->from = [
            'FROM',
            $this->rootQuerySource['name'],
            'AS',
            $this->rootQuerySource['alias'],
        ];

        return $this;
    }

    /** @inheritDoc */
    public function where(?ClauseBuilder $clauseBuilder)
    {
        $this->clauseBuilder = $clauseBuilder->useTable($this->table);


        return $this;
    }

    /** @inheritDoc */
    public function leftJoin(Table $table, string $column, string $onColumn)
    {
        $source = $this->captureQuerySource($table);
        $join = [
            'LEFT JOIN',
            $source['name'],
            'AS',
            $source['alias'],
            'ON',
            $this->prependField($column),
            '=',
            $this->prependField($onColumn, $table),
        ];

        if (!empty($this->join)) {
            $this->join = Arr::merge($this->join, $join);
        } else {
            // Build join
            $this->join = $join;
        }

        $this->joinedQuerySources[] = $source;

        return $this;
    }

    /** @inheritDoc */
    public function rightJoin(Table $table, string $column, string $onColumn)
    {
        $source = $this->captureQuerySource($table);
        $join = [
            'RIGHT JOIN',
            $source['name'],
            'AS',
            $source['alias'],
            'ON',
            $this->prependField($column),
            '=',
            $this->prependField($onColumn, $table),
        ];

        if (!empty($this->join)) {
            $this->join = Arr::merge($this->join, $join);
        } else {
            // Build join
            $this->join = $join;
        }

        $this->joinedQuerySources[] = $source;

        return $this;
    }

    /** @inheritDoc */
    public function groupBy(string $column, string ...$columns)
    {
        foreach (Arr::merge([$column], $columns) as $columnToGroup) {
            // Build group by
            if (empty($this->groupBy)) {
                $this->groupBy = ['GROUP BY', $this->prependField($columnToGroup)];
            } else {
                $this->groupBy[] = ',';
                $this->groupBy[] = $this->prependField($columnToGroup);
            }
        }

        return $this;
    }

    /** @inheritDoc */
    public function sum(string $fieldToSum, ?string $alias = null)
    {
        $alias = $alias ?: $fieldToSum . '_sum';
        // Prepare select
        $select = ['SUM(' . $this->prependField($fieldToSum) . ')', 'as', $alias];

        // Add a comma to the end if it isn't the only field
        if (count($this->select) > 1) {
            array_unshift($select, ',');
        }

        if (empty($this->select)) {
            $this->select = ['SELECT'];
        }

        // Merge into select statement.
        $this->select = array_merge($this->select, $select);

        return $this;
    }

    /** @inheritDoc */
    public function count(string $fieldToCount, ?string $alias = null)
    {
        // Default alias is "<field>_count", with a special case for `*` —
        // `*_count` isn't a valid SQL identifier and produces a syntax error
        // when the query is executed.
        if ($alias === null) {
            $alias = $fieldToCount === '*' ? 'count' : $fieldToCount . '_count';
        }

        if ($fieldToCount !== '*') {
            $fieldToCount = $this->prependField($fieldToCount);
        }

        // Prepare select
        $select = ['COUNT(' . $fieldToCount . ')', 'as', $alias];

        // Add a comma to the end if it isn't the only field
        if (count($this->select) > 1) {
            array_unshift($select, ',');
        }

        if (empty($this->select)) {
            $this->select = ['SELECT'];
        }

        // Merge into select
        $this->select = array_merge($this->select, $select);

        return $this;
    }

    /** @inheritDoc */
    public function limit(int $limit)
    {
        $this->limit = ['LIMIT', $limit];

        return $this;
    }

    /** @inheritDoc */
    public function offset(int $offset)
    {
        $this->offset = ['OFFSET', $offset];

        return $this;
    }

    /** @inheritDoc */
    public function orderBy(string $field, string $order)
    {
        // Ensure order is uppercase
        $order = strtoupper($order);

        // Ensure order is valid
        if (!in_array($order, ['ASC', 'DESC'])) {
            $order = 'ASC';
        }

        // Add order by
        $this->orderBy = ['ORDER BY', $this->prependField($field), $order];

        return $this;
    }

    /** @inheritDoc */
    public function build(): string
    {
        if (empty($this->select)) {
            $this->reset();
            throw new QueryBuilderException('Missing select field');
        }

        if (empty($this->from)) {
            $this->reset();
            throw new QueryBuilderException('Missing from field');
        }

        foreach ($this->operands as $operand) {
            if (!$this->isValidOperand($operand)) {
                $this->reset();
                throw new QueryBuilderException('Invalid operand' . $operand);
            }
        }

        $this->sql = Arr::merge($this->select, $this->from);
        $this->maybeAppend('join');
        $whereClause = null;
        $whereSqlIndex = null;
        $wherePrepareIndex = count($this->prepare);

        // ClauseBuilder handles its own sanitization, so it's not double-processed.
        if ($this->clauseBuilder !== null) {
            $whereClause = $this->buildClause($this->clauseBuilder);

            if (!empty($whereClause)) {
                $whereSqlIndex = count($this->sql);
                $this->sql[] = 'WHERE ' . $whereClause;
            }
        }

        $this->maybeAppend('groupBy');
        $this->maybeAppend('orderBy');
        $this->maybeAppend('limit');
        $this->maybeAppend('offset');

        // If necessary, prepare the query
        if (!empty($this->prepare)) {
            if ($whereSqlIndex !== null && $whereClause !== null) {
                $this->sql[$whereSqlIndex] = 'WHERE ?p';
                array_splice($this->prepare, $wherePrepareIndex, 0, [$whereClause]);
            }

            $database = $this->getActiveDatabaseStrategy();
            $sql = implode(' ', $this->sql);
            $sql = $database === null
                ? Database::parse($sql, ...$this->prepare)
                : $database->parse($sql, ...$this->prepare);
        } else {
            $sql = implode(' ', $this->sql);
        }

        $this->reset();

        return $sql;
    }

    /** @inheritDoc */
    public function reset()
    {
        $this->select = [];
        $this->clauseBuilder = null;
        $this->from = [];
        $this->sql = [];
        $this->preparedValues = [];
        $this->prepare = [];
        $this->raw = [];
        $this->items = [];
        $this->operands = [];
        $this->limit = [];
        $this->offset = [];
        $this->orderBy = [];
        $this->groupBy = [];
        $this->join = [];
        $this->rootQuerySource = null;
        $this->joinedQuerySources = [];

        return $this;
    }

    /** @inheritDoc */
    public function resetClauses(string $clause, string ...$clauses)
    {
        $clauses[] = $clause;

        foreach ($clauses as $clauseToReset) {
            $isSourceMetadata = in_array($clauseToReset, ['rootQuerySource', 'joinedQuerySources'], true);
            if (!$isSourceMetadata && isset($this->$clauseToReset)) {
                $this->$clauseToReset = [];
            }

            if ($clauseToReset === 'from') {
                $this->rootQuerySource = null;
            } elseif ($clauseToReset === 'join') {
                $this->joinedQuerySources = [];
            }
        }

        return $this;
    }

    /**
     * @return array{table: Table, name: string, alias: string}
     */
    protected function captureQuerySource(Table $table): array
    {
        return [
            'table' => $table,
            'name' => $table->getName(),
            'alias' => $table->getAlias(),
        ];
    }

    /**
     * @param array{table: Table, name: string, alias: string} $source
     */
    protected function assertQuerySourceIsUnchanged(array $source): void
    {
        if ($source['table']->getName() !== $source['name'] || $source['table']->getAlias() !== $source['alias']) {
            throw new QueryBuilderException('A table source changed after it was added to the query.');
        }
    }

    protected function buildClause(ClauseBuilder $clause): string
    {
        $database = $this->getActiveDatabaseStrategy();

        if ($database !== null && $clause instanceof CanBuildWithDatabaseStrategy) {
            return $clause->buildWithDatabaseStrategy($database);
        }

        return $clause->build();
    }

    protected function getActiveDatabaseStrategy(): ?DatabaseStrategy
    {
        if ($this->databaseStrategyStack === []) {
            return null;
        }

        return $this->databaseStrategyStack[count($this->databaseStrategyStack) - 1];
    }

    /**
     * Validates operands.
     *
     * @param string $operand The operand to check for
     * @return bool true if it exists, otherwise false.
     * @since 1.2.3
     *
     */
    private function isValidOperand($operand): bool
    {
        return in_array($operand, ['>', '<', '=', '<=', '>=', '!>', '!<', '!=', '!<=', '!>=', 'IN', 'NOT IN', 'LIKE']);
    }

    /**
     * Appends a query clause if it is set.
     *
     * @param string $key The query clause key
     *
     */
    private function maybeAppend(string $key)
    {
        if (isset($this->$key) && is_array($this->$key)) {
            foreach ($this->$key as $id => $value) {
                if (is_array($value)) {
                    $this->prepare[] = $value['value'];
                    $this->$key[$id] = $value['type'];
                }
            }

            $this->sql = array_merge($this->sql, array_values($this->$key));
        }
    }
}
