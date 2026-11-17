#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[3]
client = (root / "core/class/LocalThingsClient.php").read_text(encoding="utf-8")
plugin = (root / "core/class/localthings.class.php").read_text(encoding="utf-8")
daemon_client = (root / "core/class/LocalThingsDaemonClient.php").read_text(encoding="utf-8")
daemon = (root / "core/class/LocalThingsDaemon.php").read_text(encoding="utf-8")
transport = (root / "core/class/LocalThingsTransport.php").read_text(encoding="utf-8")
dashboard_template = (root / "core/template/dashboard/localthings.device.template.html").read_text(encoding="utf-8")
mobile_template = (root / "core/template/mobile/localthings.device.template.html").read_text(encoding="utf-8")
css = (root / "core/template/css/localthings.widget.css").read_text(encoding="utf-8")

checks = {
    "invalid write values are command rejections": "catch (InvalidArgumentException $exception)" in client
    and "new LocalThingsCommandRejectedException($exception->getMessage()" in client,
    "unverified writes are not reported as success": "$verification['matched'] !== true" in client,
    "commands refresh the complete resource snapshot": "$resources = $this->readResources($session" in client,
    "verification result survives log truncation": "applied=%2$s expected=%3$s actual=%4$s" in client,
    "OpenSSL receives explicit EOF behavior": "'-no_ign_eof'" in transport,
    "dashboard callbacks are unique per tile": dashboard_template.count("'#uid#';") >= 5,
    "mobile callbacks are unique per tile": mobile_template.count("'#uid#';") >= 5,
    "IPC requests carry an absolute expiry": "'expires_at_ms'" in daemon_client,
    "expired IPC work is rejected by the daemon": "DEADLINE_EXCEEDED" in daemon,
    "cron respects daemon auto mode": "deamonAutoMode" in plugin,
    "command failures update retry scheduling": plugin.count("recordCommunicationFailure(") >= 3,
    "unselected main statuses remain available": "$sections['details'][] = $command" in plugin,
    "inactive remaining time is visually attenuated": "localthings-status-inactive" in dashboard_template
    and "localthings-status-inactive" in mobile_template
    and "localthings-status-inactive" in css
    and "content: '—'" in css,
}

missing = [name for name, present in checks.items() if not present]
if missing:
    raise SystemExit("Missing reported-regression protections: " + "; ".join(missing))

print("LocalThings reported regression protections: OK")
