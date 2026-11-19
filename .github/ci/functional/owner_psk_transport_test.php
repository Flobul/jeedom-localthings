<?php

declare(strict_types=1);

if (!function_exists('__')) {
    function __($message, $file = null) { return $message; }
}

class LocalThingsDtlsClient
{
}

require_once dirname(__DIR__, 3) . '/core/class/LocalThingsOwnerPsk.php';

$credential = array(
    'owner_uuid' => '3771f8bf-0000-3a2d-d885-e4c9818736d2',
    'device_uuid' => '01234567-89ab-cdef-0123-456789abcdef',
    'key' => str_repeat('ab', 16),
);

// Fresh (ephemeral) source port per association: a fixed source port let a Samsung
// appliance keep a stale DTLS association and silently ignore the next ClientHello,
// surfacing as an import timeout (upstream #66/#77). The helper binds port 0 and
// reports the OS-assigned port, which connect() adopts for accurate diagnostics.
$first = new LocalThingsOwnerPskTransport('192.0.2.10', 5684, $credential);
$second = new LocalThingsOwnerPskTransport('192.0.2.10', 5684, $credential);
if ($first->localPort() !== 0 || $second->localPort() !== 0) {
    throw new RuntimeException('OwnerPSK transport must request an ephemeral source port (bind 0)');
}

$reflection = new ReflectionClass($first);
$adopt = $reflection->getMethod('adoptReportedSourcePort');
$adopt->invoke($first, array('source_port' => 41234));
if ($first->localPort() !== 41234) {
    throw new RuntimeException('OwnerPSK transport must adopt the source port reported by the helper');
}
$adopt->invoke($first, null);
if ($first->localPort() !== 41234) {
    throw new RuntimeException('A non-array RPC result must not reset the adopted source port');
}

// The carrier probe guards invalid targets without spawning the helper or touching the network.
$invalid = LocalThingsOwnerPskTransport::probeCarrier('not-an-ip', 5684);
if (($invalid['carrier'] ?? null) !== 'unknown' || ($invalid['outcome'] ?? null) !== 'invalid_target') {
    throw new RuntimeException('OwnerPSK carrier probe must reject an invalid target safely');
}

$delayed = proc_open(
    array(PHP_BINARY, '-r', 'usleep(120000); echo json_encode(array("ok" => true, "result" => array("source_port" => 41234))) . "\\n";'),
    array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
    $pipes,
    null,
    null,
    array('bypass_shell' => true)
);
if (!is_resource($delayed)) {
    throw new RuntimeException('Unable to start delayed OwnerPSK RPC fixture');
}
stream_set_blocking($pipes[1], false);
$reflection->getProperty('process')->setValue($first, $delayed);
$reflection->getProperty('pipes')->setValue($first, $pipes);
$rpc = $reflection->getMethod('rpc');
try {
    $result = $rpc->invoke($first, array('action' => 'connect'), 1.0);
    if (!is_array($result) || ($result['source_port'] ?? null) !== 41234) {
        throw new RuntimeException('Delayed OwnerPSK RPC response was not returned');
    }
} finally {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) { fclose($pipe); }
    }
    proc_close($delayed);
}

$clientSource = file_get_contents(dirname(__DIR__, 3) . '/core/class/LocalThingsClient.php');
if (!is_string($clientSource) || strpos($clientSource, '[OwnerPSK] Échec hôte=') === false) {
    throw new RuntimeException('OwnerPSK verification failures must leave a non-sensitive diagnostic log');
}

print "LocalThings OwnerPSK transport fresh source port: OK\n";
