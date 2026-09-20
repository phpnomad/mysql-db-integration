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

        $placeholder = $this->generatePlaceholder($field, $values, $operator);

        if (!empty($this->clauses) && $logic && in_array(strtoupper($logic), ['AND', 'OR'])) {
            $this->clauses[] = strtoupper($logic);
        }

        $this->clauses[] = $fieldStr;
        $this->clauses[] = $operator;
        $this->clauses[] = $placeholder;

        foreach (Arr::whereNotNull($values) as $value) {
            $this->preparedValues[] = $value;
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function build(): string
    {
        global $wpdb;
        $queryParts = [];
        $allValues = $this->preparedValues; // Initially prepared values
        $subQueryReplacements = [];
        $query = "";
        $marker = 0;

        foreach ($this->clauses as $clause) {
            if (is_string($clause)) {
                // Directly append logical operators or raw SQL parts
                $queryParts[] = $clause;
            } elseif (is_array($clause) && isset($clause['logic'], $clause['clauses'])) {
                // Process group of clauses
                $groupParts = [];
                foreach ($clause['clauses'] as $groupClause) {
                    if ($groupClause instanceof ClauseBuilder) {
                        $marker++;
                        $uniqueMarker = '__NOMADIC_SUBQUERY__' . $marker;
                        $builtClause = $this->buildClause($groupClause);
                        $subQueryReplacements[$uniqueMarker] = $builtClause;
                        $groupParts[] = $uniqueMarker;
                    }
                }
                if (!empty($groupParts)) {
                    $queryParts[] = '(' . implode(" {$clause['logic']} ", $groupParts) . ')';
                }
            } elseif ($clause instanceof ClauseBuilder) {
                $marker++;
                $uniqueMarker = '__NOMADIC_SUBQUERY__' . $marker;
                $builtClause = $this->buildClause($clause);
                $subQueryReplacements[$uniqueMarker] = $builtClause;
                $queryParts[] = $uniqueMarker;
            }
        }

        if (!empty($queryParts)) {
            $query = implode(' ', $queryParts);

            // Prepare the query with initial values if available
            if (!empty($allValues)) {
                $database = $this->getActiveDatabaseStrategy();
                $query = $database === null
                    ? Database::parse($query, ...$allValues)
                    : $database->parse($query, ...$allValues);
            }

            // Replace subquery markers with their actual queries
            foreach ($subQueryReplacements as $marker => $subQuery) {
                $query = str_replace($marker, $subQuery, $query);
            }
        }

        $this->reset();

        return $query;
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
