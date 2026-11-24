<?php
$root = dirname(__DIR__, 3);
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsDaemonClient.php';
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsProtocol.php';
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsDaemon.php';

function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (($argv[1] ?? '') === '--sleep-request') {
    $client = new LocalThingsDaemonClient($argv[2] ?? '', 5.0);
    $client->request('sleep', array('milliseconds' => 3000), 5000);
    exit(0);
}

if (($argv[1] ?? '') === '--serve') {
    $runtime = $argv[2] ?? '';
    $daemon = new LocalThingsDaemon(
        $runtime,
        function ($operation, $payload) use ($runtime) {
            if ($operation === 'echo') {
                return array('payload' => $payload, 'pid' => getmypid());
            }
            if ($operation === 'sleep') {
                file_put_contents($runtime . '/sleep-started', 'yes');
                usleep(max(0, min(3000, (int) ($payload['milliseconds'] ?? 0))) * 1000);
                return array('slept' => true);
            }
            if ($operation === 'mark') {
                file_put_contents($runtime . '/stale-operation-ran', 'yes');
                return array('marked' => true);
            }
            if ($operation === 'cooperative.sleep') {
                LocalThingsOperationDeadline::sleepMicros(1000000);
                return array('slept' => true);
            }
            throw new InvalidArgumentException('unsupported operation');
        },
        null,
        null,
        null,
        'test-version-1'
    );
    exit($daemon->run());
}

$runtime = sys_get_temp_dir() . '/localthings-daemon-' . bin2hex(random_bytes(6));
mkdir($runtime, 0700, true);
$command = array(PHP_BINARY, __FILE__, '--serve', $runtime);
$process = proc_open($command, array(
    0 => array('pipe', 'r'),
    1 => array('pipe', 'w'),
    2 => array('pipe', 'w'),
), $pipes, null, null, array('bypass_shell' => true));
check(is_resource($process), 'daemon process starts');
foreach ($pipes as $pipe) { stream_set_blocking($pipe, false); }

$ownerIssues = array();
try {
    $client = new LocalThingsDaemonClient($runtime, 2.0);
    $deadline = microtime(true) + 3.0;
    while (microtime(true) < $deadline) {
        try {
            $health = $client->health();
            if (!empty($health['pid'])) { break; }
        } catch (Throwable $error) {
            usleep(50000);
        }
    }
    check(!empty($health['pid']), 'health answers with daemon pid');
    check(($health['version'] ?? '') === 'test-version-1', 'health exposes loaded daemon code version');
    check($client->runtimeStatus(10, 'test-version-1'), 'matching daemon version is current');
    check(!$client->runtimeStatus(10, 'test-version-2'), 'stale daemon version is rejected');
    $heartbeatData = json_decode((string) file_get_contents($runtime . '/heartbeat.json'), true);
    check(($heartbeatData['version'] ?? '') === 'test-version-1', 'heartbeat exposes loaded daemon code version');
    check(is_file($runtime . '/daemon.pid'), 'pid file exists');
    check(is_file($runtime . '/heartbeat.json'), 'heartbeat exists');
    check(file_exists($runtime . '/daemon.sock'), 'unix socket exists');

    $result = $client->request('echo', array('value' => 42), 2000);
    check(($result['payload']['value'] ?? null) === 42, 'NDJSON request round-trip works');
    check(($result['pid'] ?? 0) === ($health['pid'] ?? -1), 'one daemon owns all requests');

    $expired = stream_socket_client('unix://' . $runtime . '/daemon.sock', $errno, $error, 1.0);
    check(is_resource($expired), 'expired request can reach daemon socket');
    $expiredId = str_repeat('a', 32);
    fwrite($expired, json_encode(array(
        'v' => 1,
        'id' => $expiredId,
        'op' => 'mark',
        'deadline_ms' => 100,
        'expires_at_ms' => (int) floor(microtime(true) * 1000) - 1,
        'payload' => array(),
    )) . "\n");
    $expiredResponse = json_decode((string) fgets($expired), true);
    fclose($expired);
    check(($expiredResponse['error']['code'] ?? '') === 'DEADLINE_EXCEEDED', 'expired request is rejected');
    check(!is_file($runtime . '/stale-operation-ran'), 'expired request handler is never executed');

    $cooperativeStarted = microtime(true);
    try {
        $client->request('cooperative.sleep', array(), 100);
        check(false, 'cooperative operation should exceed its deadline');
    } catch (LocalThingsDaemonRemoteException $error) {
        check($error->remoteCode() === 'DEADLINE_EXCEEDED', 'cooperative deadline error is preserved');
    }
    check(microtime(true) - $cooperativeStarted < 0.5, 'cooperative work stops near its deadline');

    $busyProcess = proc_open(
        array(PHP_BINARY, __FILE__, '--sleep-request', $runtime),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $busyPipes,
        null,
        null,
        array('bypass_shell' => true)
    );
    check(is_resource($busyProcess), 'busy request process starts');
    $busyDeadline = microtime(true) + 1.0;
    while (!is_file($runtime . '/sleep-started') && microtime(true) < $busyDeadline) {
        usleep(10000);
    }
    check(is_file($runtime . '/sleep-started'), 'busy request reaches the synchronous handler');
    $busyHealthFailed = false;
    try {
        (new LocalThingsDaemonClient($runtime, 0.2))->health();
    } catch (LocalThingsDaemonUnavailableException $error) {
        $busyHealthFailed = true;
    }
    check($busyHealthFailed, 'synchronous daemon can be temporarily busy');
    check($client->runtimeStatus(10), 'busy daemon remains alive according to lock and heartbeat');
    foreach ($busyPipes as $pipe) { fclose($pipe); }
    check(proc_close($busyProcess) === 0, 'busy request completes');

    $second = proc_open($command, array(
        0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'),
    ), $secondPipes, null, null, array('bypass_shell' => true));
    check(is_resource($second), 'second daemon process starts far enough to test its lock');
    foreach ($secondPipes as $pipe) { fclose($pipe); }
    $secondExit = proc_close($second);
    check($secondExit !== 0, 'second daemon refuses an already-owned runtime');
    check(($client->health()['pid'] ?? 0) === ($health['pid'] ?? -1), 'first daemon remains healthy');

    $client->stop();
    $deadline = microtime(true) + 3.0;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) { break; }
        usleep(50000);
    } while (microtime(true) < $deadline);
    check(!$status['running'], 'stop request terminates daemon');
    check(!file_exists($runtime . '/daemon.sock'), 'socket is removed on stop');
    check(!file_exists($runtime . '/daemon.pid'), 'pid file is removed on stop');
    check(!file_exists($runtime . '/heartbeat.json'), 'heartbeat is removed on stop');

    $ownerCheck = function () use ($root) {
        if (!is_file($root . '/core/class/LocalThingsOwnerPsk.php')) {
            throw new RuntimeException('OwnerPSK class missing');
        }
        $source = file_get_contents($root . '/core/class/LocalThingsOwnerPsk.php');
        $helper = is_file($root . '/resources/owner_psk_transport.py')
            ? file_get_contents($root . '/resources/owner_psk_transport.py') : '';
        $issues = array();
        if (strpos($source, 'public function isConnected()') === false) {
            $issues[] = 'OwnerPSK lacks public isConnected()';
        }
        if (strpos($source, 'public function localPort()') === false) {
            $issues[] = 'OwnerPSK lacks public localPort()';
        }
        if (strpos($helper, 'DtlsPskClient') === false || strpos($helper, 'engine.shutdown()') === false) {
            $issues[] = 'OwnerPSK helper lacks explicit DTLS shutdown';
        }
        if (strpos($helper, "action == 'close'") === false) {
            $issues[] = 'OwnerPSK helper lacks explicit close action';
        }
        if ($issues !== []) {
            throw new RuntimeException(implode(' ; ', $issues));
        }
    };
    try {
        $ownerCheck();
    } catch (Throwable $error) {
        $ownerIssues[] = $error->getMessage();
    }

    if ($ownerIssues !== []) {
        echo '[Daemon] OwnerPSK daemon compatibility pending : ' . implode(' ; ', $ownerIssues) . "\n";
    }

    echo "LocalThings daemon IPC lifecycle: OK\n";
} finally {
    if (is_resource($process)) {
        @proc_terminate($process, 15);
        foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
        try { @proc_close($process); } catch (Throwable $error) {}
    }
    foreach (glob($runtime . '/*') ?: array() as $path) { @unlink($path); }
    @rmdir($runtime);
}
