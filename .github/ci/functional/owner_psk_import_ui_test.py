#!/usr/bin/env python3

from pathlib import Path
import re

root = Path(__file__).resolve().parents[3]
page = (root / "desktop/php/localthings.php").read_text(encoding="utf-8")
js = (root / "desktop/js/localthings.js").read_text(encoding="utf-8")
ajax = (root / "core/ajax/localthings.ajax.php").read_text(encoding="utf-8")
css = (root / "desktop/css/localthings.css").read_text(encoding="utf-8")
client = (root / "core/class/LocalThingsClient.php").read_text(encoding="utf-8")

upload_function = re.search(
    r"async function uploadOwnerPskDatabase\(\) \{(?P<body>.*?)\n  \}", js, re.DOTALL
)
render_scan = re.search(r"function renderScan\(status\) \{(?P<body>.*?)\n  \}", js, re.DOTALL)

checks = {
    "file input": 'id="in_ownerPskDatabase"' in page and 'type="file"' in page,
    "analysis button": 'id="bt_ownerPskDatabase"' in page,
    "credential list": 'id="localthings-ownerpsk-credentials"' in page,
    "visible method choices": page.count('class="localthings-ownerpsk-method"') == 2
    and "{{Choisissez comment fournir la clé OwnerPSK}}" in page,
    "iPhone importer visible by default": re.search(
        r'<details id="localthings-ownerpsk-database-panel"[^>]*\bopen\b', page
    ) is not None,
    "explicit open action": page.count("{{Ouvrir}}") == 2
    and page.count("localthings-ownerpsk-method-description") == 2,
    "method card styling": ".localthings-ownerpsk-method > summary" in css
    and ".localthings-ownerpsk-method[open]" in css
    and "cursor: pointer" in css,
    "owner UUID input theme background": re.search(
        r"#in_ownerPskOwner\s*\{[^}]*background-color:\s*var\(--sc-lightTxt-color\)\s*!important;",
        css,
        re.DOTALL,
    ) is not None,
    "upload endpoint": "case 'decodeOwnerPskDatabase':" in ajax,
    "native FormData": "new FormData()" in js,
    "native fetch": "fetch(endpoint" in js,
    "result renderer": "renderOwnerPskCredentials" in js,
    "invalid PSK reasons rendered": "owner_uuid_invalid" in js
    and "device_uuid_invalid" in js
    and "key_invalid" in js,
    "selected import": "bt_ownerPskImportSelected" in page and "importSelectedOwnerPsk" in js,
    "known endpoint reused": "'port' => (int) $equipment->getConfiguration('port', 0)" in ajax
    and "port: credential.equipment && credential.equipment.port" in js
    and "importOwnerPsk($host, array $credential, $preferredPort = null)" in client
    and "certificateDiagnosticPort($host, $preferredPort, true)" in client,
    "selection bounded before import": "selected.length > 20" in js
    and js.index("selected.length > 20") < js.index('ajaxPromise("importOwnerPsk"'),
    "credentials cleared after discovery starts": js.index("credential.key = \"\"")
    > js.index('ajaxPromise("probeOwnerPskHosts"'),
    "upload race guard": upload_function is not None
    and "certificateBusy || scanBusy || scanState.running" in upload_function.group("body"),
    "upload controls locked": render_scan is not None
    and "bt_ownerPskDatabase" in render_scan.group("body")
    and "in_ownerPskDatabase" in render_scan.group("body"),
    "no jquery": "jQuery" not in js and "$(" not in js,
}

missing = [name for name, valid in checks.items() if not valid]
if missing:
    raise SystemExit("Missing OwnerPSK importer UI: " + ", ".join(missing))

print("LocalThings iPhone OwnerPSK importer UI: OK")
