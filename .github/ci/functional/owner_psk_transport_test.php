<?php

declare(strict_types=1);

class LocalThingsDtlsClient
{
}

require_once dirname(__DIR__, 3) . '/core/class/LocalThingsOwnerPsk.php';

$credential = array(
    'owner_uuid' => '3771f8bf-0000-3a2d-d885-e4c9818736d2',
    'device_uuid' => '01234567-89ab-cdef-0123-456789abcdef',
    'key' => str_repeat('ab', 16),
);

$first = new LocalThingsOwnerPskTransport('192.0.2.10', 5684, $credential);
$second = new LocalThingsOwnerPskTransport('192.0.2.10', 5684, $credential);
$other = new LocalThingsOwnerPskTransport('192.0.2.11', 5684, $credential);

$port = $first->localPort();
if (!is_int($port) || $port < 40000 || $port > 59999) {
    throw new RuntimeException('OwnerPSK transport must expose a valid deterministic source port');
}
if ($second->localPort() !== $port) {
    throw new RuntimeException('OwnerPSK source port must remain stable for the same host');
}
if ($other->localPort() === $port) {
    throw new RuntimeException('OwnerPSK source port should distinguish adjacent hosts');
}

$clientSource = file_get_contents(dirname(__DIR__, 3) . '/core/class/LocalThingsClient.php');
if (!is_string($clientSource) || strpos($clientSource, '[OwnerPSK] Échec hôte=') === false) {
    throw new RuntimeException('OwnerPSK verification failures must leave a non-sensitive diagnostic log');
}

print "LocalThings OwnerPSK transport source port: OK\n";
