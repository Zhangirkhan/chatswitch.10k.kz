from __future__ import annotations

import json
from pathlib import Path

from kz_locale_ai.pipeline.schema import ChatExample


def validate_file(input_path: str) -> dict[str, int]:
    valid = 0
    invalid = 0

    with Path(input_path).open(encoding="utf-8") as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            try:
                row = json.loads(line)
                ChatExample.model_validate(row)
                valid += 1
            except Exception:
                invalid += 1

    return {"valid": valid, "invalid": invalid}
