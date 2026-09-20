<?php

namespace PHPNomad\MySql\Integration\Tests\Integration\Fixtures;

use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Factories\Index;
use PHPNomad\Database\Interfaces\Table;

/** Descriptor for the connection-owned temporary formatting fixture. */
final class FormattingTable implements Table
{
    public function getName(): string { return 'nomad_bound_formatting'; }
    public function getAlias(): string { return 'f'; }
    public function getTableVersion(): string { return '1'; }
    /** @return list<Column> */
    public function getColumns(): array
    {
        return [new Column('id', 'INT', null, 'PRIMARY KEY'), new Column('score', 'INT'), new Column('label', 'VARCHAR(64)')];
    }
    /** @return list<Index> */
    public function getIndices(): array { return []; }
    public function getCharset(): ?string { return 'utf8mb4'; }
    public function getCollation(): ?string { return 'utf8mb4_bin'; }
    /** @return non-empty-list<string> */
    public function getFieldsForIdentity(): array { return ['id']; }
    public function getUnprefixedName(): string { return $this->getName(); }
    public function getSingularUnprefixedName(): string { return 'bound_formatting'; }
}
