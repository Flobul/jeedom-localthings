<?php
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsTransport.php';

function relayCheck($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function udpSocket()
{
    $socket = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
    stream_set_blocking($socket, false);
    return $socket;
}

function receivePacket($socket, &$peer = null)
{
    $read = array($socket);
    $write = $except = null;
    if (stream_select($read, $write, $except, 0, 100000) === 0) {
        return false;
    }
    return stream_socket_recvfrom($socket, 65535, 0, $peer);
}

function pumpRelay($relay)
{
    for ($i = 0; $i < 5; $i++) {
        usleep(1000);
        $relay->pump();
    }
}

// Synthetic HelloVerifyRequest with a 32-byte cookie, no customer capture.
$body = "\xfe\xfd\x20" . str_repeat('x', 32);
$handshake = "\x03\x00\x00\x23\x00\x00\x00\x00\x00\x00\x00\x23" . $body;
$hvr = "\x16\xfe\xfd" . str_repeat("\x00", 8) . pack('n', strlen($handshake)) . $handshake;
$record = "\x17\xfe\xfd\x00\x01" . str_repeat("\x00", 6) . "\x00\x03abc";
$logs = array();
$initial = udpSocket();
$alternate = udpSocket();
$unexpected = udpSocket();
$client = udpSocket();
$reservation = udpSocket();
$sourcePort = (int) substr(strrchr(stream_socket_get_name($reservation, false), ':'), 1);
fclose($reservation);
$port = (int) substr(strrchr(stream_socket_get_name($initial, false), ':'), 1);
$constructor = new ReflectionMethod(LocalThingsUdpRelay::class, '__construct');
relayCheck($constructor->getNumberOfParameters() >= 5, 'Relay bind address is not injectable');
$relay = new LocalThingsUdpRelay('127.0.0.1', $port, $sourcePort, function ($level, $message) use (&$logs) {
    $logs[] = $message;
}, '127.0.0.1');
try {
    stream_socket_sendto($client, $record, 0, $relay->endpoint());
    pumpRelay($relay);
    relayCheck(receivePacket($initial, $source) === $record, 'Initial request not forwarded');
    relayCheck($source === '127.0.0.1:' . $sourcePort, 'Local source port changed');
    foreach (array('garbage', substr($hvr, 0, -1), $record) as $invalid) {
        stream_socket_sendto($alternate, $invalid, 0, $source);
        pumpRelay($relay);
        relayCheck(receivePacket($client) === false, 'Invalid alternate-port response accepted');
    }
    stream_socket_sendto($alternate, $hvr, 0, $source);
    pumpRelay($relay);
    relayCheck(receivePacket($client) === $hvr, 'Alternate-port HVR was lost');
    stream_socket_sendto($client, $record, 0, $relay->endpoint());
    pumpRelay($relay);
    relayCheck(receivePacket($alternate, $newSource) === $record, 'Next request did not follow alternate port');
    relayCheck($source === $newSource, 'Source port changed after adoption');
    stream_socket_sendto($unexpected, $hvr, 0, $source);
    pumpRelay($relay);
    relayCheck(receivePacket($client) === false, 'Pinned peer changed again');
    stream_socket_sendto($alternate, $record . $record, 0, $source);
    pumpRelay($relay);
    relayCheck(receivePacket($client) === $record . $record, 'Multiple records were not preserved');
} finally {
    $relay->close();
}
$rebound = stream_socket_server('udp://127.0.0.1:' . $sourcePort, $errno, $error, STREAM_SERVER_BIND);
relayCheck(is_resource($rebound), 'Source port not released');
fclose($rebound);
relayCheck(strpos(implode("\n", $logs), 'Changement de port accepté') !== false, 'Missing diagnostic');
relayCheck(strpos(implode("\n", $logs), str_repeat('x', 32)) === false, 'Cookie leaked to logs');
foreach (array($initial, $alternate, $unexpected, $client) as $socket) {
    fclose($socket);
}
echo "OK: UDP relay, alternate port, source port, malformed packets, peer pinning, cleanup and logs\n";
