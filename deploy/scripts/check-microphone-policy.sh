#!/usr/bin/env bash
set -euo pipefail

URL="${1:-https://demo.accel.kz}"

headers="$(curl -fsSI "$URL" || true)"

if ! echo "$headers" | grep -qi 'permissions-policy:.*microphone=(self)'; then
    echo "FAIL: Permissions-Policy must allow microphone=(self) for dictation"
    echo "$headers" | grep -i permissions-policy || true
    exit 1
fi

if echo "$headers" | grep -qi 'permissions-policy:.*microphone=()'; then
    echo "FAIL: microphone=() blocks browser dictation"
    exit 1
fi

echo "OK: microphone policy allows self origin for $URL"
