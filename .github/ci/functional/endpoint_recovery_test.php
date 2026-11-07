<?php
$root = dirname(__DIR__, 3);
if (!function_exists('__')) {
    function __($text, $file = null) { return $text; }
}
require_once $root . '/core/class/LocalThingsClient.php';

function checkEndpointRecovery($condition, $message)
{
    if (!$condition) { throw new RuntimeException($message); }
}

$ports = LocalThingsDeviceClient::recoveryPorts(array(49155, 37952, 37952, 0, 70000, '5684'), 49155);
checkEndpointRecovery($ports === array(37952, 5684), 'only valid changed advertised ports are retried');
checkEndpointRecovery(LocalThingsDeviceClient::recoveryPorts(array(49155), 49155) === array(), 'stale port is never retried as recovery');

$source = file_get_contents($root . '/core/class/LocalThingsClient.php');
checkEndpointRecovery(strpos($source, 'discoverPorts($logger)') !== false, 'refresh recovery consults public OCF discovery');
checkEndpointRecovery(strpos($source, '[Reconnect] Port DTLS redécouvert') !== false, 'port change is explicitly logged');

echo "LocalThings DTLS endpoint recovery: OK\n";
