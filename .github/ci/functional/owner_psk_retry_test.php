<?php

function __($text, $file = null) { return $text; }
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsProtocol.php';
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsMapper.php';
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsClient.php';

$reflection = new ReflectionClass('LocalThingsDeviceClient');
$kind = $reflection->getMethod('connectionFailureKind');
@$kind->setAccessible(true);
$retryable = $reflection->getMethod('isRetryableOwnerPskImportFailure');
@$retryable->setAccessible(true);

foreach (array('ownerpsk_connect_transient', 'ownerpsk_connect_failed') as $code) {
    $failureKind = $kind->invoke(null, new RuntimeException('redacted [' . $code . ']'));
    if (!$retryable->invoke(null, $failureKind)) {
        throw new RuntimeException('Transient OwnerPSK cold-handshake failure must get one fresh retry');
    }
}
$timeoutKind = $kind->invoke(null, new RuntimeException('redacted [ownerpsk_connect_timeout]'));
if (!$retryable->invoke(null, $timeoutKind)) {
    throw new RuntimeException('OwnerPSK timeout must remain retryable');
}
$authKind = $kind->invoke(null, new RuntimeException('redacted [ownerpsk_connect_auth_failed]'));
if ($retryable->invoke(null, $authKind)) {
    throw new RuntimeException('OwnerPSK authentication failure must not be retried');
}

echo "LocalThings OwnerPSK retry classification: OK\n";
