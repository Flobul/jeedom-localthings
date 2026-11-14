<?php

if (!function_exists('__')) {
    function __($message, $file = null) { return $message; }
}
class eqLogic {}
class cmd {}

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

    $resources = array(
        '/mode/convenient/vs/0' => array(
            'x.com.samsung.da.modes' => 'Off',
            'x.com.samsung.da.supportedModes' => array('Off', 'Quiet', 'Nano', 'NanoSleep'),
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

    assertAcCommand(isset($entities['Mode de confort']), 'Convenient AC modes need a dedicated name');
    $labels = array();
    foreach ($entities['Mode de confort']['actions'][0]['options'] ?? array() as $option) {
        $labels[$option['value']] = $option['label'];
    }
    assertAcCommand(($labels['Quiet'] ?? '') === 'Silencieux', 'Quiet needs a French label');
    assertAcCommand(($labels['Nano'] ?? '') === 'WindFree', 'Nano must be exposed as WindFree');
    assertAcCommand(($labels['NanoSleep'] ?? '') === 'WindFree Sleep', 'NanoSleep must be exposed as WindFree Sleep');

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
