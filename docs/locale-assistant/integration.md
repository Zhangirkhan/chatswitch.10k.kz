# Integration guide

## WhatsApp auto-reply

`PromptBuilder::build()` calls `LocalePromptAugmenter::augmentAsMessages()` with the client question and chat context. Blocks are inserted as additional `system` messages before the user turn.

`AiReplyGenerator` applies `LocaleReplyGuard` after generation to avoid heavy slang on formal inputs.

## Operator AI panel

`ChatAssistantService` detects language from the **latest inbound client message** (not the operator's Russian prompt). Draft instructions tell the model to write client-facing text in the client's language while explaining to the operator in Russian.

## CRM AI workspace

`AiWorkspaceService` detects locale on each user query and injects `workspaceLanguageInstruction()` into parse and synthesize prompts so answers follow RU/KK/mixed style instead of hardcoded Russian.

## Example flow (mixed message)

1. Client sends: `скинь документти`
2. `KazakhstanLocaleDetector` → `mixed`, casual
3. `LocalePromptAugmenter` adds style rules + few-shot examples
4. OpenAI generates: `Қазір жіберем.`
5. `LocaleReplyGuard` leaves reply unchanged (casual context)

## Disabling

Set `LOCALE_ASSISTANT_ENABLED=false` to skip all locale blocks without removing code paths.
