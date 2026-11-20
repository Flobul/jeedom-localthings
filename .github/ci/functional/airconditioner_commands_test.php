<?php

if (!function_exists('__')) {
    function __($message, $file = null) { return $message; }
}
class eqLogic {}
class cmd {
    private $testSubtype = '';
    private $testId = 0;
    private $testValueCommand;
    public function configureTestCommand($subtype, $id, $valueCommand) {
        $this->testSubtype = $subtype;
        $this->testId = $id;
        $this->testValueCommand = $valueCommand;
    }
    public function getSubType() { return $this->testSubtype; }
    public function getId() { return $this->testId; }
    public function getCmdValue() { return $this->testValueCommand; }
}
class event {
    public static $events = array();
    public static function add($name, $payload) { self::$events[] = array($name, $payload); }
}

$repositoryRoot = dirname(__DIR__, 3);
$sandbox = sys_get_temp_dir() . '/localthings-ac-commands-' . bin2hex(random_bytes(6));
$pluginClassPath = $sandbox . '/plugins/localthings/core/class';
$corePhpPath = $sandbox . '/core/php';

function assertAcCommand($condition, $message)
{
    if (!$condition) { throw new RuntimeException($message); }
}

if (!mkdir($pluginClassPath, 0700, true) || !mkdir($corePhpPath, 0700, true)) {
    throw new RuntimeException('Unable to create AC command test sandbox');
}

try {
    foreach (glob($repositoryRoot . '/core/class/*.php') ?: array() as $source) {
        if (!copy($source, $pluginClassPath . '/' . basename($source))) {
            throw new RuntimeException('Unable to copy ' . basename($source));
        }
    }
    file_put_contents($corePhpPath . '/core.inc.php', "<?php\n");
    require_once $pluginClassPath . '/localthings.class.php';

    $merge = new ReflectionMethod('localthings', 'mergeListValueOrder');
    $merged = $merge->invoke(null,
        'Cool|Frais;Quiet|Silencieux;CustomMode|Mon mode',
        'Cool|Frais;Dry|Déshumidification;Quiet|Quiet',
        ''
    );
    assertAcCommand(
        $merged === 'Cool|Frais;Quiet|Silencieux;CustomMode|Mon mode;Dry|Déshumidification',
        'First synchronization must preserve custom values, labels and ordering'
    );
    $merged = $merge->invoke(null,
        'Cool|Frais;Quiet|Silencieux;CustomMode|Mon mode',
        'Cool|Frais;Dry|Déshumidification',
        'Cool|Frais;Quiet|Quiet'
    );
    assertAcCommand(
        $merged === 'Cool|Frais;CustomMode|Mon mode;Dry|Déshumidification',
        'Removed generated values must be pruned while custom values remain'
    );

    $allowedValues = new ReflectionMethod('localthings', 'commandAllowedValues');
    $recipe = array('encoding' => 'option_token', 'allowed' => array('Off', 'Quiet'));
    assertAcCommand(
        $allowedValues->invoke(null, $recipe, 'Off|Désactivé;Quiet|Silencieux;Custom|Mon mode') === array('Off', 'Quiet', 'Custom'),
        'Custom values kept in the action list must remain executable'
    );
    assertAcCommand(
        $allowedValues->invoke(null, $recipe, '') === array('Off', 'Quiet'),
        'The generated allow-list must still apply without custom values'
    );
    assertAcCommand(
        $allowedValues->invoke(null, array('encoding' => 'option_token', 'allowed' => array('180', '300')), 'x|y|z') === array('180', '300'),
        'Malformed custom list entries must be ignored'
    );

    $preserveValue = new ReflectionMethod('localthings', 'preserveGeneratedValue');
    assertAcCommand(
        $preserveValue->invoke(null, 'Mon libellé', 'Nouveau libellé', 'Ancien libellé') === 'Mon libellé',
        'User-customized command metadata must survive synchronization'
    );
    assertAcCommand(
        $preserveValue->invoke(null, 'Ancien libellé', 'Nouveau libellé', 'Ancien libellé') === 'Nouveau libellé',
        'Untouched generated command metadata must follow mapper updates'
    );
    $shouldRemove = new ReflectionMethod('localthings', 'shouldRemoveManagedAction');
    assertAcCommand(
        $shouldRemove->invoke(null, 'washer_cycle_x', 'action_x',
            array('washer_cycle_x' => true), array(), array()) === false,
        'A partial poll without announced actions must preserve existing action IDs'
    );
    assertAcCommand(
        $shouldRemove->invoke(null, 'washer_cycle_x', 'action_x',
            array('washer_cycle_x' => true), array('washer_cycle_x' => true), array()) === true,
        'A complete action-bearing entity may remove an action no longer announced'
    );
    $cycleGuard = new ReflectionMethod('localthingsCmd', 'isCycleSettingBlocked');
    assertAcCommand($cycleGuard->invoke(null, 'washer', 'same', 'active') === true,
        'Select-like settings must be blocked while a cycle is active');
    assertAcCommand($cycleGuard->invoke(null, 'dryer', 'delay_hours', 'pause') === true,
        'Settings must be blocked while a cycle is paused');
    assertAcCommand($cycleGuard->invoke(null, 'washer', 'fixed', 'active') === false,
        'Start, pause and stop fixed actions must remain available');
    assertAcCommand($cycleGuard->invoke(null, 'washer', 'bool', 'pause') === false,
        'Boolean safety controls must remain available');
    assertAcCommand($cycleGuard->invoke(null, 'washer', 'same', 'ready') === false,
        'Settings must remain available outside a cycle');
    assertAcCommand($cycleGuard->invoke(null, 'dishwasher', 'same', 'active') === false,
        'Cycle guards must not alter other appliance profiles');
    $machineStateCommand = new class {
        public function getConfiguration($key, $default = '') {
            return $key === 'entityKey' ? 'operational_state_0_currentMachineState_abcd' : $default;
        }
        public function execCmd() { return 'Active'; }
    };
    $machine = new class($machineStateCommand) {
        private $command;
        public function __construct($command) { $this->command = $command; }
        public function getCmd($type) { return $type === 'info' ? array($this->command) : array(); }
    };
    $currentState = new ReflectionMethod('localthingsCmd', 'currentMachineState');
    assertAcCommand($currentState->invoke(null, $machine) === 'active',
        'Cycle guard must read the stable currentMachineState entity rather than a visible name');

    $preserveUnit = new ReflectionMethod('localthings', 'preserveGeneratedUnit');
    assertAcCommand(
        $preserveUnit->invoke(null, '°C', '', '', 'string', 'washer_temperature') === '',
        'Legacy generated temperature units must be cleared when the info becomes text'
    );
    assertAcCommand(
        $preserveUnit->invoke(null, 'mon unité', '', '', 'string', 'washer_temperature') === 'mon unité',
        'Custom units must remain untouched during the text migration'
    );

    $linkedInfo = new class {
        public function execCmd() { return 'None'; }
        public function getUnite() { return ''; }
    };
    $actionCommand = new localthingsCmd();
    $actionCommand->configureTestCommand('select', 321, $linkedInfo);
    $restore = new ReflectionMethod('localthingsCmd', 'restoreCurrentActionValue');
    $restore->invoke($actionCommand);
    assertAcCommand(
        event::$events === array(array('cmd::update', array(
            'cmd_id' => 321,
            'value' => 'None',
            'display_value' => 'None',
            'unit' => '',
        ))),
        'A rejected select must push its linked info value back to the action widget'
    );

    $resources = array(
        '/mode/convenient/vs/0' => array(
            'x.com.samsung.da.modes' => 'Quiet',
            'x.com.samsung.da.supportedModes' => array('Off', 'Nano', 'NanoSleep'),
        ),
        '/mode/vs/0' => array(
            'x.com.samsung.da.modes' => array('Cool'),
            'x.com.samsung.da.supportedModes' => array('Cool', 'Dry', 'Wind'),
            'x.com.samsung.da.options' => array(
                'Sleep_6',
                'Volume_Mute',
                'FilterTime_4510',
                'FilterAlarmTime_500',
            ),
        ),
        '/modeoption/windfree/vs/0' => array('x.com.samsung.da.windfree' => 'Off'),
        '/modeoption/windsleep/vs/0' => array('x.com.samsung.da.windsleep' => 'On'),
    );
    $mapped = (new LocalThingsMapper())->map($resources);
    $entities = array();
    foreach ($mapped['entities'] as $entity) { $entities[$entity['name']] = $entity; }

    $selectCommand = $entities['Mode de confort']['actions'][0] ?? array();
    $remoteWrites = 0;
    $lastRecipe = null;
    $deviceClient = new class($remoteWrites, $lastRecipe) extends LocalThingsDeviceClient {
        public $writes;
        public $recipe;
        public function __construct(&$writes, &$recipe) {
            $this->writes =& $writes;
            $this->recipe =& $recipe;
        }
        public function execute($host, $port, $recipe, $value, $bypassRemoteControl = false) {
            $this->writes++;
            $this->recipe = $recipe;
            return array('resources' => array(), 'matched' => false);
        }
    };
    $connected = new class { public function execCmd() { return 1; } };
    $selectEquipment = new class($connected) {
        private $connected;
        private $client;
        public function __construct($connected) { $this->connected = $connected; }
        public function setClient($client) { $this->client = $client; }
        public function deviceClient() { return $this->client; }
        public function getConfiguration($key, $default = null) {
            if ($key === 'auth_mode') { return 'certificate'; }
            if ($key === 'device_type') { return 'airconditioner'; }
            return $default;
        }
        public function getCmd($type, $logicalId = null) {
            return $type === 'info' && $logicalId === 'connected' ? $this->connected : null;
        }
        public function recordCommunicationFailure($message) {}
        public function applyCommandResult($result) {}
    };
    $selectAction = new class($selectEquipment, $selectCommand, $connected) extends localthingsCmd {
        private $testEquipment;
        private $testRecipe;
        private $testListValue;
        public function __construct($equipment, $action, $connected) {
            $this->testEquipment = $equipment;
            $this->testRecipe = $action['extra']['recipe'] ?? array();
            $this->configureTestCommand('select', 0, $connected);
            $this->testListValue = 'CustomAir|Mode personnalisé';
        }
        public function getEqLogic() { return $this->testEquipment; }
        public function getConfiguration($key, $default = null) {
            if ($key === 'operation') { return 'write'; }
            if ($key === 'extra') { return json_encode(array('recipe' => $this->testRecipe)); }
            if ($key === 'listValue') { return $this->testListValue; }
            return $default;
        }
        public function getType() { return 'action'; }
        public function getSubType() { return 'select'; }
    };
    $clientProperty = new ReflectionProperty('localthings', 'daemonDeviceClient');
    $clientProperty->setValue(null, $deviceClient);
    $selectAction->executeDirect(array('select' => 'CustomAir'));
    assertAcCommand($remoteWrites === 1, 'A custom list value must be sent to the device');
    assertAcCommand(
        in_array('CustomAir', $lastRecipe['allowed'] ?? array(), true),
        'Custom list values must be merged into the write allow-list'
    );
    $clientProperty->setValue(null, null);

    assertAcCommand(isset($entities['Mode de confort']), 'Convenient AC modes need a dedicated name');
    $labels = array();
    foreach ($entities['Mode de confort']['actions'][0]['options'] ?? array() as $option) {
        $labels[$option['value']] = $option['label'];
    }
    assertAcCommand(($labels['Quiet'] ?? '') === 'Silencieux', 'Quiet needs a French label');
    assertAcCommand(($labels['Nano'] ?? '') === 'WindFree', 'Nano must be exposed as WindFree');
    assertAcCommand(($labels['NanoSleep'] ?? '') === 'WindFree Sleep', 'NanoSleep must be exposed as WindFree Sleep');

    $comfortRecipe = $entities['Mode de confort']['actions'][0]['extra']['recipe'] ?? array();
    $nanoWrite = (new LocalThingsMapper())->buildWrite($comfortRecipe, 'Nano', $resources);
    assertAcCommand(
        $nanoWrite['path'] === array('modeoption', 'windfree', 'vs', '0')
        && $nanoWrite['body'] === array('x.com.samsung.da.windfree' => 'On'),
        'Nano must use the dedicated WindFree resource when the device exposes it'
    );
    $nanoSleepWrite = (new LocalThingsMapper())->buildWrite($comfortRecipe, 'NanoSleep', $resources);
    assertAcCommand(
        $nanoSleepWrite['path'] === array('mode', 'convenient', 'vs', '0')
        && $nanoSleepWrite['body'] === array('x.com.samsung.da.modes' => 'NanoSleep'),
        'NanoSleep must keep the confirmed convenient-mode write path'
    );

    foreach (array(
        'Mode nuit tropicale',
        'Seuil d’alerte du filtre',
        'Réinitialiser le filtre',
        'Volume',
        'WindFree',
        'WindFree Sleep',
    ) as $name) {
        assertAcCommand(isset($entities[$name]), 'Missing AC control: ' . $name);
    }
    assertAcCommand(count($entities['WindFree']['actions']) === 2, 'WindFree must be writable');
    assertAcCommand(count($entities['WindFree Sleep']['actions']) === 2, 'WindFree Sleep must be writable');
    foreach (array(
        'Mode nuit tropicale' => 'mode_vs_0_option_Sleep_',
        'Seuil d’alerte du filtre' => 'mode_vs_0_option_FilterAlarmTime_',
        'Volume' => 'mode_vs_0_option_Volume_',
    ) as $name => $legacyPrefix) {
        assertAcCommand(
            strpos($entities[$name]['key'], $legacyPrefix) === 0,
            $name . ' must reuse the previously generated info command ID'
        );
    }
    $volumeActions = array_column($entities['Volume']['actions'], 'name');
    assertAcCommand($volumeActions === array('Volume +', 'Volume −'), 'Volume actions are incomplete');

    $mapper = new LocalThingsMapper();
    $volumeWrite = $mapper->buildWrite(
        $entities['Volume']['actions'][0]['extra']['recipe'],
        null,
        $resources
    );
    assertAcCommand(
        $volumeWrite['body']['x.com.samsung.da.options'] === array('Volume_100'),
        'Volume + must write the confirmed Volume_100 token'
    );
    $nightWrite = $mapper->buildWrite(
        $entities['Mode nuit tropicale']['actions'][0]['extra']['recipe'],
        16,
        $resources
    );
    assertAcCommand(
        $nightWrite['body']['x.com.samsung.da.options'] === array('Sleep_16'),
        'Tropical night mode must use the Sleep token'
    );
    try {
        $mapper->buildWrite($entities['Mode nuit tropicale']['actions'][0]['extra']['recipe'], 17, $resources);
        throw new RuntimeException('Out-of-range tropical night value accepted');
    } catch (InvalidArgumentException $expected) {
    }

    $sleepStub = $mapper->map(array(
        '/mode/vs/0' => array(
            'x.com.samsung.da.modes' => array('Cool'),
            'x.com.samsung.da.options' => array('Sleep_0'),
        ),
    ));
    $sleepStubNames = array_column($sleepStub['entities'], 'name');
    assertAcCommand(
        in_array('Mode nuit tropicale', $sleepStubNames, true),
        'The tropical-night command must remain present when Sleep returns to zero'
    );

    echo "LocalThings AC custom and advanced commands: OK\n";
} finally {
    if (is_dir($sandbox)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $path) {
            $path->isDir() ? @rmdir($path->getPathname()) : @unlink($path->getPathname());
        }
        @rmdir($sandbox);
    }
}
