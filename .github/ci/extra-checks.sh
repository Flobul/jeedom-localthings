#!/usr/bin/env bash
set -euo pipefail
# Repository-specific checks migrated from previous workflows.
git ls-files '*.json' | xargs -r -n1 jq empty
git ls-files '*.sh' | xargs -r -n1 bash -n
info_version="$(jq -r '.pluginVersion' plugin_info/info.json)"
class_version="$(sed -n "s/.*_pluginVersion = '\([^']*\)'.*/\1/p" core/class/localthings.class.php)"
test -n "$info_version"
test "$info_version" = "$class_version"
test -z "$(git ls-files | grep -E '(^|/)\.DS_Store$' || true)"
python3 .github/ci/test_release_guard.py
python3 .github/ci/test_release_workflow.py
