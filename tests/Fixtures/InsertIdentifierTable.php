<?php

namespace PHPNomad\MySql\Integration\Tests\Fixtures;

use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Factories\Index;
use PHPNomad\Database\Interfaces\Table;

/** Literal column names for the isolated ordinary-insert contract. */
final class InsertIdentifierTable implements Table
{
    public function __construct(private string $name)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }
    public function getAlias(): string
    {
        return 'i';
    }
    public function getTableVersion(): string
    {
        return '1';
    }
    /** @return list<Column> */
    public function getColumns(): array
    {
        return [
            new Column('id', 'INT', null, 'PRIMARY KEY', 'AUTO_INCREMENT'),
            new Column('label', 'VARCHAR', [64]),
            new Column('order', 'VARCHAR', [64]),
            new Column('tick`label', 'VARCHAR', [64]),
            new Column('?s', 'VARCHAR', [64]),
            new Column('with.dot', 'VARCHAR', [64]),
        ];
    }
    /** @return list<Index> */
    public function getIndices(): array
    {
        return [];
    }
    public function getCharset(): ?string
    {
        return 'utf8mb4';
    }
    public function getCollation(): ?string
    {
        return 'utf8mb4_bin';
    }
    /** @return non-empty-list<string> */
    public function getFieldsForIdentity(): array
    {
        return ['id'];
    }
    public function getUnprefixedName(): string
    {
        return $this->name;
    }
    public function getSingularUnprefixedName(): string
    {
        return 'insert_identifier';
    }
}
