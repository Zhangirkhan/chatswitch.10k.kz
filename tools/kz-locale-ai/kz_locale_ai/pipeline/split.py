from __future__ import annotations

import json
import random
from collections import defaultdict
from pathlib import Path

from kz_locale_ai.detect.detector import KazakhstanLocaleDetector


def split_file(
    input_path: str,
    train_path: str,
    val_path: str,
    ratio: float = 0.9,
    seed: int = 42,
) -> dict[str, int]:
    detector = KazakhstanLocaleDetector()
    buckets: dict[str, list[dict]] = defaultdict(list)

    with Path(input_path).open(encoding="utf-8") as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            row = json.loads(line)
            user = _extract_user_text(row)
            profile = detector.detect(user)
            buckets[profile.dominant].append(row)

    rng = random.Random(seed)
    train_rows: list[dict] = []
    val_rows: list[dict] = []

    for rows in buckets.values():
        rng.shuffle(rows)
        cut = max(1, int(len(rows) * ratio)) if len(rows) > 1 else len(rows)
        train_rows.extend(rows[:cut])
        val_rows.extend(rows[cut:])

    _write(train_path, train_rows)
    _write(val_path, val_rows)

    return {"train": len(train_rows), "val": len(val_rows)}


def _extract_user_text(row: dict) -> str:
    for msg in row.get("messages", []):
        if isinstance(msg, dict) and msg.get("role") == "user":
            return str(msg.get("content", ""))

    return str(row.get("user", ""))


def _write(path: str, rows: list[dict]) -> None:
    out = Path(path)
    out.parent.mkdir(parents=True, exist_ok=True)
    with out.open("w", encoding="utf-8") as fh:
        for row in rows:
            fh.write(json.dumps(row, ensure_ascii=False) + "\n")
