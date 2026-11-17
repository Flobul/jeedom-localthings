<?php

function __($text, $file = null) { return $text; }

require_once dirname(__DIR__, 3) . '/core/class/LocalThingsWidget.php';
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsMapper.php';

function assertRefrigeratorWidget($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$mapper = new LocalThingsMapper();
$mapped = $mapper->map(array(
    '/door/freezer/0' => array('x.com.samsung.da.openState' => 'Closed'),
    '/door/cooler/0' => array('x.com.samsung.da.openState' => 'Open'),
));
$doors = array_values(array_filter($mapped['entities'], function ($entity) {
    return strpos((string) ($entity['key'] ?? ''), 'openState') !== false;
}));
assertRefrigeratorWidget(count($doors) === 2, 'Both Samsung door resources must create commands');
$freezer = $doors[0];
$fridge = $doors[1];
$freezerKey = $freezer['key'];
$fridgeKey = $fridge['key'];

assertRefrigeratorWidget(
    LocalThingsWidget::group('refrigerator', $freezerKey, 'info', 'string', '', $freezer['name']) === 'status',
    'The freezer door must be shown on the main refrigerator page'
);
assertRefrigeratorWidget(
    LocalThingsWidget::group('refrigerator', $fridgeKey, 'info', 'string', '', $fridge['name']) === 'status',
    'The fridge door must be shown on the main refrigerator page'
);
assertRefrigeratorWidget(
    LocalThingsWidget::group('refrigerator', $fridgeKey, 'info', 'string', '', 'Firmware') === 'status',
    'A user-renamed command must not change widget classification'
);
assertRefrigeratorWidget(
    LocalThingsWidget::statusDeduplicationKey('refrigerator', $freezerKey, $freezer['name'])
        !== LocalThingsWidget::statusDeduplicationKey('refrigerator', $fridgeKey, $fridge['name']),
    'Distinct refrigerator doors must not share one widget status slot'
);
assertRefrigeratorWidget(
    LocalThingsWidget::statusDeduplicationKey('washer', $freezerKey, 'État de porte') === 'door',
    'Other appliance widgets must continue to deduplicate their door state'
);

$freezerPresentation = LocalThingsWidget::presentation(
    'refrigerator',
    $freezerKey,
    'info',
    'status',
    $freezer['name']
);
$fridgePresentation = LocalThingsWidget::presentation(
    'refrigerator',
    $fridgeKey,
    'info',
    'status',
    $fridge['name']
);
assertRefrigeratorWidget($freezerPresentation['label'] === 'Porte congélateur', 'Freezer door label missing');
assertRefrigeratorWidget($fridgePresentation['label'] === 'Porte réfrigérateur', 'Fridge door label missing');

$misleadingFridgePresentation = LocalThingsWidget::presentation(
    'refrigerator',
    $fridgeKey,
    'info',
    'status',
    'Porte congélateur'
);
assertRefrigeratorWidget(
    $misleadingFridgePresentation['label'] === 'Porte réfrigérateur',
    'A user label must not override the stable refrigerator door identity'
);
assertRefrigeratorWidget(
    LocalThingsWidget::statusSlot('', 'État de porte') === 'door',
    'Legacy commands without an entity key must retain their name fallback'
);

$temperatures = $mapper->map(array(
    '/temperatures/vs/0' => array(
        'x.com.samsung.da.items' => array(
            array(
                'x.com.samsung.da.id' => 'Fridge',
                'x.com.samsung.da.description' => 'Fridge',
                'x.com.samsung.da.current' => 5,
                'x.com.samsung.da.unit' => 'Celsius',
            ),
            array(
                'x.com.samsung.da.id' => 'Freezer',
                'x.com.samsung.da.description' => 'Freezer',
                'x.com.samsung.da.current' => -18,
                'x.com.samsung.da.unit' => 'Celsius',
            ),
        ),
    ),
));
$currentTemperature = current(array_filter($temperatures['entities'], function ($entity) {
    return strpos((string) ($entity['key'] ?? ''), 'temperatureItem_Fridge_current') !== false;
}));
assertRefrigeratorWidget(is_array($currentTemperature), 'Mapped refrigerator temperature missing');
assertRefrigeratorWidget(
    LocalThingsWidget::statusSlot($currentTemperature['key'], 'Renamed by user') === 'temperature',
    'Stable temperature item keys must remain widget statuses after a rename'
);
$fridgeTemperature = current(array_filter($temperatures['entities'], function ($entity) {
    return strpos((string) ($entity['key'] ?? ''), 'temperatureItem_Fridge_current') !== false;
}));
$freezerTemperature = current(array_filter($temperatures['entities'], function ($entity) {
    return strpos((string) ($entity['key'] ?? ''), 'temperatureItem_Freezer_current') !== false;
}));
assertRefrigeratorWidget(
    LocalThingsWidget::statusDeduplicationKey('refrigerator', $fridgeTemperature['key'], $fridgeTemperature['name'])
        !== LocalThingsWidget::statusDeduplicationKey('refrigerator', $freezerTemperature['key'], $freezerTemperature['name']),
    'Fridge and freezer temperatures must both remain on the refrigerator widget'
);
assertRefrigeratorWidget(
    LocalThingsWidget::statusLimit('refrigerator') === 4,
    'The refrigerator widget must have room for two doors and two temperatures'
);
$fridgeTemperaturePresentation = LocalThingsWidget::presentation(
    'refrigerator', $fridgeTemperature['key'], 'info', 'status', $fridgeTemperature['name']
);
$freezerTemperaturePresentation = LocalThingsWidget::presentation(
    'refrigerator', $freezerTemperature['key'], 'info', 'status', $freezerTemperature['name']
);
assertRefrigeratorWidget(
    $fridgeTemperaturePresentation['label'] === 'Température réfrigérateur',
    'Fridge temperature label missing'
);
assertRefrigeratorWidget(
    $freezerTemperaturePresentation['label'] === 'Température congélateur',
    'Freezer temperature label missing'
);

fwrite(STDOUT, "LocalThings refrigerator multi-door widget: OK\n");
