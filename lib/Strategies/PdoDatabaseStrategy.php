<?php

namespace PHPNomad\MySql\Integration\Strategies;

use PDOException;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;

/**
 * PDO-backed DatabaseStrategy: the maintained replacement for the
 * abandoned colshrapnel/safemysql backend (phpnomad/safemysql-integration#2).
 *
 * parse() implements the same placeholder language SafeMySQL defined —
 * ?n identifier, ?s string, ?i integer, ?a IN-list, ?u SET clause,
 * ?p raw fragment — including the argument preprocessing the SafeMySQL
 * integration layered on top (lists of row arrays and associative arrays
 * become raw VALUES tuples; scalars reaching ?a are wrapped), because the
 * query builders depend on those behaviors. Value escaping is delegated
 * to the driver via PDO::quote(), never hand-rolled.
 */
class PdoDatabaseStrategy implements DatabaseStrategy
{
    public function __construct(protected PdoConnection $connection)
    {
    }

    /** @inheritDoc */
    public function parse(string $query, ...$args): string
    {
        $parts = preg_split('/(\?[nsiaup])/', $query, -1, PREG_SPLIT_DELIM_CAPTURE);
        $result = '';
        $index = 0;

        foreach ($parts as $i => $part) {
            if (($i % 2) === 0) {
                $result .= $part;
                continue;
            }

            $value = $args[$index] ?? null;
            $index++;

            // The SafeMySQL integration accepted richer argument shapes than
            // the base placeholder language: a list of row arrays (bulk
            // INSERT VALUES) or a single associative array arriving at ?a/?p
            // becomes a raw, fully-escaped tuples fragment. Preserved here
            // because the query builders depend on it. ?u is exempt — it
            // legitimately consumes an associative array.
            if (in_array($part, ['?a', '?p'], true) && is_array($value) && !empty($value)) {
                if (is_array(reset($value))) {
                    $result .= $this->convertRowTuples($value);
                    continue;
                }
                if ($this->isAssociativeArray($value)) {
                    $result .= $this->convertRowTuples([$value]);
                    continue;
                }
            }

            $result .= match ($part) {
                '?n' => $this->escapeIdentifier((string) $value),
                '?s' => $this->escapeString($value),
                '?i' => $this->escapeInt($value),
                '?a' => $this->createInList(is_array($value) ? $value : [$value]),
                '?u' => $this->createSetClause((array) $value),
                '?p' => (string) $value,
            };
        }

        return $result;
    }

    /** @inheritDoc */
    public function query(string $query)
    {
        try {
            $statement = $this->connection->pdo()->query($query);

            if ($statement->columnCount() > 0) {
                return $statement->fetchAll();
            }

            return $statement->rowCount();
        } catch (PDOException $e) {
            // Keep the message stable: SQL and driver error detail must not
            // reach clients. The chained exception carries it for loggers.
            throw new DatastoreErrorException('Failed to execute query.', 500, $e);
        }
    }

    protected function escapeIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    protected function escapeString(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return $this->connection->pdo()->quote((string) $value);
    }

    protected function escapeInt(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_float($value)) {
            return number_format($value, 0, '.', '');
        }

        return (string) (int) $value;
    }

    /**
     * @param array<int, mixed> $values
     */
    protected function createInList(array $values): string
    {
        if (empty($values)) {
            return 'NULL';
        }

        return implode(',', array_map(fn($value) => $this->escapeString($value), $values));
    }

    /**
     * @param array<string, mixed> $pairs
     */
    protected function createSetClause(array $pairs): string
    {
        $sets = [];
        foreach ($pairs as $column => $value) {
            $sets[] = $this->escapeIdentifier((string) $column) . '=' . $this->escapeString($value);
        }

        return implode(', ', $sets);
    }

    protected function isAssociativeArray(array $arr): bool
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * @param array<int, array<string, mixed>> $tuples
     */
    protected function convertRowTuples(array $tuples): string
    {
        $rows = [];
        foreach ($tuples as $tuple) {
            $values = [];
            foreach ($tuple as $value) {
                if (is_null($value)) {
                    $values[] = 'NULL';
                } elseif (is_int($value) || is_float($value)) {
                    $values[] = (string) $value;
                } else {
                    $values[] = $this->escapeString($value);
                }
            }
            $rows[] = '(' . implode(', ', $values) . ')';
        }

        return implode(', ', $rows);
    }
}
