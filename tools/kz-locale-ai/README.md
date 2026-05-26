# kz-locale-ai

Offline toolkit for preparing conversational training data and evaluating Kazakhstan RU/KK locale detection.

## Install

```bash
cd tools/kz-locale-ai
pip install -e .
```

## Commands

```bash
# Ingest raw sources into normalized JSONL
kz-locale-ai ingest --input ../../resources/locale/examples/few_shot_seed.jsonl --format openai_chat --out data/raw/all.jsonl

# Dedupe near-identical rows
kz-locale-ai dedupe --in data/raw/all.jsonl --out data/processed/deduped.jsonl

# Stratified train/val split
kz-locale-ai split --in data/processed/deduped.jsonl --train data/train.jsonl --val data/val.jsonl

# Validate OpenAI chat schema
kz-locale-ai validate --in data/train.jsonl

# Export fine-tune JSONL
kz-locale-ai export-openai --in data/train.jsonl --out data/finetune/train.jsonl

# Run detector benchmark (uses shared lexicons in resources/locale)
kz-locale-ai eval detect --benchmark ../../resources/locale/benchmarks/eval_cases.jsonl
```

Lexicons and benchmarks are shared with the Laravel app under `resources/locale/`.
