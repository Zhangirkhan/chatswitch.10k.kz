from __future__ import annotations

import re

BLOCKED = ["убей", "сдохни", "terror", "nazi"]
EMAIL_RE = re.compile(r"[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+")
PHONE_RE = re.compile(r"\+?\d[\d\s\-()]{8,}\d")


def scrub_pii(text: str) -> str:
    text = EMAIL_RE.sub("[email]", text)
    text = PHONE_RE.sub("[phone]", text)

    return text


def is_toxic(text: str) -> bool:
    lower = text.lower()

    return any(word in lower for word in BLOCKED)
