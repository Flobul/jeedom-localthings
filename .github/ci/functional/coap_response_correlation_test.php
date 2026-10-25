<?php
function __($text, $file = null) { return $text; }
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsTransport.php';
require_once dirname(__DIR__, 3) . '/core/class/LocalThingsProtocol.php';
class EchoTransport extends LocalThingsDtlsClient {
    public $writes = array();
    private $frames = array();
    public function __construct() {}
    public function write($packet) {
        $this->writes[] = $packet;
        $p = LocalThingsCoap::parse($packet);
        if ($p['code'] === 1) {
            $this->frames = array($packet,
                LocalThingsCoap::build(0, 69, $p['message_id'], 'WRONG', array(), 'bad'),
                LocalThingsCoap::build(2, 69, ($p['message_id']+1)%65536, $p['token'], array(), 'bad'),
                LocalThingsCoap::build(2, 69, $p['message_id'], $p['token'], array(), 'ok'));
        }
    }
    public function readFrame($timeout = 4.0) { return array_shift($this->frames); }
    public function close() {}
}
$t = new EchoTransport();
$s = new LocalThingsSession($t);
$r = $s->get('/oic/sec/doxm', 1);
if ($r !== array(69, 'ok') || count($t->writes) !== 1) { throw new Exception('Invalid CoAP reply accepted or acknowledged'); }
echo "OK: secure session ignores request echo, wrong token and wrong ACK MID without acknowledging them\n";

class RetryTransport extends LocalThingsDtlsClient {
    public $writes = array();
    public $sentAt = array();
    private $frames = array();
    public function __construct() {}
    public function write($packet) {
        $this->writes[] = $packet;
        $this->sentAt[] = microtime(true);
        if (count($this->writes) === 1) { return; }
        $p = LocalThingsCoap::parse($packet);
        $this->frames[] = LocalThingsCoap::build(2, 69, $p['message_id'], $p['token'], array(), 'ok');
    }
    public function readFrame($timeout = 4.0) {
        if ($this->frames) { return array_shift($this->frames); }
        usleep((int) ($timeout * 1000000));
        return null;
    }
    public function close() {}
}
$t = new RetryTransport();
$s = new LocalThingsSession($t);
if ($s->get('/power/0', 4.5) !== array(69, 'ok') || count($t->writes) !== 2 || $t->writes[0] !== $t->writes[1]) {
    throw new Exception('Retransmission must preserve all bytes, MID and token');
}
$s->get('/temperature/0', 1.0);
if ($t->sentAt[2] - $t->sentAt[1] < 0.18) { throw new Exception('Distinct resource reads must be paced'); }
echo "OK: lost reply retransmitted identically and successive resources paced\n";

class BlockTransport extends LocalThingsDtlsClient {
    public $mode;
    public $writes = array();
    private $frames = array();
    public function __construct($mode) { $this->mode = $mode; }
    public function write($packet) {
        $p = LocalThingsCoap::parse($packet);
        $this->writes[] = $p;
        $block = count($this->writes) - 1;
        $tag = $block && $this->mode === 'changed' ? 'second' : 'first';
        $options = array(array(4, $tag), array(23, LocalThingsCoap::blockValue($block, $block === 0, 2)));
        $code = 69;
        if ($block && $this->mode === 'denied') { $code = 129; $options = array(); }
        $this->frames[] = LocalThingsCoap::build(2, $code, $p['message_id'], $p['token'], $options, $block ? 'b' : str_repeat('a', 64));
    }
    public function readFrame($timeout = 4.0) { return array_shift($this->frames); }
    public function close() {}
}
foreach (array('valid', 'changed', 'denied') as $mode) {
    $t = new BlockTransport($mode);
    $s = new LocalThingsSession($t);
    try {
        $r = $s->get('/oic/res', 1.0);
        if ($mode === 'changed') { throw new LogicException('Mixed representations accepted'); }
        if ($mode === 'valid' && $r !== array(69, str_repeat('a', 64) . 'b')) { throw new Exception('Invalid complete representation'); }
        if ($mode === 'denied' && $r[0] !== 129) { throw new Exception('Block-level authorization failure lost'); }
    } catch (RuntimeException $error) {
        if ($mode !== 'changed' || strpos($error->getMessage(), 'modifiée') === false) { throw $error; }
    }
    if (count($t->writes) !== 2 || $t->writes[0]['token'] !== $t->writes[1]['token']
        || $t->writes[0]['message_id'] === $t->writes[1]['message_id']) {
        throw new Exception('Block continuation requires stable token and new MID');
    }
}
echo "OK: complete multi-block reads, stable tokens, changed representation and authorization error\n";
