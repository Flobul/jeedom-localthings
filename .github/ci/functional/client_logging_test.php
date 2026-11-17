<?php

if (!function_exists('__')) { function __($message, $file = null) { return $message; } }
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsClient.php';

$reflection = new ReflectionClass('LocalThingsDeviceClient');
$client = $reflection->newInstanceWithoutConstructor();
$jsonForLog = $reflection->getMethod('jsonForLog');
$encoded = $jsonForLog->invoke($client, array('value' => str_repeat('é', 1000)));
if (strlen($encoded) > 520) {
    throw new RuntimeException('Command verification payloads must be bounded in logs');
}
if (!mb_check_encoding($encoded, 'UTF-8')) {
    throw new RuntimeException('Truncated command verification payload must remain valid UTF-8');
}

$source = file_get_contents(dirname(__DIR__, 3) . '/core/class/LocalThingsClient.php');
$format = '[Command] Vérification /%1$s applied=%2$s expected=%3$s actual=%4$s';
if (!is_string($source) || strpos($source, $format) === false) {
    throw new RuntimeException('Verification outcome must precede potentially large values in logs');
}

print "LocalThings bounded command verification log: OK\n";
