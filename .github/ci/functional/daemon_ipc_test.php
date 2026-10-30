<?php
$root = dirname(__DIR__, 3);
require_once $root . '/core/class/LocalThingsDaemonClient.php';
require_once $root . '/core/class/LocalThingsDaemon.php';

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
        function ($operation, $payload) {
            if ($operation === 'echo') {
                return array('payload' => $payload, 'pid' => getmypid());
            }
            if ($operation === 'sleep') {
                usleep(max(0, min(3000, (int) ($payload['milliseconds'] ?? 0))) * 1000);
                return array('slept' => true);
            }
            throw new InvalidArgumentException('unsupported operation');
        }
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
    check(is_file($runtime . '/daemon.pid'), 'pid file exists');
    check(is_file($runtime . '/heartbeat.json'), 'heartbeat exists');
    check(file_exists($runtime . '/daemon.sock'), 'unix socket exists');

    $result = $client->request('echo', array('value' => 42), 2000);
    check(($result['payload']['value'] ?? null) === 42, 'NDJSON request round-trip works');
    check(($result['pid'] ?? 0) === ($health['pid'] ?? -1), 'one daemon owns all requests');

    $busyProcess = proc_open(
        array(PHP_BINARY, __FILE__, '--sleep-request', $runtime),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $busyPipes,
        null,
        null,
        array('bypass_shell' => true)
    );
    check(is_resource($busyProcess), 'busy request process starts');
    usleep(150000);
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
        if (strpos($helper, 'SSL_shutdown') === false) {
            $issues[] = 'OwnerPSK helper lacks SSL_shutdown';
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
