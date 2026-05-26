# Kazakhstan Locale Assistant

Multilingual RU/KK layer for Accel AI: language detection, style matching, few-shot examples, optional slang RAG, and fine-tune dataset tooling.

## Architecture

- **Runtime (Laravel):** `app/Services/AI/Locale/*` — injected into WhatsApp auto-reply, operator AI panel, and CRM workspace.
- **Resources:** `resources/locale/` — system prompt, lexicons, seed examples, benchmarks.
- **Offline (Python):** `tools/kz-locale-ai/` — ingest, dedupe, split, validate, export, eval.

## Environment

```env
LOCALE_ASSISTANT_ENABLED=true
LOCALE_FEW_SHOT_ENABLED=true
LOCALE_FEW_SHOT_COUNT=5
LOCALE_RAG_ENABLED=false
LOCALE_RAG_TOP_K=6
```

## Artisan commands

```bash
# Import few-shot examples from JSONL
php artisan locale:import-examples resources/locale/examples/few_shot_seed.jsonl

# Import slang phrases
php artisan locale:import-examples resources/locale/examples/slang_phrases_seed.jsonl --phrases

# Index embeddings (requires OPENAI_API_KEY)
php artisan locale:index-embeddings

# Export fine-tune JSONL
php artisan locale:export-finetune resources/locale/examples/few_shot_seed.jsonl storage/app/locale-finetune.jsonl

# Run detector benchmark
php artisan locale:eval
```

## Python toolkit

```bash
cd tools/kz-locale-ai && pip install -e .
kz-locale-ai eval detect --benchmark ../../resources/locale/benchmarks/eval_cases.jsonl
```

## Privacy

When importing Telegram or support exports, run PII scrubbing via the Python `safety` module before storage. Do not auto-import production chats without explicit approval.

## Fine-tuning

1. Prepare data with `locale:export-finetune` or `kz-locale-ai export-openai`.
2. Upload JSONL to OpenAI fine-tuning dashboard or API.
3. Point `OPENAI_MODEL` to the fine-tuned model when ready.
