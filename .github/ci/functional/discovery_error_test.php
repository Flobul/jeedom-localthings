<?php
function __($text, $file = null) { return $text; }
class cache {
    public static $values = array();
    private $key;
    public static function set($key, $value, $ttl = 0) { self::$values[$key] = $value; }
    public static function delete($key) { unset(self::$values[$key]); }
    public static function byKey($key) { $c = new self(); $c->key = $key; return $c; }
    public function getValue($default = '') { return self::$values[$this->key] ?? $default; }
}
$testRoot = getenv('LOCALTHINGS_TEST_ROOT');
if (!is_string($testRoot) || $testRoot === '') {
    throw new RuntimeException('LOCALTHINGS_TEST_ROOT must point to an isolated copy');
}
require_once $testRoot . '/core/class/LocalThingsClient.php';
const STATUS = 'localthings::discovery::status';
function check($condition, $message) { if (!$condition) throw new Exception($message); }
function prepare($hosts = array('192.0.2.1')) {
    $id = bin2hex(random_bytes(16));
    cache::set(STATUS, array('running' => true, 'job_id' => $id, 'worker_pid' => 0,
        'started' => time(), 'heartbeat' => time(), 'found' => array(), 'errors' => array()));
    cache::set('localthings::discovery::job::' . $id, array('hosts' => $hosts));
    return $id;
}
$id = prepare();
$logs = array();
LocalThingsDiscovery::run($id, function ($host, $exhaustive) {
    $current = LocalThingsDiscovery::readStatus();
    check($current['current_host'] === $host && $exhaustive, 'Direct-address progress');
    throw new TypeError('Injected discovery failure');
}, function ($level, $message) use (&$logs) { $logs[] = $message; });
$result = LocalThingsDiscovery::readStatus();
check(!$result['running'] && strpos(implode(' ', $result['errors']), 'Injected') !== false, 'Fatal error finalized');
check(!isset(cache::$values['localthings::discovery::job::' . $id]), 'Consumed job deleted');
check(strpos(implode(' ', $logs), 'Tâche interrompue') !== false, 'Error logged');

$id = prepare(array('192.0.2.1', '192.0.2.2'));
$tested = 0;
LocalThingsDiscovery::run($id, function ($host) use ($id, &$tested) {
    $tested++;
    if ($tested === 1) {
        check(empty(LocalThingsDiscovery::stop(str_repeat('0', 32))['stop_requested']), 'Old tab cannot stop new job');
        try {
            LocalThingsDiscovery::start('unused.php', array(), array($host));
            throw new LogicException('Concurrent start accepted');
        } catch (RuntimeException $error) {
            check(strpos($error->getMessage(), 'déjà en cours') !== false, 'Concurrent start refused');
        }
        try {
            LocalThingsDiscovery::run($id, function () {});
            throw new LogicException('Duplicate worker accepted');
        } catch (RuntimeException $error) {}
        return array('device' => array('host' => $host));
    }
    LocalThingsDiscovery::stop($id);
    LocalThingsDiscovery::checkpoint(true);
    throw new LogicException('Cancellation not raised');
});
$result = LocalThingsDiscovery::readStatus();
check(!$result['running'] && $result['cancelled'] && count($result['found']) === 1, 'Cancellation retains discoveries');
check($result['errors'] === array() && $tested === 2, 'Cancellation is not a discovery error');

$id = prepare();
LocalThingsDiscovery::stop($id);
LocalThingsDiscovery::run($id, function () { throw new LogicException('Cancelled before start'); });
check(LocalThingsDiscovery::readStatus()['cancelled'], 'Cancellation before worker starts');

$id = prepare();
cache::$values[STATUS]['heartbeat'] = time() - 181;
$result = LocalThingsDiscovery::readStatus();
check(!$result['running'] && count($result['errors']) === 1, 'Stale worker recovered');

$id = prepare();
$newId = null;
LocalThingsDiscovery::run($id, function () use (&$newId) {
    $newId = prepare(); // Expired/replaced ownership while an old worker unwinds.
    LocalThingsDiscovery::checkpoint(true);
});
$result = LocalThingsDiscovery::readStatus();
check($result['running'] && $result['job_id'] === $newId, 'Old worker cannot overwrite next task');
echo "OK: cached discovery, errors, progress, cancellation, duplicate starts, stale and replaced workers\n";
