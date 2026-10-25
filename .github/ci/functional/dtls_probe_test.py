"""Local real UDP tests, synthetic replies, no customer captures."""
import json
import socket
import subprocess
import threading
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]

def sock():
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.bind(('127.0.0.1', 0))
    s.settimeout(.1)
    return s

def record(kind, body, epoch=0):
    return bytes([kind]) + b'\xfe\xfd' + epoch.to_bytes(2, 'big') + bytes(6) + len(body).to_bytes(2, 'big') + body

body = b'\xfe\xfd\x04TEST'
hvr = record(22, b'\x03' + len(body).to_bytes(3, 'big') + bytes(5) + len(body).to_bytes(3, 'big') + body)
stop = threading.Event()
received = [[], [], []]
with sock() as live, sock() as silent, sock() as second_route, sock() as responder:
    def serve(s, index):
        while not stop.is_set():
            try:
                packet, peer = s.recvfrom(8192)
                received[index].append(packet)
                if index != 1:
                    # Malformed/wrong-epoch packet must not terminate the probe.
                    responder.sendto(record(21, bytes([2, 40]), epoch=1), peer)
                    responder.sendto(hvr, peer)
            except socket.timeout:
                pass
    threads = [threading.Thread(target=serve, args=(s, i))
               for i, s in enumerate((live, silent, second_route))]
    for thread in threads: thread.start()
    try:
        ports = [live.getsockname()[1], silent.getsockname()[1], second_route.getsockname()[1]]
        php = '''class LocalThingsDiscovery {static function checkpoint(){}}
function __($text, $file=null) {return $text;}
require 'core/class/LocalThingsDtlsProbe.php';
$method = new ReflectionMethod(LocalThingsDtlsProbe::class, 'scan');
if ($method->getNumberOfParameters() < 6) { throw new Exception('Probe bind address is not injectable'); }
$r = LocalThingsDtlsProbe::scan('127.0.0.1', [%s], 'openssl', 1.5, false, '127.0.0.1');
echo json_encode(['results'=>$r, 'live'=>LocalThingsDtlsProbe::livePorts($r),
    'selected'=>LocalThingsDtlsProbe::select($r)]);
''' % ','.join(map(str, ports))
        result = subprocess.run(['php', '-r', php], cwd=ROOT, capture_output=True, text=True, check=True, timeout=6)
        parsed = json.loads(result.stdout)
        time.sleep(.15)
        results = parsed['results']
        responder_port = responder.getsockname()[1]
        assert results[str(ports[0])]['kind'] == 'hello_verify_request', parsed
        assert results[str(ports[0])]['responder_port'] == responder_port, parsed
        assert results[str(ports[1])]['kind'] == 'no_response', parsed
        assert results[str(ports[2])]['kind'] == 'hello_verify_request', parsed
        assert results[str(ports[2])]['responder_port'] == responder_port, parsed
        assert parsed['live'] == [responder_port], parsed
        assert parsed['selected'] == responder_port, parsed
        assert len(received[0]) == 1, 'Cookie flight sent after HVR'
        assert len(received[1]) == 2 and received[1][0] == received[1][1], 'Non-identical retry'
        assert len(received[2]) == 1, 'Cookie flight sent after HVR through second route'
        packet = received[0][0]
        assert packet[60 + packet[59]] == 0, 'Initial ClientHello contains cookie'
    finally:
        stop.set()
        for thread in threads: thread.join()
php = '''class LocalThingsDiscovery {static function checkpoint(){}}
function __($text, $file=null) {return $text;}
require 'core/class/LocalThingsDtlsProbe.php';
function check($ok) {if (!$ok) throw new Exception('Check failed');}
$r = [
    5684 => ['kind'=>'hello_verify_request', 'responder_port'=>49155],
    49155 => ['kind'=>'server_hello', 'responder_port'=>49155],
];
check(LocalThingsDtlsProbe::select($r) === 49155);
$r = [
    5684 => ['kind'=>'hello_verify_request', 'responder_port'=>49155],
    49154 => ['kind'=>'server_hello', 'responder_port'=>49154],
];
try {LocalThingsDtlsProbe::select($r); throw new LogicException('Ambiguity ignored');}
catch (RuntimeException $e) {}
check(LocalThingsDtlsProbe::select($r, 49154) === 49154);
check(LocalThingsDtlsProbe::select([52000=>[
    'kind'=>'hello_verify_request', 'responder_port'=>52000]]) === 52000);
try {LocalThingsDtlsProbe::select([5684=>['kind'=>'hello_verify_request']]);
    throw new LogicException('Missing responder port guessed from target');}
catch (RuntimeException $e) {}
check(LocalThingsDtlsProbe::firstReply('not dtls') === null);
echo 'OK';
'''
subprocess.run(['php','-r',php], cwd=ROOT, check=True, timeout=3)
print(': first-flight parsing, source-port selection, route deduplication, no cookie flight, identical retry')
