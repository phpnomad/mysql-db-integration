<?php

namespace PHPNomad\MySql\Integration\Builders;

use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Traits\WithPrependedFields;
use PHPNomad\MySql\Integration\Facades\Database;
use PHPNomad\Utils\Helpers\Arr;

class MySqlClauseBuilder implements ClauseBuilder
{
    use WithPrependedFields;

    protected array $clauses = [];
    protected array $preparedValues = [];

    /**
     * Values for each condition, keyed by the index of the condition's
     * placeholder in $clauses.
     *
     * @var array<int, array>
     */
    protected array $conditionValues = [];
    protected array $validOperators = ["=", "<", ">", "<=", ">=", "<>", "!=",
        "LIKE", "NOT LIKE", "IN", "NOT IN", "BETWEEN",
        "NOT BETWEEN", "IS NULL", "IS NOT NULL"];

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
        $group = ['logic' => $logic, 'clauses' => $clauses];
        $this->clauses[] = $group;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function andGroup(string $logic, ClauseBuilder ...$clauses)
    {
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
        if (!empty($this->clauses)) {
            $this->clauses[] = 'OR';
        }

        return $this->group($logic, ...$clauses);
    }

    /**
     * Gets the field string, filtering invalid fields.
     *
     * @param $field
     * @return string|null
     */
    protected function getFieldString($field): ?string
    {
        $result = null;

        if (!is_array($field) && $this->tableHasField($field)) {
            $result = $this->prependField($field);
        }

        if (is_array($field)) {
            $fieldStr = Arr::process($field)
                ->filter(fn($field) => $this->tableHasField($field))
                ->map(fn($field) => $this->prependField($field))
                ->setSeparator(', ')
                ->toString();

            if (!empty($fieldStr)) {
                $result = "($fieldStr)";
            }
        }

        return $result;
    }

    /**
     * Adds a condition to the clause builder.
     *
     * @param string|string[] $field The field, or fields to be compared.
     * @param string $operator The operator to be used in the comparison.
     * @param array $values The values to be compared against.
     * @param ?string $logic (optional) The logic operator to be prepended to the condition.
     * @return $this
     */
    protected function addCondition($field, string $operator, array $values, ?string $logic = null): self
    {
        $operator = strtoupper($operator);

        if (!in_array($operator, $this->validOperators)) {
            return $this;
        }

        $fieldStr = $this->getFieldString($field);

        // If the field isn't on the active table, drop the whole predicate.
        // Otherwise we'd push `null` for the field but still emit the operator
        // and placeholder, producing invalid SQL like `WHERE = 'value'`.
        if ($fieldStr === null) {
            return $this;
        }

        $placeholder = $this->generatePlaceholder($field, $values, $operator);

        if (!empty($this->clauses) && $logic && in_array(strtoupper($logic), ['AND', 'OR'])) {
            $this->clauses[] = strtoupper($logic);
        }

        $this->clauses[] = $fieldStr;
        $this->clauses[] = $operator;
        $this->clauses[] = $placeholder;
        $this->conditionValues[array_key_last($this->clauses)] = [];

        foreach (Arr::whereNotNull($values) as $value) {
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
        $builtGroups = [];

        foreach ($this->clauses as $index => $clause) {
            if (is_array($clause) && isset($clause['logic'], $clause['clauses'])) {
                $groupParts = [];
                foreach ($clause['clauses'] as $groupClause) {
                    if ($groupClause instanceof ClauseBuilder) {
                        $groupParts[] = $groupClause->build();
                    }
                }
                $builtGroups[$index] = empty($groupParts)
                    ? null
                    : '(' . implode(" {$clause['logic']} ", $groupParts) . ')';
            } elseif ($clause instanceof ClauseBuilder) {
                $builtGroups[$index] = $clause->build();
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

        $query = implode(' ', $queryParts);

        $this->reset();

        return $query;
    }

    /**
     * Binds values to a run of conditions that holds no grouped clauses.
     *
     * @param string $sql The conditions, with their placeholders.
     * @param array $values The values for those placeholders, in order.
     */
    protected function bindValues(string $sql, array $values): string
    {
        if (empty($values)) {
            return $sql;
        }

        return Database::parse($sql, ...$values);
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

    protected function generatePlaceholder($field, array $values, string $operator): string
    {
        $operator = strtoupper($operator);

        if($operator === 'IS NULL' || $operator === 'IS NOT NULL'){
            return "";
        }

        if ($operator === 'IN' || $operator === 'NOT IN') {
            $placeholders = implode(',', array_fill(0, count($values), '?a'));
            return "($placeholders)";
        }

        return '?s';
    }
}
