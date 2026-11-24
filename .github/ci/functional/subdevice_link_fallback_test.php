<?php

function __($text, $file = null) { return $text; }
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsProtocol.php';
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsMapper.php';
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsClient.php';

final class LinkDirectorySession extends LocalThingsSession
{
    private $responses;
    public $requests = array();

    public function __construct($responses)
    {
        $this->responses = $responses;
    }

    public function get($pathSegments, $timeout = 25.0, $query = array())
    {
        $path = is_array($pathSegments)
            ? '/' . implode('/', $pathSegments)
            : '/' . ltrim((string) $pathSegments, '/');
        $this->requests[] = $path;
        if (!isset($this->responses[$path])) {
            throw new RuntimeException('Unexpected GET ' . $path);
        }
        return array(69, LocalThingsCbor::encode($this->responses[$path]));
    }
}

$uuid = '01234567-89ab-cdef-0123-456789abcdef';
$directory = array(
    array('di' => $uuid),
    array('href' => '/' . $uuid . '/remotectrl/vs/0', 'rt' => array('x.com.samsung.da.remotectrl')),
    array('href' => '/' . $uuid . '/mode/vs/0', 'rt' => array('x.com.samsung.da.mode')),
    array('href' => '/' . $uuid . '/temperatures/indoor/vs/0', 'rt' => array('x.com.samsung.da.temperature')),
);
$session = new LinkDirectorySession(array(
    '/' . $uuid . '/device/0' => $directory,
    '/' . $uuid . '/remotectrl/vs/0' => array('x.com.samsung.da.remoteControlEnabled' => true),
    '/' . $uuid . '/mode/vs/0' => array(
        'x.com.samsung.da.modes' => 'heat',
        'x.com.samsung.da.supportedModes' => array('auto', 'cool', 'heat'),
    ),
    '/' . $uuid . '/temperatures/indoor/vs/0' => array(
        'x.com.samsung.da.current' => 31,
        'x.com.samsung.da.desired' => 35,
    ),
));

$client = (new ReflectionClass('LocalThingsDeviceClient'))->newInstanceWithoutConstructor();
$method = new ReflectionMethod('LocalThingsDeviceClient', 'readResources');
@$method->setAccessible(true);
$resources = $method->invoke($client, $session, array($uuid, 'device', '0'));

foreach (array('/remotectrl/vs/0', '/mode/vs/0', '/temperatures/indoor/vs/0') as $href) {
    if (!isset($resources[$href])) {
        throw new RuntimeException('Missing canonical subdevice resource ' . $href);
    }
    if (isset($resources['/' . $uuid . $href])) {
        throw new RuntimeException('Subdevice UUID leaked into canonical mapper href');
    }
    if (!in_array('/' . $uuid . $href, $session->requests, true)) {
        throw new RuntimeException('Individual subdevice resource was not read on the wire: ' . $href);
    }
}
if (!isset($resources['/mode/vs/0']['rt'])) {
    throw new RuntimeException('Directory resource type was not preserved after the individual GET');
}

print "LocalThings UUID subdevice link fallback: OK\n";
