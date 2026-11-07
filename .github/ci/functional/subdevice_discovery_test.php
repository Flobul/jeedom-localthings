<?php
$root = dirname(__DIR__, 3);
if (!function_exists('__')) { function __($text, $file = null) { return $text; } }
require_once $root . '/core/class/LocalThingsClient.php';

function checkSubdevice($condition, $message)
{
    if (!$condition) { throw new RuntimeException($message); }
}

$first = '0a1b2c3d-0000-4000-8000-000001200000';
$second = '12345678-1234-4234-8123-123456789abc';
$resources = array('/subdevices/vs/0' => array(
    'x.com.samsung.da.subdeviceIdList' => array($first, strtoupper($first), 'invalid'),
));
$oicRes = array(array('links' => array(
    array('href' => '/' . $second . '/device/0'),
    array('href' => '/' . strtoupper($first) . '/mode/vs/0'),
    array('href' => '/device/0'),
)));

checkSubdevice(
    LocalThingsDeviceClient::subdeviceIds($resources, $oicRes) === array($first, $second),
    'UUID subdevices from the list and /oic/res are merged and deduplicated'
);

$source = file_get_contents($root . '/core/class/LocalThingsClient.php');
checkSubdevice(strpos($source, "\$snapshot['subdevices'] = \$subdevices") !== false, 'root snapshots expose materialized subdevices');
checkSubdevice(strpos($source, "'subdevice_uuid'") !== false, 'subdevice identity is persisted in snapshots');
$registration = file_get_contents($root . '/core/class/localthings.class.php');
checkSubdevice(strpos($registration, "\$snapshot['subdevices']") !== false, 'Jeedom registration processes subdevice snapshots');

echo "LocalThings UUID subdevice discovery: OK\n";
