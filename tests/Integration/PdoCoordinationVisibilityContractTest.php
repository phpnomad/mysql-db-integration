<?php

namespace PHPNomad\MySql\Integration\Tests\Integration;

use PDO;
use PHPNomad\Database\Exceptions\UnsupportedCoordinationException;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\CoordinationTable;
use PHPNomad\MySql\Integration\Tests\Integration\Fixtures\OwnedPdoCoordinationContractCase;

/** Real account grants distinguish absent metadata from invisible metadata. */
final class PdoCoordinationVisibilityContractTest extends OwnedPdoCoordinationContractCase
{
    private ?int $previousPartialRevokes = null;
    private ?string $otherOwnedSchema = null;

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            try {
                if ($this->previousPartialRevokes !== null) {
                    $this->observer->exec('SET GLOBAL partial_revokes = ' . $this->previousPartialRevokes);
                }
            } finally {
                if ($this->otherOwnedSchema !== null) {
                    $this->observer->exec('DROP DATABASE `' . $this->otherOwnedSchema . '`');
                }
            }
        }
    }

    /** @dataProvider insufficientVisibility */
    public function testUnprovedVisibilityRefusesBeforeTheCallbackEvenWhenNoTriggerExists(string $coverage): void
    {
        $this->useRestrictedAccount($coverage);
        $calls = 0;
        try {
            $this->coordinate(function () use (&$calls): void { $calls++; });
            self::fail('Every participant needs proved trigger visibility.');
        } catch (UnsupportedCoordinationException $failure) {
            self::assertSame(0, $calls);
        }
        self::assertFalse($this->primary->inTransaction());
        self::assertSame([], $this->visibleEffects());
        $this->assertFailureLog('validation', 'unchanged', false, UnsupportedCoordinationException::class);
    }

    /** @dataProvider sufficientVisibility */
    public function testDirectGrantsPermitCoordinationWithoutGlobalPrivileges(string $coverage): void
    {
        $this->useRestrictedAccount($coverage);
        $calls = 0;
        $result = $this->coordinate(function (DatabaseStrategy $backend) use (&$calls): string {
            $calls++;
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            return 'committed';
        });
        self::assertSame('committed', $result);
        self::assertSame(1, $calls);
        self::assertSame([['id' => '1', 'score' => '12']], $this->visibleEffects());
        self::assertFalse($this->primary->inTransaction());
        self::assertSame([], $this->logger->entries);
    }

    /** @dataProvider invisibleTriggerCoverage */
    public function testAnInvisibleTriggerCannotLeakANontransactionalWrite(string $coverage): void
    {
        $hidden = 'nomad_hidden_' . bin2hex(random_bytes(6));
        $this->observer->exec('CREATE TABLE `' . $hidden . '` (id BIGINT PRIMARY KEY, score BIGINT) ENGINE=MyISAM');
        $this->ownedTables[] = $hidden;
        $this->observer->exec('CREATE TRIGGER `' . $hidden . '_trigger` AFTER INSERT ON `' .
            $this->effects->getName() . '` FOR EACH ROW INSERT INTO `' . $hidden . '` VALUES (NEW.id, NEW.score)');
        $this->useRestrictedAccount($coverage);
        $visible = $this->primary->prepare('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?');
        self::assertNotFalse($visible);
        $visible->execute([$this->effects->getName()]);
        self::assertSame([], $visible->fetchAll(), 'The hazardous trigger must be invisible to the operation account.');
        $calls = 0;
        try {
            $this->coordinate(function (DatabaseStrategy $backend) use (&$calls): void {
                $calls++;
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            });
            self::fail('Invisible metadata is not evidence of safe storage.');
        } catch (UnsupportedCoordinationException $failure) {
            self::assertSame(0, $calls);
        }
        self::assertSame([], $this->visibleEffects());
        $hiddenRows = $this->observer->query('SELECT * FROM `' . $hidden . '`');
        self::assertNotFalse($hiddenRows);
        self::assertSame([], $hiddenRows->fetchAll());
        self::assertFalse($this->primary->inTransaction());
        $this->assertFailureLog('validation', 'unchanged', false, UnsupportedCoordinationException::class);
    }

    /** @dataProvider participantGrantCoverage */
    public function testDirectTableGrantsMustCoverEveryParticipant(?int $missingPosition): void
    {
        $third = new CoordinationTable($this->effects->getName() . '_third', ['id']);
        $this->createTable($third->getName(), '(id BIGINT PRIMARY KEY, score BIGINT NOT NULL)');
        $participants = [$this->parents, $this->effects, $third];
        $names = array_map(static fn(CoordinationTable $table): string => $table->getName(), $participants);
        $granted = $names;
        if ($missingPosition !== null) {
            unset($granted[$missingPosition]);
        }
        $this->useRestrictedAccount('direct tables', array_values($granted));
        $calls = 0;
        try {
            $result = $this->strategy->coordinate($this->parents, ['tenantId' => 1, 'id' => 7], $participants,
                function (DatabaseStrategy $backend) use (&$calls, $third): string {
                    $calls++;
                    $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
                    $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 13)', $third->getName()));
                    return 'committed';
                }
            );
            self::assertNull($missingPosition, 'Missing direct visibility must refuse before the callback.');
            self::assertSame('committed', $result);
        } catch (UnsupportedCoordinationException $failure) {
            self::assertNotNull($missingPosition, 'Complete direct table grants must be accepted.');
        }
        self::assertSame($missingPosition === null ? 1 : 0, $calls);
        self::assertFalse($this->primary->inTransaction());
        self::assertSame($missingPosition === null ? [['id' => '1', 'score' => '12']] : [], $this->visibleEffects());
        $thirdRows = $this->observer->query('SELECT * FROM `' . $third->getName() . '`');
        self::assertNotFalse($thirdRows);
        self::assertSame($missingPosition === null ? [['id' => '1', 'score' => '13']] : [], $thirdRows->fetchAll());
        if ($missingPosition === null) {
            self::assertSame([], $this->logger->entries);
        } else {
            $this->assertFailureLog('validation', 'unchanged', false, UnsupportedCoordinationException::class, null, null, $names);
        }
    }

    /** @return array<string, array{?int}> */
    public static function participantGrantCoverage(): array
    {
        return ['first absent' => [0], 'middle absent' => [1], 'last absent' => [2], 'all present' => [null]];
    }

    /** @param list<string> $triggerTables */
    private function useRestrictedAccount(string $coverage, array $triggerTables = []): void
    {
        if ($coverage === 'partial revoke') {
            $setting = $this->observer->query('SELECT @@GLOBAL.partial_revokes');
            self::assertNotFalse($setting);
            $this->previousPartialRevokes = (int) $setting->fetchColumn();
            $this->observer->exec('SET GLOBAL partial_revokes = 1');
        }
        $name = 'nomad_coord_' . bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(24));
        $account = $this->observer->quote($name) . "@'%'";
        $this->observer->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $this->observer->quote($password));
        $this->ownedAccounts[] = $name;
        $schemaQuery = $this->observer->query('SELECT DATABASE()');
        self::assertNotFalse($schemaQuery);
        $schema = $schemaQuery->fetchColumn();
        self::assertIsString($schema);
        self::assertNotSame('', $schema);
        $database = '`' . str_replace('`', '``', $schema) . '`';
        $this->observer->exec('GRANT SELECT, INSERT, UPDATE, DELETE ON ' . $database . '.* TO ' . $account);
        if (in_array($coverage, ['other schema', 'other schema tables'], true)) {
            $other = 'nomad_scope_' . bin2hex(random_bytes(6));
            $this->observer->exec('CREATE DATABASE `' . $other . '`');
            $this->otherOwnedSchema = $other;
            if ($coverage === 'other schema') {
                $this->observer->exec('GRANT TRIGGER ON `' . $other . '`.* TO ' . $account);
            } else {
                foreach ([$this->parents->getName(), $this->effects->getName()] as $table) {
                    $this->observer->exec('CREATE TABLE `' . $other . '`.`' . $table . '` (id BIGINT PRIMARY KEY) ENGINE=InnoDB');
                    $this->observer->exec('GRANT TRIGGER ON `' . $other . '`.`' . $table . '` TO ' . $account);
                }
            }
        }
        if ($coverage === 'schema') {
            $this->observer->exec('GRANT TRIGGER ON ' . $database . '.* TO ' . $account);
        }
        if ($coverage === 'schema all') {
            $this->observer->exec('GRANT ALL PRIVILEGES ON ' . $database . '.* TO ' . $account);
        }
        if ($coverage === 'global trigger') {
            $this->observer->exec('GRANT TRIGGER ON *.* TO ' . $account);
        }
        if ($coverage === 'global all') {
            $this->observer->exec('GRANT ALL PRIVILEGES ON *.* TO ' . $account);
        }
        if ($coverage === 'schema wildcard') {
            $pattern = str_replace('`', '``', $schema . '%');
            $this->observer->exec('GRANT TRIGGER ON `' . $pattern . '`.* TO ' . $account);
        }
        if ($coverage === 'escaped schema pattern') {
            $pattern = str_replace(['_', '%'], ['\\_', '\\%'], $schema);
            if ($pattern === $schema) {
                $pattern .= '\\%';
            }
            $this->observer->exec('GRANT TRIGGER ON `' . str_replace('`', '``', $pattern) . '`.* TO ' . $account);
        }
        if ($coverage === 'table all') {
            foreach ([$this->parents->getName(), $this->effects->getName()] as $table) {
                $this->observer->exec('GRANT ALL PRIVILEGES ON ' . $database . '.`' . $table . '` TO ' . $account);
            }
        }
        foreach ($triggerTables as $table) {
            $this->observer->exec('GRANT TRIGGER ON ' . $database . '.`' . $table . '` TO ' . $account);
        }
        if ($coverage === 'partial revoke') {
            $this->observer->exec('GRANT TRIGGER ON *.* TO ' . $account);
            $this->observer->exec('REVOKE TRIGGER ON ' . $database . '.* FROM ' . $account);
        }
        if (in_array($coverage, ['parent only', 'each table'], true)) {
            $this->observer->exec('GRANT TRIGGER ON ' . $database . '.`' . $this->parents->getName() . '` TO ' . $account);
        }
        if (in_array($coverage, ['effect only', 'each table'], true)) {
            $this->observer->exec('GRANT TRIGGER ON ' . $database . '.`' . $this->effects->getName() . '` TO ' . $account);
        }
        if ($coverage === 'role only') {
            $roleName = $name . '_r';
            $role = $this->observer->quote($roleName) . "@'%'";
            $this->observer->exec('CREATE ROLE ' . $role);
            $this->ownedAccounts[] = $roleName;
            $this->observer->exec('GRANT TRIGGER ON ' . $database . '.* TO ' . $role);
            $this->observer->exec('GRANT ' . $role . ' TO ' . $account);
            $this->observer->exec('SET DEFAULT ROLE ' . $role . ' TO ' . $account);
        }
        $dsn = getenv('TEST_MYSQL_COORDINATION_DSN');
        self::assertIsString($dsn);
        self::assertNotSame('', $dsn);
        $pdo = new PDO($dsn, $name, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => true,
            PDO::ATTR_PERSISTENT => false,
        ]);
        if ($coverage === 'role only') {
            $roles = $pdo->query('SELECT CURRENT_ROLE()');
            self::assertNotFalse($roles);
            self::assertNotSame('NONE', $roles->fetchColumn(), 'The unsupported role-only profile must have an active role.');
        }
        $this->usePrimary($pdo);
    }

    /** @return array<string, array{string}> */
    public static function insufficientVisibility(): array
    {
        return [
            'none' => ['none'], 'parent only' => ['parent only'], 'effect only' => ['effect only'], 'role only' => ['role only'],
            'schema wildcard' => ['schema wildcard'], 'escaped schema pattern' => ['escaped schema pattern'],
            'other schema' => ['other schema'], 'other schema tables' => ['other schema tables'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function sufficientVisibility(): array
    {
        return [
            'schema' => ['schema'], 'each table' => ['each table'],
            'schema all' => ['schema all'], 'table all' => ['table all'],
            'global trigger' => ['global trigger'], 'global all' => ['global all'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function invisibleTriggerCoverage(): array
    {
        return ['no grant' => ['none'], 'global grant partially revoked' => ['partial revoke']];
    }
}
