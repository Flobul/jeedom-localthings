#!/usr/bin/env python3
"""Evaluate the opt-in DeepL gate before a stable GitHub Release.

The gate never calls DeepL and never changes GitHub state. It only inspects the
repository and emits a deterministic decision consumed by release.yml.
"""
from __future__ import annotations

import argparse
from dataclasses import asdict, dataclass
import json
from pathlib import Path
import sys
from typing import Any

RELEASE_ACTIONS = {"create-tag-and-release", "create-release"}
MODES = {"disabled", "simulate", "enforce"}


@dataclass(frozen=True)
class GateDecision:
    mode: str
    release_action: str
    release_allowed: bool
    dispatch_translation: bool
    reason: str
    translation_pr_open: bool
    missing_count: int
    missing: tuple[str, ...]
    errors: tuple[str, ...]
    translation_attempt_exists: bool = False


def _read_object(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"Cannot read {path}: {exc}") from exc
    if not isinstance(value, dict):
        raise RuntimeError(f"{path} must contain a JSON object")
    return value


def _language_settings(root: Path) -> tuple[str, list[str]]:
    config = _read_object(root / ".github" / "ci" / "ci-config.json")
    i18n = config.get("i18n")
    if not isinstance(i18n, dict):
        raise RuntimeError("ci-config.json has no i18n object")
    source = str(i18n.get("source", "")).strip()
    languages = i18n.get("languages")
    if not source or not isinstance(languages, list):
        raise RuntimeError("ci-config.json has invalid i18n source/languages")
    normalized = [str(language).strip() for language in languages if str(language).strip()]
    if source not in normalized:
        raise RuntimeError("source language is absent from i18n.languages")
    targets = [language for language in normalized if language != source]
    if not targets:
        raise RuntimeError("no target translation language is configured")
    return source, targets


def _flatten(data: dict[str, Any], path: Path) -> dict[tuple[str, str], str]:
    flattened: dict[tuple[str, str], str] = {}
    for section, entries in data.items():
        if not isinstance(section, str) or not isinstance(entries, dict):
            raise RuntimeError(f"{path} has an invalid translation section")
        for key, value in entries.items():
            if not isinstance(key, str) or not isinstance(value, str):
                raise RuntimeError(f"{path} translation keys and values must be strings")
            flattened[(section, key)] = value
    return flattened


def translation_gaps(root: Path) -> tuple[list[str], list[str]]:
    root = root.resolve()
    source_language, target_languages = _language_settings(root)
    i18n_dir = root / "core" / "i18n"
    source_path = i18n_dir / f"{source_language}.json"
    source = _flatten(_read_object(source_path), source_path)
    if not source:
        raise RuntimeError(f"{source_path} contains no translation entries")

    missing: list[str] = []
    errors: list[str] = []
    for language in target_languages:
        target_path = i18n_dir / f"{language}.json"
        try:
            target = _flatten(_read_object(target_path), target_path)
        except RuntimeError as exc:
            errors.append(str(exc))
            continue
        for identity in sorted(source):
            value = target.get(identity)
            if value is None or not value.strip():
                section, key = identity
                missing.append(f"{language}:{section}:{key}")
    return missing, errors


def evaluate_gate(
    *,
    root: Path,
    release_action: str,
    mode: str,
    translation_pr_open: bool,
    translation_attempt_exists: bool = False,
) -> GateDecision:
    if mode not in MODES:
        raise ValueError(f"unsupported pre-release translation mode: {mode}")
    if mode == "disabled":
        return GateDecision(mode, release_action, True, False, "gate-disabled", translation_pr_open, 0, (), ())
    if release_action not in RELEASE_ACTIONS:
        return GateDecision(mode, release_action, True, False, "no-release-candidate", translation_pr_open, 0, (), ())

    try:
        missing, errors = translation_gaps(root)
    except RuntimeError as exc:
        missing, errors = [], [str(exc)]

    if errors:
        reason = "simulation-inspection-error" if mode == "simulate" else "translation-inspection-error"
        return GateDecision(mode, release_action, mode == "simulate", False, reason, translation_pr_open, 0, (), tuple(errors))
    if not missing:
        return GateDecision(mode, release_action, True, False, "translations-complete", translation_pr_open, 0, (), ())
    if mode == "simulate":
        return GateDecision(
            mode,
            release_action,
            True,
            False,
            "simulation-translations-required",
            translation_pr_open,
            len(missing),
            tuple(missing[:20]),
            (),
        )
    if translation_pr_open:
        return GateDecision(
            mode,
            release_action,
            False,
            False,
            "translation-pr-open",
            True,
            len(missing),
            tuple(missing[:20]),
            (),
        )
    if translation_attempt_exists:
        return GateDecision(
            mode=mode,
            release_action=release_action,
            release_allowed=False,
            dispatch_translation=False,
            reason="translation-attempt-exists",
            translation_pr_open=False,
            missing_count=len(missing),
            missing=tuple(missing[:20]),
            errors=(),
            translation_attempt_exists=True,
        )
    return GateDecision(
        mode,
        release_action,
        False,
        True,
        "translations-required",
        False,
        len(missing),
        tuple(missing[:20]),
        (),
    )


def _write_outputs(path: Path, decision: GateDecision) -> None:
    with path.open("a", encoding="utf-8") as handle:
        handle.write(f"release_allowed={str(decision.release_allowed).lower()}\n")
        handle.write(f"dispatch_translation={str(decision.dispatch_translation).lower()}\n")
        handle.write(f"reason={decision.reason}\n")
        handle.write(f"missing_count={decision.missing_count}\n")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path.cwd())
    parser.add_argument("--release-action", required=True)
    parser.add_argument("--mode", choices=sorted(MODES), required=True)
    parser.add_argument("--translation-pr-open", choices=("true", "false"), required=True)
    parser.add_argument("--translation-attempt-exists", choices=("true", "false"), required=True)
    parser.add_argument("--report", type=Path, required=True)
    parser.add_argument("--github-output", type=Path)
    args = parser.parse_args()

    decision = evaluate_gate(
        root=args.root,
        release_action=args.release_action,
        mode=args.mode,
        translation_pr_open=args.translation_pr_open == "true",
        translation_attempt_exists=args.translation_attempt_exists == "true",
    )
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(asdict(decision), ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    if args.github_output:
        _write_outputs(args.github_output, decision)
    print(json.dumps(asdict(decision), ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
