from __future__ import annotations

import json
from pathlib import Path

from kz_locale_ai.pipeline.safety import is_toxic, scrub_pii
from kz_locale_ai.pipeline.validate import validate_file


def export_openai(
    input_path: str,
    out_path: str,
    system_prompt_path: str | None = None,
) -> dict[str, int]:
    default_system = "You are a multilingual Kazakhstan assistant."
    if system_prompt_path and Path(system_prompt_path).is_file():
        default_system = Path(system_prompt_path).read_text(encoding="utf-8").strip()

    exported = 0
    skipped = 0
    out = Path(out_path)
    out.parent.mkdir(parents=True, exist_ok=True)

    with Path(input_path).open(encoding="utf-8") as fh, out.open("w", encoding="utf-8") as out_fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            row = json.loads(line)
            messages = row.get("messages")
            if not messages:
                skipped += 1
                continue

            normalized = []
            has_system = False
            for msg in messages:
                role = msg.get("role")
                content = scrub_pii(str(msg.get("content", "")))
                if not content:
                    skipped += 1
                    break
                if role == "system":
                    has_system = True
                normalized.append({"role": role, "content": content})
            else:
                if not has_system:
                    normalized.insert(0, {"role": "system", "content": default_system})
                assistant = next((m["content"] for m in reversed(normalized) if m["role"] == "assistant"), "")
                if is_toxic(assistant):
                    skipped += 1
                    continue
                out_fh.write(json.dumps({"messages": normalized}, ensure_ascii=False) + "\n")
                exported += 1
                continue
            skipped += 1

    validation = validate_file(str(out))
    if validation["invalid"] > 0:
        raise RuntimeError(f"Export produced invalid rows: {validation}")

    return {"exported": exported, "skipped": skipped}
