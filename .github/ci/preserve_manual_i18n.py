#!/usr/bin/env python3
"""Snapshot and restore non-empty manual i18n values around generation."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any


def read_object(path: Path) -> dict[str, Any]:
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise ValueError(f"{path} must contain an object")
    return value


def snapshot(root: Path, output: Path) -> None:
    payload: dict[str, dict[str, dict[str, str]]] = {}
    for path in sorted((root / "core" / "i18n").glob("*.json")):
        data = read_object(path)
        kept: dict[str, dict[str, str]] = {}
        for section, entries in data.items():
            if not isinstance(section, str) or not isinstance(entries, dict):
                raise ValueError(f"{path} has an invalid section")
            values = {
                key: value
                for key, value in entries.items()
                if isinstance(key, str) and isinstance(value, str) and value.strip()
            }
            if values:
                kept[section] = values
        payload[path.stem] = kept
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def restore(root: Path, source: Path) -> None:
    payload = read_object(source)
    for language, sections in payload.items():
        if not isinstance(language, str) or not isinstance(sections, dict):
            raise ValueError("invalid snapshot language")
        path = root / "core" / "i18n" / f"{language}.json"
        data = read_object(path)
        for section, entries in sections.items():
            if not isinstance(section, str) or not isinstance(entries, dict):
                raise ValueError("invalid snapshot section")
            target = data.setdefault(section, {})
            if not isinstance(target, dict):
                raise ValueError(f"{path} has an invalid section {section}")
            for key, value in entries.items():
                if not isinstance(key, str) or not isinstance(value, str) or not value.strip():
                    raise ValueError("invalid snapshot translation")
                target[key] = value
        path.write_text(json.dumps(data, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=("snapshot", "restore"))
    parser.add_argument("--root", type=Path, required=True)
    parser.add_argument("--snapshot", type=Path, required=True)
    args = parser.parse_args()
    if args.mode == "snapshot":
        snapshot(args.root.resolve(), args.snapshot.resolve())
    else:
        restore(args.root.resolve(), args.snapshot.resolve())
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
