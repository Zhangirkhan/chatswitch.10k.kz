from __future__ import annotations

import json
import re
from dataclasses import dataclass
from pathlib import Path


@dataclass
class LocaleProfile:
    dominant: str
    ru_pct: float
    kk_pct: float
    script: str
    formality: str
    slang_score: float
    allow_mixed_reply: bool
    prefer_kk_cyrillic: bool
    confidence: str


class KazakhstanLocaleDetector:
    def __init__(self, lexicon_root: str | None = None) -> None:
        root = Path(lexicon_root) if lexicon_root else Path(__file__).resolve().parents[4] / "resources" / "locale" / "lexicons"
        self._lexicons = {
            name: json.loads((root / f"{name}.json").read_text(encoding="utf-8"))
            for name in ["kz_letters", "ru_function_words", "kk_function_words", "formal_markers", "slang_ru_kk"]
        }

    def detect(self, text: str, chat_context: str | None = None) -> LocaleProfile:
        text = text.strip()
        if not text:
            return LocaleProfile("unknown", 0.5, 0.5, "mixed", "neutral", 0.0, False, False, "low")

        combined = f"{text}\n{chat_context}" if chat_context else text
        lower = text.lower()
        tokens = self._tokenize(lower)
        token_count = max(1, len(tokens))

        ru_hits = self._hits(lower, tokens, self._words("ru_function_words"))
        kk_hits = self._hits(lower, tokens, self._words("kk_function_words"))
        slang_hits = self._hits(lower, tokens, self._words("slang_ru_kk", "terms"))
        formal_hits = self._hits(lower, tokens, self._words("formal_markers", "formal"))
        casual_hits = self._hits(lower, tokens, self._words("formal_markers", "casual"))

        kk_letters = sum(combined.count(ch) for ch in self._lexicons["kz_letters"].get("kk_cyrillic", []))
        cyr = self._ratio(combined, r"\p{Cyrillic}")
        lat = self._ratio(combined, r"\p{Latin}")
        script = "mixed"
        if cyr >= 0.6:
            script = "cyrillic"
        elif lat >= 0.6:
            script = "latin"

        translit_kk = self._translit_hits(lower, "translit_kk_words")
        translit_ru = self._translit_hits(lower, "translit_ru_words")

        ru_score = ru_hits + (0.5 if cyr > 0.5 else 0) + translit_ru * 0.5
        kk_score = kk_hits + kk_letters * 2 + translit_kk * 0.8
        total = max(0.01, ru_score + kk_score)
        ru_pct = min(1.0, ru_score / total)
        kk_pct = min(1.0, kk_score / total)

        mixed_threshold = 0.2
        dominant_threshold = 0.55
        dominant = "unknown"
        if script == "latin" and (translit_kk or translit_ru or kk_hits):
            dominant = "translit_mixed"
        elif ru_pct >= dominant_threshold and kk_pct < mixed_threshold:
            dominant = "ru"
        elif kk_pct >= dominant_threshold and ru_pct < mixed_threshold:
            dominant = "kk"
        elif ru_pct >= mixed_threshold and kk_pct >= mixed_threshold:
            dominant = "mixed"
        elif kk_letters >= 2:
            dominant = "kk"
        elif ru_hits and not kk_hits and not kk_letters:
            dominant = "ru"
        elif kk_hits and not ru_hits:
            dominant = "kk"
        elif ru_hits or kk_hits:
            dominant = "mixed"

        slang_score = min(1.0, (slang_hits + casual_hits * 0.5) / token_count)
        formality = "neutral"
        if formal_hits > casual_hits and formal_hits:
            formality = "formal"
        elif casual_hits > formal_hits and (casual_hits or slang_score >= 0.35):
            formality = "casual"

        confidence = "low" if len(tokens) <= 3 else "high"
        if confidence == "low" and formality == "casual":
            formality = "neutral"

        allow_mixed = dominant in {"mixed", "translit_mixed"} or (ru_pct >= mixed_threshold and kk_pct >= mixed_threshold)
        prefer_kk = script == "latin" and (translit_kk > 0 or dominant == "kk")

        return LocaleProfile(
            dominant,
            round(ru_pct, 2),
            round(kk_pct, 2),
            script,
            formality,
            round(slang_score, 2),
            allow_mixed,
            prefer_kk,
            confidence,
        )

    def _words(self, name: str, key: str = "words") -> list[str]:
        data = self._lexicons.get(name, {})
        items = data.get(key, data.get("terms", []))

        return [str(i).lower() for i in items if isinstance(i, str)]

    def _tokenize(self, text: str) -> list[str]:
        normalized = re.sub(r"[^\w\s]+", " ", text, flags=re.UNICODE)
        return [t for t in normalized.split() if t]

    def _hits(self, haystack: str, tokens: list[str], lexicon: list[str]) -> int:
        return sum(1 for w in lexicon if w in tokens or w in haystack)

    def _translit_hits(self, haystack: str, key: str) -> int:
        words = self._lexicons["kz_letters"].get(key, [])

        return sum(1 for w in words if isinstance(w, str) and w.lower() in haystack)

    def _ratio(self, text: str, pattern: str) -> float:
        # Approximate without regex unicode properties
        cyr = len(re.findall(r"[а-яёәөүұқңғһі]", text, flags=re.IGNORECASE))
        lat = len(re.findall(r"[a-z]", text, flags=re.IGNORECASE))
        total = cyr + lat

        if pattern.endswith("Cyrillic"):
            return cyr / total if total else 0.0

        return lat / total if total else 0.0
