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

    public function put($pathSegments, $representation, $timeout = 15.0)
    {
        $this->requests[] = '/' . implode('/', (array) $pathSegments);
        if (!$this->codes) { throw new RuntimeException('Unexpected PUT'); }
        return array(array_shift($this->codes), '');
    }

    public function post($pathSegments, $representation, $timeout = 15.0)
    {
        throw new RuntimeException('Samsung operational resources must be written with CoAP PUT');
    }
}

$client = (new ReflectionClass('LocalThingsDeviceClient'))->newInstanceWithoutConstructor();
$method = new ReflectionMethod('LocalThingsDeviceClient', 'putCommand');
@$method->setAccessible(true);
$uuid = '01234567-89ab-cdef-0123-456789abcdef';
$canonicalPath = array('mode', 'dhw', 'vs', '0');

$accepted = new SubdeviceWriteSession(array(68)); // 2.04 Changed
$result = $method->invoke($client, $accepted, $canonicalPath, $uuid, array('x.com.samsung.da.modes' => array('Eco')));
if ($accepted->requests !== array('/' . $uuid . '/mode/dhw/vs/0')) {
    throw new RuntimeException('A prefixed subdevice write must target its real on-wire href');
}
if (($result['code'] ?? null) !== 68
    || ($result['wire_path'] ?? null) !== array($uuid, 'mode', 'dhw', 'vs', '0')) {
    throw new RuntimeException('The actual prefixed PUT path must be returned for verification');
}

$notFound = new SubdeviceWriteSession(array(132)); // 4.04 Not Found
$result = $method->invoke($client, $notFound, $canonicalPath, $uuid, array('x.com.samsung.da.modes' => array('Eco')));
if (count($notFound->requests) !== 1 || ($result['code'] ?? null) !== 132) {
    throw new RuntimeException('An explicit PUT refusal must not be replayed against the parent namespace');
}

echo "LocalThings UUID subdevice CoAP PUT: OK\n";
