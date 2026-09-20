<?php

namespace PHPNomad\MySql\Integration\Tests\Unit\Fixtures;

use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\MySql\Integration\Builders\QueryBuilder;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

final class JoinedQuerySourcesConsumerBuilder extends QueryBuilder
{
    protected int $joinedQuerySources = 7;

    public function consumerJoinState(): int
    {
        return $this->joinedQuerySources;
    }
}

$table = static function (string $name, string $alias): Table {
    return new class ($name, $alias) implements Table {
        public function __construct(private string $name, private string $alias)
        {
        }
        public function getName(): string
        {
            return $this->name;
        }
        public function getAlias(): string
        {
            return $this->alias;
        }
        public function getTableVersion(): string
        {
            return '1';
        }
        public function getColumns(): array
        {
            return [new Column('id', 'BIGINT')];
        }
        public function getIndices(): array
        {
            return [];
        }
        public function getCharset(): ?string
        {
            return null;
        }
        public function getCollation(): ?string
        {
            return null;
        }
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
            return $this->name;
        }
    };
};
$root = $table('scores', 's');
$join = $table('programs', 'p');
$assertConsumerJoinState = static function (int $actual, string $failure): void {
    if ($actual !== 7) {
        fwrite(STDERR, $failure);
        exit(1);
    }
};
$builder = (new JoinedQuerySourcesConsumerBuilder())
    ->from($root)
    ->select('*')
    ->leftJoin($join, 'programId', 'id');

$assertConsumerJoinState($builder->consumerJoinState(), 'CONSUMER_JOIN_STATE_CHANGED');
if (method_exists($builder, 'getReferencedTables') && $builder->getReferencedTables() !== [$root, $join]) {
    fwrite(STDERR, 'PARENT_QUERY_METADATA_CHANGED');
    exit(1);
}
if ($builder->build() !== 'SELECT * FROM scores AS s LEFT JOIN programs AS p ON s.programId = p.id') {
    fwrite(STDERR, 'BUILT_QUERY_CHANGED');
    exit(1);
}
$assertConsumerJoinState($builder->consumerJoinState(), 'CONSUMER_JOIN_STATE_RESET');

fwrite(STDOUT, 'JOINED_QUERY_SOURCES_SUBCLASS_COMPATIBLE');
