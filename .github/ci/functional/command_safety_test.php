<?php

if (!function_exists('__')) { function __($message, $file = null) { return $message; } }
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsMapper.php';

function assertCommandSafety($condition, $message)
{
    if (!$condition) { throw new RuntimeException($message); }
}

$mapper = new LocalThingsMapper();
$washer = $mapper->map(array(
    '/washer/vs/0' => array(
        'x.com.samsung.da.rinseCycles' => 2,
        'x.com.samsung.da.supportedRinseCycles' => array(1, 2, 3),
    ),
));
$rinse = current(array_filter($washer['entities'], function ($entity) {
    return strpos((string) ($entity['key'] ?? ''), 'rinseCycles') !== false;
}));
assertCommandSafety(is_array($rinse), 'Rinse-cycle entity missing');
assertCommandSafety(($rinse['subtype'] ?? '') === 'string', 'Select-backed info must remain string');
assertCommandSafety(($rinse['unit'] ?? '') === '', 'Select-backed info must not keep a numeric unit');
$rinseRecipe = $rinse['actions'][0]['extra']['recipe'] ?? array();
assertCommandSafety(($rinseRecipe['allowed'] ?? array()) === array('1', '2', '3'), 'Select recipe must carry its allowed values');
try {
    $mapper->buildWrite($rinseRecipe, 'abc', array('/washer/vs/0' => array()));
    throw new RuntimeException('Out-of-list select value was accepted');
} catch (InvalidArgumentException $expected) {
}

$cycleResources = array(
    '/course/vs/0' => array('x.com.samsung.da.options' => array('Course_D0')),
    '/wm/editcourse/vs/0' => array('x.com.samsung.da.editCourseList' => 'Course_D0D1'),
);
$cycles = $mapper->map($cycleResources);
$cycle = current(array_filter($cycles['entities'], function ($entity) {
    return strpos((string) ($entity['key'] ?? ''), 'washer_cycle_') === 0;
}));
$cycleRecipe = $cycle['actions'][0]['extra']['recipe'] ?? array();
assertCommandSafety(($cycleRecipe['allowed'] ?? array()) === array('D0', 'D1'), 'Cycle recipe must carry editCourseList values');
try {
    $mapper->buildWrite($cycleRecipe, 'AB', $cycleResources);
    throw new RuntimeException('Unknown cycle value was accepted');
} catch (InvalidArgumentException $expected) {
}

$delayRecipe = array(
    'href' => '/course/vs/0',
    'field' => 'x.com.samsung.da.delayEndTime',
    'encoding' => 'delay_hours',
    'min' => 0,
    'max' => 24,
);
$delayWrite = $mapper->buildWrite($delayRecipe, 3, array('/course/vs/0' => array()));
assertCommandSafety(
    $delayWrite['body']['x.com.samsung.da.delayEndTime'] === '03:00:00',
    'Delay end time must use two-digit hours'
);
try {
    $mapper->buildWrite($delayRecipe, 25, array('/course/vs/0' => array()));
    throw new RuntimeException('Out-of-range delay was accepted');
} catch (InvalidArgumentException $expected) {
}

print "LocalThings command value safety: OK\n";
