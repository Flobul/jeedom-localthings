<?php

if (!function_exists('__')) {
    function __($message, $file = null)
    {
        return $message;
    }
}

class config
{
    public static function byKey($key, $plugin = '', $default = null)
    {
        if ($key === 'poll_interval_online') {
            return '1';
        }
        if ($key === 'poll_interval_offline') {
            return '30';
        }
        return $default;
    }
}

class log
{
    public static function add($plugin, $level, $message)
    {
    }
}

class eqLogic
{
    public static $testEquipment = array();

    public static function byType($type, $enabledOnly = false)
    {
        return self::$testEquipment;
    }
}

class cmd
{
}

final class OfflineBackoffConnectedCommand
{
    private $value = 1;

    public function execCmd()
    {
        return $this->value;
    }

    public function setValue($value)
    {
        $this->value = (int) $value;
    }
}

final class OfflineBackoffEquipment
{
    public $configuration = array(
        'last_refresh' => 0,
        'offline_retry_failures' => 0,
    );

    private $connected;
    private $failRefresh = true;

    public function __construct()
    {
        $this->connected = new OfflineBackoffConnectedCommand();
    }

    public function getCmd($type, $logicalId)
    {
        return $type === 'info' && $logicalId === 'connected' ? $this->connected : null;
    }

    public function getConfiguration($key, $default = null)
    {
        return array_key_exists($key, $this->configuration)
            ? $this->configuration[$key]
            : $default;
    }

    public function setConfiguration($key, $value)
    {
        $this->configuration[$key] = $value;
        return $this;
    }

    public function refreshDirect()
    {
        if ($this->failRefresh) {
            throw new RuntimeException('Session OwnerPSK impossible ou interrompue [ownerpsk_connect_timeout]');
        }
    }

    public function setFailRefresh($fail)
    {
        $this->failRefresh = (bool) $fail;
    }

    public function checkAndUpdateCmd($logicalId, $value)
    {
        if ($logicalId === 'connected') {
            $this->connected->setValue($value);
        }
    }

    public function save($direct = false)
    {
    }

    public function getHumanName()
    {
        return '[Test][Offline device]';
    }

    public function connectedValue()
    {
        return $this->connected->execCmd();
    }
}

$repositoryRoot = dirname(__DIR__, 3);
$sandbox = sys_get_temp_dir() . '/localthings-offline-backoff-' . bin2hex(random_bytes(6));
$pluginClassPath = $sandbox . '/plugins/localthings/core/class';
$corePhpPath = $sandbox . '/core/php';

if (!mkdir($pluginClassPath, 0700, true) || !mkdir($corePhpPath, 0700, true)) {
    throw new RuntimeException('Unable to create the offline-backoff test sandbox');
}

try {
    foreach (glob($repositoryRoot . '/core/class/*.php') ?: array() as $source) {
        if (!copy($source, $pluginClassPath . '/' . basename($source))) {
            throw new RuntimeException('Unable to copy ' . basename($source) . ' into the sandbox');
        }
    }
    file_put_contents($corePhpPath . '/core.inc.php', "<?php\n");
    require_once $pluginClassPath . '/localthings.class.php';

    $equipment = new OfflineBackoffEquipment();
    eqLogic::$testEquipment = array($equipment);

    localthings::pollDirect();

    if ((int) $equipment->configuration['offline_retry_failures'] !== 1) {
        throw new RuntimeException(
            'The first failed poll must arm the 30-second retry, even when the device was online before the attempt'
        );
    }

    if ((int) $equipment->configuration['last_refresh'] <= 0) {
        throw new RuntimeException('The failed attempt must update last_refresh');
    }
    if ($equipment->connectedValue() !== 1) {
        throw new RuntimeException('First transient OwnerPSK timeout must not immediately mark an online device offline');
    }

    $equipment->setFailRefresh(false);
    $equipment->configuration['last_refresh'] = 0;
    localthings::pollDirect();
    if ((int) $equipment->configuration['offline_retry_failures'] !== 0) {
        throw new RuntimeException('A successful retry must reset the transient OwnerPSK failure count');
    }

    $equipment->setFailRefresh(true);
    $equipment->configuration['last_refresh'] = 0;
    localthings::pollDirect();
    if ($equipment->connectedValue() !== 1) {
        throw new RuntimeException('A new first transient timeout after recovery must keep the device online');
    }

    $equipment->configuration['last_refresh'] = 0;
    localthings::pollDirect();
    if ($equipment->connectedValue() !== 0) {
        throw new RuntimeException('Second consecutive transient OwnerPSK timeout must mark the device offline; failures=' . json_encode($equipment->configuration));
    }

    echo "LocalThings offline retry transient OwnerPSK transition: OK\n";
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $path) {
        if ($path->isDir()) {
            @rmdir($path->getPathname());
        } else {
            @unlink($path->getPathname());
        }
    }
    @rmdir($sandbox);
}
