<?php

namespace PHPNomad\MySql\Integration\Tests\Integration\Fixtures;

use PDO;
use PHPNomad\MySql\Integration\Connections\PdoConnection;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Strategies\PdoCoordinatedDatabaseStrategy;
use RuntimeException;
use Throwable;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** @return array<string, mixed> */
function readMessage(): array
{
    $line = fgets(STDIN);
    if ($line === false) {
        throw new RuntimeException('The controlling test closed its input pipe.');
    }
    $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($message)) {
        throw new RuntimeException('The controlling test sent an invalid message.');
    }
    foreach (array_keys($message) as $key) {
        if (!is_string($key)) {
            throw new RuntimeException('Protocol fields must have names.');
        }
    }
    return $message;
}

/** @param array<string, mixed> $data */
function emit(string $event, array $data = []): void
{
    fwrite(STDOUT, json_encode(['event' => $event] + $data, JSON_THROW_ON_ERROR) . "\n");
    fflush(STDOUT);
}

/** @param array<string, mixed> $message */
function textField(array $message, string $key): string
{
    $value = $message[$key] ?? null;
    if (!is_string($value) || $value === '') {
        throw new RuntimeException('Missing protocol text field: ' . $key);
    }
    return $value;
}

/** @param array<string, mixed> $message */
function integerField(array $message, string $key): int
{
    $value = $message[$key] ?? null;
    if (!is_int($value)) {
        throw new RuntimeException('Missing protocol integer field: ' . $key);
    }
    return $value;
}

$callbackCalls = 0;
$logger = new RecordingLogger();
$pdo = null;
try {
    $config = readMessage();
    $dsn = getenv('TEST_MYSQL_COORDINATION_DSN');
    if (!$dsn) {
        throw new RuntimeException('An explicitly assigned test schema is required.');
    }
    $pdo = new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: 'root', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES => true,
        PDO::ATTR_PERSISTENT => false,
    ]);
    $connectionId = $pdo->query('SELECT CONNECTION_ID()');
    if ($connectionId === false) {
        throw new RuntimeException('Cannot identify the test connection.');
    }
    $id = $connectionId->fetchColumn();
    if (!is_numeric($id)) {
        throw new RuntimeException('Invalid server connection identity.');
    }
    if (($config['mode'] ?? 'effect') === 'ddl') {
        emit('READY', ['connectionId' => (int) $id]);
        if ((readMessage()['action'] ?? null) !== 'run') {
            throw new RuntimeException('Expected the DDL run barrier.');
        }
        $table = textField($config, 'table');
        if (!preg_match('/^nomad_coord_[a-f0-9]+_(parents|effects|claims)$/D', $table)) {
            throw new RuntimeException('DDL requires an explicitly owned participant table.');
        }
        emit('ATTEMPT');
        $pdo->exec('ALTER TABLE `' . $table . '` ENGINE=MyISAM');
        emit('DONE');
        exit(0);
    }
    $isolation = textField($config, 'isolation');
    if (!in_array($isolation, ['READ COMMITTED', 'REPEATABLE READ'], true)) {
        throw new RuntimeException('Unsupported test isolation.');
    }
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation);
    if (isset($config['lockWaitSeconds'])) {
        $seconds = integerField($config, 'lockWaitSeconds');
        if ($seconds < 1 || $seconds > 5) {
            throw new RuntimeException('The test lock wait must be bounded.');
        }
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . $seconds);
    }
    $parents = new CoordinationTable(textField($config, 'parents'), ['tenantId', 'id']);
    $effects = new CoordinationTable(textField($config, 'effects'), ['id']);
    $claims = new CoordinationTable(textField($config, 'claims'), ['id']);
    $strategy = new PdoCoordinatedDatabaseStrategy(PdoConnection::fromPdo($pdo), $logger);
    emit('READY', ['connectionId' => (int) $id]);
    if ((readMessage()['action'] ?? null) !== 'run') {
        throw new RuntimeException('Expected the run barrier.');
    }

    emit('ATTEMPT');
    $result = $strategy->coordinate(
        $parents,
        ['tenantId' => integerField($config, 'tenantId'), 'id' => integerField($config, 'recordId')],
        [$parents, $effects, $claims],
        function (DatabaseStrategy $backend) use ($config, $effects, $claims, &$callbackCalls): array {
            $callbackCalls++;
            emit('CALLBACK_ENTERED');
            if (($config['holdBeforeIo'] ?? 0) === 1) {
                emit('BEFORE_IO');
                if ((readMessage()['action'] ?? null) !== 'continue') {
                    throw new RuntimeException('Expected the first-I/O barrier.');
                }
            }
            $effectId = integerField($config, 'effectId');
            $rows = $backend->query($backend->parse('SELECT score FROM ?n WHERE id = ?i', $effects->getName(), $effectId));
            if (!is_array($rows)) {
                throw new RuntimeException('A score read did not return rows.');
            }
            $before = 0;
            if ($rows !== []) {
                $value = $rows[0]['score'] ?? null;
                if (!is_numeric($value)) {
                    throw new RuntimeException('The score fixture has invalid data.');
                }
                $before = (int) $value;
            }
            $claimId = integerField($config, 'claimId');
            $claimed = $claimId === 0 ? [] : $backend->query($backend->parse('SELECT id FROM ?n WHERE id = ?i', $claims->getName(), $claimId));
            if (!is_array($claimed)) {
                throw new RuntimeException('A claim read did not return rows.');
            }
            $applied = $claimed === [];
            $after = $before;
            if ($applied) {
                if ($claimId !== 0) {
                    $backend->query($backend->parse('INSERT INTO ?n VALUES (?i)', $claims->getName(), $claimId));
                }
                $after += integerField($config, 'delta');
                $sql = $rows === [] ? 'INSERT INTO ?n (score, id) VALUES (?i, ?i)' : 'UPDATE ?n SET score = ?i WHERE id = ?i';
                $backend->query($backend->parse($sql, $effects->getName(), $after, $effectId));
            }
            $result = ['before' => $before, 'after' => $after, 'applied' => $applied];
            emit('HOLDING', ['result' => $result]);
            if ((readMessage()['action'] ?? null) !== 'release') {
                throw new RuntimeException('Expected the release barrier.');
            }
            if (isset($config['thenEffectId'])) {
                $backend->query($backend->parse('UPDATE ?n SET score = score + ?i WHERE id = ?i',
                    $effects->getName(), integerField($config, 'delta'), integerField($config, 'thenEffectId')));
            }
            return $result;
        }
    );
    emit('DONE', ['result' => $result, 'callbackCalls' => $callbackCalls, 'logs' => $logger->entries]);
} catch (Throwable $failure) {
    emit('ERROR', ['class' => get_class($failure), 'message' => $failure->getMessage(),
        'callbackCalls' => $callbackCalls, 'transactionActive' => $pdo?->inTransaction(), 'logs' => $logger->entries]);
    exit(1);
}
