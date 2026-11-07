#!/usr/bin/env python3
"""The manual OwnerPSK flow remains visible after DTLS probe failures."""
from __future__ import annotations

import pathlib
import re

ROOT = pathlib.Path(__file__).resolve().parents[3]
source = (ROOT / "desktop/js/localthings.js").read_text(encoding="utf-8")

assert re.search(r"function\s+ownerPskGuidance\s*\(", source), "Missing timeout guidance helper"
assert re.search(r"unknown_ca|unknown ca", source, re.I), "Certificate rejection must trigger guidance"
assert re.search(r"timeout|délai dépassé|timed out", source, re.I), "Timeout must trigger guidance"
assert "localthings-ownerpsk-panel" in source, "Guidance must open the OwnerPSK panel"
assert "in_ownerPskOwner" in source, "Guidance must focus the first credential field"

print("LocalThings OwnerPSK timeout UX: OK")
