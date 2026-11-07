#!/usr/bin/env python3
"""OwnerPSK credential sizes accepted by both PHP and Python transports."""
from __future__ import annotations

import importlib.util
import pathlib
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[3]
HELPER = ROOT / "resources" / "owner_psk_transport.py"

spec = importlib.util.spec_from_file_location("owner_psk_transport", HELPER)
if spec is None or spec.loader is None:
    raise RuntimeError(f"Unable to load {HELPER}")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

identity = bytes.fromhex("12345678123442348123456789abcdef")
module.validate_credential(identity, bytes.fromhex("11" * 16))
module.validate_credential(identity, bytes.fromhex("22" * 32))

for bad_identity, bad_key in (
    (b"\0" + identity[1:], bytes.fromhex("11" * 16)),
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

print("LocalThings OwnerPSK 16/32-byte validation: OK")
