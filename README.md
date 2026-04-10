# phpnomad/mysql-db-integration

[![Latest Version](https://img.shields.io/packagist/v/phpnomad/mysql-integration.svg)](https://packagist.org/packages/phpnomad/mysql-integration)
[![Total Downloads](https://img.shields.io/packagist/dt/phpnomad/mysql-integration.svg)](https://packagist.org/packages/phpnomad/mysql-integration)
[![PHP Version](https://img.shields.io/packagist/php-v/phpnomad/mysql-integration.svg)](https://packagist.org/packages/phpnomad/mysql-integration)
[![License](https://img.shields.io/packagist/l/phpnomad/mysql-integration.svg)](https://packagist.org/packages/phpnomad/mysql-integration)

`phpnomad/mysql-db-integration` implements `phpnomad/db`'s query builders and table strategies for MySQL. It generates the SQL and delegates execution to a `DatabaseStrategy` bound in your container, so it does not open a MySQL connection on its own. Pair it with `phpnomad/safemysql-integration` for a ready-made driver, or supply your own if you want to run the queries through `mysqli` or `PDO` directly.

## Installation

```bash
composer require phpnomad/mysql-integration
```

## What This Provides

- A `QueryBuilder` that handles SELECT, JOIN, GROUP BY, ORDER BY, LIMIT, and OFFSET against `phpnomad/db` tables
- A `ClauseBuilder` for WHERE conditions with AND/OR groups and the common comparison, `LIKE`, `IN`, `BETWEEN`, and null operators
- `QueryStrategy`, `TableCreateStrategy`, `TableDeleteStrategy`, `TableExistsStrategy`, and `TableUpdateStrategy` implementations that cover insert, update, delete, query, estimated count, and schema management
- `DatabaseDateAdapter` that round-trips `DateTime` objects through the `Y-m-d H:i:s` MySQL format
- A `Database` facade exposing `parse()` and `query()` for the bound `DatabaseStrategy`

## Requirements

- `phpnomad/db ^2.0`
- `phpnomad/loader ^1.0 || ^2.0`
- A `PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy` implementation bound in the container. The `parse()` method uses SafeMySQL-style placeholders (`?s`, `?n`, `?a`), which `phpnomad/safemysql-integration` already implements.

## Usage

Add `MySqlInitializer` to your bootstrapper's initializer list and bind a `DatabaseStrategy` in the container before loading. The initializer registers every builder, strategy, and adapter listed above.

```php
<?php

use PHPNomad\Di\Container\Container;
use PHPNomad\Loader\Bootstrapper;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\MySqlInitializer;
use PHPNomad\SafeMySql\Integration\Strategies\SafeMySqlDatabaseStrategy;
use SafeMySQL;

$container = new Container();

$container->bindFactory(
    DatabaseStrategy::class,
    fn() => new SafeMySqlDatabaseStrategy(new SafeMySQL([
        'host' => '127.0.0.1',
        'user' => 'myapp',
        'pass' => 'secret',
        'db'   => 'myapp',
    ]))
);

$bootstrapper = new Bootstrapper(
    $container,
    new MySqlInitializer()
);

$bootstrapper->load();
```

Handlers in `phpnomad/db` will now resolve `QueryStrategy`, `QueryBuilder`, `ClauseBuilder`, and the table strategies from this package.

## Documentation

The `phpnomad/db` documentation at [phpnomad.com](https://phpnomad.com) covers table schemas, handlers, and how the query building pipeline fits together. Placeholder semantics and connection options for the SafeMySQL driver live in the [SafeMySQL repository](https://github.com/colshrapnel/safemysql).

## License

MIT License. See [LICENSE.txt](LICENSE.txt).
