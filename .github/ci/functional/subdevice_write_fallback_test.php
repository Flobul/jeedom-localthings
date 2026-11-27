<?php

function __($text, $file = null) { return $text; }
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsProtocol.php';
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsMapper.php';
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsClient.php';

final class SubdeviceWriteSession extends LocalThingsSession
{
    public $requests = array();
    private $codes;

    public function __construct($codes)
    {
        $this->codes = array_values($codes);
    }

    public function post($pathSegments, $representation, $timeout = 15.0)
    {
        $this->requests[] = '/' . implode('/', (array) $pathSegments);
        if (!$this->codes) { throw new RuntimeException('Unexpected POST'); }
        return array(array_shift($this->codes), '');
    }
}

$client = (new ReflectionClass('LocalThingsDeviceClient'))->newInstanceWithoutConstructor();
$method = new ReflectionMethod('LocalThingsDeviceClient', 'postCommandWithSubdeviceFallback');
@$method->setAccessible(true);
$uuid = '01234567-89ab-cdef-0123-456789abcdef';
$canonicalPath = array('mode', 'dhw', 'vs', '0');

$notFound = new SubdeviceWriteSession(array(132, 68)); // 4.04 then 2.04
$result = $method->invoke($client, $notFound, $canonicalPath, $uuid, array('x.com.samsung.da.modes' => 'eco'));
if ($notFound->requests !== array('/' . $uuid . '/mode/dhw/vs/0', '/mode/dhw/vs/0')) {
    throw new RuntimeException('A subdevice 4.04 must retry exactly once on the canonical href');
}
if (($result['code'] ?? null) !== 68 || ($result['wire_path'] ?? null) !== $canonicalPath) {
    throw new RuntimeException('The successful canonical fallback path must be returned for verification');
}

$forbidden = new SubdeviceWriteSession(array(131)); // 4.03
$result = $method->invoke($client, $forbidden, $canonicalPath, $uuid, array('x.com.samsung.da.modes' => 'eco'));
if (count($forbidden->requests) !== 1 || ($result['code'] ?? null) !== 131) {
    throw new RuntimeException('Only an explicit 4.04 may trigger the canonical write fallback');
}

echo "LocalThings UUID subdevice write fallback: OK\n";
