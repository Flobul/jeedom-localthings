#!/usr/bin/env python3
from __future__ import annotations

import json
from pathlib import Path
import sys
import tempfile
import unittest

CI_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(CI_DIR))

import preserve_manual_i18n as preserve


class PreserveManualI18nTests(unittest.TestCase):
    def test_restore_keeps_existing_nonempty_values_and_obsolete_entries(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            i18n = root / "core" / "i18n"
            i18n.mkdir(parents=True)
            path = i18n / "en_US.json"
            path.write_text(
                json.dumps(
                    {
                        "source.php": {
                            "Manual": "Human value",
                            "Obsolete": "Keep historical manual value",
                            "Empty": "",
                        }
                    }
                ),
                encoding="utf-8",
            )
            snapshot = root / "manual.json"
            preserve.snapshot(root, snapshot)

            path.write_text(
                json.dumps(
                    {
                        "source.php": {
                            "Manual": "Generated overwrite",
                            "Empty": "Generated empty replacement",
                            "New": "Generated new value",
                        }
                    }
                ),
                encoding="utf-8",
            )
            preserve.restore(root, snapshot)
            restored = json.loads(path.read_text(encoding="utf-8"))["source.php"]

            self.assertEqual(restored["Manual"], "Human value")
            self.assertEqual(restored["Obsolete"], "Keep historical manual value")
            self.assertEqual(restored["Empty"], "Generated empty replacement")
            self.assertEqual(restored["New"], "Generated new value")


if __name__ == "__main__":
    unittest.main(verbosity=2)
