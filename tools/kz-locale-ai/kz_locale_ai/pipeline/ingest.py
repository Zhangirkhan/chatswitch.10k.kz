from __future__ import annotations

import csv
import json
from pathlib import Path
from typing import Any

from kz_locale_ai.pipeline.normalize import normalize_unicode


def ingest_file(input_path: str, fmt: str, out_path: str) -> int:
    path = Path(input_path)
    rows: list[dict[str, Any]] = []

    if fmt == "openai_chat":
        rows = _ingest_openai_chat(path)
    elif fmt == "pairs_csv":
        rows = _ingest_pairs_csv(path)
    elif fmt == "telegram":
        rows = _ingest_telegram(path)
    else:
        raise ValueError(f"Unknown format: {fmt}")

    out = Path(out_path)
    out.parent.mkdir(parents=True, exist_ok=True)
    with out.open("w", encoding="utf-8") as fh:
        for row in rows:
            fh.write(json.dumps(row, ensure_ascii=False) + "\n")

    return len(rows)


def _ingest_openai_chat(path: Path) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    with path.open(encoding="utf-8") as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            row = json.loads(line)
            if "messages" in row:
                row["messages"] = _normalize_messages(row["messages"])
            rows.append(row)

    return rows


def _ingest_pairs_csv(path: Path) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    with path.open(encoding="utf-8") as fh:
        reader = csv.DictReader(fh)
        for item in reader:
            user = normalize_unicode(str(item.get("user", "")))
            assistant = normalize_unicode(str(item.get("assistant", "")))
            if not user or not assistant:
                continue
            rows.append(
                {
                    "messages": [
                        {"role": "system", "content": "You are a multilingual Kazakhstan assistant."},
                        {"role": "user", "content": user},
                        {"role": "assistant", "content": assistant},
                    ]
                }
            )

    return rows


def _ingest_telegram(path: Path) -> list[dict[str, Any]]:
    data = json.loads(path.read_text(encoding="utf-8"))
    messages = data.get("messages", data) if isinstance(data, dict) else data
    rows: list[dict[str, Any]] = []
    if not isinstance(messages, list):
        return rows

    for msg in messages:
        if not isinstance(msg, dict):
            continue
        text = msg.get("text")
        if isinstance(text, list):
            text = "".join(part if isinstance(part, str) else "" for part in text)
        if not isinstance(text, str):
            continue
        text = normalize_unicode(text)
        if not text:
            continue
        rows.append({"raw_text": text, "source": "telegram"})

    return rows


def _normalize_messages(messages: list[dict[str, str]]) -> list[dict[str, str]]:
    result: list[dict[str, str]] = []
    for msg in messages:
        role = msg.get("role", "")
        content = normalize_unicode(str(msg.get("content", "")))
        if role in {"system", "user", "assistant"} and content:
            result.append({"role": role, "content": content})

    return result
