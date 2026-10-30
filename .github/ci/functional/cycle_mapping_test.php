<?php
if (!function_exists('__')) { function __($message) { return $message; } }
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsMapper.php';

$mapper = new LocalThingsMapper();
$resources = array(
    '/course/vs/0' => array(
        'x.com.samsung.da.options' => array('Course_D0', 'DeviceType_0167'),
        'x.com.samsung.da.supportedOptions' => array(
            '3D0847E923FA67DDC811E933FA33DDB841E923FA67DDA8102923FA53DD9831E933FA53DD8841E920FA30DD7831E920FA57DD6841E933FA30DD585209204A520BA80009000A67ED48000913FA67DD3831E920FA30DD2843E923FA67DD18430923FA67D'
        ),
    ),
    '/st/dryercourse/vs/0' => array('value' => 'D0'),
);
$mapped = $mapper->map($resources);
$cycle = null;
foreach ($mapped['entities'] as $entity) {
    if (strpos((string) ($entity['key'] ?? ''), 'dryer_cycle_') === 0) {
        $cycle = $entity;
        break;
    }
}
if (!is_array($cycle)) { throw new RuntimeException('cycle entity missing'); }
if (($cycle['value'] ?? null) !== 'D0') { throw new RuntimeException('current cycle must remain D0'); }
if (!empty($cycle['actions'])) {
    throw new RuntimeException('opaque supportedOptions must not create guessed writable cycle values');
}

$resources['/wm/editcourse/vs/0'] = array(
    'x.com.samsung.da.editCourseList' => 'Course_D0D1DA',
);
$mapped = $mapper->map($resources);
$cycle = null;
foreach ($mapped['entities'] as $entity) {
    if (strpos((string) ($entity['key'] ?? ''), 'dryer_cycle_') === 0) {
        $cycle = $entity;
        break;
    }
}
if (empty($cycle['actions'][0])) {
    throw new RuntimeException('explicit editCourseList must keep the writable cycle selector');
}
$values = array_column($cycle['actions'][0]['options'] ?? array(), 'value');
if ($values !== array('D0', 'D1', 'DA')) {
    throw new RuntimeException('editCourseList cycle values must be preserved exactly');
}
echo "LocalThings cycle mapping safety: OK\n";
