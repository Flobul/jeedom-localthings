#!/usr/bin/env python3
from __future__ import annotations

import json
from pathlib import Path
import sys
import tempfile
import unittest

CI_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(CI_DIR))

import pre_release_translation as gate


class PreReleaseTranslationTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        (self.root / "core" / "i18n").mkdir(parents=True)
        (self.root / ".github" / "ci").mkdir(parents=True)
        (self.root / ".github" / "ci" / "ci-config.json").write_text(
            json.dumps(
                {
                    "i18n": {
                        "source": "fr_FR",
                        "languages": ["fr_FR", "en_US", "de_DE"],
                    }
                }
            ),
            encoding="utf-8",
        )
        self.write_language("fr_FR", {"source.php": {"Bonjour": "Bonjour"}})
        self.write_language("en_US", {"source.php": {"Bonjour": "Hello"}})
        self.write_language("de_DE", {"source.php": {"Bonjour": "Hallo"}})

    def tearDown(self):
        self.temporary.cleanup()

    def write_language(self, language: str, data: dict) -> None:
        (self.root / "core" / "i18n" / f"{language}.json").write_text(
            json.dumps(data, ensure_ascii=False) + "\n",
            encoding="utf-8",
        )

    def test_no_release_candidate_never_calls_deepl(self):
        decision = gate.evaluate_gate(
            root=self.root,
            release_action="none",
            mode="enforce",
            translation_pr_open=False,
        )
        self.assertTrue(decision.release_allowed)
        self.assertFalse(decision.dispatch_translation)
        self.assertEqual(decision.reason, "no-release-candidate")

    def test_complete_translations_allow_candidate_without_deepl(self):
        decision = gate.evaluate_gate(
            root=self.root,
            release_action="create-tag-and-release",
            mode="enforce",
            translation_pr_open=False,
        )
        self.assertTrue(decision.release_allowed)
        self.assertFalse(decision.dispatch_translation)
        self.assertEqual(decision.reason, "translations-complete")

    def test_missing_translation_blocks_and_requests_generation(self):
        self.write_language("fr_FR", {"source.php": {"Bonjour": "Bonjour", "Nouveau": "Nouveau"}})
        decision = gate.evaluate_gate(
            root=self.root,
            release_action="create-tag-and-release",
            mode="enforce",
            translation_pr_open=False,
        )
        self.assertFalse(decision.release_allowed)
        self.assertTrue(decision.dispatch_translation)
        self.assertEqual(decision.reason, "translations-required")
        self.assertEqual(decision.missing_count, 2)

    def test_open_translation_pr_blocks_without_redispatch_loop(self):
        self.write_language("fr_FR", {"source.php": {"Bonjour": "Bonjour", "Nouveau": "Nouveau"}})
        decision = gate.evaluate_gate(
            root=self.root,
            release_action="create-release",
            mode="enforce",
            translation_pr_open=True,
        )
        self.assertFalse(decision.release_allowed)
        self.assertFalse(decision.dispatch_translation)
        self.assertEqual(decision.reason, "translation-pr-open")

    def test_translation_attempt_on_same_sha_blocks_automatic_retry(self):
        self.write_language("fr_FR", {"source.php": {"Bonjour": "Bonjour", "Nouveau": "Nouveau"}})
        decision = gate.evaluate_gate(
            root=self.root,
            release_action="create-release",
            mode="enforce",
            translation_pr_open=False,
            translation_attempt_exists=True,
        )
        self.assertFalse(decision.release_allowed)
        self.assertFalse(decision.dispatch_translation)
        self.assertEqual(decision.reason, "translation-attempt-exists")

    def test_complete_translations_after_pr_merge_allow_release(self):
        decision = gate.evaluate_gate(
            root=self.root,
            release_action="create-release",
            mode="enforce",
            translation_pr_open=False,
            translation_attempt_exists=False,
        )
        self.assertTrue(decision.release_allowed)
        self.assertEqual(decision.reason, "translations-complete")

    def test_simulation_reports_block_but_preserves_current_release_behavior(self):
        self.write_language("fr_FR", {"source.php": {"Bonjour": "Bonjour", "Nouveau": "Nouveau"}})
        decision = gate.evaluate_gate(
            root=self.root,
            release_action="create-tag-and-release",
            mode="simulate",
            translation_pr_open=False,
        )
        self.assertTrue(decision.release_allowed)
        self.assertFalse(decision.dispatch_translation)
        self.assertEqual(decision.reason, "simulation-translations-required")
        self.assertEqual(decision.missing_count, 2)

    def test_disabled_mode_is_a_strict_bypass(self):
        self.write_language("fr_FR", {"source.php": {"Bonjour": "Bonjour", "Nouveau": "Nouveau"}})
        decision = gate.evaluate_gate(
            root=self.root,
            release_action="create-tag-and-release",
            mode="disabled",
            translation_pr_open=False,
        )
        self.assertTrue(decision.release_allowed)
        self.assertFalse(decision.dispatch_translation)
        self.assertEqual(decision.reason, "gate-disabled")

    def test_invalid_json_fails_closed_in_enforce_mode(self):
        (self.root / "core" / "i18n" / "en_US.json").write_text("{", encoding="utf-8")
        decision = gate.evaluate_gate(
            root=self.root,
            release_action="create-tag-and-release",
            mode="enforce",
            translation_pr_open=False,
        )
        self.assertFalse(decision.release_allowed)
        self.assertFalse(decision.dispatch_translation)
        self.assertEqual(decision.reason, "translation-inspection-error")


if __name__ == "__main__":
    unittest.main(verbosity=2)
