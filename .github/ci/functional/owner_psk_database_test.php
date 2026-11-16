<?php

if (!function_exists('__')) {
    function __($message, $file = null) { return $message; }
}

$root = dirname(__DIR__, 3);
require_once $root . '/core/class/LocalThingsProtocol.php';
require_once $root . '/core/class/LocalThingsOwnerPskImporter.php';

function assertOwnerPskDatabase($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cborByteString($value)
{
    $length = strlen($value);
    if ($length < 24) {
        return chr(0x40 | $length) . $value;
    }
    if ($length <= 0xFF) {
        return "\x58" . chr($length) . $value;
    }
    if ($length <= 0xFFFF) {
        return "\x59" . pack('n', $length) . $value;
    }
    throw new RuntimeException('Synthetic CBOR value too large');
}

$ownerUuid = '12345678-1234-5678-9abc-def012345678';
$deviceOne = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
$deviceTwo = '00112233-4455-6677-8899-aabbccddeeff';
$keyOne = hex2bin('00112233445566778899aabbccddeeff');
$keyTwo = hex2bin('ffeeddccbbaa99887766554433221100');

$credentialResource = array(
    'rowneruuid' => str_replace('-', '', $ownerUuid),
    'creds' => array(
        array(
            'credtype' => 1,
            'subjectuuid' => str_replace('-', '', $deviceOne),
            'privatedata' => array(
                'encoding' => 'oic.sec.encoding.base64',
                'data' => base64_encode($keyOne),
            ),
        ),
        array(
            'credtype' => 8,
            'subjectuuid' => str_replace('-', '', $deviceOne),
            'optionaldata' => array('data' => 'certificate'),
        ),
        array(
            'credtype' => 1,
            'subjectuuid' => hex2bin(str_replace('-', '', $deviceTwo)),
            'privatedata' => array('data' => $keyTwo),
        ),
    ),
);
$nested = LocalThingsCbor::encode($credentialResource);
$database = "\xA1\x64cred" . cborByteString($nested);

$result = LocalThingsOwnerPskImporter::decodeDatabase($database);
assertOwnerPskDatabase($result['total_credentials'] === 3, 'All credential records must be counted');
assertOwnerPskDatabase($result['psk_credentials'] === 2, 'Only type-1 PSK records must be exposed');
assertOwnerPskDatabase(count($result['credentials']) === 2, 'Two importable credentials expected');
assertOwnerPskDatabase($result['credentials'][0]['owner_uuid'] === $ownerUuid, 'Owner UUID normalization failed');
assertOwnerPskDatabase($result['credentials'][0]['device_uuid'] === $deviceOne, 'Text device UUID normalization failed');
assertOwnerPskDatabase($result['credentials'][0]['key'] === bin2hex($keyOne), 'Base64 PSK decoding failed');
assertOwnerPskDatabase($result['credentials'][1]['device_uuid'] === $deviceTwo, 'Binary device UUID normalization failed');
assertOwnerPskDatabase($result['credentials'][1]['key'] === bin2hex($keyTwo), 'Binary PSK decoding failed');

$deepValue = 'leaf';
for ($depth = 0; $depth < 40; $depth++) {
    $deepValue = array($deepValue);
}
$deepResource = $credentialResource;
$deepResource['creds'][0]['untrusted'] = $deepValue;
$deepDatabase = "\xA1\x64cred" . cborByteString(LocalThingsCbor::encode($deepResource));
try {
    LocalThingsOwnerPskImporter::decodeDatabase($deepDatabase);
    throw new RuntimeException('Excessively deep CBOR database accepted');
} catch (InvalidArgumentException $expected) {
}

$withoutPsk = LocalThingsCbor::encode(array(
    'cred' => array(
        'rowneruuid' => str_replace('-', '', $ownerUuid),
        'creds' => array(array('credtype' => 8, 'subjectuuid' => str_replace('-', '', $deviceOne))),
    ),
));
$empty = LocalThingsOwnerPskImporter::decodeDatabase($withoutPsk);
assertOwnerPskDatabase($empty['total_credentials'] === 1, 'Certificate records must remain countable');
assertOwnerPskDatabase($empty['psk_credentials'] === 0 && $empty['credentials'] === array(), 'Certificate records must not be offered as PSKs');

$nullOwnerResource = array(
    'rowneruuid' => "\0" . str_repeat("A", 15),
    'creds' => array(array(
        'credtype' => 1,
        'subjectuuid' => str_replace('-', '', $deviceOne),
        'privatedata' => array('data' => base64_encode($keyOne)),
    )),
);
$nullOwnerDatabase = "\xA1\x64cred" . cborByteString(LocalThingsCbor::encode($nullOwnerResource));
$nullOwner = LocalThingsOwnerPskImporter::decodeDatabase($nullOwnerDatabase);
assertOwnerPskDatabase($nullOwner['psk_credentials'] === 0, 'OpenSSL-incompatible owner UUID must not be offered');
assertOwnerPskDatabase($nullOwner['invalid_psk_credentials'] === 1, 'Incompatible owner UUID must be counted as invalid');
assertOwnerPskDatabase($nullOwner['psk_records'] === 1, 'Detected PSK records must be counted before compatibility filtering');
assertOwnerPskDatabase(
    ($nullOwner['invalid_reasons']['owner_uuid_transport_incompatible'] ?? 0) === 1,
    'OpenSSL-incompatible identity must have an explicit safe diagnostic'
);

$largeValue = array_fill(0, 100001, 1);
$largeCbor = LocalThingsCbor::encode($largeValue);
$largeConsumed = 0;
$largeDecoded = LocalThingsCbor::decode($largeCbor, $largeConsumed);
assertOwnerPskDatabase(count($largeDecoded) === 100001, 'General CBOR decoder contract must remain unbounded');
assertOwnerPskDatabase($largeConsumed === strlen($largeCbor), 'General decoder must consume the large CBOR value');

foreach (array('', "\xA1\x64cred\x41\xFF", str_repeat('x', LocalThingsOwnerPskImporter::MAX_DATABASE_BYTES + 1)) as $invalid) {
    try {
        LocalThingsOwnerPskImporter::decodeDatabase($invalid);
        throw new RuntimeException('Invalid database accepted');
    } catch (InvalidArgumentException $expected) {
    }
}

echo "LocalThings iPhone OwnerPSK CBOR import: OK\n";
