<?php
function __($text, $file = null) { return $text; }
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsMapper.php';
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsWidget.php';
$mapper = new LocalThingsMapper();
$generic = array('device_types' => array('oic.d.airconditioner'));
$cases = array(
    array(array('/controller/0' => array('rt' => array('x.com.samsung.da.controllerstatus.ehs'))), $generic + array('model' => 'MIM-H04EN|260320'), 'airconditioner'),
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
echo "OK: EHS precedes generic AC, PRAC unchanged, suffix ignored, EHS widget selected\n";
