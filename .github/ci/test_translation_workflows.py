#!/usr/bin/env python3
import json
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
WORKFLOWS = ROOT / '.github' / 'workflows'


class TranslationWorkflowSeparationTests(unittest.TestCase):
    def test_blocking_ci_uses_read_only_translation_validation(self):
        ci = (WORKFLOWS / 'ci.yml').read_text(encoding='utf-8')
        self.assertIn('uses: ./.github/workflows/check-translation.yml', ci)
        translation_job = ci[ci.index('  translation:'):ci.index('  report:')]
        self.assertNotIn('contents: write', translation_job)
        self.assertNotIn('pull-requests: write', translation_job)
        self.assertNotIn('secrets: inherit', translation_job)

    def test_validation_workflow_is_secretless_and_read_only(self):
        workflow = (WORKFLOWS / 'check-translation.yml').read_text(encoding='utf-8')
        config = json.loads((ROOT / '.github' / 'ci' / 'ci-config.json').read_text())
        expected = ','.join(config['i18n']['languages'])
        self.assertIn('workflow_call:', workflow)
        self.assertIn('contents: read', workflow)
        self.assertIn(f'--expected {expected}', workflow)
        self.assertIn('--require-languages', workflow)
        self.assertNotIn('DEEPL_API_KEY', workflow)
        self.assertNotIn('create-pull-request', workflow)
        self.assertNotIn('contents: write', workflow)

    def test_generation_is_manual_independent_and_skips_without_secret(self):
        workflow = (WORKFLOWS / 'translation.yml').read_text(encoding='utf-8')
        self.assertIn('workflow_dispatch:', workflow)
        self.assertNotIn('workflow_call:', workflow)
        self.assertIn('id: deepl', workflow)
        self.assertIn('configured=false', workflow)
        self.assertIn("steps.deepl.outputs.configured == 'true'", workflow)
        self.assertIn('peter-evans/create-pull-request@v8', workflow)
        self.assertNotIn('::error::Translation blocked', workflow)

    def test_translation_mode_default_is_exactly_simulate(self):
        import re
        workflow = (WORKFLOWS / 'release.yml').read_text(encoding='utf-8')
        block = re.search(
            r"^      translation_mode:\n(?P<body>(?:        .*\n)+?)(?=\n|^      [a-zA-Z_])",
            workflow,
            re.MULTILINE,
        )
        self.assertIsNotNone(block)
        assert block is not None
        self.assertIn('default: simulate', block.group('body'))
        self.assertNotIn('default: enforce', block.group('body'))
        self.assertIn('PRE_RELEASE_TRANSLATION_MODE', workflow)
        self.assertIn('test_pre_release_translation.py', workflow)
        self.assertIn('pre_release_translation.py', workflow)
        self.assertIn("repository: jeedom/core", workflow)
        self.assertIn("ref: '90a95f1dcef74a65d77374a1fed427541032fe82'", workflow)
        self.assertIn('--core-source .ci/jeedom-core/core/i18n/fr_FR.json', workflow)
        self.assertIn("needs.plan.outputs.translation_ready == 'true'", workflow)

    def test_release_workflow_never_auto_merges_translation_pr(self):
        release = (WORKFLOWS / 'release.yml').read_text(encoding='utf-8')
        translation = (WORKFLOWS / 'translation.yml').read_text(encoding='utf-8')
        combined = release + '\n' + translation
        self.assertNotIn('gh pr merge', combined)
        self.assertNotIn('merge-method:', combined)
        self.assertNotIn('auto-merge', combined)

    def test_release_dispatches_translation_only_from_explicit_gate_output(self):
        workflow = (WORKFLOWS / 'release.yml').read_text(encoding='utf-8')
        self.assertIn("needs.plan.outputs.dispatch_translation == 'true'", workflow)
        self.assertIn('gh workflow run translation.yml', workflow)
        self.assertIn("steps.translation_gate.outputs.release_allowed", workflow)
        self.assertIn('--commit "$TARGET_COMMIT"', workflow)
        self.assertIn('--translation-attempt-exists', workflow)

    def test_actions_write_and_deepl_dispatch_are_confined_to_guarded_job(self):
        workflow = (WORKFLOWS / 'release.yml').read_text(encoding='utf-8')
        matches = list(re.finditer(r'^  (?P<name>[a-zA-Z0-9_-]+):\n', workflow, re.MULTILINE))
        jobs = {}
        for index, match in enumerate(matches):
            end = matches[index + 1].start() if index + 1 < len(matches) else len(workflow)
            jobs[match.group('name')] = workflow[match.start():end]

        writers = [name for name, body in jobs.items() if 'actions: write' in body]
        dispatchers = [name for name, body in jobs.items() if 'gh workflow run translation.yml' in body]
        self.assertEqual(writers, ['request-translations'])
        self.assertEqual(dispatchers, ['request-translations'])

        request = jobs['request-translations']
        self.assertIn('needs: plan', request)
        self.assertIn("if: needs.plan.outputs.dispatch_translation == 'true'", request)
        self.assertEqual(workflow.count('gh workflow run translation.yml'), 1)


if __name__ == '__main__':
    unittest.main()
