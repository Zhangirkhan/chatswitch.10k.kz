from __future__ import annotations

import hashlib
import json
from pathlib import Path


def _char_ngrams(text: str, n: int = 3) -> set[str]:
    text = text.lower().replace(" ", "")
    if len(text) < n:
        return {text} if text else set()

    return {text[i : i + n] for i in range(len(text) - n + 1)}


def _jaccard(a: set[str], b: set[str]) -> float:
    if not a or not b:
        return 0.0
    inter = len(a & b)
    union = len(a | b)

    return inter / union if union else 0.0


def _row_signature(row: dict) -> str:
    messages = row.get("messages", [])
    parts: list[str] = []
    for msg in messages:
        if isinstance(msg, dict):
            parts.append(f"{msg.get('role','')}:{msg.get('content','')}")
    return normalize_key("|".join(parts))


def normalize_key(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def dedupe_file(input_path: str, out_path: str, threshold: float = 0.92) -> dict[str, int]:
    kept: list[dict] = []
    kept_ngrams: list[set[str]] = []
    exact: set[str] = set()
    removed = 0

    with Path(input_path).open(encoding="utf-8") as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            row = json.loads(line)
            sig = _row_signature(row)
            if sig in exact:
                removed += 1
                continue

            text = _row_signature(row)
            grams = _char_ngrams(text)
            duplicate = False
            for existing in kept_ngrams:
                if _jaccard(grams, existing) >= threshold:
                    duplicate = True
                    break
            if duplicate:
                removed += 1
                continue

            exact.add(sig)
            kept.append(row)
            kept_ngrams.append(grams)

    out = Path(out_path)
    out.parent.mkdir(parents=True, exist_ok=True)
    with out.open("w", encoding="utf-8") as fh:
        for row in kept:
            fh.write(json.dumps(row, ensure_ascii=False) + "\n")

    return {"kept": len(kept), "removed": removed}
