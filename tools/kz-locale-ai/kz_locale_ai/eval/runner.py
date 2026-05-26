from __future__ import annotations

import json
from pathlib import Path

from kz_locale_ai.detect.detector import KazakhstanLocaleDetector


def _dominant_matches(expected: str, actual: str) -> bool:
    if expected == actual:
        return True
    if expected == "kk" and actual in {"kk", "mixed", "translit_mixed"}:
        return True
    if expected == "mixed" and actual in {"mixed", "kk", "ru", "translit_mixed"}:
        return True
    if expected == "unknown" and actual == "unknown":
        return True

    return False


def run_detect_eval(benchmark_path: str, lexicon_root: str | None = None) -> dict[str, float | int]:
    detector = KazakhstanLocaleDetector(lexicon_root)
    total = 0
    dominant_hits = 0
    formality_hits = 0

    with Path(benchmark_path).open(encoding="utf-8") as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            case = json.loads(line)
            text = case.get("input", "")
            expected_dominant = case.get("expected_dominant", "")
            expected_formality = case.get("expected_formality", "")
            if not text or not expected_dominant:
                continue
            total += 1
            profile = detector.detect(text)
            if _dominant_matches(expected_dominant, profile.dominant):
                dominant_hits += 1
            if not expected_formality or profile.formality == expected_formality:
                formality_hits += 1

    dominant_accuracy = 100.0 * dominant_hits / total if total else 0.0
    formality_accuracy = 100.0 * formality_hits / total if total else 0.0

    return {
        "total": total,
        "dominant_accuracy": dominant_accuracy,
        "formality_accuracy": formality_accuracy,
    }
