<?php

namespace PHPNomad\MySql\Integration\Builders;

use PHPNomad\Database\Exceptions\QueryBuilderException;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Traits\WithPrependedFields;
use PHPNomad\MySql\Integration\Facades\Database;
use PHPNomad\MySql\Integration\Interfaces\CanBuildWithDatabaseStrategy;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\Utils\Helpers\Arr;

class MySqlClauseBuilder implements ClauseBuilder, CanBuildWithDatabaseStrategy
{
    use WithPrependedFields;

    /** @var list<mixed> */
    protected array $clauses = [];
    /** @var list<mixed> */
    protected array $preparedValues = [];
    /**
     * Values for each condition, keyed by the index of the condition's
     * placeholder in $clauses.
     *
     * @var array<int, list<mixed>>
     */
    protected array $conditionValues = [];
    /** @var list<DatabaseStrategy> */
    private array $databaseStrategyStack = [];

    /** @var list<string> */
    protected array $validOperators = ["=", "<", ">", "<=", ">=", "<>", "!=",
        "LIKE", "NOT LIKE", "IN", "NOT IN", "BETWEEN",
        "NOT BETWEEN", "IS NULL", "IS NOT NULL"];

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

    /**
     * @inheritDoc
     */
    public function where($field, string $operator, ...$values)
    {
        $this->addCondition($field, $operator, $values);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function andWhere($field, string $operator, ...$values)
    {
        $this->addCondition($field, $operator, $values, 'AND');
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function orWhere($field, string $operator, ...$values)
    {
        $this->addCondition($field, $operator, $values, 'OR');
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function group(string $logic, ClauseBuilder ...$clauses)
    {
        $this->clauses[] = ['logic' => $this->normalizeGroupLogic($logic), 'clauses' => $clauses];

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function andGroup(string $logic, ClauseBuilder ...$clauses)
    {
        $logic = $this->normalizeGroupLogic($logic);

        if (!empty($this->clauses)) {
            $this->clauses[] = 'AND';
        }

        return $this->group($logic, ...$clauses);
    }

    /**
     * @inheritDoc
     */
    public function orGroup(string $logic, ClauseBuilder ...$clauses)
    {
        $logic = $this->normalizeGroupLogic($logic);

        if (!empty($this->clauses)) {
            $this->clauses[] = 'OR';
        }

        return $this->group($logic, ...$clauses);
    }

    /**
     * Gets the field string after validating every requested field.
     *
     * @param string|string[] $field
     * @return string|null
     * @throws QueryBuilderException
     */
    protected function getFieldString($field): ?string
    {
        if (!is_array($field)) {
            if (!$this->tableHasField($field)) {
                throw new QueryBuilderException("Unknown field: {$field}");
            }

            return $this->prependField($field);
        }

        if ($field === []) {
            throw new QueryBuilderException('A condition field list cannot be empty.');
        }

        foreach ($field as $member) {
            if (!$this->tableHasField($member)) {
                throw new QueryBuilderException("Unknown field: {$member}");
            }
        }

        $fieldStr = Arr::process($field)
            ->map(fn ($member) => $this->prependField($member))
            ->setSeparator(', ')
            ->toString();

        return "($fieldStr)";
    }

    /**
     * Adds a condition to the clause builder.
     *
     * @param string|string[] $field The field, or fields to be compared.
     * @param string $operator The operator to be used in the comparison.
     * @param array<mixed> $values The values to be compared against.
     * @param ?string $logic (optional) The logic operator to be prepended to the condition.
     * @return $this
     */
    protected function addCondition($field, string $operator, array $values, ?string $logic = null): self
    {
        $operator = strtoupper($operator);

        if (!in_array($operator, $this->validOperators, true)) {
            throw new QueryBuilderException("Unknown operator: {$operator}");
        }

        $fieldStr = $this->getFieldString($field);

        if ($fieldStr === null) {
            throw new QueryBuilderException('A condition field cannot resolve to an empty value.');
        }

        $values = $this->normalizeConditionValues($operator, $values);
        $placeholder = $this->generatePlaceholder($field, $values, $operator);

        if (!empty($this->clauses) && $logic && in_array(strtoupper($logic), ['AND', 'OR'])) {
            $this->clauses[] = strtoupper($logic);
        }

        $this->clauses[] = $fieldStr;
        $this->clauses[] = $operator;
        $this->clauses[] = $placeholder;
        $this->conditionValues[array_key_last($this->clauses)] = [];

        foreach ($values as $value) {
            $this->preparedValues[] = $value;
            $this->conditionValues[array_key_last($this->clauses)][] = $value;
        }

        return $this;
    }

    /**
     * Builds the clause in a single pass.
     *
     * Grouped clauses are built first. Each run of plain conditions between
     * them is then bound to its own values, and the finished group SQL is
     * placed between those runs. Bound values are never searched or rewritten
     * afterward.
     *
     * @inheritDoc
     */
    public function build(): string
    {
        try {
            return $this->assemble();
        } finally {
            $this->reset();
        }
    }

    /**
     * Assembles the conditions into SQL.
     */
    protected function assemble(): string
    {
        $builtGroups = [];

        foreach ($this->clauses as $index => $clause) {
            if (is_array($clause) && isset($clause['logic'], $clause['clauses'])) {
                $groupParts = [];
                foreach ($clause['clauses'] as $groupClause) {
                    if ($groupClause instanceof ClauseBuilder) {
                        $groupParts[] = $this->buildClause($groupClause);
                    }
                }
                $builtGroups[$index] = empty($groupParts)
                    ? null
                    : '(' . implode(" {$clause['logic']} ", $groupParts) . ')';
            } elseif ($clause instanceof ClauseBuilder) {
                $builtGroups[$index] = $this->buildClause($clause);
            }
        }

        $queryParts = [];
        $run = [];
        $runValues = [];

        foreach ($this->clauses as $index => $clause) {
            if (is_string($clause)) {
                $run[] = $clause;
                foreach ($this->conditionValues[$index] ?? [] as $value) {
                    $runValues[] = $value;
                }
            } elseif (array_key_exists($index, $builtGroups)) {
                if (!empty($run)) {
                    $queryParts[] = $this->bindValues(implode(' ', $run), $runValues);
                    $run = [];
                    $runValues = [];
                }
                if ($builtGroups[$index] !== null) {
                    $queryParts[] = $builtGroups[$index];
                }
            }
        }

        if (!empty($run)) {
            $queryParts[] = $this->bindValues(implode(' ', $run), $runValues);
        }

        return implode(' ', $queryParts);
    }

    /**
     * Binds values to a run of conditions that holds no grouped clauses.
     *
     * @param string $sql The conditions, with their placeholders.
     * @param list<mixed> $values The values for those placeholders, in order.
     */
    protected function bindValues(string $sql, array $values): string
    {
        if (empty($values)) {
            return $sql;
        }

        $database = $this->getActiveDatabaseStrategy();

        return $database === null
            ? Database::parse($sql, ...$values)
            : $database->parse($sql, ...$values);
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
     * @inheritDoc
     */
    public function reset()
    {
        $this->clauses = [];
        $this->preparedValues = [];
        $this->conditionValues = [];

        return $this;
    }

    /**
     * @param string|string[] $field
     * @param array<mixed> $values
     */
    protected function generatePlaceholder($field, array $values, string $operator): string
    {
        $operator = strtoupper($operator);

        if ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
            return "";
        }

        if ($operator === 'IN' || $operator === 'NOT IN') {
            $placeholders = implode(',', array_fill(0, count($values), '?a'));
            return "($placeholders)";
        }

        if ($operator === 'BETWEEN' || $operator === 'NOT BETWEEN') {
            return '?s AND ?s';
        }

        return '?s';
    }

    /**
     * @param list<mixed> $values
     * @return list<mixed>
     * @throws QueryBuilderException
     */
    private function normalizeConditionValues(string $operator, array $values): array
    {
        $count = count($values);

        if ($operator === 'IS NULL' || $operator === 'IS NOT NULL') {
            if ($values === [] || $values === [null]) {
                return [];
            }

            throw new QueryBuilderException("Operator {$operator} accepts no values or one null value.");
        }

        if ($operator === 'BETWEEN' || $operator === 'NOT BETWEEN') {
            if ($count !== 2) {
                throw new QueryBuilderException("Operator {$operator} expects exactly two values; {$count} given.");
            }

            return $values;
        }

        if ($operator === 'IN' || $operator === 'NOT IN') {
            if ($count === 0) {
                throw new QueryBuilderException("Operator {$operator} expects at least one value.");
            }

            return $values;
        }

        if ($count !== 1) {
            throw new QueryBuilderException("Operator {$operator} expects exactly one value; {$count} given.");
        }

        return $values;
    }

    /** @throws QueryBuilderException */
    protected function normalizeGroupLogic(string $logic): string
    {
        $logic = strtoupper($logic);

        if (!in_array($logic, ['AND', 'OR'], true)) {
            throw new QueryBuilderException("Unknown group logic: {$logic}");
        }

        return $logic;
    }
}
