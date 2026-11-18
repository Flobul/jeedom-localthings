#!/usr/bin/env python3
"""OwnerPSK credential sizes accepted by both PHP and Python transports."""
from __future__ import annotations

import importlib.util
import json
import pathlib
import subprocess
import sys

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import ec

ROOT = pathlib.Path(__file__).resolve().parents[3]
HELPER = ROOT / "resources" / "owner_psk_transport.py"
sys.path.insert(0, str(HELPER.parent))

spec = importlib.util.spec_from_file_location("owner_psk_transport", HELPER)
if spec is None or spec.loader is None:
    raise RuntimeError(f"Unable to load {HELPER}")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

identity = bytes.fromhex("12345678123442348123456789abcdef")
binary_identity = bytes.fromhex("3771f8bf00003a2dd885e4c9818736d2")
module.validate_credential(identity, bytes.fromhex("11" * 16))
module.validate_credential(identity, bytes.fromhex("22" * 32))
module.validate_credential(binary_identity, bytes.fromhex("11" * 16))
engine = module.DtlsPskClient(identity=binary_identity, key=bytes.fromhex("11" * 16), mtu=1200)
engine._private_key = ec.generate_private_key(ec.SECP256R1())
peer_key = ec.generate_private_key(ec.SECP256R1())
engine._peer_point = peer_key.public_key().public_bytes(
    serialization.Encoding.X962,
    serialization.PublicFormat.UncompressedPoint,
)
engine._client_random = bytes.fromhex("55" * 32)
engine._server_random = bytes.fromhex("66" * 32)
engine._send_client_flight()
flight = engine.bio_read()
record_length = int.from_bytes(flight[11:13], "big")
handshake = flight[13:13 + record_length]
client_key_exchange = handshake[12:]
if client_key_exchange[:2] != b"\x00\x10" or client_key_exchange[2:18] != binary_identity:
    raise AssertionError("Binary OwnerPSK identity was not length-framed intact")

for bad_identity, bad_key in (
    (bytes(16), bytes.fromhex("11" * 16)),
    (identity[:-1], bytes.fromhex("11" * 16)),
    (identity, bytes.fromhex("33" * 24)),
):
    try:
        module.validate_credential(bad_identity, bad_key)
    except ValueError:
        pass
    else:
        raise AssertionError("Invalid OwnerPSK credential was accepted")

php = r'''
function __($text, $file = null) { return $text; }
class LocalThingsDtlsClient {}
require $argv[1];
foreach (array(str_repeat('11', 16), str_repeat('22', 32)) as $key) {
    $credential = LocalThingsOwnerPsk::validate(array(
        'owner_uuid' => '12345678-1234-4234-8123-456789abcdef',
        'device_uuid' => 'abcdefab-cdef-4abc-8def-abcdefabcdef',
        'key' => $key,
    ));
    if ($credential['key'] !== $key) { throw new RuntimeException('Key changed'); }
}
$binaryCredential = LocalThingsOwnerPsk::validate(array(
    'owner_uuid' => '3771f8bf-0000-3a2d-d885-e4c9818736d2',
    'device_uuid' => 'abcdefab-cdef-4abc-8def-abcdefabcdef',
    'key' => str_repeat('44', 16),
));
try {
    LocalThingsOwnerPsk::validate(array(
        'owner_uuid' => '12345678-1234-4234-8123-456789abcdef',
        'device_uuid' => 'abcdefab-cdef-4abc-8def-abcdefabcdef',
        'key' => str_repeat('33', 24),
    ));
    throw new RuntimeException('Invalid key accepted');
} catch (InvalidArgumentException $expected) {}
echo "OK\n";
'''
result = subprocess.run(
    ["php", "-r", php, str(ROOT / "core/class/LocalThingsOwnerPsk.php")],
    text=True,
    capture_output=True,
    check=False,
)
if result.returncode != 0:
    sys.stderr.write(result.stdout + result.stderr)
    raise SystemExit(result.returncode)

secret_identity = binary_identity.hex()
secret_key = "a5" * 16
rpc = subprocess.run(
    [sys.executable, str(HELPER)],
    input=json.dumps({
        "action": "connect",
        "host": "127.0.0.1",
        "port": 9,
        "source_port": 0,
        "identity": secret_identity,
        "key": secret_key,
        "timeout": 0.1,
    }) + "\n",
    text=True,
    capture_output=True,
    check=False,
    timeout=5,
)
captured = rpc.stdout + rpc.stderr
if secret_identity in captured or secret_key in captured:
    raise AssertionError("OwnerPSK material escaped the private RPC")
response = json.loads(rpc.stdout)
if response != {
    "ok": False,
    "error": "Session OwnerPSK impossible ou interrompue",
    "code": "ownerpsk_connect_failed",
}:
    raise AssertionError("OwnerPSK RPC did not return its safe diagnostic code")

print("LocalThings OwnerPSK 16/32-byte validation: OK")
