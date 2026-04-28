#!/usr/bin/env bash
# Очистка всех чатов (сообщения, медиа, реакции, назначения) и контактов.
# Использование:
#   ./scripts/purge-chats-and-contacts.sh --dry-run
#   ./scripts/purge-chats-and-contacts.sh --force
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
exec php artisan chats:purge "$@"
