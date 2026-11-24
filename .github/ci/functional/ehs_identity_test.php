<?php
function __($text, $file = null) { return $text; }
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsMapper.php';
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsWidget.php';
$mapper = new LocalThingsMapper();
$generic = array('device_types' => array('oic.d.airconditioner'));
$cases = array(
    array(array('/controller/0' => array('rt' => array('x.com.samsung.da.controllerstatus.ehs'))), $generic + array('model' => 'MIM-H04EN|260320'), 'airconditioner'),
    array(array(
        '/mode/dhw/vs/0' => array('x.com.samsung.da.modes' => 'eco'),
        '/temperatures/dhw/vs/0' => array('x.com.samsung.da.current' => 48),
    ), $generic + array('model' => 'MIM-H04EN|260320'), 'ehs'),
    array(array(), $generic + array('model' => 'SAC_EHS_MONO|synthetic'), 'ehs'),
    array(array('/information/vs/0' => array('x.com.samsung.da.modelNum' => 'SAC_EHS_MONO|synthetic')), $generic, 'ehs'),
    array(array('/controller/0' => array('rt' => array('x.com.samsung.da.controllerstatus.ehs'))), $generic, 'airconditioner'),
    array(array(), $generic + array('model' => 'ARTIK051_PRAC_20K|synthetic'), 'airconditioner'),
    array(array(), $generic + array('model' => 'ARTIK051_PRAC_20K|EHS'), 'airconditioner'),
    array(array(), $generic + array('model' => 'FRESH_MODEL'), 'airconditioner'),
);
foreach ($cases as list($resources, $identity, $expected)) {
    if ($mapper->deviceType($resources, $identity) !== $expected) { throw new RuntimeException('Wrong type: ' . $expected); }
}
if (LocalThingsWidget::profile('ehs')['label'] !== 'Pompe à chaleur EHS') { throw new RuntimeException('Wrong EHS widget'); }

$ehsResources = array(
    '/temperatures/outside/vs/0' => array(
        'x.com.samsung.da.current' => 7.9,
        'x.com.samsung.da.unit' => 'Celsius',
    ),
    '/energy/consumption/vs/0' => array(
        'x.com.samsung.da.instantaneousPower' => '1.034000',
        'x.com.samsung.da.instantaneousPowerUnit' => 'KW',
        'x.com.samsung.da.cumulativePower' => '16839.0',
        'x.com.samsung.da.cumulativeConsumption' => '16839.0',
        'x.com.samsung.da.cumulativeUnit' => 'Wh',
    ),
    '/energy/generation/vs/0' => array(
        'x.com.samsung.da.cumulativeGeneration' => 23294287.0,
        'x.com.samsung.da.cumulativeGenerationUnit' => 'Wh',
    ),
    '/controllerstatus/ehs/vs/0' => array(
        'x.com.samsung.da.ehs.boosterHeater' => false,
        'x.com.samsung.da.ehs.3wayValve' => 'Room',
    ),
);
$mapped = $mapper->map($ehsResources);
$byName = array();
foreach ($mapped['entities'] as $entity) { $byName[$entity['name']] = $entity; }
$expected = array(
    'Température extérieure' => array(7.9, '°C'),
    'Puissance instantanée' => array(1.034, 'kW'),
    'Consommation cumulée' => array(16.839, 'kWh'),
    'Chaleur produite' => array(23294.287, 'kWh'),
    'Appoint électrique' => array(0, ''),
    'Vanne trois voies' => array('Room', ''),
);
foreach ($expected as $name => list($value, $unit)) {
    if (!isset($byName[$name]) || $byName[$name]['value'] !== $value || $byName[$name]['unit'] !== $unit) {
        throw new RuntimeException('Wrong EHS mapping: ' . $name);
    }
}
$cumulative = array_filter($mapped['entities'], function ($entity) {
    return $entity['name'] === 'Consommation cumulée';
});
if (count($cumulative) !== 1) { throw new RuntimeException('Duplicate EHS cumulative energy'); }

$controlResources = array(
    '/mode/vs/0' => array(
        'x.com.samsung.da.modes' => 'heat',
        'x.com.samsung.da.supportedModes' => array('auto', 'cool', 'heat'),
    ),
    '/mode/dhw/vs/0' => array(
        'x.com.samsung.da.modes' => 'eco',
        'x.com.samsung.da.supportedModes' => array('eco', 'std', 'power', 'force'),
    ),
    '/power/dhw/vs/0' => array('x.com.samsung.da.power' => 'On'),
    '/temperatures/indoor/vs/0' => array(
        'x.com.samsung.da.current' => 31,
        'x.com.samsung.da.desired' => 35,
        'x.com.samsung.da.minimum' => -1000,
        'x.com.samsung.da.maximum' => -1000,
        'x.com.samsung.da.increment' => 1,
        'x.com.samsung.da.unit' => 'Celsius',
        'x.com.samsung.da.type' => 'WaterLaw',
        'x.com.samsung.da.displaycondition.button' => 'Disable',
    ),
    '/temperatures/zone2/indoor/vs/0' => array(
        'x.com.samsung.da.current' => 29,
        'x.com.samsung.da.desired' => 34,
        'x.com.samsung.da.minimum' => 20,
        'x.com.samsung.da.maximum' => 50,
        'x.com.samsung.da.increment' => 1,
        'x.com.samsung.da.unit' => 'Celsius',
    ),
    '/temperatures/dhw/vs/0' => array(
        'x.com.samsung.da.current' => 48,
        'x.com.samsung.da.desired' => 52,
        'x.com.samsung.da.minimum' => 35,
        'x.com.samsung.da.maximum' => 65,
        'x.com.samsung.da.increment' => 1,
        'x.com.samsung.da.unit' => 'Celsius',
    ),
);
$controls = $mapper->map($controlResources);
$controlsByName = array();
foreach ($controls['entities'] as $entity) { $controlsByName[$entity['name']] = $entity; }
foreach (array(
    'Mode chauffage/climatisation', 'Mode ECS', 'Marche ECS',
    'Température Zone 1', 'Consigne Zone 1',
    'Température Zone 2', 'Consigne Zone 2',
    'Température ECS', 'Consigne ECS',
) as $name) {
    if (!isset($controlsByName[$name])) { throw new RuntimeException('Missing EHS control label: ' . $name); }
}
if (count($controlsByName['Consigne Zone 1']['actions']) !== 0) {
    throw new RuntimeException('Disabled EHS sentinel range must not create a slider');
}
if (count($controlsByName['Consigne Zone 2']['actions']) !== 1) {
    throw new RuntimeException('Writable EHS Zone 2 range must keep its slider');
}
foreach ($controls['entities'] as $entity) {
    if (preg_match('/^(Minimum|Maximum|Incrément|Unité|Type|Condition)/i', $entity['name'])) {
        throw new RuntimeException('EHS temperature metadata leaked as a user command: ' . $entity['name']);
    }
}
$dhwOptions = array();
foreach ($controlsByName['Mode ECS']['actions'][0]['options'] ?? array() as $option) {
    $dhwOptions[$option['value']] = $option['label'];
}
foreach (array('eco' => 'Éco', 'std' => 'Standard', 'power' => 'Puissance maximale', 'force' => 'Forcé') as $value => $label) {
    if (($dhwOptions[$value] ?? null) !== $label) {
        throw new RuntimeException('Wrong EHS DHW option label for ' . $value);
    }
}

echo "OK: EHS identity, widget and MIM-H04EN measurements mapped\n";
