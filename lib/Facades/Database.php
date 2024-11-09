<?php

namespace PHPNomad\MySql\Integration\Facades;

use PHPNomad\Facade\Abstracts\Facade;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy as QueryStrategyInterface;
use PHPNomad\Singleton\Traits\WithInstance;

class Database extends Facade
{
    use WithInstance;

    public static function parse(string $query, ...$args)
    {
        return static::instance()->getContainedInstance()->parse($query, ...$args);
    }

    public static function query(string $query)
    {
        return static::instance()->getContainedInstance()->query($query);
    }

    protected function abstractInstance(): string
    {
        return QueryStrategyInterface::class;
    }
}