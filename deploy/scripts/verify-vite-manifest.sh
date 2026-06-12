#!/usr/bin/env bash
# Проверяет, что каждый file/css/assets из public/build/manifest.json существует на диске.
# Использование: verify-vite-manifest.sh [APP_ROOT]
set -euo pipefail

APP_ROOT="${1:-.}"
MANIFEST="${APP_ROOT}/public/build/manifest.json"
BUILD_DIR="${APP_ROOT}/public/build"

if [[ ! -f "${MANIFEST}" ]]; then
    echo "verify-vite-manifest: manifest not found: ${MANIFEST}" >&2
    exit 1
fi

python3 - "${MANIFEST}" "${BUILD_DIR}" <<'PY'
import json
import sys
from pathlib import Path

manifest_path = Path(sys.argv[1])
build_dir = Path(sys.argv[2])

with manifest_path.open(encoding="utf-8") as handle:
    manifest = json.load(handle)

paths: set[str] = set()
for entry in manifest.values():
    if not isinstance(entry, dict):
        continue
    file_path = entry.get("file")
    if isinstance(file_path, str) and file_path:
        paths.add(file_path)
    for key in ("css", "assets"):
        for item in entry.get(key) or []:
            if isinstance(item, str) and item:
                paths.add(item)

missing = sorted(
    rel for rel in paths if not (build_dir / rel).is_file()
)

if missing:
    print(
        f"verify-vite-manifest: {len(missing)} asset(s) missing for {manifest_path}",
        file=sys.stderr,
    )
    for rel in missing[:25]:
        print(f"  - {rel}", file=sys.stderr)
    if len(missing) > 25:
        print(f"  ... and {len(missing) - 25} more", file=sys.stderr)
    sys.exit(1)

print(f"verify-vite-manifest: OK ({len(paths)} asset(s) present)")
PY
