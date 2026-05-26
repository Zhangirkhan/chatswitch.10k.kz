from __future__ import annotations

import re
import unicodedata


def normalize_unicode(text: str) -> str:
    """NFC normalization only — preserve slang and intentional spelling."""
    text = unicodedata.normalize("NFC", text)
    text = text.replace("\ufeff", "").replace("\u200b", "").replace("\u200c", "").replace("\u200d", "")
    text = re.sub(r"[ \t]+", " ", text)
    text = re.sub(r"\n{3,}", "\n\n", text)

    return text.strip()
