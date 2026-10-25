#!/usr/bin/env bash
set -uo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root" || exit 1

failures=0
active_sandbox=""
cleanup_active_sandbox() {
  if [[ -n "$active_sandbox" && -d "$active_sandbox" ]]; then
    rm -rf -- "$active_sandbox" || return 1
  fi
  active_sandbox=""
}
trap cleanup_active_sandbox EXIT
trap 'cleanup_active_sandbox; exit 130' INT TERM HUP

run_test() {
  local label="$1"
  shift
  printf '\n==> %s\n' "$label"
  if "$@"; then
    printf 'PASS: %s\n' "$label"
  else
    printf 'FAIL: %s\n' "$label" >&2
    failures=$((failures + 1))
  fi
}

run_discovery_test() {
  local status
  active_sandbox="$(mktemp -d "${TMPDIR:-/tmp}/localthings-functional.XXXXXX")" || return 1
  mkdir -p "$active_sandbox/core" "$active_sandbox/data" || {
    cleanup_active_sandbox
    return 1
  }
  cp -R core/class "$active_sandbox/core/class" || {
    cleanup_active_sandbox
    return 1
  }
  LOCALTHINGS_TEST_ROOT="$active_sandbox" php .github/ci/functional/discovery_error_test.php
  status=$?
  if ! cleanup_active_sandbox; then
    return 1
  fi
  return "$status"
}

run_test "LocalThings mapper and equipment profile" php .github/ci/functional/ehs_identity_test.php
run_test "CoAP response correlation, retries and block transfer" php .github/ci/functional/coap_response_correlation_test.php
run_test "UDP relay and DTLS peer pinning" php .github/ci/functional/udp_relay_test.php
run_test "Discovery lifecycle and error recovery" run_discovery_test
run_test "DTLS probe with synthetic loopback responders" python3 .github/ci/functional/dtls_probe_test.py

if (( failures > 0 )); then
  printf '\n%d functional test(s) failed.\n' "$failures" >&2
  exit 1
fi
printf '\nAll LocalThings functional tests passed.\n'
