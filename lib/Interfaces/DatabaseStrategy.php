<?php

namespace PHPNomad\MySql\Integration\Interfaces;

use PHPNomad\Datastore\Exceptions\DatastoreErrorException;

interface DatabaseStrategy
{
    /**
     * Parses the args, making them safe for execution.
     *
     * @param string $query
     * @param ...$args
     * @return string
     */
    public function parse(string $query, ...$args): string;

    /**
     * Queries the database. Note that this does not sanitize. Use QueryStrategy::parse to sanitize.
     *
     * @param string $query
     * @return mixed
     * @throws DatastoreErrorException
     */
    public function query(string $query);
}
